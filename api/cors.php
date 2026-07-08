<?php
/**
 * CORS and Security Configuration - WITH LOGGING
 * Aggressively enforces API access rules defined in the admin panel.
 */

// Use absolute paths
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';

// Initialize Database and get settings
$corsDb = new Database($pdo);
$apiSettings = $corsDb->getApiSettings();

// 1. CONFIGURATION
$corsEnabled = (string)($apiSettings['cors_enabled'] ?? '1') === '1';
$allowAllOrigins = (string)($apiSettings['allow_all_origins'] ?? '0') === '1';

// Current Request Data
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$origin = rtrim(trim($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
$uri = $_SERVER['REQUEST_URI'] ?? 'Unknown';

// --- LOGGING ---
$logFile = __DIR__ . '/cors_debug.log';
$logData = date('[Y-m-d H:i:s] ') . "Method: $method | URI: $uri | Origin: $origin\n";
$logData .= "Settings: cors_enabled=" . ($corsEnabled ? '1' : '0') . " | allowed_methods=" . ($apiSettings['allowed_methods'] ?? 'EMPTY') . "\n";

// 2. SECURITY BLOCKING
if ($corsEnabled || $allowAllOrigins) {
    $methodsStr = $apiSettings['allowed_methods'] ?? '';
    $allowedMethods = array_filter(array_map('trim', explode(',', strtoupper($methodsStr))));
    
    $isMethodAllowed = in_array($method, $allowedMethods);
    
    // Add debugging headers
    header("X-CORS-Status: Active");
    header("X-CORS-Allowed: " . ($methodsStr ?: "NONE"));

    if (!$isMethodAllowed && $method !== 'OPTIONS') {
        $logData .= "RESULT: BLOCKED (Method Not Allowed)\n\n";
        file_put_contents($logFile, $logData, FILE_APPEND);
        
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => "طريقة الطلب ($method) محظورة. المسموح: [" . implode(', ', $allowedMethods) . "]",
            'debug_info' => 'If this list is empty, all methods are blocked.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Origin Validation
    if (!$allowAllOrigins && !empty($origin)) {
        $dbOrigins = $corsDb->getAllowedOrigins();
        $whitelisted = [];
        foreach ($dbOrigins as $o) {
            if ((int)($o['is_active'] ?? 1) === 1) {
                $whitelisted[] = rtrim(trim($o['origin']), '/');
            }
        }

        $isSameOrigin = false;
        if (isset($_SERVER['HTTP_HOST']) && strpos($origin, $_SERVER['HTTP_HOST']) !== false) {
            $isSameOrigin = true;
        }

        if (!in_array($origin, $whitelisted) && !$isSameOrigin) {
            $logData .= "RESULT: BLOCKED (Origin Not Allowed)\n\n";
            file_put_contents($logFile, $logData, FILE_APPEND);
            
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'error',
                'message' => "النطاق ($origin) غير مصرح له.",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
    } elseif ($allowAllOrigins) {
        header('Access-Control-Allow-Origin: *');
    }

    $headersStr = $apiSettings['allowed_headers'] ?? '';
    $allowedHeaders = array_filter(array_map('trim', explode(',', $headersStr)));
    foreach ([
        'X-Timezone',
        'Timezone',
        'X-Auto-Timezone',
        'X-Visitor-IP',
        'X-End-User-IP',
        'X-Country-Code',
        'Country-Code',
        'X-Viewer-Country',
        'X-Appengine-Country',
        'CloudFront-Viewer-Country',
        'X-Geo-Country'
    ] as $requiredHeader) {
        if (!in_array($requiredHeader, $allowedHeaders, true)) {
            $allowedHeaders[] = $requiredHeader;
        }
    }
    $headersStr = implode(', ', array_filter($allowedHeaders));
    header("Access-Control-Allow-Methods: " . ($methodsStr ?: 'OPTIONS'));
    header("Access-Control-Allow-Headers: $headersStr");
    header('Access-Control-Max-Age: 86400');
}

$logData .= "RESULT: ALLOWED\n\n";
file_put_contents($logFile, $logData, FILE_APPEND);

// 3. PREFLIGHT HANDLING
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
