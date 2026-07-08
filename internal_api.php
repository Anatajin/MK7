<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');

function sportInternalJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sportInternalHeaderValue(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$serverKey] ?? ''));
}

function sportInternalRequestPayload(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return $_POST;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sportInternalJsonResponse(405, [
        'status' => 'error',
        'message' => 'Method not allowed.'
    ]);
}

$token = sportInternalHeaderValue('X-Internal-Token');
if ($token === '') {
    $authHeader = sportInternalHeaderValue('Authorization');
    if (stripos($authHeader, 'Bearer ') === 0) {
        $token = trim(substr($authHeader, 7));
    }
}

if ($token === '' || !hash_equals(SPORT_INTERNAL_API_TOKEN, $token)) {
    sportInternalJsonResponse(403, [
        'status' => 'error',
        'message' => 'Forbidden.'
    ]);
}

$payload = sportInternalRequestPayload();
$action = trim((string)($payload['action'] ?? ''));

if ($action === '') {
    sportInternalJsonResponse(400, [
        'status' => 'error',
        'message' => 'Missing action.'
    ]);
}

$db = new Database($pdo);

try {
    if ($action === 'provision_api_key') {
        $result = $db->provisionOwnedApiKey([
            'owner_platform' => trim((string)($payload['owner_platform'] ?? 'bloge')),
            'owner_user_id' => (int)($payload['owner_user_id'] ?? 0),
            'owner_email' => trim((string)($payload['owner_email'] ?? '')),
            'owner_name' => trim((string)($payload['owner_name'] ?? '')),
            'owner_plan_slug' => trim((string)($payload['owner_plan_slug'] ?? '')),
            'owner_plan_name' => trim((string)($payload['owner_plan_name'] ?? '')),
            'owner_resource_id' => trim((string)($payload['owner_resource_id'] ?? '')),
            'existing_key_id' => (int)($payload['existing_key_id'] ?? 0),
            'plan_type' => trim((string)($payload['plan_type'] ?? 'free')),
            'subscription_expires_at' => trim((string)($payload['subscription_expires_at'] ?? '')),
            'origin' => trim((string)($payload['origin'] ?? '')),
            'key_name' => trim((string)($payload['key_name'] ?? '')),
            'allowed_country_names' => (array)($payload['allowed_country_names'] ?? []),
            'allowed_league_ids' => (array)($payload['allowed_league_ids'] ?? []),
            'restrict_country_scope' => (int)($payload['restrict_country_scope'] ?? 0),
            'restrict_league_scope' => (int)($payload['restrict_league_scope'] ?? 0),
        ]);

        if (!$result) {
            throw new RuntimeException('Unable to provision API access.');
        }

        sportInternalJsonResponse(200, [
            'status' => 'ok',
            'data' => [
                'key_id' => (int)($result['id'] ?? 0),
                'api_key' => (string)($result['api_key'] ?? ''),
                'key_name' => (string)($result['name'] ?? ''),
                'allowed_origin_id' => (int)($result['allowed_origin_id'] ?? 0),
                'allowed_origin' => (string)($result['allowed_domain'] ?? ''),
                'plan_type' => (string)($result['plan_type'] ?? ''),
                'allowed_country_names' => array_values(array_map('strval', (array)($result['allowed_country_names'] ?? []))),
                'allowed_league_ids' => array_values(array_map('intval', (array)($result['allowed_league_ids'] ?? []))),
                'restrict_country_scope' => !empty($result['restrict_country_scope']) ? 1 : 0,
                'restrict_league_scope' => !empty($result['restrict_league_scope']) ? 1 : 0,
                'expires_at' => (string)($result['expires_at'] ?? ''),
                'created_at' => (string)($result['created_at'] ?? ''),
            ]
        ]);
    }

    if ($action === 'delete_api_key') {
        $result = $db->deleteOwnedApiKey([
            'owner_platform' => trim((string)($payload['owner_platform'] ?? 'bloge')),
            'owner_user_id' => (int)($payload['owner_user_id'] ?? 0),
            'owner_resource_id' => trim((string)($payload['owner_resource_id'] ?? '')),
            'existing_key_id' => (int)($payload['existing_key_id'] ?? 0),
        ]);

        sportInternalJsonResponse(200, [
            'status' => 'ok',
            'data' => $result,
        ]);
    }

    if ($action === 'get_user_api_stats') {
        $ownerPlatform = trim((string)($payload['owner_platform'] ?? 'bloge'));
        $ownerUserId = (int)($payload['owner_user_id'] ?? 0);
        $ownerPlanSlug = trim((string)($payload['owner_plan_slug'] ?? ''));

        if ($ownerUserId <= 0) {
            throw new InvalidArgumentException('Missing owner user id.');
        }

        sportInternalJsonResponse(200, [
            'status' => 'ok',
            'data' => $db->getOwnedApiDashboard($ownerPlatform, $ownerUserId, $ownerPlanSlug)
        ]);
    }

    if ($action === 'get_api_filters_catalog') {
        sportInternalJsonResponse(200, [
            'status' => 'ok',
            'data' => $db->getApiFiltersCatalog()
        ]);
    }

    sportInternalJsonResponse(400, [
        'status' => 'error',
        'message' => 'Unknown action.'
    ]);
} catch (Throwable $e) {
    sportInternalJsonResponse(422, [
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
