<?php
require_once __DIR__ . '/includes/EmbedAccess.php';

error_reporting(0);
ini_set('display_errors', 0);

const STREAM_PROXY_MANIFEST_TTL = 2;
const STREAM_PROXY_SEGMENT_TTL = 45;
const STREAM_PROXY_KEY_TTL = 600;
const STREAM_PROXY_BINARY_CACHE_MAX_BYTES = 8388608;

function decodeUrlSafeBase64Param($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? '' : trim((string)$decoded);
}

function encodeUrlSafeBase64Param($value) {
    return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
}

function renderStreamProxyStrokePage($statusCode = 403) {
    http_response_code((int)$statusCode > 0 ? (int)$statusCode : 403);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Haya Shoout</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
            background: #ffffff;
            overflow: hidden;
            font-family: 'Outfit', sans-serif;
        }

        body {
            min-height: 100vh;
        }

        .stroke-page {
            width: 100%;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
        }

        .stroke-wrap {
            width: 100%;
            height: 100vh;
            direction: ltr;
        }

        #strokeChart {
            width: 100%;
            height: 100%;
        }
    </style>
</head>
<body>
    <main class="stroke-page">
        <div class="stroke-wrap">
            <div id="strokeChart" aria-label="Haya Shoout Stroke"></div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    <script>
        (function () {
            const chartDom = document.getElementById('strokeChart');
            if (!chartDom || !window.echarts) {
                return;
            }

            const strokeChart = echarts.init(chartDom, null, { renderer: 'svg' });

            function buildStrokeOption() {
                const width = chartDom.clientWidth || window.innerWidth;
                const height = chartDom.clientHeight || window.innerHeight;
                const mainSize = Math.max(70, Math.min(210, Math.round(Math.min(width, height) * 0.2)));
                const dashWidth = Math.max(320, Math.round(mainSize * 4.6));

                return {
                    backgroundColor: '#ffffff',
                    animation: true,
                    graphic: {
                        elements: [
                            {
                                type: 'text',
                                x: width / 2,
                                y: height / 2,
                                silent: true,
                                style: {
                                    x: 0,
                                    y: 0,
                                    text: 'Haya Shoout',
                                    fontSize: mainSize,
                                    fontWeight: 800,
                                    fontFamily: 'Outfit',
                                    textAlign: 'center',
                                    textVerticalAlign: 'middle',
                                    fill: 'transparent',
                                    stroke: '#0f172a',
                                    lineWidth: 2,
                                    lineDash: [0, dashWidth],
                                    lineDashOffset: 0
                                },
                                keyframeAnimation: {
                                    duration: 3200,
                                    loop: true,
                                    keyframes: [
                                        {
                                            percent: 0.72,
                                            style: {
                                                fill: 'transparent',
                                                lineDash: [dashWidth, 0],
                                                lineDashOffset: dashWidth
                                            }
                                        },
                                        {
                                            percent: 0.84,
                                            style: {
                                                fill: 'transparent'
                                            }
                                        },
                                        {
                                            percent: 1,
                                            style: {
                                                fill: '#0f172a'
                                            }
                                        }
                                    ]
                                }
                            }
                        ]
                    }
                };
            }

            function renderStrokeChart() {
                strokeChart.setOption(buildStrokeOption(), true);
            }

            renderStrokeChart();
            window.addEventListener('resize', () => {
                strokeChart.resize();
                renderStrokeChart();
            });
        })();
    </script>
</body>
</html>
    <?php
    exit;
}

function getStreamProxyCacheRoot() {
    return __DIR__ . '/.monitoring/stream_proxy_cache';
}

function ensureStreamProxyCacheDirectory($directory) {
    if (is_dir($directory)) {
        return true;
    }

    return @mkdir($directory, 0775, true) || is_dir($directory);
}

function buildStreamProxyCacheKey($kind, $targetUrl, $referer, $scope = '') {
    return hash('sha256', implode('|', [$kind, $targetUrl, $referer, $scope]));
}

function getStreamProxyCachePaths($cacheKey) {
    $directory = getStreamProxyCacheRoot() . '/' . substr($cacheKey, 0, 2);
    $baseName = substr($cacheKey, 2);

    return [
        'directory' => $directory,
        'body' => $directory . '/' . $baseName . '.body',
        'meta' => $directory . '/' . $baseName . '.json',
    ];
}

function readStreamProxyCache($cacheKey, $ttl) {
    $paths = getStreamProxyCachePaths($cacheKey);
    if (!is_file($paths['body']) || !is_file($paths['meta'])) {
        return null;
    }

    $metaRaw = @file_get_contents($paths['meta']);
    $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : null;
    $createdAt = (int)($meta['created_at'] ?? 0);
    if ($createdAt <= 0 || (time() - $createdAt) > $ttl) {
        @unlink($paths['body']);
        @unlink($paths['meta']);
        return null;
    }

    return [
        'body_path' => $paths['body'],
        'content_type' => trim((string)($meta['content_type'] ?? 'application/octet-stream')),
        'size' => (int)($meta['size'] ?? (is_file($paths['body']) ? filesize($paths['body']) : 0)),
    ];
}

function writeStreamProxyCacheBody($cacheKey, $body, $contentType) {
    $paths = getStreamProxyCachePaths($cacheKey);
    if (!ensureStreamProxyCacheDirectory($paths['directory'])) {
        return false;
    }

    $tempBody = tempnam($paths['directory'], 'spc_');
    $tempMeta = tempnam($paths['directory'], 'spm_');
    if ($tempBody === false || $tempMeta === false) {
        if ($tempBody !== false) {
            @unlink($tempBody);
        }
        if ($tempMeta !== false) {
            @unlink($tempMeta);
        }
        return false;
    }

    $written = @file_put_contents($tempBody, $body, LOCK_EX);
    if ($written === false) {
        @unlink($tempBody);
        @unlink($tempMeta);
        return false;
    }

    $meta = json_encode([
        'created_at' => time(),
        'content_type' => trim((string)$contentType),
        'size' => strlen((string)$body),
    ]);
    if ($meta === false || @file_put_contents($tempMeta, $meta, LOCK_EX) === false) {
        @unlink($tempBody);
        @unlink($tempMeta);
        return false;
    }

    @rename($tempBody, $paths['body']);
    @rename($tempMeta, $paths['meta']);
    return true;
}

function createStreamProxyCacheTempFile($cacheKey) {
    $paths = getStreamProxyCachePaths($cacheKey);
    if (!ensureStreamProxyCacheDirectory($paths['directory'])) {
        return '';
    }

    $tempPath = tempnam($paths['directory'], 'spb_');
    return $tempPath === false ? '' : $tempPath;
}

function finalizeStreamProxyCacheTempFile($cacheKey, $tempPath, $contentType, $size) {
    if ($tempPath === '' || !is_file($tempPath)) {
        return false;
    }

    $paths = getStreamProxyCachePaths($cacheKey);
    if (!ensureStreamProxyCacheDirectory($paths['directory'])) {
        @unlink($tempPath);
        return false;
    }

    $tempMeta = tempnam($paths['directory'], 'spm_');
    if ($tempMeta === false) {
        @unlink($tempPath);
        return false;
    }

    $meta = json_encode([
        'created_at' => time(),
        'content_type' => trim((string)$contentType),
        'size' => (int)$size,
    ]);
    if ($meta === false || @file_put_contents($tempMeta, $meta, LOCK_EX) === false) {
        @unlink($tempPath);
        @unlink($tempMeta);
        return false;
    }

    @rename($tempPath, $paths['body']);
    @rename($tempMeta, $paths['meta']);
    return true;
}

function detectStreamProxyBinaryProfile($targetUrl) {
    $path = strtolower((string)(parse_url($targetUrl, PHP_URL_PATH) ?: ''));

    if (preg_match('~\.(key|bin)$~', $path) || strpos($path, '/key') !== false || strpos($path, 'decrypt') !== false) {
        return ['kind' => 'key', 'ttl' => STREAM_PROXY_KEY_TTL];
    }

    if (preg_match('~\.(ts|m4s|mp4|m4a|aac|mp3|vtt|webvtt|jpg|jpeg|png|gif)($|/)~', $path)) {
        return ['kind' => 'segment', 'ttl' => STREAM_PROXY_SEGMENT_TTL];
    }

    return ['kind' => 'binary', 'ttl' => 20];
}

function isAllowedProxyHost($host) {
    $host = strtolower(trim((string)$host));
    if ($host === '') {
        return false;
    }

    if (preg_match('/(^|\\.)\\d{8}\\.net$/', $host)) {
        return true;
    }

    if (preg_match('/(^|\\.)hls-[a-z0-9-]+\\.live$/', $host)) {
        return true;
    }

    $allowedSuffixes = [
        'taktikora.live',
        'simosports.live',
        'kora-top.zip',
        'kora-plus.dad',
        '58103793.net',
        'smartagro.mov',
        '000003.mov',
        'yalla-shoot.mov',
        'totalsportekx.top',
        'korasimo.com',
    ];

    foreach ($allowedSuffixes as $suffix) {
        if ($host === $suffix || substr($host, -strlen('.' . $suffix)) === '.' . $suffix) {
            return true;
        }
    }

    return false;
}

function resolveProxyUrlAgainstBase($url, $baseUrl) {
    $url = trim((string)$url);
    $baseUrl = trim((string)$baseUrl);

    if ($url === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    if (strpos($url, '//') === 0) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $url;
    }

    $parts = parse_url($baseUrl);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $scheme = $parts['scheme'];
    $host = $parts['host'];
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    if (strpos($url, '/') === 0) {
        return $scheme . '://' . $host . $port . $url;
    }

    $path = $parts['path'] ?? '/';
    $directory = preg_replace('#/[^/]*$#', '/', $path);
    return $scheme . '://' . $host . $port . $directory . $url;
}

function buildProxyUrl($targetUrl, $referer, array $accessContext = []) {
    $params = [
        'url' => encodeUrlSafeBase64Param($targetUrl),
    ];

    if ($referer !== '') {
        $params['ref'] = encodeUrlSafeBase64Param($referer);
    }

    $mode = (string)($accessContext['mode'] ?? '');
    $expiresAt = (int)($accessContext['expires_at'] ?? 0);
    $ttl = $expiresAt > 0 ? max(120, $expiresAt - time()) : null;

    if ($mode === 'cast_signed') {
        $params = embedAccessBuildCastSignedQuery($params, $ttl);
    } else {
        $signOrigin = embedAccessNormalizeOrigin($accessContext['allowed_origin'] ?? null)
            ?: embedAccessNormalizeOrigin($accessContext['incoming_origin'] ?? null);
        if ($signOrigin !== null) {
            $params = embedAccessBuildSignedQuery($params, $signOrigin, $ttl);
        }
    }

    return '/stream_proxy.php?' . http_build_query($params);
}

function getDefaultStreamReferer($targetUrl) {
    $host = strtolower((string)(parse_url($targetUrl, PHP_URL_HOST) ?: ''));
    if ($host === '') {
        return '';
    }

    if ($host === 'taktikora.live' || substr($host, -strlen('.taktikora.live')) === '.taktikora.live') {
        return 'https://1.simosports.live/';
    }

    if ($host === 'kora-plus.dad' || substr($host, -strlen('.kora-plus.dad')) === '.kora-plus.dad') {
        return 'https://vsys.kora-top.zip/';
    }

    return '';
}

function isTrustedUnsignedStreamProxyReferer($referer) {
    $referer = trim((string)$referer);
    if ($referer === '') {
        return false;
    }

    $parts = parse_url($referer);
    if (!$parts || empty($parts['host'])) {
        return false;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
    $host = strtolower((string)$parts['host']);
    $port = isset($parts['port']) ? (int)$parts['port'] : null;
    $origin = $scheme . '://' . $host;

    if ($port !== null) {
        $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        if (!$isDefaultPort) {
            $origin .= ':' . $port;
        }
    }

    if (!embedAccessIsInternalOrigin($origin)) {
        return false;
    }

    $path = strtolower((string)($parts['path'] ?? ''));
    return in_array($path, [
        '/stream_player.php',
        '/stream_proxy.php',
        '/cast_media.php',
    ], true);
}

function rewriteManifestLine($line, $baseUrl, $referer, array $accessContext = []) {
    $trimmed = trim((string)$line);
    if ($trimmed === '' || strpos($trimmed, '#') === 0) {
        if (preg_match('/URI="([^"]+)"/i', $line, $matches)) {
            $absolute = resolveProxyUrlAgainstBase($matches[1], $baseUrl);
            if ($absolute !== '' && isAllowedProxyHost((string)parse_url($absolute, PHP_URL_HOST))) {
                $line = str_replace($matches[1], buildProxyUrl($absolute, $referer, $accessContext), $line);
            }
        }
        return $line;
    }

    $absolute = resolveProxyUrlAgainstBase($trimmed, $baseUrl);
    if ($absolute === '' || !isAllowedProxyHost((string)parse_url($absolute, PHP_URL_HOST))) {
        return $line;
    }

    return buildProxyUrl($absolute, $referer, $accessContext);
}

$targetUrl = decodeUrlSafeBase64Param($_GET['url'] ?? '');
$referer = decodeUrlSafeBase64Param($_GET['ref'] ?? '');
$incomingOrigin = embedAccessResolveIncomingOrigin();
$embedAccess = embedAccessVerifyRequest($_GET, $incomingOrigin, true);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($embedAccess['ok'] && !empty($embedAccess['incoming_origin'])) {
    header('Access-Control-Allow-Origin: ' . $embedAccess['incoming_origin']);
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code($embedAccess['ok'] ? 204 : 403);
    exit;
}

if (!$embedAccess['ok']) {
    renderStreamProxyStrokePage(403);
}

if ((string)($embedAccess['mode'] ?? '') === 'public_unsigned') {
    $requestReferer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if (!isTrustedUnsignedStreamProxyReferer($requestReferer)) {
        renderStreamProxyStrokePage(403);
    }
}

if (!preg_match('#^https?://#i', $targetUrl)) {
    renderStreamProxyStrokePage(400);
}

$targetHost = (string)(parse_url($targetUrl, PHP_URL_HOST) ?: '');
if (!isAllowedProxyHost($targetHost)) {
    renderStreamProxyStrokePage(403);
}

if ($referer === '') {
    $referer = getDefaultStreamReferer($targetUrl);
}

$isManifestRequest = preg_match('~\.m3u8($|[?\#])~i', $targetUrl) === 1;
$manifestCacheKey = '';
$binaryCacheKey = '';
$binaryCacheProfile = null;

if ($isManifestRequest) {
    $manifestScope = implode('|', [
        (string)($embedAccess['mode'] ?? ''),
        (string)($embedAccess['allowed_origin'] ?? ''),
    ]);
    $manifestCacheKey = buildStreamProxyCacheKey('manifest', $targetUrl, $referer, $manifestScope);
    $manifestCacheEntry = readStreamProxyCache($manifestCacheKey, STREAM_PROXY_MANIFEST_TTL);
    if ($manifestCacheEntry && is_file($manifestCacheEntry['body_path'])) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: application/vnd.apple.mpegurl');
        header('X-Stream-Proxy-Cache: HIT');
        readfile($manifestCacheEntry['body_path']);
        exit;
    }
} else {
    $binaryCacheProfile = detectStreamProxyBinaryProfile($targetUrl);
    $binaryCacheKey = buildStreamProxyCacheKey($binaryCacheProfile['kind'], $targetUrl, $referer);
    $binaryCacheEntry = readStreamProxyCache($binaryCacheKey, (int)$binaryCacheProfile['ttl']);
    if ($binaryCacheEntry && is_file($binaryCacheEntry['body_path'])) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Accel-Buffering: no');
        header('X-Stream-Proxy-Cache: HIT');
        if ($binaryCacheEntry['content_type'] !== '') {
            header('Content-Type: ' . $binaryCacheEntry['content_type']);
        }
        if ($binaryCacheEntry['size'] > 0) {
            header('Content-Length: ' . $binaryCacheEntry['size']);
        }
        readfile($binaryCacheEntry['body_path']);
        exit;
    }
}

$headers = [];
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, $isManifestRequest);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_ENCODING, '');
curl_setopt($ch, CURLOPT_BUFFERSIZE, 16384);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
if (defined('CURL_HTTP_VERSION_2TLS')) {
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
}
if (defined('CURLOPT_TCP_NODELAY')) {
    curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
}
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$headers) {
    $length = strlen($line);
    $trimmed = trim($line);

    if ($trimmed === '') {
        return $length;
    }

    if (stripos($trimmed, 'HTTP/') === 0) {
        $headers = [];
        return $length;
    }

    $separatorPos = strpos($trimmed, ':');
    if ($separatorPos !== false) {
        $name = strtolower(trim(substr($trimmed, 0, $separatorPos)));
        $value = trim(substr($trimmed, $separatorPos + 1));
        $headers[$name] = $value;
    }

    return $length;
});

$requestHeaders = [
    'Accept: */*',
];

if ($referer !== '' && preg_match('#^https?://#i', $referer)) {
    curl_setopt($ch, CURLOPT_REFERER, $referer);
    $originParts = parse_url($referer);
    if (!empty($originParts['scheme']) && !empty($originParts['host'])) {
        $origin = $originParts['scheme'] . '://' . $originParts['host'];
        if (!empty($originParts['port'])) {
            $origin .= ':' . $originParts['port'];
        }
        $requestHeaders[] = 'Origin: ' . $origin;
    }
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

$streamedResponseFailed = false;
$streamedCacheFailed = false;
$streamedCacheBytes = 0;
$streamedCachePath = '';
$streamedCacheHandle = null;
if (!$isManifestRequest) {
    header('Content-Type: application/octet-stream');
    header('X-Accel-Buffering: no');
    header('X-Stream-Proxy-Cache: MISS');
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    ob_implicit_flush(true);

    if ($binaryCacheKey !== '') {
        $streamedCachePath = createStreamProxyCacheTempFile($binaryCacheKey);
        if ($streamedCachePath !== '') {
            $streamedCacheHandle = @fopen($streamedCachePath, 'wb');
            if (!$streamedCacheHandle) {
                @unlink($streamedCachePath);
                $streamedCachePath = '';
            }
        }
    }

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $chunk) use (&$streamedResponseFailed, &$streamedCacheHandle, &$streamedCachePath, &$streamedCacheFailed, &$streamedCacheBytes) {
        $length = strlen($chunk);
        if ($length === 0) {
            return 0;
        }

        if ($streamedCacheHandle && !$streamedCacheFailed) {
            $streamedCacheBytes += $length;
            if ($streamedCacheBytes > STREAM_PROXY_BINARY_CACHE_MAX_BYTES || @fwrite($streamedCacheHandle, $chunk) === false) {
                $streamedCacheFailed = true;
                @fclose($streamedCacheHandle);
                $streamedCacheHandle = null;
                if ($streamedCachePath !== '') {
                    @unlink($streamedCachePath);
                    $streamedCachePath = '';
                }
            }
        }

        echo $chunk;
        if (function_exists('flush')) {
            @flush();
        }

        if (connection_status() !== CONNECTION_NORMAL) {
            $streamedResponseFailed = true;
            return 0;
        }

        return $length;
    });
}

$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$contentType = trim((string)($headers['content-type'] ?? curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? 'application/octet-stream'));
curl_close($ch);

if ($streamedCacheHandle) {
    @fclose($streamedCacheHandle);
}

if (($isManifestRequest && $body === false) || (!$isManifestRequest && $streamedResponseFailed) || $status >= 400) {
    if ($streamedCachePath !== '') {
        @unlink($streamedCachePath);
    }
    http_response_code($status > 0 ? $status : 502);
    echo 'Unable to load stream.';
    exit;
}

$isManifest = $isManifestRequest
    || stripos($contentType, 'application/vnd.apple.mpegurl') !== false
    || stripos($contentType, 'application/x-mpegurl') !== false
    || preg_match('~\.m3u8($|[?\#])~i', $effectiveUrl)
    || ($isManifestRequest && strpos(ltrim((string)$body), '#EXTM3U') === 0);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($isManifest) {
    header('Content-Type: application/vnd.apple.mpegurl');
    header('X-Stream-Proxy-Cache: MISS');
    $lines = preg_split("/\r\n|\n|\r/", (string)$body);
    $rewritten = [];
    foreach ($lines as $line) {
        $rewritten[] = rewriteManifestLine($line, $effectiveUrl !== '' ? $effectiveUrl : $targetUrl, $referer, $embedAccess);
    }
    $manifestBody = implode("\n", $rewritten);
    if ($manifestCacheKey !== '') {
        writeStreamProxyCacheBody($manifestCacheKey, $manifestBody, 'application/vnd.apple.mpegurl');
    }
    echo $manifestBody;
    exit;
}

if ($contentType !== '') {
    header('Content-Type: ' . $contentType);
}

if ($isManifestRequest) {
    echo $body;
    exit;
}

if ($streamedCachePath !== '' && !$streamedCacheFailed && $binaryCacheKey !== '') {
    finalizeStreamProxyCacheTempFile($binaryCacheKey, $streamedCachePath, $contentType, $streamedCacheBytes);
}

exit;
