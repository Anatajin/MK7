<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/EmbedAccess.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Scraper.php';

error_reporting(0);
ini_set('display_errors', 0);

// CORS headers — required for Cast receiver on Google's domain.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function castMediaRespond($message, $status = 400) {
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

function castMediaExtractIframeSrc($html) {
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

function castMediaDecodeLocalPlayerUrl($url) {
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

    return [
        'stream_url' => trim($streamUrl),
        'referer' => is_string($referer) ? trim($referer) : '',
    ];
}

function castMediaExtractPayloadFromMatch(array $match) {
    foreach (['live_iframe', 'live_url'] as $field) {
        $value = trim((string)($match[$field] ?? ''));
        if ($value === '') {
            continue;
        }

        $src = castMediaExtractIframeSrc($value);
        if ($src !== null) {
            $decoded = castMediaDecodeLocalPlayerUrl($src);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        $decoded = castMediaDecodeLocalPlayerUrl($value);
        if ($decoded !== null) {
            return $decoded;
        }

        if (preg_match('#^https?://#i', $value)) {
            return [
                'stream_url' => $value,
                'referer' => '',
            ];
        }
    }

    return null;
}

function castMediaExtractExpiryTimestamp($streamUrl) {
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

function castMediaNeedsRefresh($payload) {
    if (!$payload || empty($payload['stream_url'])) {
        return true;
    }

    $expiresAt = castMediaExtractExpiryTimestamp($payload['stream_url']);
    if ($expiresAt === null) {
        return false;
    }

    return $expiresAt <= (time() + 180);
}

function castMediaEncodeParam($value) {
    return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
}

function castMediaBuildProxyUrl($targetUrl, $referer, $ttl = null) {
    $params = [
        'url' => castMediaEncodeParam($targetUrl),
    ];

    if ($referer !== '') {
        $params['ref'] = castMediaEncodeParam($referer);
    }

    $params = embedAccessBuildCastSignedQuery($params, $ttl);
    $scheme = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'));
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $path = '/stream_proxy.php?' . http_build_query($params);
    if ($host === '') {
        return $path;
    }
    return $scheme . '://' . $host . $path;
}

function castMediaExtractPayloadFromQuery() {
    $encodedStream = trim((string)($_GET['stream'] ?? ''));
    if ($encodedStream === '') {
        return null;
    }

    $streamUrl = embedAccessBase64UrlDecode($encodedStream);
    if (!is_string($streamUrl) || !preg_match('#^https?://#i', trim($streamUrl))) {
        return null;
    }

    $referer = '';
    $encodedReferer = trim((string)($_GET['ref'] ?? ''));
    if ($encodedReferer !== '') {
        $decodedReferer = embedAccessBase64UrlDecode($encodedReferer);
        if (is_string($decodedReferer)) {
            $referer = trim($decodedReferer);
        }
    }

    return [
        'stream_url' => trim($streamUrl),
        'referer' => $referer,
    ];
}

$incomingOrigin = embedAccessResolveIncomingOrigin();
$embedAccess = embedAccessVerifyRequest($_GET, $incomingOrigin, true);
if (!$embedAccess['ok']) {
    castMediaRespond('Unauthorized cast request.', 403);
}

$matchId = (int)($_GET['match_id'] ?? 0);
if ($matchId <= 0) {
    castMediaRespond('Invalid match id.', 400);
}

try {
    $payload = castMediaExtractPayloadFromQuery();

    $db = new Database($pdo);
    $match = $db->getMatchById($matchId);
    if (!$match && !$payload) {
        castMediaRespond('Match not found.', 404);
    }

    if (!$payload && $match) {
        $payload = castMediaExtractPayloadFromMatch($match);
    }

    if (castMediaNeedsRefresh($payload) && $match) {
        $scraper = new Scraper($db);
        $scraper->scrapeMatchLive($matchId);
        $match = $db->getMatchById($matchId);
        if ($match) {
            $refreshedPayload = castMediaExtractPayloadFromMatch($match);
            if ($refreshedPayload) {
                $payload = $refreshedPayload;
            }
        }
    }

    if (!$payload || empty($payload['stream_url'])) {
        castMediaRespond('No playable stream found.', 404);
    }

    $ttl = max(300, ((int)($embedAccess['expires_at'] ?? 0)) - time());
    $location = castMediaBuildProxyUrl($payload['stream_url'], $payload['referer'] ?? '', $ttl > 0 ? $ttl : null);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Location: ' . $location, true, 302);
    exit;
} catch (Throwable $e) {
    castMediaRespond('Unable to prepare cast stream.', 500);
}
