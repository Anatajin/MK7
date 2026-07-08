<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once dirname(__DIR__) . '/config.php';
chdir(__DIR__);

$_GET = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/api/auto_scraper.php';
$_SERVER['REQUEST_URI'] = '/api/auto_scraper.php';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

ob_start();
include __DIR__ . '/auto_scraper.php';
$output = trim((string) ob_get_clean());

if ($output !== '') {
    echo $output;
}
