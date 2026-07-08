<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/EmbedAccess.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Scraper.php';

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function streamRefreshRespond($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function streamRefreshExtractIframeSrc($html) {
    $html = trim((string)$html);
    if ($html === '' || stripos($html, '<iframe') === false) {
        return null;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
        return null;
    }

    $iframe = $dom->getElementsByTagName('iframe')->item(0);
    if (!$iframe) {
        return null;
    }

    $src = trim((string)$iframe->getAttribute('src'));
    return $src !== '' ? $src : null;
}

function streamRefreshDecodeLocalPlayerUrl($url) {
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return null;
    }

    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
    if (basename($path) !== 'stream_player.php') {
        return null;
    }

    $query = [];
    parse_str((string)(parse_url($url, PHP_URL_QUERY) ?: ''), $query);

    $encodedStream = trim((string)($query['stream'] ?? ''));
    if ($encodedStream === '') {
        return null;
    }

    $streamUrl = embedAccessBase64UrlDecode($encodedStream);
    if (!is_string($streamUrl) || trim($streamUrl) === '') {
        return null;
    }

    $ref = trim((string)($query['ref'] ?? ''));
    $referer = $ref !== '' ? embedAccessBase64UrlDecode($ref) : '';
    $matchId = (int)($query['match_id'] ?? 0);

    return [
        'stream_url' => trim($streamUrl),
        'referer' => is_string($referer) ? trim($referer) : '',
        'match_id' => $matchId,
        'player_url' => $url,
    ];
}

function streamRefreshExtractPayloadFromMatch(array $match) {
    foreach (['live_iframe', 'live_url'] as $field) {
        $value = trim((string)($match[$field] ?? ''));
        if ($value === '') {
            continue;
        }

        $src = streamRefreshExtractIframeSrc($value);
        if ($src !== null) {
            $decoded = streamRefreshDecodeLocalPlayerUrl($src);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        $decoded = streamRefreshDecodeLocalPlayerUrl($value);
        if ($decoded !== null) {
            return $decoded;
        }

        if (preg_match('#^https?://#i', $value)) {
            return [
                'stream_url' => $value,
                'referer' => '',
                'match_id' => (int)($match['id'] ?? 0),
                'player_url' => null,
            ];
        }
    }

    return null;
}

function streamRefreshExtractExpiryTimestamp($streamUrl) {
    $query = [];
    parse_str((string)(parse_url((string)$streamUrl, PHP_URL_QUERY) ?: ''), $query);

    foreach (['expires', 'exp'] as $key) {
        $candidate = trim((string)($query[$key] ?? ''));
        if ($candidate !== '' && ctype_digit($candidate)) {
            $timestamp = (int)$candidate;
            if ($timestamp > 1000000000) {
                return $timestamp;
            }
        }
    }

    return null;
}

function streamRefreshCurrentOrigin() {
    $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = $forwardedProto !== '' ? strtolower(explode(',', $forwardedProto)[0]) : (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443) ? 'https' : 'http');
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return null;
    }

    return $scheme . '://' . $host;
}

function streamRefreshBuildProxyUrl($streamUrl, $referer, array $accessContext = []) {
    $streamUrl = trim((string)$streamUrl);
    if ($streamUrl === '' || !preg_match('#^https?://#i', $streamUrl)) {
        return '';
    }

    $params = [
        'url' => embedAccessBase64UrlEncode($streamUrl),
    ];

    $referer = trim((string)$referer);
    if ($referer !== '' && preg_match('#^https?://#i', $referer)) {
        $params['ref'] = embedAccessBase64UrlEncode($referer);
    }

    $mode = (string)($accessContext['mode'] ?? '');
    $expiresAt = (int)($accessContext['expires_at'] ?? 0);
    $ttl = $expiresAt > 0 ? max(120, $expiresAt - time()) : 900;

    if ($mode === 'cast_signed') {
        $params = embedAccessBuildCastSignedQuery($params, $ttl);
    } else {
        $signOrigin = embedAccessNormalizeOrigin($accessContext['allowed_origin'] ?? null)
            ?: embedAccessNormalizeOrigin($accessContext['incoming_origin'] ?? null);
        if ($signOrigin !== null) {
            $params = embedAccessBuildSignedQuery($params, $signOrigin, $ttl);
        }
    }

    $origin = streamRefreshCurrentOrigin();
    $path = '/stream_proxy.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    if ($origin === null) {
        return $path;
    }

    return $origin . $path;
}

function streamRefreshShouldRenew($payload, $forceRefresh) {
    if ($forceRefresh) {
        return true;
    }

    if (!$payload || empty($payload['stream_url'])) {
        return true;
    }

    $expiresAt = streamRefreshExtractExpiryTimestamp($payload['stream_url']);
    if ($expiresAt === null) {
        return false;
    }

    return $expiresAt <= (time() + 180);
}

$incomingOrigin = embedAccessResolveIncomingOrigin();
$embedAccess = embedAccessVerifyRequest($_GET, $incomingOrigin, true);

if (!$embedAccess['ok']) {
    streamRefreshRespond([
        'status' => 'error',
        'message' => 'Unauthorized stream refresh request.',
        'reason' => $embedAccess['reason'] ?? 'forbidden',
    ], 403);
}

$matchId = (int)($_GET['match_id'] ?? 0);
if ($matchId <= 0) {
    streamRefreshRespond([
        'status' => 'error',
        'message' => 'Invalid match id.',
    ], 400);
}

$forceRefresh = (string)($_GET['force'] ?? '0') === '1';

try {
    $db = new Database($pdo);
    $match = $db->getMatchById($matchId);
    if (!$match) {
        streamRefreshRespond([
            'status' => 'error',
            'message' => 'Match not found.',
        ], 404);
    }

    $payload = streamRefreshExtractPayloadFromMatch($match);
    $refreshed = false;

    if (streamRefreshShouldRenew($payload, $forceRefresh)) {
        $scraper = new Scraper($db);
        $scraper->scrapeMatchLive($matchId);
        $match = $db->getMatchById($matchId);
        $payload = streamRefreshExtractPayloadFromMatch($match);
        $refreshed = true;
    }

    if (!$payload || empty($payload['stream_url'])) {
        streamRefreshRespond([
            'status' => 'error',
            'message' => 'No playable stream found.',
            'refreshed' => $refreshed,
        ], 404);
    }

    streamRefreshRespond([
        'status' => 'success',
        'refreshed' => $refreshed,
        'stream_url' => $payload['stream_url'],
        'referer' => $payload['referer'] ?? '',
        'proxy_url' => streamRefreshBuildProxyUrl($payload['stream_url'], $payload['referer'] ?? '', $embedAccess),
        'expires_at' => streamRefreshExtractExpiryTimestamp($payload['stream_url']),
        'match_id' => $matchId,
    ]);
} catch (Throwable $e) {
    streamRefreshRespond([
        'status' => 'error',
        'message' => 'Unable to refresh stream right now.',
    ], 500);
}
