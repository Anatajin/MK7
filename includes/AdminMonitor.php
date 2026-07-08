<?php

class AdminMonitor
{
    private $pdo;
    private $projectRoot;
    private $monitoringDir;
    private $metricsLogPath;
    private $snapshotPath;
    private $phpResponseCacheDir;
    private $nginxMicrocacheDir;
    private $accessLogPath;
    private $blockedIpsPath;
    private $blockedIpsIncludePath;
    private $ipGeoCachePath;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->projectRoot = dirname(__DIR__);
        $this->monitoringDir = $this->projectRoot . DIRECTORY_SEPARATOR . '.monitoring';
        $this->metricsLogPath = $this->monitoringDir . DIRECTORY_SEPARATOR . 'api_metrics.log';
        $this->snapshotPath = $this->monitoringDir . DIRECTORY_SEPARATOR . 'monitor_snapshot.json';
        $this->phpResponseCacheDir = $this->projectRoot . DIRECTORY_SEPARATOR . '.api-response-cache';
        $this->nginxMicrocacheDir = DIRECTORY_SEPARATOR === '\\'
            ? $this->projectRoot . DIRECTORY_SEPARATOR . '.nginx-microcache'
            : '/var/cache/nginx/sport_api_microcache';
        $this->accessLogPath = DIRECTORY_SEPARATOR === '\\'
            ? $this->monitoringDir . DIRECTORY_SEPARATOR . 'mock_access.log'
            : '/var/log/nginx/access.log';
        $this->blockedIpsPath = $this->monitoringDir . DIRECTORY_SEPARATOR . 'blocked_ips.json';
        $this->blockedIpsIncludePath = $this->monitoringDir . DIRECTORY_SEPARATOR . 'blocked_ips.conf';
        $this->ipGeoCachePath = $this->monitoringDir . DIRECTORY_SEPARATOR . 'ip_geo_cache.json';
    }

    public function getSnapshot($windowMinutes = 60)
    {
        $windowMinutes = max(5, min(1440, (int)$windowMinutes));

        $cached = $this->readSnapshotCache($windowMinutes);
        if ($cached !== null) {
            return $cached;
        }

        $this->ensureMonitoringDirectory();

        $snapshot = [
            'generated_at' => gmdate('c'),
            'window_minutes' => $windowMinutes,
            'system' => $this->collectSystemMetrics(),
            'services' => $this->collectServiceStates(),
            'api' => $this->collectApiTrafficMetrics($windowMinutes),
            'cache' => $this->collectCacheMetrics($windowMinutes),
            'security' => $this->collectSecurityMetrics($windowMinutes),
            'top_keys' => $this->collectTopKeys(),
        ];

        @file_put_contents(
            $this->snapshotPath,
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );

        return $snapshot;
    }

    public function setServiceState($serviceName, $shouldBeActive)
    {
        $definition = $this->findServiceDefinition($serviceName);
        if ($definition === null) {
            return [
                'success' => false,
                'message' => 'الخدمة المطلوبة غير مدعومة داخل لوحة المراقبة.',
            ];
        }

        if (empty($definition['toggleable'])) {
            return [
                'success' => false,
                'message' => 'هذه الخدمة محمية داخل اللوحة حتى لا يتوقف الموقع عن العمل.',
                'service' => $this->formatServiceState($definition, $this->getMonitorServiceStatus($definition)),
            ];
        }

        $targetActive = (bool)$shouldBeActive;
        $currentStatus = $this->getMonitorServiceStatus($definition);

        if (($definition['type'] ?? 'systemd') === 'setting') {
            if (($targetActive && $currentStatus === 'active') || (!$targetActive && $currentStatus !== 'active')) {
                return [
                    'success' => true,
                    'message' => $targetActive ? 'الخيار مفعل بالفعل.' : 'الخيار متوقف بالفعل.',
                    'service' => $this->formatServiceState($definition, $currentStatus),
                ];
            }

            $stored = $this->setApiSettingValue(
                (string)($definition['setting_key'] ?? ''),
                $targetActive ? (string)($definition['active_value'] ?? '1') : (string)($definition['inactive_value'] ?? '0')
            );

            $cleanupResult = null;
            if (
                $stored &&
                !$targetActive &&
                (string)($definition['setting_key'] ?? '') === 'enable_365scores_fallback'
            ) {
                $cleanupResult = $this->purge365ScoresFallbackArtifacts();
            }

            $finalStatus = $this->getMonitorServiceStatus($definition);

            if ($stored) {
                $this->clearSnapshotCache();
            }

            return [
                'success' => $stored,
                'message' => $stored
                    ? (
                        $targetActive
                            ? 'تم تفعيل الخيار بنجاح.'
                            : (
                                is_array($cleanupResult)
                                    ? sprintf(
                                        'تم إيقاف الخيار بنجاح وتنظيف %d مباراة و%d قناة من روابط 365Scores.',
                                        (int)($cleanupResult['matches'] ?? 0),
                                        (int)($cleanupResult['channels'] ?? 0)
                                    )
                                    : 'تم إيقاف الخيار بنجاح.'
                            )
                      )
                    : 'تعذر تحديث حالة الخيار داخل الإعدادات.',
                'service' => $this->formatServiceState($definition, $finalStatus),
            ];
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return [
                'success' => false,
                'message' => 'التحكم المباشر بالخدمات غير متاح على البيئة المحلية الحالية.',
                'service' => $this->formatServiceState($definition, $currentStatus),
            ];
        }

        $systemctl = $this->getSystemctlBinary();

        if (($targetActive && $currentStatus === 'active') || (!$targetActive && $currentStatus !== 'active')) {
            return [
                'success' => true,
                'message' => $targetActive ? 'الخدمة تعمل بالفعل.' : 'الخدمة متوقفة بالفعل.',
                'service' => $this->formatServiceState($definition, $currentStatus),
            ];
        }

        $action = $targetActive ? 'start' : 'stop';
        $command = 'sudo -n ' . escapeshellcmd($systemctl) . ' ' . $action . ' ' . escapeshellarg($serviceName) . ' 2>&1';
        $output = trim((string)$this->runCommand($command));

        $finalStatus = $currentStatus;
        $attempts = 0;
        while ($attempts < 8) {
            usleep(250000);
            $finalStatus = $this->getServiceStatus($serviceName);

            if ($targetActive && $finalStatus === 'active') {
                break;
            }

            if (!$targetActive && $finalStatus !== 'active') {
                break;
            }

            $attempts++;
        }

        $success = $targetActive ? ($finalStatus === 'active') : ($finalStatus !== 'active');
        if ($success) {
            $this->clearSnapshotCache();
        }

        return [
            'success' => $success,
            'message' => $success
                ? ($targetActive ? 'تم تشغيل الخدمة بنجاح.' : 'تم إيقاف الخدمة بنجاح.')
                : ($output !== '' ? $output : 'تعذر تغيير حالة الخدمة من الخادم.'),
            'service' => $this->formatServiceState($definition, $finalStatus),
        ];
    }

    public function lookupIpLocation($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [
                'success' => false,
                'message' => 'عنوان IP غير صالح.',
            ];
        }

        $this->ensureMonitoringDirectory();

        $cache = $this->readIpGeoCache();
        $cached = $cache[$ip] ?? null;
        $cacheTtl = 7 * 24 * 60 * 60;

        if (is_array($cached)) {
            $cachedAt = isset($cached['cached_at']) ? strtotime((string)$cached['cached_at']) : false;
            if ($cachedAt !== false && (time() - $cachedAt) <= $cacheTtl && !empty($cached['map_url'])) {
                return [
                    'success' => true,
                    'data' => $cached,
                ];
            }
        }

        if (!$this->isPublicLookupIp($ip)) {
            return [
                'success' => false,
                'message' => 'هذا العنوان محلي أو خاص، ولا يمكن تحديد موقعه على الخريطة.',
            ];
        }

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 3,
                    'ignore_errors' => true,
                ],
            ]);

            $url = "http://ip-api.com/json/{$ip}?fields=status,message,country,countryCode,regionName,city,lat,lon,timezone,query";
            $response = @file_get_contents($url, false, $context);
            if ($response === false || trim($response) === '') {
                return [
                    'success' => false,
                    'message' => 'تعذر جلب موقع الـ IP حاليًا.',
                ];
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
                return [
                    'success' => false,
                    'message' => trim((string)($decoded['message'] ?? 'تعذر تحديد الموقع لهذا الـ IP.')),
                ];
            }

            $lat = isset($decoded['lat']) ? (float)$decoded['lat'] : null;
            $lon = isset($decoded['lon']) ? (float)$decoded['lon'] : null;

            if ($lat === null || $lon === null) {
                return [
                    'success' => false,
                    'message' => 'لم يتم العثور على إحداثيات كافية لعرض الموقع.',
                ];
            }

            $location = [
                'ip' => $ip,
                'country' => trim((string)($decoded['country'] ?? '')) ?: null,
                'country_code' => trim((string)($decoded['countryCode'] ?? '')) ?: null,
                'region' => trim((string)($decoded['regionName'] ?? '')) ?: null,
                'city' => trim((string)($decoded['city'] ?? '')) ?: null,
                'timezone' => trim((string)($decoded['timezone'] ?? '')) ?: null,
                'lat' => $lat,
                'lon' => $lon,
                'map_url' => sprintf(
                    'https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=9/%s/%s',
                    rawurlencode((string)$lat),
                    rawurlencode((string)$lon),
                    rawurlencode((string)$lat),
                    rawurlencode((string)$lon)
                ),
                'cached_at' => gmdate('c'),
            ];

            $cache[$ip] = $location;
            $this->persistIpGeoCache($cache);

            return [
                'success' => true,
                'data' => $location,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء تحديد موقع الـ IP.',
            ];
        }
    }

    private function readSnapshotCache($windowMinutes)
    {
        if (!is_file($this->snapshotPath)) {
            return null;
        }

        $raw = @file_get_contents($this->snapshotPath);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $generatedAt = isset($decoded['generated_at']) ? strtotime($decoded['generated_at']) : false;
        $cachedWindow = isset($decoded['window_minutes']) ? (int)$decoded['window_minutes'] : null;
        if ($generatedAt === false || $cachedWindow !== $windowMinutes) {
            return null;
        }

        if ((time() - $generatedAt) > 10) {
            return null;
        }

        return $decoded;
    }

    private function ensureMonitoringDirectory()
    {
        if (!is_dir($this->monitoringDir)) {
            @mkdir($this->monitoringDir, 0775, true);
        }

        if (!is_file($this->blockedIpsIncludePath)) {
            @file_put_contents(
                $this->blockedIpsIncludePath,
                "# Managed by SPORT monitor" . PHP_EOL . "# No blocked IPs currently" . PHP_EOL,
                LOCK_EX
            );
        }

        if (!is_file($this->blockedIpsPath)) {
            @file_put_contents($this->blockedIpsPath, "[]", LOCK_EX);
        }

        if (!is_file($this->ipGeoCachePath)) {
            @file_put_contents($this->ipGeoCachePath, "{}", LOCK_EX);
        }
    }

    private function collectSystemMetrics()
    {
        $loadAvg = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
        $memory = $this->getMemoryStats();
        $disk = $this->getDiskStats();

        return [
            'hostname' => gethostname() ?: php_uname('n'),
            'os' => $this->getOperatingSystemName(),
            'php_version' => PHP_VERSION,
            'nginx_version' => $this->getNginxVersion(),
            'database_version' => $this->getDatabaseVersion(),
            'server_time_utc' => gmdate('Y-m-d H:i:s'),
            'uptime_human' => $this->getSystemUptimeHuman(),
            'cpu_cores' => $this->getCpuCoreCount(),
            'load_average' => [
                'one' => isset($loadAvg[0]) ? round((float)$loadAvg[0], 2) : null,
                'five' => isset($loadAvg[1]) ? round((float)$loadAvg[1], 2) : null,
                'fifteen' => isset($loadAvg[2]) ? round((float)$loadAvg[2], 2) : null,
            ],
            'memory' => $memory,
            'disk' => $disk,
        ];
    }

    private function collectServiceStates()
    {
        $results = [];
        foreach ($this->getServiceDefinitions() as $service) {
            $results[] = $this->formatServiceState(
                $service,
                $this->getMonitorServiceStatus($service)
            );
        }

        return $results;
    }

    private function collectApiTrafficMetrics($windowMinutes)
    {
        $entries = $this->readMetricsLogEntries($windowMinutes);
        $bucketSize = $windowMinutes >= 180 ? 15 : ($windowMinutes >= 60 ? 5 : 1);
        $bucketCount = max(1, (int)ceil($windowMinutes / $bucketSize));
        $bucketMap = [];

        for ($i = $bucketCount - 1; $i >= 0; $i--) {
            $timestamp = time() - ($i * $bucketSize * 60);
            $bucketKey = gmdate('Y-m-d H:i', $this->roundDownToBucket($timestamp, $bucketSize));
            $bucketMap[$bucketKey] = [
                'label' => gmdate('H:i', strtotime($bucketKey . ':00')),
                'requests' => 0,
                'rate_limited' => 0,
                'hits' => 0,
                'misses' => 0,
            ];
        }

        $totalRequests = 0;
        $total429 = 0;
        $total5xx = 0;
        $sumRequestTimeMs = 0.0;
        $requestTimeSamples = 0;
        $topActions = [];

        foreach ($entries as $entry) {
            $totalRequests++;

            $status = (int)($entry['status'] ?? 0);
            if ($status === 429) {
                $total429++;
            }
            if ($status >= 500) {
                $total5xx++;
            }

            $requestTime = isset($entry['request_time']) ? (float)$entry['request_time'] : null;
            if ($requestTime !== null) {
                $sumRequestTimeMs += ($requestTime * 1000);
                $requestTimeSamples++;
            }

            $action = trim((string)($entry['action'] ?? ''));
            if ($action === '' || $action === '-' || strtolower($action) === 'unknown') {
                $action = 'matches';
            }
            if (!isset($topActions[$action])) {
                $topActions[$action] = 0;
            }
            $topActions[$action]++;

            $entryTime = isset($entry['time']) ? strtotime($entry['time']) : false;
            if ($entryTime !== false) {
                $bucketKey = gmdate('Y-m-d H:i', $this->roundDownToBucket($entryTime, $bucketSize));
                if (!isset($bucketMap[$bucketKey])) {
                    $bucketMap[$bucketKey] = [
                        'label' => gmdate('H:i', $entryTime),
                        'requests' => 0,
                        'rate_limited' => 0,
                        'hits' => 0,
                        'misses' => 0,
                    ];
                }

                $bucketMap[$bucketKey]['requests']++;
                if ($status === 429) {
                    $bucketMap[$bucketKey]['rate_limited']++;
                }

                $cacheStatus = strtoupper(trim((string)($entry['cache'] ?? '')));
                if ($cacheStatus === 'HIT') {
                    $bucketMap[$bucketKey]['hits']++;
                } elseif ($cacheStatus === 'MISS') {
                    $bucketMap[$bucketKey]['misses']++;
                }
            }
        }

        arsort($topActions);
        $series = array_values($bucketMap);
        $peakRequests = 0;
        foreach ($series as $point) {
            if ($point['requests'] > $peakRequests) {
                $peakRequests = $point['requests'];
            }
        }

        return [
            'log_available' => is_file($this->metricsLogPath),
            'window_minutes' => $windowMinutes,
            'total_requests' => $totalRequests,
            'rate_limited' => $total429,
            'server_errors' => $total5xx,
            'avg_request_time_ms' => $requestTimeSamples > 0 ? round($sumRequestTimeMs / $requestTimeSamples, 2) : 0,
            'requests_per_minute_avg' => $windowMinutes > 0 ? round($totalRequests / $windowMinutes, 2) : 0,
            'requests_per_minute_peak' => $peakRequests,
            'series' => $series,
            'top_actions' => array_slice(
                array_map(function ($action, $count) {
                    return ['action' => $action, 'count' => $count];
                }, array_keys($topActions), array_values($topActions)),
                0,
                6
            ),
        ];
    }

    private function collectCacheMetrics($windowMinutes)
    {
        $entries = $this->readMetricsLogEntries($windowMinutes);
        $statusCounts = [
            'HIT' => 0,
            'MISS' => 0,
            'BYPASS' => 0,
            'EXPIRED' => 0,
            'STALE' => 0,
            'UPDATING' => 0,
            'REVALIDATED' => 0,
            'NONE' => 0,
        ];

        foreach ($entries as $entry) {
            $cacheStatus = strtoupper(trim((string)($entry['cache'] ?? '')));
            if ($cacheStatus === '' || $cacheStatus === '-') {
                $cacheStatus = 'NONE';
            }

            if (!isset($statusCounts[$cacheStatus])) {
                $statusCounts[$cacheStatus] = 0;
            }

            $statusCounts[$cacheStatus]++;
        }

        $cacheableBase = $statusCounts['HIT']
            + $statusCounts['MISS']
            + $statusCounts['EXPIRED']
            + $statusCounts['STALE']
            + $statusCounts['REVALIDATED']
            + $statusCounts['UPDATING'];

        $hitRatio = $cacheableBase > 0
            ? round(($statusCounts['HIT'] / $cacheableBase) * 100, 2)
            : 0;

        return [
            'status_counts' => $statusCounts,
            'hit_ratio' => $hitRatio,
            'php_response_cache' => $this->getDirectoryStats($this->phpResponseCacheDir),
            'nginx_microcache' => $this->getDirectoryStats($this->nginxMicrocacheDir),
        ];
    }

    private function collectSecurityMetrics($windowMinutes)
    {
        $entries = $this->readAccessLogEntries($windowMinutes);
        $requestTraffic = $this->collectTopIpRequestMetrics($windowMinutes);
        $bucketSize = $windowMinutes >= 180 ? 15 : ($windowMinutes >= 60 ? 5 : 1);
        $bucketCount = max(1, (int)ceil($windowMinutes / $bucketSize));
        $bucketMap = [];

        for ($i = $bucketCount - 1; $i >= 0; $i--) {
            $timestamp = time() - ($i * $bucketSize * 60);
            $bucketKey = gmdate('Y-m-d H:i', $this->roundDownToBucket($timestamp, $bucketSize));
            $bucketMap[$bucketKey] = [
                'label' => gmdate('H:i', strtotime($bucketKey . ':00')),
                'total' => 0,
                'rate_limited' => 0,
                'probes' => 0,
                'blocked' => 0,
            ];
        }

        $totalSignals = 0;
        $uniqueIps = [];
        $topIps = [];
        $topTargets = [];
        $ipStats = [];
        $rateLimitedTotal = 0;
        $probeTotal = 0;
        $blockedTotal = 0;

        foreach ($requestTraffic as $row) {
            $ip = trim((string)($row['ip'] ?? ''));
            if ($ip === '') {
                continue;
            }

            $uniqueIps[$ip] = true;
            $ipStats[$ip] = [
                'ip' => $ip,
                'requests_total' => (int)($row['requests_total'] ?? 0),
                'count' => 0,
                'rate_limited' => 0,
                'probes' => 0,
                'blocked_hits' => 0,
                'last_seen' => $row['last_seen'] ?? null,
                'last_target' => $row['last_endpoint'] ?? null,
                'country_code' => $row['country_code'] ?? null,
                'api_keys_total' => (int)($row['api_keys_total'] ?? 0),
                'is_blocked' => false,
                'blocked_at' => null,
            ];
        }

        foreach ($entries as $entry) {
            $classification = $this->classifySecurityEntry($entry);
            if (!$classification['is_attack']) {
                continue;
            }

            $totalSignals++;
            $ip = $entry['ip'] ?? '';
            if ($ip !== '') {
                $uniqueIps[$ip] = true;
                if (!isset($topIps[$ip])) {
                    $topIps[$ip] = 0;
                }
                $topIps[$ip]++;

                if (!isset($ipStats[$ip])) {
                    $ipStats[$ip] = [
                        'ip' => $ip,
                        'requests_total' => 0,
                        'count' => 0,
                        'rate_limited' => 0,
                        'probes' => 0,
                        'blocked_hits' => 0,
                        'last_seen' => null,
                        'last_target' => null,
                        'country_code' => null,
                        'api_keys_total' => 0,
                        'is_blocked' => false,
                        'blocked_at' => null,
                    ];
                }

                $ipStats[$ip]['count']++;
            }

            $target = $classification['target'] ?: ($entry['path'] ?? $entry['uri'] ?? '/');
            if (!isset($topTargets[$target])) {
                $topTargets[$target] = 0;
            }
            $topTargets[$target]++;

            $entryTime = isset($entry['timestamp']) ? (int)$entry['timestamp'] : 0;
            if ($ip !== '' && isset($ipStats[$ip])) {
                $currentLastSeen = $ipStats[$ip]['last_seen'] ? strtotime((string)$ipStats[$ip]['last_seen']) : 0;
                if ($entryTime > 0 && $entryTime >= $currentLastSeen) {
                    $ipStats[$ip]['last_seen'] = gmdate('c', $entryTime);
                    $ipStats[$ip]['last_target'] = $target;
                } elseif ($ipStats[$ip]['last_target'] === null) {
                    $ipStats[$ip]['last_target'] = $target;
                }
            }

            if ($entryTime > 0) {
                $bucketKey = gmdate('Y-m-d H:i', $this->roundDownToBucket($entryTime, $bucketSize));
                if (!isset($bucketMap[$bucketKey])) {
                    $bucketMap[$bucketKey] = [
                        'label' => gmdate('H:i', $entryTime),
                        'total' => 0,
                        'rate_limited' => 0,
                        'probes' => 0,
                        'blocked' => 0,
                    ];
                }

                $bucketMap[$bucketKey]['total']++;
                if ($classification['rate_limited']) {
                    $bucketMap[$bucketKey]['rate_limited']++;
                }
                if ($classification['probe']) {
                    $bucketMap[$bucketKey]['probes']++;
                }
                if ($classification['blocked']) {
                    $bucketMap[$bucketKey]['blocked']++;
                }
            }

            if ($classification['rate_limited']) {
                $rateLimitedTotal++;
                if ($ip !== '' && isset($ipStats[$ip])) {
                    $ipStats[$ip]['rate_limited']++;
                }
            }
            if ($classification['probe']) {
                $probeTotal++;
                if ($ip !== '' && isset($ipStats[$ip])) {
                    $ipStats[$ip]['probes']++;
                }
            }
            if ($classification['blocked']) {
                $blockedTotal++;
                if ($ip !== '' && isset($ipStats[$ip])) {
                    $ipStats[$ip]['blocked_hits']++;
                }
            }
        }

        arsort($topIps);
        arsort($topTargets);

        $blockedIpMap = $this->readBlockedIps();
        foreach ($blockedIpMap as $blockedIp => $meta) {
            if (!isset($ipStats[$blockedIp])) {
                $ipStats[$blockedIp] = [
                    'ip' => $blockedIp,
                    'requests_total' => 0,
                    'count' => 0,
                    'rate_limited' => 0,
                    'probes' => 0,
                    'blocked_hits' => 0,
                    'last_seen' => null,
                    'last_target' => null,
                    'country_code' => null,
                    'api_keys_total' => 0,
                    'is_blocked' => true,
                    'blocked_at' => $meta['blocked_at'] ?? null,
                ];
            } else {
                $ipStats[$blockedIp]['is_blocked'] = true;
                $ipStats[$blockedIp]['blocked_at'] = $meta['blocked_at'] ?? null;
            }
        }

        $requestThreshold = $this->getSuspiciousRequestThreshold($windowMinutes);
        $suspiciousRows = [];

        foreach ($ipStats as $ip => $row) {
            $row['requests_total'] = (int)($row['requests_total'] ?? 0);
            $row['count'] = (int)($row['count'] ?? 0);
            $row['rate_limited'] = (int)($row['rate_limited'] ?? 0);
            $row['probes'] = (int)($row['probes'] ?? 0);
            $row['blocked_hits'] = (int)($row['blocked_hits'] ?? 0);
            $row['is_high_request'] = $row['requests_total'] >= $requestThreshold;

            $riskScore = 0;
            if (!empty($row['is_blocked'])) {
                $riskScore += 1000;
            }
            if ($row['is_high_request']) {
                $riskScore += 250 + min(250, (int)floor($row['requests_total'] / max(1, $requestThreshold)) * 30);
            }
            $riskScore += $row['count'] * 20;
            $riskScore += $row['rate_limited'] * 15;
            $riskScore += $row['probes'] * 40;
            $riskScore += $row['blocked_hits'] * 25;

            $isSuspicious = !empty($row['is_blocked'])
                || $row['count'] > 0
                || $row['rate_limited'] > 0
                || $row['probes'] > 0
                || $row['blocked_hits'] > 0
                || $row['is_high_request'];

            if (!$isSuspicious) {
                continue;
            }

            $row['risk_score'] = $riskScore;
            $suspiciousRows[$ip] = $row;
        }

        uasort($suspiciousRows, function ($a, $b) {
            if ((int)($a['risk_score'] ?? 0) !== (int)($b['risk_score'] ?? 0)) {
                return (int)($b['risk_score'] ?? 0) <=> (int)($a['risk_score'] ?? 0);
            }

            if ((int)($a['requests_total'] ?? 0) !== (int)($b['requests_total'] ?? 0)) {
                return (int)($b['requests_total'] ?? 0) <=> (int)($a['requests_total'] ?? 0);
            }

            if ((int)($a['is_blocked'] ?? false) !== (int)($b['is_blocked'] ?? false)) {
                return (int)($b['is_blocked'] ?? false) <=> (int)($a['is_blocked'] ?? false);
            }

            if ((int)($a['count'] ?? 0) !== (int)($b['count'] ?? 0)) {
                return (int)($b['count'] ?? 0) <=> (int)($a['count'] ?? 0);
            }

            return strcmp((string)($b['last_seen'] ?? ''), (string)($a['last_seen'] ?? ''));
        });

        $series = array_values($bucketMap);
        $peakSignals = 0;
        foreach ($series as $point) {
            if (($point['total'] ?? 0) > $peakSignals) {
                $peakSignals = (int)$point['total'];
            }
        }

        $topIp = null;
        if (!empty($suspiciousRows)) {
            $firstIp = array_key_first($suspiciousRows);
            $topIp = [
                'ip' => $firstIp,
                'count' => (int)($suspiciousRows[$firstIp]['requests_total'] ?? $suspiciousRows[$firstIp]['count'] ?? 0),
            ];
        }

        $topTarget = null;
        if (!empty($topTargets)) {
            $firstTarget = array_key_first($topTargets);
            $topTarget = [
                'target' => $firstTarget,
                'count' => (int)$topTargets[$firstTarget],
            ];
        }

        return [
            'log_available' => is_file($this->accessLogPath),
            'window_minutes' => $windowMinutes,
            'total_signals' => $totalSignals,
            'rate_limited' => $rateLimitedTotal,
            'probes' => $probeTotal,
            'blocked' => $blockedTotal,
            'unique_ips' => count($suspiciousRows),
            'suspicious_total' => count($suspiciousRows),
            'suspicious_request_threshold' => $requestThreshold,
            'peak_bucket' => $peakSignals,
            'top_ip' => $topIp,
            'top_target' => $topTarget,
            'blocked_ips_total' => count($blockedIpMap),
            'top_ips' => array_slice(array_values($suspiciousRows), 0, 10),
            'series' => $series,
        ];
    }

    private function getSuspiciousRequestThreshold($windowMinutes)
    {
        $windowMinutes = max(5, (int)$windowMinutes);

        if ($windowMinutes <= 15) {
            return 25;
        }

        if ($windowMinutes <= 60) {
            return 80;
        }

        if ($windowMinutes <= 180) {
            return 160;
        }

        if ($windowMinutes <= 720) {
            return 400;
        }

        return 900;
    }

    private function collectTopIpRequestMetrics($windowMinutes)
    {
        $windowMinutes = max(5, min(1440, (int)$windowMinutes));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($windowMinutes * 60));

        try {
            $sql = "
                SELECT
                    logs.ip_address AS ip,
                    COUNT(*) AS requests_total,
                    MAX(logs.created_at) AS last_seen,
                    COUNT(DISTINCT logs.api_key) AS api_keys_total,
                    MAX(COALESCE(logs.country_code, '')) AS country_code,
                    (
                        SELECT latest.endpoint
                        FROM api_logs_24 latest
                        WHERE latest.ip_address = logs.ip_address
                          AND latest.created_at >= :cutoff_latest
                        ORDER BY latest.created_at DESC, latest.id DESC
                        LIMIT 1
                    ) AS last_endpoint
                FROM api_logs_24 logs
                WHERE logs.created_at >= :cutoff
                  AND logs.ip_address IS NOT NULL
                  AND logs.ip_address <> ''
                GROUP BY logs.ip_address
                ORDER BY requests_total DESC, last_seen DESC
                LIMIT 200
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':cutoff_latest' => $cutoff,
                ':cutoff' => $cutoff,
            ]);

            return array_map(function ($row) {
                return [
                    'ip' => trim((string)($row['ip'] ?? '')),
                    'requests_total' => (int)($row['requests_total'] ?? 0),
                    'last_seen' => $row['last_seen'] ?? null,
                    'api_keys_total' => (int)($row['api_keys_total'] ?? 0),
                    'country_code' => trim((string)($row['country_code'] ?? '')) ?: null,
                    'last_endpoint' => trim((string)($row['last_endpoint'] ?? '')) ?: null,
                ];
            }, (array)$stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    private function collectTopKeys()
    {
        try {
            $entries = $this->readMetricsLogEntries(1440, 50000);
            if (empty($entries)) {
                return [];
            }

            $keyStats = [];
            foreach ($entries as $entry) {
                $apiKey = trim((string)($entry['api_key'] ?? ''));
                if ($apiKey === '') {
                    continue;
                }

                if (!isset($keyStats[$apiKey])) {
                    $keyStats[$apiKey] = [
                        'requests_24h' => 0,
                        'last_request_at' => null,
                    ];
                }

                $keyStats[$apiKey]['requests_24h']++;

                $entryTime = $entry['time'] ?? null;
                if ($entryTime && ($keyStats[$apiKey]['last_request_at'] === null || strtotime($entryTime) > strtotime($keyStats[$apiKey]['last_request_at']))) {
                    $keyStats[$apiKey]['last_request_at'] = $entryTime;
                }
            }

            if (empty($keyStats)) {
                return [];
            }

            uasort($keyStats, function ($a, $b) {
                if ($a['requests_24h'] === $b['requests_24h']) {
                    return strcmp((string)$b['last_request_at'], (string)$a['last_request_at']);
                }

                return $b['requests_24h'] <=> $a['requests_24h'];
            });

            $keyStats = array_slice($keyStats, 0, 5, true);
            $metadata = $this->getApiKeysMetadata(array_keys($keyStats));

            $rows = [];
            foreach ($keyStats as $apiKey => $stats) {
                $meta = $metadata[$apiKey] ?? [
                    'name' => 'مفتاح غير معروف',
                    'request_count_total' => $stats['requests_24h'],
                    'origin_label' => 'مفتاح تطوير / غير مربوط',
                ];

                $rows[] = [
                    'api_key' => $apiKey,
                    'key_name' => $meta['name'],
                    'requests_24h' => $stats['requests_24h'],
                    'request_count_total' => $meta['request_count_total'],
                    'last_request_at' => $stats['last_request_at'],
                    'origin_label' => $meta['origin_label'],
                ];
            }

            return array_map(function ($row) {
                return [
                    'name' => $row['key_name'],
                    'masked_key' => $this->maskApiKey($row['api_key']),
                    'requests_24h' => (int)$row['requests_24h'],
                    'request_count_total' => (int)$row['request_count_total'],
                    'last_request_at' => $row['last_request_at'],
                    'origin_label' => $row['origin_label'],
                ];
            }, $rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function readMetricsLogEntries($windowMinutes, $maxLines = 12000)
    {
        if (!is_file($this->metricsLogPath)) {
            return [];
        }

        $lines = $this->tailFileLines($this->metricsLogPath, $maxLines);
        if (empty($lines)) {
            return [];
        }

        $minTimestamp = time() - ($windowMinutes * 60);
        $entries = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            $entryTime = isset($decoded['time']) ? strtotime($decoded['time']) : false;
            if ($entryTime !== false && $entryTime < $minTimestamp) {
                continue;
            }

            $entries[] = $decoded;
        }

        return $entries;
    }

    private function getApiKeysMetadata($apiKeys)
    {
        if (empty($apiKeys)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($apiKeys), '?'));

        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    k.api_key,
                    k.name,
                    k.request_count,
                    COALESCE(o.origin, 'مفتاح تطوير / غير مربوط') AS origin_label
                FROM api_keys k
                LEFT JOIN api_allowed_origins o ON k.allowed_origin_id = o.id
                WHERE k.api_key IN ($placeholders)
            ");
            $stmt->execute(array_values($apiKeys));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        $metadata = [];
        foreach ((array)$rows as $row) {
            $metadata[$row['api_key']] = [
                'name' => $row['name'] ?? 'مفتاح غير معروف',
                'request_count_total' => isset($row['request_count']) ? (int)$row['request_count'] : 0,
                'origin_label' => $row['origin_label'] ?? 'مفتاح تطوير / غير مربوط',
            ];
        }

        return $metadata;
    }

    private function readAccessLogEntries($windowMinutes, $maxLines = 40000)
    {
        if (!is_file($this->accessLogPath)) {
            return [];
        }

        $lines = $this->tailFileLines($this->accessLogPath, $maxLines);
        if (empty($lines)) {
            return [];
        }

        $minTimestamp = time() - ($windowMinutes * 60);
        $entries = [];

        foreach ($lines as $line) {
            $entry = $this->parseAccessLogLine($line);
            if ($entry === null) {
                continue;
            }

            if (($entry['timestamp'] ?? 0) < $minTimestamp) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    private function parseAccessLogLine($line)
    {
        $line = trim((string)$line);
        if ($line === '') {
            return null;
        }

        $pattern = '/^(?<ip>\S+)\s+\S+\s+\S+\s+\[(?<time>[^\]]+)\]\s+"(?<method>[A-Z]+)\s+(?<uri>\S+)\s+[^"]*"\s+(?<status>\d{3})\s+\S+\s+"(?<referrer>[^"]*)"\s+"(?<ua>[^"]*)"/';
        if (!preg_match($pattern, $line, $matches)) {
            return null;
        }

        $timestamp = DateTime::createFromFormat('d/M/Y:H:i:s O', $matches['time']);
        $uri = $matches['uri'] ?? '/';
        $path = (string)parse_url($uri, PHP_URL_PATH);
        $path = $path !== '' ? $path : '/';

        return [
            'ip' => $matches['ip'] ?? '',
            'time' => $matches['time'] ?? '',
            'timestamp' => $timestamp ? $timestamp->getTimestamp() : 0,
            'method' => strtoupper((string)($matches['method'] ?? 'GET')),
            'uri' => $uri,
            'path' => $path,
            'status' => (int)($matches['status'] ?? 0),
            'referrer' => $matches['referrer'] ?? '',
            'user_agent' => $matches['ua'] ?? '',
        ];
    }

    private function classifySecurityEntry(array $entry)
    {
        $status = (int)($entry['status'] ?? 0);
        $rawUri = strtolower(rawurldecode((string)($entry['uri'] ?? '/')));
        $path = strtolower(rawurldecode((string)($entry['path'] ?? '/')));

        $sensitivePatterns = [
            '/wp-login.php',
            '/xmlrpc.php',
            '/.env',
            '/.git',
            '/phpmyadmin',
            '/pma',
            '/adminer',
            '/vendor/phpunit',
            '/hnap1',
            '/boaform',
            '/cgi-bin',
            '/server-status',
            '/actuator',
            '/owa/',
            '/autodiscover',
            '/jenkins',
            '/manager/html',
            '/solr/',
            '/containers/json',
            '/w00tw00t',
            '/_ignition/',
            '/debug/default',
        ];

        $probe = false;
        foreach ($sensitivePatterns as $needle) {
            if (strpos($rawUri, $needle) !== false || strpos($path, $needle) !== false) {
                $probe = true;
                break;
            }
        }

        if (!$probe && preg_match('/(^|\/)\.(env|git|svn|hg|ds_store)/i', $path)) {
            $probe = true;
        }

        if (
            !$probe &&
            preg_match('/\.(bak|backup|old|orig|save|swp|dist|sql|zip|tar|gz)(\?.*)?$/i', $rawUri)
        ) {
            $probe = true;
        }

        $blocked = in_array($status, [401, 403, 444, 495, 496, 497], true);
        $rateLimited = ($status === 429);
        $isAttack = $probe || $blocked || $rateLimited;

        return [
            'is_attack' => $isAttack,
            'probe' => $probe,
            'blocked' => $blocked,
            'rate_limited' => $rateLimited,
            'target' => $probe ? (($entry['path'] ?? '/') ?: '/') : null,
        ];
    }

    private function tailFileLines($path, $maxLines)
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return [];
        }

        $buffer = '';
        $chunkSize = 8192;
        $lineCount = 0;

        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);

        while ($position > 0 && $lineCount <= $maxLines) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            fseek($handle, $position);
            $buffer = fread($handle, $readSize) . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }

        fclose($handle);

        $buffer = trim($buffer);
        if ($buffer === '') {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $buffer);
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return $lines;
    }

    private function roundDownToBucket($timestamp, $bucketMinutes)
    {
        $bucketSeconds = max(60, $bucketMinutes * 60);
        return (int)(floor($timestamp / $bucketSeconds) * $bucketSeconds);
    }

    private function getMemoryStats()
    {
        $stats = [
            'total_bytes' => null,
            'available_bytes' => null,
            'used_bytes' => null,
            'used_percent' => null,
        ];

        if (DIRECTORY_SEPARATOR !== '\\' && is_readable('/proc/meminfo')) {
            $meminfo = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $parsed = [];

            foreach ((array)$meminfo as $line) {
                if (strpos($line, ':') === false) {
                    continue;
                }

                list($key, $value) = explode(':', $line, 2);
                $parsed[$key] = (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
            }

            $total = isset($parsed['MemTotal']) ? $parsed['MemTotal'] * 1024 : null;
            $available = isset($parsed['MemAvailable']) ? $parsed['MemAvailable'] * 1024 : null;

            if ($total !== null && $available !== null) {
                $used = max(0, $total - $available);

                $stats['total_bytes'] = $total;
                $stats['available_bytes'] = $available;
                $stats['used_bytes'] = $used;
                $stats['used_percent'] = $total > 0 ? round(($used / $total) * 100, 2) : null;

                return $stats;
            }
        }

        $currentUsage = memory_get_usage(true);
        $stats['used_bytes'] = $currentUsage;

        return $stats;
    }

    private function getDiskStats()
    {
        $path = DIRECTORY_SEPARATOR === '\\' ? $this->projectRoot : '/';
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        $used = ($total !== false && $free !== false) ? max(0, $total - $free) : null;

        return [
            'path' => $path,
            'total_bytes' => $total !== false ? $total : null,
            'free_bytes' => $free !== false ? $free : null,
            'used_bytes' => $used,
            'used_percent' => ($total && $used !== null) ? round(($used / $total) * 100, 2) : null,
        ];
    }

    private function getCpuCoreCount()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return getenv('NUMBER_OF_PROCESSORS') ?: null;
        }

        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                preg_match_all('/^processor\s*:/m', $cpuinfo, $matches);
                if (!empty($matches[0])) {
                    return count($matches[0]);
                }
            }
        }

        $nproc = trim((string)$this->runCommand('nproc 2>/dev/null'));
        return ctype_digit($nproc) ? (int)$nproc : null;
    }

    private function getOperatingSystemName()
    {
        if (DIRECTORY_SEPARATOR !== '\\' && is_readable('/etc/os-release')) {
            $content = @file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ((array)$content as $line) {
                if (strpos($line, 'PRETTY_NAME=') === 0) {
                    return trim(substr($line, strlen('PRETTY_NAME=')), "\"'");
                }
            }
        }

        return php_uname('s') . ' ' . php_uname('r');
    }

    private function getNginxVersion()
    {
        $output = trim((string)$this->runCommand('nginx -v 2>&1'));
        if ($output === '') {
            return null;
        }

        if (preg_match('/nginx\/([0-9.]+)/i', $output, $matches)) {
            return $matches[1];
        }

        return $output;
    }

    private function getDatabaseVersion()
    {
        try {
            return $this->pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getSystemUptimeHuman()
    {
        if (DIRECTORY_SEPARATOR !== '\\' && is_readable('/proc/uptime')) {
            $content = @file_get_contents('/proc/uptime');
            if ($content !== false) {
                $parts = explode(' ', trim($content));
                $seconds = isset($parts[0]) ? (int)floor((float)$parts[0]) : null;
                if ($seconds !== null) {
                    return $this->formatDuration($seconds);
                }
            }
        }

        return null;
    }

    private function getServiceStatus($service)
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return 'unknown';
        }

        $output = trim((string)$this->runCommand('systemctl is-active ' . escapeshellarg($service) . ' 2>/dev/null'));
        if ($output === '') {
            return 'unknown';
        }

        $knownStatuses = ['active', 'inactive', 'failed', 'activating', 'deactivating', 'unknown'];
        return in_array($output, $knownStatuses, true) ? $output : 'unknown';
    }

    private function getServiceDefinitions()
    {
        return [
            [
                'label' => 'Nginx',
                'service' => 'nginx',
                'description' => 'واجهة الويب الأساسية للموقع',
                'icon' => 'fa-globe',
                'theme' => 'blue',
                'toggleable' => false,
            ],
            [
                'label' => 'PHP-FPM',
                'service' => 'php8.3-fpm',
                'description' => 'محرك تنفيذ صفحات PHP الحالية',
                'icon' => 'fa-code',
                'theme' => 'purple',
                'toggleable' => false,
            ],
            [
                'label' => 'Smart Runner',
                'service' => 'sport-smart-runner.service',
                'description' => 'التحديث الذكي للمباريات في الخلفية',
                'icon' => 'fa-wand-magic-sparkles',
                'theme' => 'violet',
                'toggleable' => true,
            ],
            [
                'label' => 'Auto Scraper',
                'service' => 'sport-auto-scraper.service',
                'description' => 'الجدولة التلقائية ومهام السحب الخلفية',
                'icon' => 'fa-robot',
                'theme' => 'green',
                'toggleable' => true,
            ],
            [
                'label' => '365Scores',
                'service' => '365scores_fallback',
                'description' => 'Fallback live tracker source after all enabled channel sources fail',
                'icon' => 'fa-tower-broadcast',
                'theme' => 'blue',
                'toggleable' => true,
                'type' => 'setting',
                'setting_key' => 'enable_365scores_fallback',
                'active_value' => '1',
                'inactive_value' => '0',
            ],
        ];
    }

    private function findServiceDefinition($serviceName)
    {
        foreach ($this->getServiceDefinitions() as $service) {
            if (($service['service'] ?? '') === $serviceName) {
                return $service;
            }
        }

        return null;
    }


    private function getMonitorServiceStatus(array $definition)
    {
        if (($definition['type'] ?? 'systemd') === 'setting') {
            $value = $this->getApiSettingValue(
                (string) ($definition['setting_key'] ?? ''),
                (string) ($definition['active_value'] ?? '1')
            );

            return ((string) $value === (string) ($definition['active_value'] ?? '1')) ? 'active' : 'inactive';
        }

        return $this->getServiceStatus($definition['service'] ?? '');
    }

    private function formatServiceState(array $definition, $status)
    {
        $isActive = ($status === 'active');
        $statusLabel = $isActive ? 'نشط' : (($status === 'inactive' || $status === 'failed') ? 'متوقف' : 'غير معروف');

        return [
            'label' => $definition['label'],
            'service' => $definition['service'],
            'description' => $definition['description'] ?? '',
            'icon' => $definition['icon'] ?? 'fa-server',
            'theme' => $definition['theme'] ?? 'blue',
            'status' => $status,
            'status_label' => $statusLabel,
            'is_active' => $isActive,
            'toggleable' => !empty($definition['toggleable']),
        ];
    }

    private function clearSnapshotCache()
    {
        if (is_file($this->snapshotPath)) {
            @unlink($this->snapshotPath);
        }
    }

    private function ensureApiSettingsTable()
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_settings (
                id SERIAL PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    private function getApiSettingValue($key, $default = '1')
    {
        if ($key === '') {
            return $default;
        }

        $this->ensureApiSettingsTable();

        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM api_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();

            if ($value === false) {
                $this->setApiSettingValue($key, $default);
                return $default;
            }

            return (string) $value;
        } catch (Throwable $e) {
            return $default;
        }
    }

    private function setApiSettingValue($key, $value)
    {
        if ($key === '') {
            return false;
        }

        $this->ensureApiSettingsTable();

        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $stmt = $this->pdo->prepare("INSERT INTO api_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value");
            } else {
                $stmt = $this->pdo->prepare("INSERT INTO api_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            }

            return $stmt->execute([$key, $value]);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function purge365ScoresFallbackArtifacts()
    {
        try {
            $matchStmt = $this->pdo->prepare("
                SELECT id, channel, live_url, live_iframe
                FROM matches
                WHERE live_url LIKE ?
                   OR live_url LIKE ?
                   OR live_url LIKE ?
                   OR live_iframe LIKE ?
                   OR live_iframe LIKE ?
                   OR live_iframe LIKE ?
            ");
            $matchStmt->execute([
                '%365scores.com%',
                '%lmtsrcf.365scores.com%',
                '%sportradar.com%',
                '%365scores.com%',
                '%lmtsrcf.365scores.com%',
                '%sportradar.com%'
            ]);
            $rows = $matchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $channelsCleared = 0;
            $matchesCleared = 0;

            if (!empty($rows)) {
                $clearMatchStmt = $this->pdo->prepare("
                    UPDATE matches
                    SET live_url = NULL, live_iframe = NULL
                    WHERE id = ?
                ");
                $clearChannelStmt = $this->pdo->prepare("
                    UPDATE channels
                    SET stream_url = NULL
                    WHERE name = ?
                      AND stream_url = ?
                ");

                foreach ($rows as $row) {
                    $stream = trim((string)($row['live_iframe'] ?: $row['live_url']));
                    $clearMatchStmt->execute([(int)$row['id']]);
                    $matchesCleared += $clearMatchStmt->rowCount() > 0 ? 1 : 0;

                    $channelName = trim((string)($row['channel'] ?? ''));
                    if ($channelName !== '' && $stream !== '') {
                        $clearChannelStmt->execute([$channelName, $stream]);
                        $channelsCleared += $clearChannelStmt->rowCount();
                    }
                }
            }

            return [
                'matches' => $matchesCleared,
                'channels' => $channelsCleared,
            ];
        } catch (Throwable $e) {
            return [
                'matches' => 0,
                'channels' => 0,
            ];
        }
    }

    public function updateBlockedIps(array $ips, $shouldBlock)
    {
        $shouldBlock = (bool)$shouldBlock;
        $blockedIps = $this->readBlockedIps();
        $normalizedIps = [];

        foreach ($ips as $ip) {
            $ip = trim((string)$ip);
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $normalizedIps[$ip] = $ip;
        }

        if (empty($normalizedIps)) {
            return [
                'success' => false,
                'message' => 'لم يتم العثور على أي IP صالح للتنفيذ.',
            ];
        }

        foreach ($normalizedIps as $ip) {
            if ($shouldBlock) {
                $blockedIps[$ip] = [
                    'ip' => $ip,
                    'blocked_at' => $blockedIps[$ip]['blocked_at'] ?? gmdate('c'),
                ];
            } else {
                unset($blockedIps[$ip]);
            }
        }

        $saved = $this->persistBlockedIps($blockedIps);
        if (!$saved) {
            return [
                'success' => false,
                'message' => 'تعذر حفظ قائمة الحظر الحالية.',
            ];
        }

        $this->clearApiCacheStores();

        if (DIRECTORY_SEPARATOR !== '\\') {
            $firewallSync = $this->syncSystemFirewallBlocks();
            if (!$firewallSync['success']) {
                return [
                    'success' => false,
                    'message' => $firewallSync['message'],
                ];
            }

            $output = trim((string)$this->runCommand('sudo -n /usr/bin/systemctl restart nginx 2>&1'));
            $nginxActive = trim((string)$this->runCommand('systemctl is-active nginx 2>/dev/null')) === 'active';
            if (!$nginxActive) {
                return [
                    'success' => false,
                    'message' => $output !== '' ? $output : 'تم حفظ الحظر لكن تعذر إعادة تحميل nginx.',
                ];
            }
        }

        $this->clearSnapshotCache();

        return [
            'success' => true,
            'message' => $shouldBlock ? 'تم حظر الـ IPs المحددة بنجاح.' : 'تم رفع الحظر عن الـ IPs المحددة بنجاح.',
        ];
    }

    private function readBlockedIps()
    {
        $this->ensureMonitoringDirectory();

        if (!is_file($this->blockedIpsPath)) {
            return [];
        }

        $raw = @file_get_contents($this->blockedIpsPath);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $ip = trim((string)($item['ip'] ?? ''));
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $normalized[$ip] = [
                'ip' => $ip,
                'blocked_at' => trim((string)($item['blocked_at'] ?? '')) ?: null,
            ];
        }

        ksort($normalized, SORT_NATURAL);
        return $normalized;
    }

    private function persistBlockedIps(array $blockedIps)
    {
        $this->ensureMonitoringDirectory();

        $rows = array_values(array_map(function ($item) {
            return [
                'ip' => (string)($item['ip'] ?? ''),
                'blocked_at' => (string)($item['blocked_at'] ?? gmdate('c')),
            ];
        }, $blockedIps));

        usort($rows, function ($a, $b) {
            return strcmp((string)$a['ip'], (string)$b['ip']);
        });

        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        $confLines = ["# Managed by SPORT monitor", "# Updated at " . gmdate('c')];
        foreach ($rows as $row) {
            $ip = trim((string)($row['ip'] ?? ''));
            if ($ip === '') {
                continue;
            }

            $confLines[] = 'deny ' . $ip . ';';
            $escapedIp = preg_quote($ip, '/');
            $confLines[] = 'if ($http_cf_connecting_ip = "' . $ip . '") { return 403; }';
            $confLines[] = 'if ($http_true_client_ip = "' . $ip . '") { return 403; }';
            $confLines[] = 'if ($http_x_real_ip = "' . $ip . '") { return 403; }';
            $confLines[] = 'if ($http_x_forwarded_for ~* "(^|,\\s*)' . $escapedIp . '($|,\\s*)") { return 403; }';
        }
        $confContent = implode(PHP_EOL, $confLines) . PHP_EOL;

        $jsonSaved = $this->writeManagedFile($this->blockedIpsPath, $json . PHP_EOL);
        $confSaved = $this->writeManagedFile($this->blockedIpsIncludePath, $confContent);

        return $jsonSaved && $confSaved;
    }

    private function writeManagedFile($path, $content)
    {
        $directory = dirname((string)$path);
        if ($directory === '' || !is_dir($directory)) {
            return false;
        }

        $temporaryPath = $directory
            . DIRECTORY_SEPARATOR
            . '.tmp-'
            . basename((string)$path)
            . '-'
            . bin2hex(random_bytes(6));

        $written = @file_put_contents($temporaryPath, (string)$content, LOCK_EX);
        if ($written === false) {
            return false;
        }

        @chmod($temporaryPath, 0644);

        if (@rename($temporaryPath, $path)) {
            clearstatcache(true, $path);
            return true;
        }

        @unlink($path);
        $renamed = @rename($temporaryPath, $path);
        if (!$renamed) {
            @unlink($temporaryPath);
            return false;
        }

        clearstatcache(true, $path);
        return true;
    }

    private function syncSystemFirewallBlocks()
    {
        $scriptPath = '/usr/local/sbin/sport-sync-firewall-blocks';
        if (!is_file($scriptPath)) {
            return [
                'success' => false,
                'message' => 'سكريبت مزامنة جدار الحماية غير موجود على الخادم.',
            ];
        }

        $output = trim((string)$this->runCommand('sudo -n ' . escapeshellarg($scriptPath) . ' 2>&1'));
        $status = trim((string)$this->runCommand('systemctl is-active nftables 2>/dev/null'));

        if ($status !== 'active') {
            return [
                'success' => false,
                'message' => $output !== '' ? $output : 'تم تحديث قائمة الحظر لكن تعذر تفعيل جدار الحماية على مستوى السيرفر.',
            ];
        }

        return [
            'success' => true,
            'message' => $output,
        ];
    }

    private function clearApiCacheStores()
    {
        $this->clearDirectoryContents($this->phpResponseCacheDir);
        $this->clearDirectoryContents($this->nginxMicrocacheDir);
    }

    private function clearDirectoryContents($path)
    {
        if (!is_dir($path)) {
            return;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                $realPath = $item->getRealPath();
                if ($realPath === false) {
                    continue;
                }

                if ($item->isDir()) {
                    @rmdir($realPath);
                } else {
                    @unlink($realPath);
                }
            }
        } catch (Throwable $e) {
            // Ignore cache cleanup failures to keep blocking usable.
        }
    }

    private function isPublicLookupIp($ip)
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function readIpGeoCache()
    {
        if (!is_file($this->ipGeoCachePath)) {
            return [];
        }

        $raw = @file_get_contents($this->ipGeoCachePath);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function persistIpGeoCache(array $cache)
    {
        @file_put_contents(
            $this->ipGeoCachePath,
            json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }


    private function getSystemctlBinary()
    {
        foreach (['/usr/bin/systemctl', '/bin/systemctl'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'systemctl';
    }

    private function getDirectoryStats($path)
    {
        if (!is_dir($path)) {
            return [
                'path' => $path,
                'exists' => false,
                'files' => 0,
                'size_bytes' => 0,
            ];
        }

        $files = 0;
        $size = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $files++;
                    $size += $item->getSize();
                }
            }
        } catch (Throwable $e) {
            return [
                'path' => $path,
                'exists' => true,
                'files' => 0,
                'size_bytes' => 0,
            ];
        }

        return [
            'path' => $path,
            'exists' => true,
            'files' => $files,
            'size_bytes' => $size,
        ];
    }

    private function maskApiKey($key)
    {
        $key = trim((string)$key);
        if ($key === '') {
            return '-';
        }

        $length = strlen($key);
        if ($length <= 12) {
            return substr($key, 0, 3) . str_repeat('*', max(0, $length - 6)) . substr($key, -3);
        }

        return substr($key, 0, 6) . '...' . substr($key, -4);
    }

    private function formatDuration($seconds)
    {
        $seconds = max(0, (int)$seconds);
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ' يوم';
        }
        if ($hours > 0) {
            $parts[] = $hours . ' ساعة';
        }
        if ($minutes > 0 || empty($parts)) {
            $parts[] = $minutes . ' دقيقة';
        }

        return implode(' • ', $parts);
    }

    private function runCommand($command)
    {
        if (!function_exists('shell_exec')) {
            return '';
        }

        $disabled = ini_get('disable_functions');
        if (is_string($disabled) && stripos($disabled, 'shell_exec') !== false) {
            return '';
        }

        $output = @shell_exec($command);
        return is_string($output) ? $output : '';
    }
}
