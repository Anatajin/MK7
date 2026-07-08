<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(0);
ignore_user_abort(true);

require_once dirname(__DIR__) . '/config.php';

const SMART_RUNNER_IDLE_MS = 5000;
const SMART_RUNNER_ERROR_MS = 10000;
const SMART_RUNNER_MIN_ACTIVE_MS = 100;

$runtimeDir = getenv('SMART_RUNNER_RUNTIME_DIR');
if (!$runtimeDir) {
    $runtimeDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.runtime' . DIRECTORY_SEPARATOR . 'smart-runner';
}

$clientId = trim((string) getenv('SMART_RUNNER_CLIENT_ID'));
if ($clientId === '') {
    $clientId = 'server_worker';
}

if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0775, true) && !is_dir($runtimeDir)) {
    fwrite(STDERR, "Unable to create runtime directory: {$runtimeDir}\n");
    exit(1);
}

$stateFile = $runtimeDir . DIRECTORY_SEPARATOR . 'service_state.json';
$logFile = $runtimeDir . DIRECTORY_SEPARATOR . 'service.log';
$phpBinary = PHP_BINARY ?: 'php';
$runnerScript = __DIR__ . DIRECTORY_SEPARATOR . 'smart_runner_once.php';

function logServiceMessage(string $message, string $logFile): void
{
    $line = '[' . date('Y-m-d H:i:s') . "] {$message}";
    echo $line . PHP_EOL;
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

function loadRunnerState(string $stateFile): array
{
    if (!is_file($stateFile)) {
        return [
            'next_index' => 0,
            'next_step' => 0,
            'enabled' => false,
            'last_mode' => null,
            'last_run_at' => null
        ];
    }

    $decoded = json_decode((string) @file_get_contents($stateFile), true);
    if (!is_array($decoded)) {
        return [
            'next_index' => 0,
            'next_step' => 0,
            'enabled' => false,
            'last_mode' => null,
            'last_run_at' => null
        ];
    }

    return array_merge(
        [
            'next_index' => 0,
            'next_step' => 0,
            'enabled' => false,
            'last_mode' => null,
            'last_run_at' => null
        ],
        $decoded
    );
}

function saveRunnerState(string $stateFile, array $state): void
{
    @file_put_contents(
        $stateFile,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function sleepMilliseconds(int $milliseconds): void
{
    if ($milliseconds < 0) {
        $milliseconds = 0;
    }

    usleep($milliseconds * 1000);
}

function createFreshPdo(): PDO
{
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function isSmartRunnerEnabled(): bool
{
    $pdo = createFreshPdo();
    $stmt = $pdo->query("SELECT is_active FROM scraper_settings WHERE task_key = 'smart_runner' LIMIT 1");
    $value = $stmt->fetchColumn();
    return $value === '1' || $value === 1;
}

function decodeRunnerPayload(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($raw, '{');
    $end = strrpos($raw, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }

    $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
    return is_array($decoded) ? $decoded : null;
}

function invokeRunner(string $phpBinary, string $runnerScript, int $matchIndex, int $stepIndex, string $clientId): array
{
    $command = implode(' ', [
        escapeshellarg($phpBinary),
        escapeshellarg($runnerScript),
        '--match-index=' . $matchIndex,
        '--step-index=' . $stepIndex,
        '--client-id=' . escapeshellarg($clientId)
    ]);

    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    $raw = trim(implode(PHP_EOL, $output));
    $payload = decodeRunnerPayload($raw);

    return [
        'exit_code' => $exitCode,
        'raw' => $raw,
        'payload' => $payload
    ];
}

function normalizeNextInterval(array $payload): int
{
    $interval = isset($payload['next_interval']) ? (int) $payload['next_interval'] : 0;
    $mode = (string) ($payload['mode'] ?? '');

    if ($interval <= 0 && $mode === 'active') {
        return SMART_RUNNER_MIN_ACTIVE_MS;
    }

    if ($interval <= 0) {
        return SMART_RUNNER_MIN_ACTIVE_MS;
    }

    return $interval;
}

$state = loadRunnerState($stateFile);
$lastEnabled = (bool) ($state['enabled'] ?? false);
$lastMode = isset($state['last_mode']) ? (string) $state['last_mode'] : null;

logServiceMessage('Smart Runner service booted.', $logFile);

while (true) {
    try {
        $enabled = isSmartRunnerEnabled();
    } catch (Throwable $e) {
        logServiceMessage('Database check failed: ' . $e->getMessage(), $logFile);
        sleepMilliseconds(SMART_RUNNER_ERROR_MS);
        continue;
    }

    if (!$enabled) {
        if ($lastEnabled) {
            $state['next_index'] = 0;
            $state['next_step'] = 0;
            $state['enabled'] = false;
            $state['last_mode'] = 'disabled';
            $state['last_run_at'] = date('c');
            saveRunnerState($stateFile, $state);
            logServiceMessage('Smart Runner disabled from admin; worker is idling.', $logFile);
        }

        $lastEnabled = false;
        $lastMode = 'disabled';
        sleepMilliseconds(SMART_RUNNER_IDLE_MS);
        continue;
    }

    if (!$lastEnabled) {
        $state['next_index'] = 0;
        $state['next_step'] = 0;
        $state['enabled'] = true;
        $state['last_mode'] = 'booting';
        $state['last_run_at'] = date('c');
        saveRunnerState($stateFile, $state);
        logServiceMessage('Smart Runner enabled; starting a fresh server-side loop.', $logFile);
    }

    $lastEnabled = true;

    $result = invokeRunner(
        $phpBinary,
        $runnerScript,
        (int) ($state['next_index'] ?? 0),
        (int) ($state['next_step'] ?? 0),
        $clientId
    );

    $payload = $result['payload'];
    if (!is_array($payload)) {
        logServiceMessage('Runner returned invalid output: ' . $result['raw'], $logFile);
        sleepMilliseconds(SMART_RUNNER_ERROR_MS);
        continue;
    }

    if (($payload['status'] ?? 'error') !== 'success') {
        $message = (string) ($payload['message'] ?? 'Unknown runner error.');
        logServiceMessage('Runner error: ' . $message, $logFile);
        sleepMilliseconds(SMART_RUNNER_ERROR_MS);
        continue;
    }

    $mode = (string) ($payload['mode'] ?? 'active');
    $nextInterval = normalizeNextInterval($payload);

    $state['next_index'] = (int) ($payload['next_index'] ?? 0);
    $state['next_step'] = (int) ($payload['next_step'] ?? 0);
    $state['enabled'] = true;
    $state['last_mode'] = $mode;
    $state['last_run_at'] = date('c');
    $state['last_server_time'] = (int) ($payload['server_time'] ?? time());
    $state['last_interval_ms'] = $nextInterval;
    saveRunnerState($stateFile, $state);

    if ($mode !== $lastMode) {
        logServiceMessage("Runner mode changed to {$mode} (next check in {$nextInterval}ms).", $logFile);
        $lastMode = $mode;
    }

    sleepMilliseconds($nextInterval);
}
