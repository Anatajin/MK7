<?php
// Copy this file to config.php and set production values through environment variables.

// Security & Production Settings
error_reporting(0);
ini_set('display_errors', 0);

// Global Security Headers
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Allow public entry points, but block direct access to private project internals.
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
$normalizedUri = parse_url(str_replace('\\', '/', $requestUri), PHP_URL_PATH) ?: '/';
$normalizedFile = str_replace('\\', '/', $scriptFilename);
$projectRoot = str_replace('\\', '/', __DIR__);
$relativeFile = ltrim(str_replace($projectRoot, '', $normalizedFile), '/');

$isCli = php_sapi_name() === 'cli';
$blockedPrefixes = [
    'includes/',
    'deploy/',
    'database/',
    'node_modules/',
    '.puppeteer-runtime/',
    '.puppeteer-cache/',
];
$blockedFiles = [
    'config.php',
    '403.php',
];

$isBlockedPrefix = false;
foreach ($blockedPrefixes as $prefix) {
    if (stripos($relativeFile, $prefix) === 0) {
        $isBlockedPrefix = true;
        break;
    }
}

$isBlockedFile = in_array(strtolower(basename($relativeFile)), $blockedFiles, true);
$isPrivateWebRequest = !$isCli && ($isBlockedPrefix || $isBlockedFile);

if ($isPrivateWebRequest) {
    require '403.php';
    exit;
}

function sport_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : trim((string) $value);
}

define('DB_HOST', sport_env('DB_HOST', 'localhost'));
define('DB_NAME', sport_env('DB_NAME', 'football_app'));
define('DB_USER', sport_env('DB_USER', 'root'));
define('DB_PASS', sport_env('DB_PASS', ''));

define('APP_TIMEZONE', sport_env('APP_TIMEZONE', 'UTC'));
define('MATCH_STORAGE_TIMEZONE', sport_env('MATCH_STORAGE_TIMEZONE', 'UTC'));
define('SPORT_CAST_APP_ID', sport_env('SPORT_CAST_APP_ID', ''));
define('SPORT_INTERNAL_API_TOKEN', sport_env('SPORT_INTERNAL_API_TOKEN', 'change-me'));

// Use a 64-character hex string in production. Do not commit the real value.
define('ENCRYPTION_KEY', sport_env('ENCRYPTION_KEY', 'change-me-change-me-change-me-change-me'));

// Set Timezone to ensure consistency between PHP and DB
date_default_timezone_set(APP_TIMEZONE);

function sport_encryption_key(): string
{
    static $binaryKey = null;

    if ($binaryKey !== null) {
        return $binaryKey;
    }

    $keyMaterial = (string) ENCRYPTION_KEY;
    $decodedHex = false;

    if ($keyMaterial !== '' && ctype_xdigit($keyMaterial) && strlen($keyMaterial) % 2 === 0) {
        $decodedHex = hex2bin($keyMaterial);
    }

    $source = $decodedHex !== false ? $decodedHex : $keyMaterial;
    $binaryKey = hash('sha256', $source, true);

    return $binaryKey;
}

function sport_username_hash(string $username): string
{
    return hash_hmac('sha256', strtolower(trim($username)), sport_encryption_key());
}

function sport_encrypt_username(string $username): string
{
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = random_bytes($ivLength);
    $encrypted = openssl_encrypt($username, 'aes-256-cbc', sport_encryption_key(), OPENSSL_RAW_DATA, $iv);

    if ($encrypted === false) {
        throw new RuntimeException('Unable to encrypt username.');
    }

    return base64_encode($iv . '::' . $encrypted);
}

function sport_decrypt_username(string $stored): string
{
    $decoded = base64_decode($stored, true);
    if ($decoded === false) {
        return '';
    }

    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    if (strlen($decoded) <= ($ivLength + 2)) {
        return '';
    }

    $iv = substr($decoded, 0, $ivLength);
    $separator = substr($decoded, $ivLength, 2);
    $cipherText = substr($decoded, $ivLength + 2);

    if ($separator !== '::') {
        return '';
    }

    $decrypted = openssl_decrypt($cipherText, 'aes-256-cbc', sport_encryption_key(), OPENSSL_RAW_DATA, $iv);

    return $decrypted === false ? '' : $decrypted;
}

// Connect to Database
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    die("Database Connection Failed.");
}
?>
