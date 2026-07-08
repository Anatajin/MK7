<?php

require_once __DIR__ . '/AdminMonitor.php';

class AdminAssistant
{
    private PDO $pdo;
    private AdminMonitor $monitor;
    private string $projectRoot;
    private string $monitoringDir;
    private string $assistantLogPath;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->monitor = new AdminMonitor($pdo);
        $this->projectRoot = dirname(__DIR__);
        $this->monitoringDir = $this->projectRoot . DIRECTORY_SEPARATOR . '.monitoring';
        $this->assistantLogPath = $this->monitoringDir . DIRECTORY_SEPARATOR . 'assistant_actions.log';
    }

    public function handleMessage(string $message, array $history = [], array $options = []): array
    {
        $message = trim($message);
        if ($message === '') {
            return [
                'success' => false,
                'message' => 'الرسالة فارغة.',
            ];
        }

        $role = (string)($options['role'] ?? 'viewer');
        $page = (string)($options['page'] ?? '');
        $mode = $this->detectRequestMode($message);

        $actionResult = null;
        if ($mode === 'action') {
            $actionResult = $this->tryExecuteSafeAction($message, $role);
            if ($actionResult !== null) {
                $actionResult = $this->normalizeActionResultMessages($actionResult);
                $this->appendAssistantActionLog($message, $role, $actionResult);
            }
        }

        $context = $this->buildContext($message, $page, $actionResult, $mode);
        $reply = $this->askGemini($message, $history, $context, $role, $actionResult, $mode);

        return [
            'success' => true,
            'reply' => $reply,
            'action' => $actionResult,
            'context' => [
                'page' => $page,
                'role' => $role,
                'mode' => $mode,
                'matched_files' => array_map(
                    static fn(array $item): array => [
                        'file' => $item['file'],
                        'reason' => $item['reason'],
                    ],
                    $context['project_matches'] ?? []
                ),
                'database_summary' => $context['database_summary'] ?? null,
                'service_logs' => $context['service_logs'] ?? [],
            ],
        ];
    }

    private function normalizeActionResultMessages(array $actionResult): array
    {
        $type = (string)($actionResult['type'] ?? '');

        if ($type === 'top_ips') {
            $rows = (array)($actionResult['rows'] ?? []);
            $actionResult['message'] = empty($rows)
                ? 'لا توجد عناوين IP مشبوهة أو كثيفة حاليًا ضمن نافذة المراقبة.'
                : 'تم جلب أكثر عناوين IP كثافة من لوحة المراقبة.';
        }

        if ($type === 'block_ips') {
            $actionResult['message'] = 'تم حظر عناوين IP المحددة.';
        }

        if ($type === 'unblock_ips') {
            $actionResult['message'] = 'تم رفع الحظر عن عناوين IP المحددة.';
        }

        return $actionResult;
    }

    private function extractShellCommand(string $message): ?string
    {
        $patterns = [
            '/^\s*cmd\s*:\s*(.+)\s*$/iu',
            '/^\s*shell\s*:\s*(.+)\s*$/iu',
            '/^\s*run\s+command\s*:\s*(.+)\s*$/iu',
            '/^\s*نفذ\s+الأمر\s*:?\s*(.+)\s*$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $command = trim((string)($matches[1] ?? ''));
                return $command !== '' ? $command : null;
            }
        }

        return null;
    }

    private function askGemini(string $message, array $history, array $context, string $role, ?array $actionResult, string $mode): string
    {
        $apiKey = $this->getActiveGeminiKey();
        if ($apiKey === null) {
            return 'لا يوجد مفتاح Gemini نشط داخل الإعدادات حاليًا. فعّل مفتاحًا من صفحة الإعدادات أولًا.';
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . rawurlencode($apiKey);
        $contents = [];

        $systemPrompt = $this->buildSystemPrompt($role, $context, $actionResult, $mode);
        $contents[] = [
            'role' => 'user',
            'parts' => [
                ['text' => $systemPrompt]
            ]
        ];

        $trimmedHistory = array_slice($history, -8);
        foreach ($trimmedHistory as $entry) {
            $entryRole = (($entry['role'] ?? '') === 'assistant') ? 'model' : 'user';
            $entryText = trim((string)($entry['text'] ?? ''));
            if ($entryText === '') {
                continue;
            }

            $contents[] = [
                'role' => $entryRole,
                'parts' => [
                    ['text' => $entryText]
                ]
            ];
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [
                ['text' => $message]
            ]
        ];

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.4,
                'topP' => 0.9,
                'maxOutputTokens' => 900,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 45,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            return 'تعذر الوصول إلى Gemini الآن. حاول مجددًا بعد قليل.';
        }

        $decoded = json_decode($response, true);
        if ($httpCode !== 200) {
            $errorMessage = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
            return $this->formatGeminiErrorMessage($httpCode, $errorMessage);
        }

        $text = trim((string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        if ($text === '') {
            return 'وصل رد فارغ من Gemini. حاول بصياغة أخرى أو راجع المفتاح.';
        }

        $this->incrementGeminiUsage();
        return $text;
    }

    private function buildSystemPrompt(string $role, array $context, ?array $actionResult, string $mode): string
    {
        $actionSummary = $actionResult
            ? json_encode($actionResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'null';

        $compactContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $modeRules = match ($mode) {
            'chat' => "- هذا طلب دردشة عادي.\n- تعامل كصديق مساعد طبيعي داخل لوحة التحكم.\n- لا تبدأ البحث في الملفات ولا فحص السيرفر من نفسك.\n- إذا كانت الرسالة تحية أو دردشة خفيفة فجاوب باختصار طبيعي.",
            'status' => "- هذا طلب حالة أو شرح للنظام.\n- استخدم ملخص النظام وقاعدة البيانات واللوغات المرفقة.\n- لا تبحث داخل الملفات إلا إذا طلب المستخدم ذلك صراحة.",
            'search' => "- هذا طلب بحث صريح داخل النظام أو الملفات.\n- استخدم نتائج البحث المرفقة واللوغات إن وجدت.\n- اذكر بوضوح ما وجدته وما لم تجده.",
            'action' => "- هذا طلب تنفيذ إجراء.\n- إذا وُجدت نتيجة تنفيذ فعلية فاذكرها أولًا.\n- الأوامر التنفيذية هنا allowlist فقط وليست shell مفتوحًا.\n- إذا طلب المستخدم أمرًا خارج الصلاحيات الآمنة، اشرح ذلك واقترح بديلًا آمنًا.",
            default => "- تعامل مع الطلب بحذر ووضوح."
        };

        return <<<PROMPT
أنت مساعد إداري عربي ودود داخل لوحة تحكم SPORT.

قواعد ثابتة:
- أجب بالعربية وبأسلوب طبيعي وودود.
- لا تبدأ البحث في الملفات أو فحص النظام إلا إذا كان الطلب واضحًا وصريحًا.
- لا تدّعِ تنفيذ شيء لم يتم فعلاً.
- الصلاحيات التنفيذية هنا واسعة لكن آمنة: خدمات محددة، قاعدة بيانات، نسخ احتياطي، تنظيف كاش، وقراءة لوغات. ليست shell مفتوحًا وليست SQL حرًا.
- إذا طلب المستخدم تحكمًا كاملًا غير محدود في النظام أو السيرفر، اشرح بلطف أن التحكم المفتوح غير مفعّل لأسباب الأمان، ثم اقترح الإجراء الآمن الأقرب.
- إذا كانت هناك عملية نُفّذت بالفعل في هذا الطلب فاذكر نتيجتها أولًا باختصار.
- استخدم سياق النظام المرفق فقط، ولا تخترع ملفات أو جداول أو حالات غير موجودة.

نمط الطلب الحالي: {$mode}
تعليمات خاصة بهذا النمط:
{$modeRules}

بيانات الجلسة:
- دور المستخدم الحالي: {$role}
- نتيجة الإجراء المنفذ في هذا الطلب: {$actionSummary}

سياق النظام الحالي:
{$compactContext}
PROMPT;
    }

    private function buildContext(string $message, string $page, ?array $actionResult, string $mode): array
    {
        $context = [
            'page' => $page,
            'mode' => $mode,
            'action_result' => $actionResult,
            'settings' => [
                'active_gemini_key_name' => $this->getActiveGeminiKeyName(),
                'active_scraper_source' => $this->getActiveScraperSource(),
                'enabled_tasks' => $this->getEnabledTasksSummary(),
            ],
            'project_matches' => [],
            'service_logs' => [],
        ];

        if (in_array($mode, ['status', 'search', 'action'], true)) {
            $snapshot = $this->monitor->getSnapshot(60);
            $context['system_summary'] = [
                'hostname' => $snapshot['system']['hostname'] ?? null,
                'php_version' => $snapshot['system']['php_version'] ?? null,
                'nginx_version' => $snapshot['system']['nginx_version'] ?? null,
                'database_version' => $snapshot['system']['database_version'] ?? null,
                'load_average' => $snapshot['system']['load_average'] ?? [],
                'memory_used_percent' => $snapshot['system']['memory']['used_percent'] ?? null,
                'disk_used_percent' => $snapshot['system']['disk']['used_percent'] ?? null,
                'uptime_human' => $snapshot['system']['uptime_human'] ?? null,
                'services' => $snapshot['services'] ?? [],
                'api' => [
                    'total_requests' => $snapshot['api']['total_requests'] ?? 0,
                    'rate_limited_429' => $snapshot['api']['rate_limited'] ?? ($snapshot['api']['rate_limited_requests'] ?? 0),
                    'server_errors_5xx' => $snapshot['api']['server_errors'] ?? 0,
                    'top_actions' => $snapshot['api']['top_actions'] ?? [],
                ],
                'cache' => [
                    'hit_ratio' => $snapshot['cache']['hit_ratio'] ?? 0,
                    'status_counts' => $snapshot['cache']['status_counts'] ?? [],
                ],
                'security' => [
                    'suspicious_total' => $snapshot['security']['suspicious_total'] ?? 0,
                    'blocked_ips_total' => $snapshot['security']['blocked_ips_total'] ?? 0,
                    'top_ips' => array_slice((array)($snapshot['security']['top_ips'] ?? []), 0, 10),
                ],
                'top_keys' => $snapshot['top_keys'] ?? [],
            ];

            $context['database_summary'] = $this->getDatabaseSummary();
        }

        if ($mode === 'search') {
            $context['project_matches'] = $this->searchProject($message, $page);
        }

        if ($this->containsAny($this->normalizeText($message), ['log', 'logs', 'سجل', 'سجلات', 'لوج', 'الاخطاء', 'الأخطاء'])) {
            $context['service_logs'] = $this->collectRequestedLogs($message);
        }

        return $context;
    }

    private function tryExecuteSafeAction(string $message, string $role): ?array
    {
        $normalized = $this->normalizeText($message);

        $shellCommand = $this->extractShellCommand($message);
        if ($shellCommand !== null) {
            return $this->executeAdvancedShellCommand($shellCommand, $role);
        }

        $isWriteIntent = $this->containsAny($normalized, [
            'شغل', 'فعل', 'ابدأ', 'ابدء', 'وقف', 'اوقف', 'عطل', 'clear', 'امسح', 'نظف',
            'restart', 'أعد تشغيل', 'اعد تشغيل', 'backup', 'نسخة احتياطية', 'dump',
            'optimize', 'optimise', 'repair', 'check', 'تحسين', 'إصلاح', 'فحص'
        ]);

        if (!empty($this->extractIpAddresses($message)) &&
            $this->containsAny($normalized, ['Ø§Ø­Ø¸Ø±', 'Ø­Ø¸Ø±', 'Ø±ÙØ¹ Ø§Ù„Ø­Ø¸Ø±', 'ÙÙƒ Ø§Ù„Ø­Ø¸Ø±', 'Ø§Ù„ØºØ§Ø¡ Ø§Ù„Ø­Ø¸Ø±', 'Ø¥Ù„ØºØ§Ø¡ Ø§Ù„Ø­Ø¸Ø±', 'block', 'unblock', 'ban'])) {
            $isWriteIntent = true;
        }

        if (!empty($this->extractIpAddresses($message)) &&
            ($this->hasIpBlockIntent($normalized) || $this->hasIpUnblockIntent($normalized))) {
            $isWriteIntent = true;
        }

        if ($isWriteIntent && $role !== 'admin') {
            return [
                'success' => false,
                'type' => 'permission_denied',
                'message' => 'تنفيذ الإجراءات متاح للمسؤول Admin فقط داخل هذا المساعد.',
            ];
        }

        if ($this->containsAny($normalized, ['backup', 'نسخة احتياطية', 'باك اب', 'dump']) &&
            $this->containsAny($normalized, ['database', 'db', 'قاعدة البيانات', 'القاعدة'])) {
            return $this->createDatabaseBackup();
        }

        if ($this->containsAny($normalized, ['optimize', 'optimise', 'repair', 'check', 'إصلاح', 'فحص', 'تحسين']) &&
            $this->containsAny($normalized, ['database', 'db', 'قاعدة البيانات', 'الجداول', 'tables', 'table'])) {
            return $this->optimizeDatabaseTables();
        }

        if ($this->isTopIpsRequest($normalized)) {
            return $this->listTopSuspiciousIps();
        }

        if ($this->hasTopIpIntent($normalized)) {
            return $this->listTopSuspiciousIps();
        }

        $ips = $this->extractIpAddresses($message);
        if (!empty($ips)) {
            if ($this->hasIpUnblockIntent($normalized)) {
                return $this->updateIpBlockState($ips, false);
            }

            if ($this->hasIpBlockIntent($normalized)) {
                return $this->updateIpBlockState($ips, true);
            }
            if ($this->containsAny($normalized, ['رفع الحظر', 'فك الحظر', 'الغاء الحظر', 'إلغاء الحظر', 'unblock', 'remove block'])) {
                return $this->updateIpBlockState($ips, false);
            }

            if ($this->containsAny($normalized, ['احظر', 'حظر', 'ban', 'block', 'امنع', 'منع'])) {
                return $this->updateIpBlockState($ips, true);
            }
        }

        $requestedService = $this->detectRequestedService($normalized);
        if ($requestedService !== null) {
            if ($this->containsAny($normalized, ['restart', 'أعد تشغيل', 'اعد تشغيل'])) {
                return $this->restartManagedService($requestedService['service'], $requestedService['label']);
            }

            if ($this->containsAny($normalized, ['شغل', 'فعل', 'ابدأ', 'ابدء', 'start', 'enable'])) {
                return $this->executeServiceToggle($requestedService['service'], true, $requestedService['label']);
            }

            if ($this->containsAny($normalized, ['وقف', 'اوقف', 'عطل', 'stop', 'disable'])) {
                return $this->executeServiceToggle($requestedService['service'], false, $requestedService['label']);
            }
        }

        if (
            $this->containsAny($normalized, ['cache', 'الكاش', 'الذاكرة المؤقتة']) &&
            $this->containsAny($normalized, ['امسح', 'نظف', 'clear', 'delete', 'remove'])
        ) {
            return $this->clearApiCaches();
        }

        return [
            'success' => false,
            'type' => 'unsupported_action',
            'message' => 'هذا النوع من التنفيذ غير متاح مباشرة. أستطيع تنفيذ خدمات محددة، نسخ احتياطي لقاعدة البيانات، تحسين الجداول، تنظيف الكاش، وقراءة اللوغات.',
        ];
    }

    private function executeServiceToggle(string $serviceName, bool $shouldBeActive, ?string $label = null): array
    {
        $protectedServices = ['nginx', 'php8.3-fpm'];
        if (in_array($serviceName, $protectedServices, true)) {
            return [
                'success' => false,
                'type' => 'protected_service',
                'service' => $serviceName,
                'message' => 'للخدمات الأساسية مثل Nginx و PHP-FPM أستخدم إعادة التشغيل الآمنة فقط، وليس الإيقاف أو التشغيل المباشر.',
            ];
        }

        $result = $this->monitor->setServiceState($serviceName, $shouldBeActive);

        return [
            'success' => (bool)($result['success'] ?? false),
            'type' => 'service_toggle',
            'service' => $serviceName,
            'label' => $label ?? $serviceName,
            'target_state' => $shouldBeActive ? 'active' : 'inactive',
            'message' => (string)($result['message'] ?? 'تم تنفيذ الطلب.'),
        ];
    }

    private function listTopSuspiciousIps(): array
    {
        $snapshot = $this->monitor->getSnapshot(60);
        $rows = array_slice((array)($snapshot['security']['top_ips'] ?? []), 0, 10);

        return [
            'success' => true,
            'type' => 'top_ips',
            'window_minutes' => (int)($snapshot['security']['window_minutes'] ?? 60),
            'suspicious_total' => (int)($snapshot['security']['suspicious_total'] ?? 0),
            'blocked_ips_total' => (int)($snapshot['security']['blocked_ips_total'] ?? 0),
            'rows' => array_map(static function (array $row): array {
                return [
                    'ip' => (string)($row['ip'] ?? ''),
                    'requests_total' => (int)($row['requests_total'] ?? 0),
                    'signals' => (int)($row['count'] ?? 0),
                    'rate_limited' => (int)($row['rate_limited'] ?? 0),
                    'probes' => (int)($row['probes'] ?? 0),
                    'blocked_hits' => (int)($row['blocked_hits'] ?? 0),
                    'is_blocked' => !empty($row['is_blocked']),
                    'last_seen' => $row['last_seen'] ?? null,
                    'last_target' => $row['last_target'] ?? null,
                    'country_code' => $row['country_code'] ?? null,
                ];
            }, $rows),
            'message' => empty($rows)
                ? 'Ù„Ø§ ØªÙˆØ¬Ø¯ IPs Ù…Ø´Ø¨ÙˆÙ‡Ø© Ø£Ùˆ ÙƒØ«ÙŠÙØ© Ø­Ø§Ù„ÙŠÙ‹Ø§ Ø¶Ù…Ù† Ù†Ø§ÙØ°Ø© Ø§Ù„Ù…Ø±Ø§Ù‚Ø¨Ø©.'
                : 'ØªÙ… Ø¬Ù„Ø¨ Ø£ÙƒØ«Ø± Ø§Ù„Ù€ IPs ÙƒØ«Ø§ÙØ© Ù…Ù† Ù„ÙˆØ­Ø© Ø§Ù„Ù…Ø±Ø§Ù‚Ø¨Ø©.',
        ];
    }

    private function updateIpBlockState(array $ips, bool $shouldBlock): array
    {
        $result = $this->monitor->updateBlockedIps($ips, $shouldBlock);

        return [
            'success' => (bool)($result['success'] ?? false),
            'type' => $shouldBlock ? 'block_ips' : 'unblock_ips',
            'ips' => array_values(array_unique($ips)),
            'message' => (string)($result['message'] ?? ($shouldBlock ? 'ØªÙ… Ø­Ø¸Ø± Ø§Ù„Ù€ IPs.' : 'ØªÙ… Ø±ÙØ¹ Ø§Ù„Ø­Ø¸Ø± Ø¹Ù† Ø§Ù„Ù€ IPs.')),
        ];
    }

    private function restartManagedService(string $serviceName, string $label): array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return [
                'success' => false,
                'type' => 'restart_service',
                'service' => $serviceName,
                'message' => 'إعادة التشغيل المباشرة غير متاحة في البيئة المحلية الحالية.',
            ];
        }

        $systemctl = $this->findBinary(['/usr/bin/systemctl', '/bin/systemctl', 'systemctl']);
        $command = 'sudo -n ' . escapeshellcmd((string)$systemctl) . ' restart ' . escapeshellarg($serviceName);
        $result = $this->runCommandWithStatus($command);
        $finalStatus = $this->getServiceStatusDirect($serviceName);
        $success = $result['exit_code'] === 0 && $finalStatus === 'active';

        return [
            'success' => $success,
            'type' => 'restart_service',
            'service' => $serviceName,
            'label' => $label,
            'message' => $success
                ? "تمت إعادة تشغيل {$label} بنجاح."
                : ($result['output'] !== '' ? $result['output'] : "تعذر إعادة تشغيل {$label} الآن."),
        ];
    }

    private function clearApiCaches(): array
    {
        $targets = [
            $this->projectRoot . DIRECTORY_SEPARATOR . '.api-response-cache',
            DIRECTORY_SEPARATOR === '\\'
                ? $this->projectRoot . DIRECTORY_SEPARATOR . '.nginx-microcache'
                : '/var/cache/nginx/sport_api_microcache',
        ];

        $deleted = 0;
        $errors = [];

        foreach ($targets as $target) {
            if (!is_dir($target)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                $path = $item->getPathname();
                if ($item->isDir()) {
                    @rmdir($path);
                    continue;
                }

                if (@unlink($path)) {
                    $deleted++;
                } else {
                    $errors[] = $path;
                }
            }
        }

        return [
            'success' => empty($errors),
            'type' => 'clear_cache',
            'deleted_files' => $deleted,
            'message' => empty($errors)
                ? "تم تنظيف {$deleted} ملف كاش بنجاح."
                : 'تم تنظيف جزء من الكاش، لكن بعض الملفات تعذر حذفها.',
        ];
    }

    private function createDatabaseBackup(): array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return [
                'success' => false,
                'type' => 'database_backup',
                'message' => 'إنشاء نسخة احتياطية تلقائية من قاعدة البيانات غير متاح في البيئة المحلية الحالية.',
            ];
        }

        $dumpBinary = $this->findBinary(['/usr/bin/mysqldump', '/usr/bin/mariadb-dump', 'mysqldump', 'mariadb-dump']);
        if ($dumpBinary === null) {
            return [
                'success' => false,
                'type' => 'database_backup',
                'message' => 'لم أجد أداة mysqldump على الخادم حاليًا.',
            ];
        }

        $backupDir = $this->getDatabaseBackupDirectory();
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            return [
                'success' => false,
                'type' => 'database_backup',
                'message' => 'تعذر إنشاء مجلد النسخ الاحتياطية على الخادم.',
            ];
        }

        $filename = DB_NAME . '-' . gmdate('Ymd-His') . '.sql';
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . $filename;
        $defaultsFile = $this->buildMysqlDefaultsTempFile();

        if ($defaultsFile === null) {
            return [
                'success' => false,
                'type' => 'database_backup',
                'message' => 'تعذر تجهيز ملف اتصال آمن لعملية النسخ الاحتياطي.',
            ];
        }

        $command = escapeshellcmd($dumpBinary)
            . ' --defaults-extra-file=' . escapeshellarg($defaultsFile)
            . ' --single-transaction --skip-lock-tables --default-character-set=utf8mb4 '
            . escapeshellarg(DB_NAME)
            . ' > ' . escapeshellarg($backupPath);

        $result = $this->runCommandWithStatus($command);
        @unlink($defaultsFile);

        $success = $result['exit_code'] === 0 && is_file($backupPath) && filesize($backupPath) > 0;
        if (!$success && is_file($backupPath)) {
            @unlink($backupPath);
        }

        return [
            'success' => $success,
            'type' => 'database_backup',
            'path' => $success ? $backupPath : null,
            'message' => $success
                ? 'تم إنشاء نسخة احتياطية جديدة لقاعدة البيانات بنجاح.'
                : ($result['output'] !== '' ? $result['output'] : 'تعذر إنشاء النسخة الاحتياطية الآن.'),
        ];
    }

    private function optimizeDatabaseTables(): array
    {
        try {
            $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (empty($tables)) {
                return [
                    'success' => false,
                    'type' => 'optimize_database',
                    'message' => 'لم أجد جداول لتحسينها داخل قاعدة البيانات.',
                ];
            }

            $results = [];
            foreach ($tables as $table) {
                $safeTable = str_replace('`', '``', (string)$table);
                $stmt = $this->pdo->query("OPTIMIZE TABLE `{$safeTable}`");
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                $results[] = [
                    'table' => $table,
                    'status' => $rows[0]['Msg_text'] ?? 'done',
                ];
            }

            return [
                'success' => true,
                'type' => 'optimize_database',
                'tables_count' => count($tables),
                'results' => $results,
                'message' => 'تم تنفيذ تحسين جداول قاعدة البيانات بنجاح.',
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'type' => 'optimize_database',
                'message' => 'تعذر تحسين الجداول الآن: ' . $e->getMessage(),
            ];
        }
    }

    private function executeAdvancedShellCommand(string $command, string $role): array
    {
        if ($role !== 'admin') {
            return [
                'success' => false,
                'type' => 'permission_denied',
                'message' => 'تنفيذ أوامر Shell متاح للمسؤول Admin فقط.',
            ];
        }

        $validation = $this->validateAdvancedShellCommand($command);
        if (!$validation['success']) {
            return [
                'success' => false,
                'type' => 'shell_blocked',
                'command' => $command,
                'message' => $validation['message'],
            ];
        }

        $normalizedCommand = (string)$validation['command'];
        $result = $this->runCommandWithStatus($normalizedCommand);

        return [
            'success' => $result['exit_code'] === 0,
            'type' => 'shell_command',
            'command' => $normalizedCommand,
            'exit_code' => $result['exit_code'],
            'output' => $this->truncateOutput($result['output'], 2400),
            'message' => $result['exit_code'] === 0
                ? 'تم تنفيذ أمر Shell المسموح بنجاح.'
                : 'تم تنفيذ الأمر لكن رجع بحالة فشل. راجع الناتج المرفق.',
        ];
    }

    private function validateAdvancedShellCommand(string $command): array
    {
        $command = trim($command);
        if ($command === '') {
            return ['success' => false, 'message' => 'أمر Shell فارغ.'];
        }

        if (preg_match('/[;&|><`\r\n]/u', $command) === 1) {
            return ['success' => false, 'message' => 'الأمر يحتوي على رموز غير مسموحة داخل الـ Shell الآمن.'];
        }

        $tokens = preg_split('/\s+/', $command) ?: [];
        if (empty($tokens)) {
            return ['success' => false, 'message' => 'تعذر تحليل الأمر.'];
        }

        $binary = strtolower((string)$tokens[0]);
        return match ($binary) {
            'uptime' => ['success' => true, 'command' => 'uptime'],
            'whoami' => ['success' => true, 'command' => 'whoami'],
            'uname' => $this->validateSimpleArgCommand($command, ['-a', '-r', '-s']),
            'free' => $this->validateSimpleArgCommand($command, ['-m', '-h']),
            'df' => $this->validateSimpleArgCommand($command, ['-h', '/', '-h /']),
            'ps' => $this->validateSimpleArgCommand($command, ['aux', '-ef']),
            'ls' => $this->validatePathCommand($command, ['ls'], [$this->projectRoot, $this->monitoringDir, '/var/log/nginx'], true),
            'du' => $this->validatePathCommand($command, ['du', '-sh'], [$this->projectRoot, $this->monitoringDir, '/var/log/nginx'], true),
            'tail' => $this->validateTailCommand($command),
            'journalctl' => $this->validateJournalctlCommand($command),
            'systemctl' => $this->validateSystemctlCommand($command),
            default => ['success' => false, 'message' => 'هذا الأمر غير موجود ضمن أوامر الـ Shell المسموح بها. استخدم الصيغة cmd: مع أوامر آمنة فقط.'],
        };
    }

    private function validateSimpleArgCommand(string $command, array $allowedVariants): array
    {
        $baseCommand = trim((string)strtok($command, ' '));
        if ($command === $baseCommand) {
            return ['success' => true, 'command' => $command];
        }

        foreach ($allowedVariants as $variant) {
            if ($command === $baseCommand . ' ' . trim($variant)) {
                return ['success' => true, 'command' => $command];
            }
        }

        return ['success' => false, 'message' => 'صيغة الأمر غير مسموحة داخل الـ Shell الآمن.'];
    }

    private function validatePathCommand(string $command, array $prefixParts, array $allowedBases, bool $allowNoPath = false): array
    {
        $pattern = '/^' . preg_quote(implode(' ', $prefixParts), '/') . '(?:\s+(.+))?$/u';
        if (preg_match($pattern, $command, $matches) !== 1) {
            return ['success' => false, 'message' => 'صيغة أمر المسار غير صحيحة.'];
        }

        $path = trim((string)($matches[1] ?? ''));
        if ($path === '') {
            return $allowNoPath ? ['success' => true, 'command' => implode(' ', $prefixParts)] : ['success' => false, 'message' => 'المسار مطلوب لهذا الأمر.'];
        }

        $resolved = $this->normalizeAllowedPath($path);
        if ($resolved === null || !$this->isPathWithinAllowedBases($resolved, $allowedBases)) {
            return ['success' => false, 'message' => 'المسار المطلوب خارج النطاق المسموح به.'];
        }

        return ['success' => true, 'command' => implode(' ', $prefixParts) . ' ' . escapeshellarg($resolved)];
    }

    private function validateTailCommand(string $command): array
    {
        if (preg_match('/^tail\s+-n\s+(\d{1,3})\s+(.+)$/u', $command, $matches) !== 1) {
            return ['success' => false, 'message' => 'استخدم tail بهذه الصيغة فقط: tail -n 50 /path/to/file.log'];
        }

        $lines = max(1, min(100, (int)$matches[1]));
        $path = $this->normalizeAllowedPath(trim($matches[2]));
        $allowedBases = [$this->monitoringDir, '/var/log/nginx', '/var/www/html/.monitoring'];
        if ($path === null || !$this->isPathWithinAllowedBases($path, $allowedBases)) {
            return ['success' => false, 'message' => 'هذا الملف خارج النطاق المسموح لقراءة logs.'];
        }

        return ['success' => true, 'command' => 'tail -n ' . $lines . ' ' . escapeshellarg($path)];
    }

    private function validateJournalctlCommand(string $command): array
    {
        $allowedServices = [
            'sport-smart-runner.service',
            'sport-auto-scraper.service',
            'nginx',
            'php8.3-fpm',
        ];

        if (preg_match('/^journalctl\s+-u\s+([a-zA-Z0-9._-]+)(?:\s+-n\s+(\d{1,3}))?\s+--no-pager$/u', $command, $matches) !== 1) {
            return ['success' => false, 'message' => 'استخدم journalctl بهذه الصيغة فقط: journalctl -u service -n 40 --no-pager'];
        }

        $service = $matches[1];
        if (!in_array($service, $allowedServices, true)) {
            return ['success' => false, 'message' => 'هذه الخدمة غير مسموح بقراءة لوجاتها من خلال الـ Shell الآمن.'];
        }

        $lines = isset($matches[2]) ? max(1, min(100, (int)$matches[2])) : 40;
        $journalctl = $this->findBinary(['/usr/bin/journalctl', '/bin/journalctl', 'journalctl']);
        if ($journalctl === null) {
            return ['success' => false, 'message' => 'أداة journalctl غير متاحة على الخادم.'];
        }

        return [
            'success' => true,
            'command' => 'sudo -n ' . escapeshellcmd($journalctl) . ' -u ' . escapeshellarg($service) . ' -n ' . $lines . ' --no-pager',
        ];
    }

    private function validateSystemctlCommand(string $command): array
    {
        $allowed = [
            'sport-smart-runner.service' => ['status', 'start', 'stop', 'restart'],
            'sport-auto-scraper.service' => ['status', 'start', 'stop', 'restart'],
            'nginx' => ['status', 'restart'],
            'php8.3-fpm' => ['status', 'restart'],
        ];

        if (preg_match('/^systemctl\s+(status|start|stop|restart)\s+([a-zA-Z0-9._-]+)$/u', $command, $matches) !== 1) {
            return ['success' => false, 'message' => 'استخدم systemctl بهذه الصيغة فقط: systemctl status service'];
        }

        $action = $matches[1];
        $service = $matches[2];
        if (!isset($allowed[$service]) || !in_array($action, $allowed[$service], true)) {
            return ['success' => false, 'message' => 'هذا الأمر أو هذه الخدمة غير مسموح بها داخل الـ Shell الآمن.'];
        }

        $systemctl = $this->findBinary(['/usr/bin/systemctl', '/bin/systemctl', 'systemctl']);
        if ($systemctl === null) {
            return ['success' => false, 'message' => 'أداة systemctl غير متاحة على الخادم.'];
        }

        $prefix = $action === 'status' ? '' : 'sudo -n ';
        return [
            'success' => true,
            'command' => $prefix . escapeshellcmd($systemctl) . ' ' . $action . ' ' . escapeshellarg($service),
        ];
    }

    private function normalizeAllowedPath(string $path): ?string
    {
        $path = trim($path, " \t\n\r\0\x0B'\"");
        if ($path === '') {
            return null;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $resolved = realpath($path);
            return $resolved !== false ? $resolved : null;
        }

        if ($path[0] !== '/') {
            $path = $this->projectRoot . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }

        $resolved = realpath($path);
        return $resolved !== false ? $resolved : null;
    }

    private function isPathWithinAllowedBases(string $path, array $allowedBases): bool
    {
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');

        foreach ($allowedBases as $base) {
            $resolvedBase = realpath($base);
            if ($resolvedBase === false) {
                continue;
            }

            $normalizedBase = rtrim(str_replace('\\', '/', $resolvedBase), '/');
            if ($normalizedPath === $normalizedBase || str_starts_with($normalizedPath . '/', $normalizedBase . '/')) {
                return true;
            }
        }

        return false;
    }

    private function truncateOutput(string $output, int $maxLength): string
    {
        $output = trim($output);
        if (mb_strlen($output, 'UTF-8') <= $maxLength) {
            return $output;
        }

        return mb_substr($output, 0, $maxLength, 'UTF-8') . "\n...\n[تم اقتطاع بقية الناتج]";
    }

    private function collectRequestedLogs(string $message): array
    {
        $normalized = $this->normalizeText($message);
        $logs = [];
        $services = $this->getActionableServices();

        foreach ($services as $service) {
            if ($this->containsAny($normalized, $service['aliases'])) {
                $lines = $this->readServiceLogs($service['service']);
                if (!empty($lines)) {
                    $logs[] = [
                        'service' => $service['label'],
                        'lines' => $lines,
                    ];
                }
            }
        }

        if (empty($logs) && $this->containsAny($normalized, ['log', 'logs', 'سجل', 'سجلات', 'لوج'])) {
            foreach (['sport-smart-runner.service', 'sport-auto-scraper.service'] as $serviceName) {
                $service = $this->findServiceByName($serviceName);
                if ($service === null) {
                    continue;
                }

                $lines = $this->readServiceLogs($service['service']);
                if (!empty($lines)) {
                    $logs[] = [
                        'service' => $service['label'],
                        'lines' => $lines,
                    ];
                }
            }
        }

        return $logs;
    }

    private function readServiceLogs(string $serviceName): array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return [];
        }

        $journalctl = $this->findBinary(['/usr/bin/journalctl', '/bin/journalctl', 'journalctl']);
        if ($journalctl === null) {
            return [];
        }

        $command = 'sudo -n ' . escapeshellcmd($journalctl) . ' -u ' . escapeshellarg($serviceName) . ' -n 40 --no-pager';
        $result = $this->runCommandWithStatus($command);
        if ($result['output'] === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($result['output']));
        $lines = array_values(array_filter(array_map('trim', $lines), static fn($line): bool => $line !== ''));
        if (count($lines) > 12) {
            $lines = array_slice($lines, -12);
        }

        return $lines;
    }

    private function getDatabaseSummary(): array
    {
        try {
            $summaryStmt = $this->pdo->query("
                SELECT
                    COUNT(*) AS tables_count,
                    COALESCE(SUM(table_rows), 0) AS approx_rows,
                    COALESCE(SUM(data_length + index_length), 0) AS total_bytes
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
            ");
            $summary = $summaryStmt ? $summaryStmt->fetch(PDO::FETCH_ASSOC) : [];

            $topTablesStmt = $this->pdo->query("
                SELECT
                    table_name,
                    engine,
                    COALESCE(table_rows, 0) AS table_rows,
                    COALESCE(data_length + index_length, 0) AS total_bytes,
                    update_time
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                ORDER BY total_bytes DESC, table_name ASC
                LIMIT 6
            ");
            $topTables = $topTablesStmt ? $topTablesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

            return [
                'database' => DB_NAME,
                'tables_count' => (int)($summary['tables_count'] ?? 0),
                'approx_rows' => (int)($summary['approx_rows'] ?? 0),
                'total_bytes' => (int)($summary['total_bytes'] ?? 0),
                'recent_backups' => $this->getRecentBackupFiles(),
                'top_tables' => array_map(static function (array $row): array {
                    return [
                        'table_name' => $row['table_name'] ?? ($row['TABLE_NAME'] ?? null),
                        'engine' => $row['engine'] ?? ($row['ENGINE'] ?? null),
                        'table_rows' => (int)($row['table_rows'] ?? ($row['TABLE_ROWS'] ?? 0)),
                        'total_bytes' => (int)($row['total_bytes'] ?? ($row['TOTAL_BYTES'] ?? 0)),
                        'update_time' => $row['update_time'] ?? ($row['UPDATE_TIME'] ?? null),
                    ];
                }, $topTables),
            ];
        } catch (Throwable $e) {
            return [
                'database' => DB_NAME,
                'error' => $e->getMessage(),
                'recent_backups' => $this->getRecentBackupFiles(),
            ];
        }
    }

    private function getRecentBackupFiles(): array
    {
        $backupDir = $this->getDatabaseBackupDirectory();
        if (!is_dir($backupDir)) {
            return [];
        }

        $files = glob($backupDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $files = array_slice($files, 0, 5);

        return array_map(static function (string $path): array {
            return [
                'name' => basename($path),
                'size_bytes' => is_file($path) ? (int)filesize($path) : 0,
                'modified_at' => is_file($path) ? gmdate('Y-m-d H:i:s', (int)filemtime($path)) : null,
            ];
        }, $files);
    }

    private function getDatabaseBackupDirectory(): string
    {
        return $this->monitoringDir . DIRECTORY_SEPARATOR . 'db-backups';
    }

    private function detectRequestedService(string $normalized): ?array
    {
        foreach ($this->getActionableServices() as $service) {
            if ($this->containsAny($normalized, $service['aliases'])) {
                return $service;
            }
        }

        return null;
    }

    private function getActionableServices(): array
    {
        return [
            [
                'label' => 'Smart Runner',
                'service' => 'sport-smart-runner.service',
                'aliases' => ['smart runner', 'سمارت رانر', 'المشغل الذكي'],
            ],
            [
                'label' => 'Auto Scraper',
                'service' => 'sport-auto-scraper.service',
                'aliases' => ['auto scraper', 'اوتو سكرابر', 'الجدولة التلقائية', 'auto-scraper'],
            ],
            [
                'label' => 'Nginx',
                'service' => 'nginx',
                'aliases' => ['nginx', 'انجنكس'],
            ],
            [
                'label' => 'PHP-FPM',
                'service' => 'php8.3-fpm',
                'aliases' => ['php-fpm', 'php fpm', 'php8.3-fpm', 'بي اتش بي'],
            ],
        ];
    }

    private function findServiceByName(string $serviceName): ?array
    {
        foreach ($this->getActionableServices() as $service) {
            if ($service['service'] === $serviceName) {
                return $service;
            }
        }

        return null;
    }

    private function searchProject(string $message, string $page = ''): array
    {
        $tokens = $this->extractSearchTokens($message);
        $matches = [];

        $candidateFiles = [
            $this->projectRoot . DIRECTORY_SEPARATOR . 'config.php',
            $this->projectRoot . DIRECTORY_SEPARATOR . 'admin',
            $this->projectRoot . DIRECTORY_SEPARATOR . 'includes',
            $this->projectRoot . DIRECTORY_SEPARATOR . 'api',
            $this->projectRoot . DIRECTORY_SEPARATOR . 'database',
        ];

        if ($page !== '') {
            $pageBase = basename($page);
            if ($pageBase !== '') {
                $tokens[] = $pageBase;
            }
        }

        $tokens = array_values(array_unique(array_filter($tokens)));
        if (empty($tokens)) {
            return [];
        }

        foreach ($candidateFiles as $candidate) {
            if (!file_exists($candidate)) {
                continue;
            }

            if (is_file($candidate)) {
                $found = $this->scanFileForTokens($candidate, $tokens);
                if ($found !== null) {
                    $matches[] = $found;
                }
            } else {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($candidate, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo->isFile()) {
                        continue;
                    }

                    $ext = strtolower((string)$fileInfo->getExtension());
                    if (!in_array($ext, ['php', 'js', 'css', 'sql', 'html', 'md', 'txt'], true)) {
                        continue;
                    }

                    if ($fileInfo->getSize() > 400000) {
                        continue;
                    }

                    $found = $this->scanFileForTokens($fileInfo->getPathname(), $tokens);
                    if ($found !== null) {
                        $matches[] = $found;
                    }

                    if (count($matches) >= 6) {
                        break 2;
                    }
                }
            }

            if (count($matches) >= 6) {
                break;
            }
        }

        return $matches;
    }

    private function scanFileForTokens(string $path, array $tokens): ?array
    {
        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return null;
        }

        $haystack = mb_strtolower($content, 'UTF-8');
        $filename = mb_strtolower(basename($path), 'UTF-8');

        foreach ($tokens as $token) {
            $needle = mb_strtolower($token, 'UTF-8');
            $position = mb_stripos($filename, $needle, 0, 'UTF-8');
            $reason = 'اسم الملف';

            if ($position === false) {
                $position = mb_stripos($haystack, $needle, 0, 'UTF-8');
                $reason = 'محتوى الملف';
            }

            if ($position === false) {
                continue;
            }

            $start = max(0, $position - 120);
            $snippet = mb_substr($content, $start, 320, 'UTF-8');
            $snippet = preg_replace('/\s+/u', ' ', trim((string)$snippet));

            return [
                'file' => $path,
                'reason' => $reason . ' يطابق: ' . $token,
                'snippet' => $snippet,
            ];
        }

        return null;
    }

    private function extractSearchTokens(string $message): array
    {
        preg_match_all('/[\p{Arabic}\p{L}\p{N}_\.-]{3,}/u', $message, $matches);
        $tokens = array_map(
            static fn(string $token): string => trim($token),
            $matches[0] ?? []
        );

        $stopWords = [
            'على', 'من', 'في', 'هذا', 'هذه', 'ذلك', 'التي', 'الذي', 'كيف', 'اريد', 'أريد', 'سوي', 'سرفير', 'نظام', 'صفحة', 'شيء',
            'search', 'find', 'lookup', 'about', 'please', 'file', 'files', 'for', 'the'
        ];

        return array_values(array_filter($tokens, static function (string $token) use ($stopWords): bool {
            return !in_array($token, $stopWords, true);
        }));
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (mb_stripos($haystack, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return (string)$text;
    }

    private function isTopIpsRequest(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'Ø£ÙƒØ«Ø± ips', 'Ø§ÙƒØ«Ø± ips', 'top ips', 'top ip', 'ips ÙƒØ«Ø§ÙØ©', 'ip ÙƒØ«Ø§ÙØ©',
            'Ø¹Ù†Ø§ÙˆÙŠÙ† ip Ø§Ù„Ù…Ø´Ø¨ÙˆÙ‡Ø©', 'Ø§Ø¹Ø±Ø¶ ips', 'Ø§Ø¸Ù‡Ø± ips', 'Ø£Ø¸Ù‡Ø± ips', 'Ø§Ù„Ù…Ø´Ø¨ÙˆÙ‡ÙŠÙ†', 'Ø§Ù„Ù…Ø­Ø¸ÙˆØ±ÙŠÙ†'
        ]);
    }

    private function hasTopIpIntent(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'top ips',
            'top ip',
            'show top ip',
            'show top ips',
            'display top ip',
            'display top ips',
        ]) || (
            $this->containsAny($normalized, $this->getSafeIpTopicKeywords()) &&
            $this->containsAny($normalized, $this->getSafeIpListKeywords())
        );
    }

    private function hasIpBlockIntent(string $normalized): bool
    {
        if ($this->hasIpUnblockIntent($normalized)) {
            return false;
        }

        return $this->containsAny($normalized, [
            'block',
            'ban',
            'block ip',
            'ban ip',
            "\u{0627}\u{062D}\u{0638}\u{0631}",
            "\u{062D}\u{0638}\u{0631}",
            "\u{0627}\u{0645}\u{0646}\u{0639}",
            "\u{0645}\u{0646}\u{0639}",
        ]);
    }

    private function hasIpUnblockIntent(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'unblock',
            'unban',
            'remove block',
            'unlock ip',
            "\u{0631}\u{0641}\u{0639} \u{0627}\u{0644}\u{062D}\u{0638}\u{0631}",
            "\u{0641}\u{0643} \u{0627}\u{0644}\u{062D}\u{0638}\u{0631}",
            "\u{0627}\u{0644}\u{063A}\u{0627}\u{0621} \u{0627}\u{0644}\u{062D}\u{0638}\u{0631}",
            "\u{0625}\u{0644}\u{063A}\u{0627}\u{0621} \u{0627}\u{0644}\u{062D}\u{0638}\u{0631}",
        ]);
    }

    private function getSafeIpTopicKeywords(): array
    {
        return [
            'ip',
            'ips',
            'ipv4',
            'ipv6',
            "\u{0622}\u{064A}\u{0628}\u{064A}",
            "\u{0627}\u{064A}\u{0628}\u{064A}",
            "\u{0639}\u{0646}\u{0648}\u{0627}\u{0646}",
            "\u{0639}\u{0646}\u{0627}\u{0648}\u{064A}\u{0646}",
        ];
    }

    private function getSafeIpListKeywords(): array
    {
        return [
            'top',
            'show',
            'display',
            'list',
            'dense',
            'density',
            'suspicious',
            'blocked',
            "\u{0627}\u{0639}\u{0631}\u{0636}",
            "\u{0627}\u{0638}\u{0647}\u{0631}",
            "\u{0623}\u{0638}\u{0647}\u{0631}",
            "\u{0623}\u{0643}\u{062B}\u{0631}",
            "\u{0643}\u{062B}\u{0627}\u{0641}\u{0629}",
            "\u{0627}\u{0644}\u{0645}\u{0634}\u{0628}\u{0648}\u{0647}\u{064A}\u{0646}",
            "\u{0627}\u{0644}\u{0645}\u{062D}\u{0638}\u{0648}\u{0631}\u{064A}\u{0646}",
        ];
    }

    private function extractIpAddresses(string $message): array
    {
        preg_match_all('/(?:(?:\d{1,3}\.){3}\d{1,3})|(?:[a-f0-9:]{2,})/iu', $message, $matches);
        $ips = [];

        foreach (($matches[0] ?? []) as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $ips[$candidate] = $candidate;
        }

        return array_values($ips);
    }

    private function formatGeminiErrorMessage(int $httpCode, string $errorMessage): string
    {
        $normalized = mb_strtolower($errorMessage, 'UTF-8');

        if (str_contains($normalized, 'high demand') || str_contains($normalized, 'temporarily unavailable') || str_contains($normalized, 'overloaded')) {
            return 'خدمة Gemini عليها ضغط مرتفع الآن، لذلك تعذر إكمال الطلب مؤقتًا. حاول مرة أخرى بعد قليل.';
        }

        if (str_contains($normalized, 'quota') || str_contains($normalized, 'rate limit') || str_contains($normalized, 'resource exhausted')) {
            return 'تم الوصول إلى حد استخدام Gemini مؤقتًا. انتظر قليلًا ثم أعد المحاولة.';
        }

        if (str_contains($normalized, 'api key') || str_contains($normalized, 'permission') || str_contains($normalized, 'unauthorized')) {
            return 'مفتاح Gemini الحالي غير صالح أو لا يملك الصلاحية الكافية. راجع إعدادات المفاتيح أولًا.';
        }

        if ($httpCode >= 500) {
            return 'حدث عطل مؤقت داخل خدمة Gemini. حاول مجددًا بعد قليل.';
        }

        return 'تعذر على Gemini معالجة الطلب في الوقت الحالي. حاول بصياغة مختلفة أو أعد المحاولة بعد قليل.';
    }

    private function detectRequestMode(string $message): string
    {
        $normalized = $this->normalizeText($message);

        if ($this->extractShellCommand($message) !== null) {
            return 'action';
        }

        if ($this->hasTopIpIntent($normalized)) {
            return 'action';
        }

        if (!empty($this->extractIpAddresses($message)) &&
            ($this->hasIpBlockIntent($normalized) || $this->hasIpUnblockIntent($normalized))) {
            return 'action';
        }

        if ($this->containsAny($normalized, ['ip', 'ips', 'Ø¹Ù†Ø§ÙˆÙŠÙ† ip', 'Ø§Ù„Ù…Ø´Ø¨ÙˆÙ‡ÙŠÙ†', 'Ø§Ù„Ù…Ø­Ø¸ÙˆØ±ÙŠÙ†', 'Ø§Ù„Ù‡Ø¬Ù…Ø§Øª']) &&
            $this->containsAny($normalized, ['Ø§Ø­Ø¸Ø±', 'Ø­Ø¸Ø±', 'Ø±ÙØ¹ Ø§Ù„Ø­Ø¸Ø±', 'ÙÙƒ Ø§Ù„Ø­Ø¸Ø±', 'block', 'unblock', 'ban', 'Ø£ÙƒØ«Ø±', 'top', 'ÙƒØ«Ø§ÙØ©', 'Ø§Ø¹Ø±Ø¶', 'Ø§Ø¸Ù‡Ø±'])) {
            return 'action';
        }

        $actionKeywords = [
            'شغل', 'فعل', 'ابدأ', 'ابدء', 'وقف', 'اوقف', 'عطل', 'أعد تشغيل', 'اعد تشغيل', 'restart', 'start', 'stop',
            'enable', 'disable', 'clear', 'امسح', 'نظف', 'backup', 'نسخة احتياطية', 'dump',
            'optimize', 'optimise', 'repair', 'check', 'تحسين', 'إصلاح', 'فحص'
        ];
        $actionTargets = [
            'smart runner', 'المشغل الذكي', 'auto scraper', 'الجدولة', 'cache', 'الكاش', 'خدمة', 'service', 'nginx', 'php-fpm',
            'database', 'db', 'قاعدة البيانات', 'الجداول', 'tables'
        ];
        if ($this->containsAny($normalized, $actionKeywords) && $this->containsAny($normalized, $actionTargets)) {
            return 'action';
        }

        $searchKeywords = ['ابحث', 'بحث', 'فتش', 'دور', 'اعثر', 'search', 'find', 'lookup', 'افتح الملف', 'افتح لي', 'راجع الكود', 'حلل الكود'];
        $searchTargets = ['ملف', 'ملفات', 'صفحة', 'صفحات', 'كود', 'css', 'js', 'php', 'sql', 'config', 'endpoint', 'api', 'class', 'function', 'header', 'footer', 'table', 'جدول'];
        $looksLikeFileReference = preg_match('/\.(php|js|css|sql|html|json|txt|md)\b/u', $normalized) === 1
            || preg_match('#(?:^|[\s/])(admin|api|includes|database)/#u', $normalized) === 1;
        if ($this->containsAny($normalized, $searchKeywords) || $looksLikeFileReference || ($this->containsAny($normalized, $searchTargets) && $this->containsAny($normalized, ['أين', 'فين', 'where', 'كيف', 'لماذا']))) {
            return 'search';
        }

        $statusKeywords = [
            'حالة', 'status', 'ضغط', 'load', 'رام', 'ram', 'memory', 'cpu', 'cache', '429', 'سيرفر', 'server',
            'خدمات', 'مراقبة', 'monitor', 'uptime', 'إحصائيات', 'requests', 'database', 'db', 'قاعدة البيانات', 'لوج', 'logs', 'سجل'
        ];
        if ($this->containsAny($normalized, $statusKeywords)) {
            return 'status';
        }

        return 'chat';
    }

    private function getServiceStatusDirect(string $serviceName): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return 'unknown';
        }

        $systemctl = $this->findBinary(['/usr/bin/systemctl', '/bin/systemctl', 'systemctl']);
        if ($systemctl === null) {
            return 'unknown';
        }

        $command = escapeshellcmd($systemctl) . ' is-active ' . escapeshellarg($serviceName);
        $result = $this->runCommandWithStatus($command);
        $status = trim($result['output']);
        return $status !== '' ? $status : 'unknown';
    }

    private function buildMysqlDefaultsTempFile(): ?string
    {
        $directory = $this->monitoringDir . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        $path = tempnam($directory, 'mysql-');
        if ($path === false) {
            return null;
        }

        $content = "[client]\n"
            . 'host=' . DB_HOST . "\n"
            . 'user=' . DB_USER . "\n"
            . 'password=' . DB_PASS . "\n"
            . "default-character-set=utf8mb4\n";

        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            @unlink($path);
            return null;
        }

        @chmod($path, 0600);
        return $path;
    }

    private function findBinary(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (str_contains($candidate, DIRECTORY_SEPARATOR)) {
                if (is_executable($candidate)) {
                    return $candidate;
                }
                continue;
            }

            $result = trim((string)$this->runCommandWithStatus('command -v ' . escapeshellarg($candidate))['output']);
            if ($result !== '') {
                return $result;
            }
        }

        return null;
    }

    private function runCommandWithStatus(string $command): array
    {
        $disabled = ',' . str_replace(' ', '', (string)ini_get('disable_functions')) . ',';
        $canUseExec = function_exists('exec') && !str_contains($disabled, ',exec,');

        if ($canUseExec) {
            $output = [];
            $exitCode = 1;
            @exec($command . ' 2>&1', $output, $exitCode);

            return [
                'output' => trim(implode("\n", $output)),
                'exit_code' => (int)$exitCode,
            ];
        }

        $canUseShellExec = function_exists('shell_exec') && !str_contains($disabled, ',shell_exec,');
        if ($canUseShellExec) {
            $output = @shell_exec($command . ' 2>&1');
            return [
                'output' => trim((string)$output),
                'exit_code' => $output !== null ? 0 : 1,
            ];
        }

        return [
            'output' => '',
            'exit_code' => 1,
        ];
    }

    private function appendAssistantActionLog(string $message, string $role, array $actionResult): void
    {
        if (!is_dir($this->monitoringDir)) {
            @mkdir($this->monitoringDir, 0775, true);
        }

        $entry = [
            'time' => gmdate('c'),
            'role' => $role,
            'message' => $message,
            'action' => $actionResult,
        ];

        @file_put_contents(
            $this->assistantLogPath,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function getActiveGeminiKey(): ?string
    {
        $this->ensureGeminiKeysTable();
        $stmt = $this->pdo->query("SELECT api_key FROM translation_api_keys WHERE is_active = 1 LIMIT 1");
        $key = $stmt ? trim((string)$stmt->fetchColumn()) : '';
        return $key !== '' ? $key : null;
    }

    private function getActiveGeminiKeyName(): ?string
    {
        $this->ensureGeminiKeysTable();
        $stmt = $this->pdo->query("SELECT provider FROM translation_api_keys WHERE is_active = 1 LIMIT 1");
        $name = $stmt ? trim((string)$stmt->fetchColumn()) : '';
        return $name !== '' ? $name : null;
    }

    private function incrementGeminiUsage(): void
    {
        $this->ensureGeminiKeysTable();
        $this->pdo->exec("UPDATE translation_api_keys SET request_count = request_count + 1, last_used_at = NOW() WHERE is_active = 1");
    }

    private function getActiveScraperSource(): ?string
    {
        try {
            $stmt = $this->pdo->query("SELECT source_key FROM scraper_source_settings WHERE is_active = 1 LIMIT 1");
            $value = $stmt ? trim((string)$stmt->fetchColumn()) : '';
            return $value !== '' ? $value : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getEnabledTasksSummary(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT task_key, task_name, interval_seconds, start_time
                FROM scraper_settings
                WHERE is_active = 1
                ORDER BY id ASC
                LIMIT 12
            ");

            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function ensureGeminiKeysTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS translation_api_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(50) NOT NULL,
            api_key TEXT NOT NULL,
            is_active TINYINT(1) DEFAULT 0,
            request_count INT DEFAULT 0,
            last_used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
