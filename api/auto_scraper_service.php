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

const AUTO_SCRAPER_POLL_MS = 30000;
const AUTO_SCRAPER_IDLE_MS = 60000;
const AUTO_SCRAPER_ERROR_MS = 15000;

$runtimeDir = getenv('AUTO_SCRAPER_RUNTIME_DIR');
if (!$runtimeDir) {
    $runtimeDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.runtime' . DIRECTORY_SEPARATOR . 'auto-scraper';
}

if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0775, true) && !is_dir($runtimeDir)) {
    fwrite(STDERR, "Unable to create runtime directory: {$runtimeDir}\n");
    exit(1);
}

$stateFile = $runtimeDir . DIRECTORY_SEPARATOR . 'service_state.json';
$logFile = $runtimeDir . DIRECTORY_SEPARATOR . 'service.log';
$phpBinary = PHP_BINARY ?: 'php';
$runnerScript = __DIR__ . DIRECTORY_SEPARATOR . 'auto_scraper_once.php';

function logAutoScraperService(string $message, string $logFile): void
{
    $line = '[' . date('Y-m-d H:i:s') . "] {$message}";
    echo $line . PHP_EOL;
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

function sleepMilliseconds(int $milliseconds): void
{
    if ($milliseconds < 0) {
        $milliseconds = 0;
    }

    usleep($milliseconds * 1000);
}

function saveAutoScraperState(string $stateFile, array $state): void
{
    @file_put_contents(
        $stateFile,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function createFreshAutoScraperPdo(): PDO
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

function getAutoScraperMeta(): array
{
    $pdo = createFreshAutoScraperPdo();
    $stmt = $pdo->query("SELECT COUNT(*) AS active_count, MIN(NULLIF(interval_seconds, 0)) AS min_interval FROM scraper_settings WHERE is_active = 1");
    $row = $stmt->fetch() ?: ['active_count' => 0, 'min_interval' => null];

    return [
        'active_count' => (int) ($row['active_count'] ?? 0),
        'min_interval' => isset($row['min_interval']) ? (int) $row['min_interval'] : null
    ];
}

function decodeAutoScraperPayload(string $raw): ?array
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

function invokeAutoScraper(string $phpBinary, string $runnerScript): array
{
    $command = implode(' ', [
        escapeshellarg($phpBinary),
        escapeshellarg($runnerScript)
    ]);

    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    $raw = trim(implode(PHP_EOL, $output));
    $payload = decodeAutoScraperPayload($raw);

    return [
        'exit_code' => $exitCode,
        'raw' => $raw,
        'payload' => $payload
    ];
}

function chooseAutoScraperSleep(array $meta): int
{
    if (($meta['active_count'] ?? 0) <= 0) {
        return AUTO_SCRAPER_IDLE_MS;
    }

    $minInterval = $meta['min_interval'] ?? null;
    if ($minInterval === null || $minInterval <= 0) {
        return AUTO_SCRAPER_POLL_MS;
    }

    return max(15000, min(AUTO_SCRAPER_IDLE_MS, $minInterval * 1000));
}

$state = [
    'last_run_at' => null,
    'last_server_time' => null,
    'last_results_count' => 0,
    'last_message' => null,
    'active_tasks' => 0
];

logAutoScraperService('Auto Scraper service booted.', $logFile);

while (true) {
    try {
        $meta = getAutoScraperMeta();
    } catch (Throwable $e) {
        logAutoScraperService('Database check failed: ' . $e->getMessage(), $logFile);
        sleepMilliseconds(AUTO_SCRAPER_ERROR_MS);
        continue;
    }

    $state['active_tasks'] = (int) ($meta['active_count'] ?? 0);

    $result = invokeAutoScraper($phpBinary, $runnerScript);
    $payload = $result['payload'];

    if (!is_array($payload)) {
        logAutoScraperService('Runner returned invalid output: ' . $result['raw'], $logFile);
        sleepMilliseconds(AUTO_SCRAPER_ERROR_MS);
        continue;
    }

    $results = isset($payload['results']) && is_array($payload['results']) ? $payload['results'] : [];
    $message = isset($payload['message']) ? (string) $payload['message'] : null;

    $state['last_run_at'] = date('c');
    $state['last_server_time'] = (string) ($payload['server_time'] ?? date('Y-m-d H:i:s'));
    $state['last_results_count'] = count($results);
    $state['last_message'] = $message;
    saveAutoScraperState($stateFile, $state);

    if (count($results) > 0) {
        logAutoScraperService('Executed ' . count($results) . ' scheduled task(s).', $logFile);
    } elseif ($message === 'Auto Scraper is already running.') {
        logAutoScraperService('Skipped this cycle because another Auto Scraper process already holds the lock.', $logFile);
    }

    sleepMilliseconds(chooseAutoScraperSleep($meta));
}
