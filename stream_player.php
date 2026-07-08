<?php
require_once __DIR__ . '/includes/EmbedAccess.php';
require_once __DIR__ . '/config.php';

error_reporting(0);
ini_set('display_errors', 0);

$encodedStream = trim((string)($_GET['stream'] ?? ''));
$encodedReferer = trim((string)($_GET['ref'] ?? ''));
$title = trim((string)($_GET['title'] ?? 'Live Stream'));
$matchId = (int)($_GET['match_id'] ?? 0);
$autoCastRequested = (string)($_GET['autocast'] ?? '0') === '1';
$tvOnlyMode = (string)($_GET['tv_only'] ?? '0') === '1';

$streamUrl = '';
if ($encodedStream !== '') {
    $decoded = base64_decode(strtr($encodedStream, '-_', '+/'), true);
    if ($decoded !== false) {
        $streamUrl = trim((string)$decoded);
    }
}

$streamReferer = '';
if ($encodedReferer !== '') {
    $decodedReferer = base64_decode(strtr($encodedReferer, '-_', '+/'), true);
    if ($decodedReferer !== false) {
        $streamReferer = trim((string)$decodedReferer);
    }
}

$isValidStream = (bool)preg_match('#^https?://#i', $streamUrl);
$incomingOrigin = embedAccessResolveIncomingOrigin();
$embedAccess = embedAccessVerifyRequest($_GET, $incomingOrigin, true);
$frameAncestors = embedAccessBuildFrameAncestorsDirective($embedAccess['allowed_origin'] ?? null);
$showErrorState = !$embedAccess['ok'] || !$isValidStream;

function streamPlayerCurrentOrigin() {
    $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = $forwardedProto !== '' ? strtolower(explode(',', $forwardedProto)[0]) : (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443) ? 'https' : 'http');
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return null;
    }

    return $scheme . '://' . $host;
}

function streamPlayerBuildProxyUrl($streamUrl, $referer, array $accessContext = []) {
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

    $origin = streamPlayerCurrentOrigin();
    $path = '/stream_proxy.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    if ($origin === null) {
        return $path;
    }

    return $origin . $path;
}

$castMediaUrl = '';
$standaloneCastUrl = '';
$initialProxyUrl = '';
if (!$showErrorState) {
    $currentOrigin = streamPlayerCurrentOrigin();
    $initialProxyUrl = streamPlayerBuildProxyUrl($streamUrl, $streamReferer, $embedAccess);
    if ($currentOrigin !== null) {
        $standaloneParams = [
            'tv_only' => '1',
            'autocast' => '1',
        ];
        if ($encodedStream !== '') {
            $standaloneParams['stream'] = $encodedStream;
        }
        if ($encodedReferer !== '') {
            $standaloneParams['ref'] = $encodedReferer;
        }
        if ($title !== '') {
            $standaloneParams['title'] = $title;
        }
        if ($matchId > 0) {
            $standaloneParams['match_id'] = (string)$matchId;
        }
        $standaloneCastUrl = embedAccessSignCastLocalUrl(
            $currentOrigin . '/stream_player.php?' . http_build_query($standaloneParams, '', '&', PHP_QUERY_RFC3986),
            600
        );

        if ($matchId > 0) {
            $castParams = [
                'match_id' => (string)$matchId,
            ];

            // Route Chromecast through cast_media.php for live matches so the
            // receiver always gets a freshly refreshed playable URL instead of
            // a possibly expired tokenized HLS URL from the current player.
            if ($encodedStream !== '') {
                $castParams['stream'] = $encodedStream;
            }
            if ($encodedReferer !== '') {
                $castParams['ref'] = $encodedReferer;
            }

            $castMediaUrl = embedAccessSignCastLocalUrl(
                $currentOrigin . '/cast_media.php?' . http_build_query($castParams, '', '&', PHP_QUERY_RFC3986)
            );
        } elseif ($encodedStream !== '') {
            $castProxyParams = [
                'url' => $encodedStream,
            ];
            if ($encodedReferer !== '') {
                $castProxyParams['ref'] = $encodedReferer;
            }
            $castProxyParams = embedAccessBuildCastSignedQuery($castProxyParams);
            $castMediaUrl = $currentOrigin . '/stream_proxy.php?' . http_build_query($castProxyParams, '', '&', PHP_QUERY_RFC3986);
        }
    }
}
$castAppId = trim((string)(defined('SPORT_CAST_APP_ID') ? SPORT_CAST_APP_ID : ''));

header('Content-Type: text/html; charset=UTF-8');
if (function_exists('header_remove')) {
    header_remove('X-Frame-Options');
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: picture-in-picture=(self), fullscreen=(self), autoplay=(self)");
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' https: data: blob:; script-src 'self' 'unsafe-inline' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: blob: https:; connect-src * data: blob:; media-src * data: blob:; worker-src 'self' blob:; frame-ancestors {$frameAncestors};");

if (!$embedAccess['ok']) {
    http_response_code(403);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title !== '' ? $title : 'Live Stream', ENT_QUOTES, 'UTF-8'); ?></title>
    <?php if ($showErrorState): ?>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <?php endif; ?>
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
            height: 100%;
            overflow: hidden;
            background: #05070d;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        body.error-mode {
            background: #ffffff;
            font-family: 'Outfit', sans-serif;
        }

        .player-shell {
            position: relative;
            width: 100%;
            height: 100%;
            background: #000;
            overflow: hidden;
            cursor: default;
        }

        #video {
            width: 100%;
            height: 100%;
            display: block;
            background: #000;
            object-fit: contain;
        }

        .player-shell.is-playing,
        .player-shell.controls-visible,
        .player-shell:not(.is-playing) {
            cursor: default;
        }

        .center-play {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 92px;
            height: 92px;
            border: 0;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(154, 157, 108, 0.58);
            color: #ffffff;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.14);
            cursor: pointer;
            transition: opacity 0.25s ease, transform 0.25s ease;
            z-index: 4;
        }

        .loading-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.22s ease;
            z-index: 2;
        }

        .player-shell.is-buffering .loading-overlay {
            opacity: 1;
        }

        .loading-dots {
            width: 50px;
            aspect-ratio: 1;
            border-radius: 50%;
            background:
                radial-gradient(farthest-side, #ffffff 94%, #0000) top / 5px 5px no-repeat,
                conic-gradient(#0000 30%, #ffffff);
            -webkit-mask: radial-gradient(farthest-side, #0000 calc(100% - 5px), #000 0);
            mask: radial-gradient(farthest-side, #0000 calc(100% - 5px), #000 0);
            animation: player-loader-dots 1s infinite linear;
        }

        @keyframes player-loader-dots {
            100% {
                transform: rotate(1turn);
            }
        }

        .center-play svg {
            width: 24px;
            height: 24px;
            display: block;
            margin-left: 4px;
        }

        .player-shell.is-playing .center-play {
            opacity: 0;
            transform: translate(-50%, -50%) scale(0.9);
            pointer-events: none;
        }

        .controls-layer {
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: 1;
            transition: opacity 0.2s ease;
            z-index: 3;
        }

        .player-shell.controls-hidden .controls-layer {
            opacity: 0;
        }

        .player-shell.controls-hidden .controls-bar,
        .player-shell.controls-hidden .center-play {
            pointer-events: none;
        }

        .controls-gradient {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 88px;
            background: linear-gradient(180deg, rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0.05) 34%, rgba(0, 0, 0, 0.42) 100%);
        }

        .controls-bar {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            gap: 13px;
            direction: ltr;
            padding: 15px 18px 13px;
            color: #ffffff;
            pointer-events: auto;
        }

        .controls-left,
        .controls-right {
            display: flex;
            align-items: center;
            gap: 11px;
            flex-shrink: 0;
        }

        .controls-center {
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .progress-wrap {
            position: relative;
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: center;
        }

        .preview-bubble {
            position: absolute;
            left: 0;
            bottom: 22px;
            width: 176px;
            padding: 6px;
            border-radius: 16px;
            background: rgba(0, 0, 0, 0.42);
            border: 1px solid rgba(255, 255, 255, 0.10);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.24);
            backdrop-filter: blur(8px);
            transform: translateX(-50%) translateY(8px);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s ease;
            overflow: hidden;
        }

        .preview-bubble.is-visible {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .preview-canvas {
            display: block;
            width: 100%;
            aspect-ratio: 16 / 9;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.04);
            object-fit: cover;
        }

        .preview-time {
            margin-top: 6px;
            text-align: center;
            font-size: 12px;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            color: rgba(255, 255, 255, 0.94);
        }

        .preview-video {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
            left: -9999px;
            top: -9999px;
        }

        .player-button,
        .speed-button {
            appearance: none;
            border: 0;
            background: transparent;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0.96;
            transition: transform 0.18s ease, opacity 0.18s ease;
            position: relative;
            border-radius: 999px;
            isolation: isolate;
        }

        .player-button {
            width: 24px;
            height: 24px;
        }

        #castButton {
            display: none;
        }

        .player-button.is-active::before {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
            background: rgba(255, 255, 255, 0.18);
        }

        .player-button::before,
        .speed-button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.12);
            transform: translate(-50%, -50%) scale(0.86);
            opacity: 0;
            transition: opacity 0.18s ease, transform 0.18s ease, background-color 0.18s ease;
            z-index: -1;
        }

        .player-button:hover,
        .speed-button:hover {
            opacity: 1;
            transform: none;
        }

        .player-button:hover::before,
        .player-button:focus-visible::before,
        .speed-button:hover::before,
        .speed-button:focus-visible::before {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }

        .player-button svg {
            width: 18px;
            height: 18px;
            display: block;
        }

        .speed-button {
            font-size: 14px;
            line-height: 1;
            min-width: 24px;
            font-weight: 500;
            height: 24px;
            padding-inline: 3px;
        }

        .time-label,
        .status-label {
            font-size: 14px;
            line-height: 1;
            color: rgba(255, 255, 255, 0.95);
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .range {
            appearance: none;
            width: 100%;
            background: transparent;
            cursor: pointer;
        }

        .range:focus {
            outline: none;
        }

        .range::-webkit-slider-runnable-track {
            height: 6px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.28);
        }

        .range::-moz-range-track {
            height: 6px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.28);
        }

        .range::-webkit-slider-thumb {
            appearance: none;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #ffffff;
            margin-top: -4px;
            box-shadow: 0 1px 6px rgba(0, 0, 0, 0.14);
        }

        .range::-moz-range-thumb {
            width: 14px;
            height: 14px;
            border: 0;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 1px 6px rgba(0, 0, 0, 0.14);
        }

        .progress-range {
            --progress: 0%;
            background:
                linear-gradient(to right, rgba(255, 255, 255, 0.98) 0, rgba(255, 255, 255, 0.98) var(--progress), rgba(255, 255, 255, 0.30) var(--progress), rgba(255, 255, 255, 0.30) 100%);
            border-radius: 999px;
        }

        .progress-range::-webkit-slider-runnable-track {
            background: transparent;
        }

        .progress-range::-moz-range-track {
            background: transparent;
        }

        .progress-range.is-live-static::-webkit-slider-thumb {
            opacity: 0;
            transform: scale(0.01);
            box-shadow: none;
        }

        .progress-range.is-live-static::-moz-range-thumb {
            opacity: 0;
            transform: scale(0.01);
            box-shadow: none;
        }

        .volume-range {
            width: 62px;
            --progress: 100%;
            background:
                linear-gradient(to right, rgba(255, 255, 255, 0.98) 0, rgba(255, 255, 255, 0.98) var(--progress), rgba(255, 255, 255, 0.30) var(--progress), rgba(255, 255, 255, 0.30) 100%);
            border-radius: 999px;
        }

        .volume-range::-webkit-slider-runnable-track {
            background: transparent;
        }

        .volume-range::-moz-range-track {
            background: transparent;
        }

        .error-page {
            position: relative;
            width: 100%;
            height: 100%;
            background: #ffffff;
        }

        .error-chart {
            width: 100%;
            height: 100%;
            direction: ltr;
        }

        @media (max-width: 860px) {
            .controls-bar {
                gap: 11px;
                padding: 13px 15px 11px;
            }

            .volume-range,
            #pipButton,
            #speedButton {
                display: none;
            }

            .center-play {
                width: 78px;
                height: 78px;
            }
        }

        @media (max-width: 620px) {
            .controls-bar {
                padding-inline: 14px;
            }

            .time-label,
            .status-label {
                font-size: 13px;
            }

            .controls-left,
            .controls-right {
                gap: 9px;
            }
        }
    </style>
</head>
<body class="<?php echo $showErrorState ? 'error-mode' : ''; ?>">
    <?php if ($showErrorState): ?>
        <main class="error-page">
            <div id="strokeChart" class="error-chart" aria-label="Haya Shoout Stroke"></div>
        </main>
    <?php else: ?>
        <div class="player-shell controls-visible" id="playerShell">
            <video id="video" autoplay playsinline preload="auto"></video>

            <div class="loading-overlay" id="loadingOverlay" aria-hidden="true">
                <div class="loading-dots"></div>
            </div>

            <button type="button" class="center-play" id="centerPlay" aria-label="Play"></button>

            <div class="controls-layer" id="controlsLayer">
                <div class="controls-gradient"></div>
                <div class="controls-bar">
                    <div class="controls-left">
                        <button type="button" class="player-button" id="playPauseButton" aria-label="Play or pause"></button>
                        <button type="button" class="player-button" id="muteButton" aria-label="Mute"></button>
                        <input type="range" class="range volume-range" id="volumeRange" min="0" max="1" step="0.01" value="1" aria-label="Volume">
                        <span class="time-label" id="currentTimeLabel">00:00</span>
                    </div>

                    <div class="controls-center">
                        <div class="progress-wrap" id="progressWrap">
                            <div class="preview-bubble" id="previewBubble" aria-hidden="true">
                                <canvas class="preview-canvas" id="previewCanvas" width="320" height="180"></canvas>
                                <div class="preview-time" id="previewTimeLabel">00:00</div>
                            </div>
                            <input type="range" class="range progress-range" id="progressRange" min="0" max="100" step="0.1" value="0" aria-label="Seek">
                        </div>
                    </div>

                    <div class="controls-right">
                        <span class="time-label" id="durationLabel">LIVE</span>
                        <button type="button" class="speed-button" id="speedButton" aria-label="Playback rate">1x</button>
                        <button type="button" class="player-button" id="castButton" aria-label="Cast to TV"></button>
                        <button type="button" class="player-button" id="pipButton" aria-label="Picture in picture"></button>
                        <button type="button" class="player-button" id="fullscreenButton" aria-label="Fullscreen"></button>
                    </div>
                </div>
            </div>
            <video id="previewVideo" class="preview-video" muted playsinline preload="metadata" aria-hidden="true"></video>
        </div>
    <?php endif; ?>

    <?php if ($showErrorState): ?>
        <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
        <script>
            (function () {
                const chartDom = document.getElementById('strokeChart');
                if (!chartDom || !window.echarts) {
                    return;
                }

                const chart = window.echarts.init(chartDom, null, { renderer: 'svg' });

                function buildOption() {
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

                function render() {
                    chart.setOption(buildOption(), true);
                }

                render();
                window.addEventListener('resize', function () {
                    chart.resize();
                    render();
                });
            })();
        </script>
    <?php elseif ($embedAccess['ok'] && $isValidStream): ?>
        <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.18/dist/hls.min.js"></script>
        <script>
            (function () {
                let currentStreamUrl = <?php echo json_encode($streamUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                let currentStreamReferer = <?php echo json_encode($streamReferer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                const matchId = <?php echo (int)$matchId; ?>;
                const video = document.getElementById('video');
                const playerShell = document.getElementById('playerShell');
                const loadingOverlay = document.getElementById('loadingOverlay');
                const centerPlay = document.getElementById('centerPlay');
                const playPauseButton = document.getElementById('playPauseButton');
                const muteButton = document.getElementById('muteButton');
                const volumeRange = document.getElementById('volumeRange');
                const progressWrap = document.getElementById('progressWrap');
                const progressRange = document.getElementById('progressRange');
                const previewBubble = document.getElementById('previewBubble');
                const previewCanvas = document.getElementById('previewCanvas');
                const previewTimeLabel = document.getElementById('previewTimeLabel');
                const previewVideo = document.getElementById('previewVideo');
                const currentTimeLabel = document.getElementById('currentTimeLabel');
                const durationLabel = document.getElementById('durationLabel');
                const speedButton = document.getElementById('speedButton');
                const castButton = document.getElementById('castButton');
                const pipButton = document.getElementById('pipButton');
                const fullscreenButton = document.getElementById('fullscreenButton');
                const castMediaUrl = <?php echo json_encode($castMediaUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                const castMediaTitle = <?php echo json_encode($title !== '' ? $title : 'Live Stream', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                const castAppId = <?php echo json_encode($castAppId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                const autoCastRequested = <?php echo $autoCastRequested ? 'true' : 'false'; ?>;
                const tvOnlyMode = <?php echo $tvOnlyMode ? 'true' : 'false'; ?>;
                const standaloneCastUrl = <?php echo json_encode($standaloneCastUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                const initialProxyUrl = <?php echo json_encode($initialProxyUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                const resolvedCastUrlCache = new Map();

                if (!video || !currentStreamUrl || !playerShell) {
                    return;
                }

                (function silencePlayerDebugConsole() {
                    const debugEnabled = false;
                    if (debugEnabled || !window.console) {
                        return;
                    }

                    const prefixes = [
                        '[CAST-DEBUG]',
                        '[AIRPLAY-DEBUG]'
                    ];

                    ['log', 'warn', 'error', 'info', 'debug'].forEach(function(method) {
                        if (typeof console[method] !== 'function') {
                            return;
                        }

                        const original = console[method].bind(console);
                        console[method] = function() {
                            const firstArg = arguments.length > 0 ? arguments[0] : '';
                            if (typeof firstArg === 'string') {
                                const shouldSilence = prefixes.some(function(prefix) {
                                    return firstArg.indexOf(prefix) === 0;
                                });
                                if (shouldSilence) {
                                    return;
                                }
                            }
                            return original.apply(console, arguments);
                        };
                    });
                })();

                const supportsAirPlay = typeof video.webkitShowPlaybackTargetPicker === 'function';
                const supportsNativeHls = !!video.canPlayType('application/vnd.apple.mpegurl');
                const isSamsungInternet = /SamsungBrowser/i.test(navigator.userAgent || '');
                let airPlayAvailability = supportsAirPlay ? 'maybe' : 'not-available';
                let airPlayActive = !!video.webkitCurrentPlaybackTargetIsWireless;

                if (supportsAirPlay) {
                    try {
                        video.disableRemotePlayback = false;
                    } catch (error) {}
                    video.removeAttribute('disableremoteplayback');
                    video.removeAttribute('controlslist');
                    video.setAttribute('x-webkit-airplay', 'allow');
                    video.setAttribute('airplay', 'allow');
                } else {
                    try {
                        video.disableRemotePlayback = true;
                    } catch (error) {}
                    video.setAttribute('controlslist', 'noremoteplayback');
                    video.setAttribute('x-webkit-airplay', 'deny');
                    video.setAttribute('disableremoteplayback', '');
                }

                if (navigator.mediaSession) {
                    try {
                        navigator.mediaSession.metadata = null;
                        navigator.mediaSession.playbackState = 'none';
                    } catch (error) {}

                    [
                        'play',
                        'pause',
                        'seekbackward',
                        'seekforward',
                        'seekto',
                        'previoustrack',
                        'nexttrack',
                        'stop'
                    ].forEach(function (action) {
                        try {
                            navigator.mediaSession.setActionHandler(action, null);
                        } catch (error) {}
                    });
                }

                const icons = {
                    play: '<svg fill="none" height="36" viewBox="0 0 36 36" width="36" aria-hidden="true"><path d="M 17 8.6 L 10.89 4.99 C 9.39 4.11 7.5 5.19 7.5 6.93 C 7.5 6.93 7.5 6.93 7.5 6.93 L 7.5 29.06 C 7.5 30.8 9.39 31.88 10.89 31 C 10.89 31 10.89 31 10.89 31 L 17 27.4 C 17 27.4 17 27.4 17 27.4 C 17 27.4 17 27.4 17 27.4 L 17 8.6 C 17 8.6 17 8.6 17 8.6 C 17 8.6 17 8.6 17 8.6 Z M 17 8.6 L 17 8.6 C 17 8.6 17 8.6 17 8.6 C 17 8.6 17 8.6 17 8.6 V 27.4 C 17 27.4 17 27.4 17 27.4 C 17 27.4 17 27.4 17 27.4 L 33 18 C 33 18 33 18 33 18 C 33 18 33 18 33 18 V 18 L 17 8.6 C 17 8.6 17 8.6 17 8.6 C 17 8.6 17 8.6 17 8.6 Z" fill="currentColor"></path></svg>',
                    pause: '<svg fill="none" height="36" viewBox="0 0 36 36" width="36" aria-hidden="true"><path d="M 12.75 4.5 L 9.75 4.5 C 9.15 4.5 8.58 4.73 8.15 5.15 C 7.73 5.58 7.5 6.15 7.5 6.75 L 7.5 29.25 C 7.5 29.84 7.73 30.41 8.15 30.84 C 8.58 31.26 9.15 31.5 9.75 31.5 L 12.75 31.5 C 13.34 31.5 13.91 31.26 14.34 30.84 C 14.76 30.41 15 29.84 15 29.25 L 15 6.75 C 15 6.15 14.76 5.58 14.34 5.15 C 13.91 4.73 13.34 4.5 12.75 4.5 Z M 26.25 4.5 L 23.25 4.5 C 22.65 4.5 22.08 4.73 21.65 5.15 C 21.23 5.58 21 6.15 21 6.75 V 29.25 C 21 29.84 21.23 30.41 21.65 30.84 C 22.08 31.26 22.65 31.5 23.25 31.5 L 26.25 31.5 C 26.84 31.5 27.41 31.26 27.84 30.84 C 28.26 30.41 28.5 29.84 28.5 29.25 V 6.75 L 28.5 6.75 C 28.5 6.15 28.26 5.58 27.84 5.15 C 27.41 4.73 26.84 4.5 26.25 4.5 Z" fill="currentColor"></path></svg>',
                    volume: '<svg height="24" viewBox="0 0 24 24" width="24" aria-hidden="true"><path d="M 11.60 2.08 L 11.48 2.14 L 3.91 6.68 C 3.02 7.21 2.28 7.97 1.77 8.87 C 1.26 9.77 1.00 10.79 1 11.83 V 12.16 L 1.01 12.56 C 1.07 13.52 1.37 14.46 1.87 15.29 C 2.38 16.12 3.08 16.81 3.91 17.31 L 11.48 21.85 C 11.63 21.94 11.80 21.99 11.98 21.99 C 12.16 22.00 12.33 21.95 12.49 21.87 C 12.64 21.78 12.77 21.65 12.86 21.50 C 12.95 21.35 13 21.17 13 21 V 3 C 12.99 2.83 12.95 2.67 12.87 2.52 C 12.80 2.37 12.68 2.25 12.54 2.16 C 12.41 2.07 12.25 2.01 12.08 2.00 C 11.92 1.98 11.75 2.01 11.60 2.08 Z" fill="currentColor"></path><path d=" M 15.53 7.05 C 15.35 7.22 15.25 7.45 15.24 7.70 C 15.23 7.95 15.31 8.19 15.46 8.38 L 15.53 8.46 L 15.70 8.64 C 16.09 9.06 16.39 9.55 16.61 10.08 L 16.70 10.31 C 16.90 10.85 17 11.42 17 12 L 16.99 12.24 C 16.96 12.73 16.87 13.22 16.70 13.68 L 16.61 13.91 C 16.36 14.51 15.99 15.07 15.53 15.53 C 15.35 15.72 15.25 15.97 15.26 16.23 C 15.26 16.49 15.37 16.74 15.55 16.92 C 15.73 17.11 15.98 17.21 16.24 17.22 C 16.50 17.22 16.76 17.12 16.95 16.95 C 17.6 16.29 18.11 15.52 18.46 14.67 L 18.59 14.35 C 18.82 13.71 18.95 13.03 18.99 12.34 L 19 12 C 18.99 11.19 18.86 10.39 18.59 9.64 L 18.46 9.32 C 18.15 8.57 17.72 7.89 17.18 7.3 L 16.95 7.05 L 16.87 6.98 C 16.68 6.82 16.43 6.74 16.19 6.75 C 15.94 6.77 15.71 6.87 15.53 7.05" fill="currentColor" transform="translate(18, 12) scale(1) translate(-18,-12)"></path><path d="M18.36 4.22C18.18 4.39 18.08 4.62 18.07 4.87C18.05 5.12 18.13 5.36 18.29 5.56L18.36 5.63L18.66 5.95C19.36 6.72 19.91 7.60 20.31 8.55L20.47 8.96C20.82 9.94 21 10.96 21 11.99L20.98 12.44C20.94 13.32 20.77 14.19 20.47 15.03L20.31 15.44C19.86 16.53 19.19 17.52 18.36 18.36C18.17 18.55 18.07 18.80 18.07 19.07C18.07 19.33 18.17 19.59 18.36 19.77C18.55 19.96 18.80 20.07 19.07 20.07C19.33 20.07 19.59 19.96 19.77 19.77C20.79 18.75 21.61 17.54 22.16 16.20L22.35 15.70C22.72 14.68 22.93 13.62 22.98 12.54L23 12C22.99 10.73 22.78 9.48 22.35 8.29L22.16 7.79C21.67 6.62 20.99 5.54 20.15 4.61L19.77 4.22L19.70 4.15C19.51 3.99 19.26 3.91 19.02 3.93C18.77 3.94 18.53 4.04 18.36 4.22 Z" fill="currentColor" transform="translate(22, 12) scale(1) translate(-22, -12)"></path></svg>',
                    muted: '<svg height="24" viewBox="0 0 24 24" width="24" aria-hidden="true"><path d="M11.60 2.08L11.48 2.14L3.91 6.68C3.02 7.21 2.28 7.97 1.77 8.87C1.26 9.77 1.00 10.79 1 11.83V12.16L1.01 12.56C1.07 13.52 1.37 14.46 1.87 15.29C2.38 16.12 3.08 16.81 3.91 17.31L11.48 21.85C11.63 21.94 11.80 21.99 11.98 21.99C12.16 22.00 12.33 21.95 12.49 21.87C12.64 21.78 12.77 21.65 12.86 21.50C12.95 21.35 13 21.17 13 21V3C12.99 2.83 12.95 2.67 12.87 2.52C12.80 2.37 12.68 2.25 12.54 2.16C12.41 2.07 12.25 2.01 12.08 2.00C11.92 1.98 11.75 2.01 11.60 2.08ZM4.94 8.4V8.40L11 4.76V19.23L4.94 15.6C4.38 15.26 3.92 14.80 3.58 14.25C3.24 13.70 3.05 13.07 3.00 12.43L3 12.17V11.83C2.99 11.14 3.17 10.46 3.51 9.86C3.85 9.25 4.34 8.75 4.94 8.4ZM21.29 8.29L19 10.58L16.70 8.29L16.63 8.22C16.43 8.07 16.19 7.99 15.95 8.00C15.70 8.01 15.47 8.12 15.29 8.29C15.12 8.47 15.01 8.70 15.00 8.95C14.99 9.19 15.07 9.43 15.22 9.63L15.29 9.70L17.58 12L15.29 14.29C15.19 14.38 15.12 14.49 15.06 14.61C15.01 14.73 14.98 14.87 14.98 15.00C14.98 15.13 15.01 15.26 15.06 15.39C15.11 15.51 15.18 15.62 15.28 15.71C15.37 15.81 15.48 15.88 15.60 15.93C15.73 15.98 15.86 16.01 15.99 16.01C16.12 16.01 16.26 15.98 16.38 15.93C16.50 15.87 16.61 15.80 16.70 15.70L19 13.41L21.29 15.70L21.36 15.77C21.56 15.93 21.80 16.01 22.05 15.99C22.29 15.98 22.53 15.88 22.70 15.70C22.88 15.53 22.98 15.29 22.99 15.05C23.00 14.80 22.93 14.56 22.77 14.36L22.70 14.29L20.41 12L22.70 9.70C22.80 9.61 22.87 9.50 22.93 9.38C22.98 9.26 23.01 9.12 23.01 8.99C23.01 8.86 22.98 8.73 22.93 8.60C22.88 8.48 22.81 8.37 22.71 8.28C22.62 8.18 22.51 8.11 22.39 8.06C22.26 8.01 22.13 7.98 22.00 7.98C21.87 7.98 21.73 8.01 21.61 8.06C21.49 8.12 21.38 8.19 21.29 8.29Z" fill="currentColor"></path></svg>',
                    airplay: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 3H3C2.46 3 1.96 3.21 1.58 3.58C1.21 3.96 1 4.46 1 5V8C1.68 8.00 2.34 8.05 3 8.15V5H21V19H13.84C13.94 19.65 13.99 20.31 14 21H21C21.53 21 22.03 20.78 22.41 20.41C22.78 20.03 23 19.53 23 19V5C23 4.46 22.78 3.96 22.41 3.58C22.03 3.21 21.53 3 21 3ZM1 10V12C2.18 12 3.35 12.23 4.44 12.68C5.53 13.13 6.52 13.80 7.36 14.63C8.19 15.47 8.86 16.46 9.31 17.55C9.76 18.64 10 19.81 10 21H12C12 18.08 10.84 15.28 8.77 13.22C6.71 11.15 3.91 10 1 10ZM1 14V16C1.65 16 2.30 16.12 2.91 16.38C3.52 16.63 4.07 17.00 4.53 17.46C4.99 17.92 5.36 18.48 5.61 19.08C5.87 19.69 6 20.34 6 21H8C8 19.14 7.26 17.36 5.94 16.05C4.63 14.73 2.85 14 1 14ZM1 18V21H4C3.99 20.20 3.68 19.44 3.12 18.87C2.55 18.31 1.79 18.00 1 18Z" fill="currentColor"></path></svg>',
                    cast: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 3H3C2.46 3 1.96 3.21 1.58 3.58C1.21 3.96 1 4.46 1 5V8C1.68 8.00 2.34 8.05 3 8.15V5H21V19H13.84C13.94 19.65 13.99 20.31 14 21H21C21.53 21 22.03 20.78 22.41 20.41C22.78 20.03 23 19.53 23 19V5C23 4.46 22.78 3.96 22.41 3.58C22.03 3.21 21.53 3 21 3ZM1 10V12C2.18 12 3.35 12.23 4.44 12.68C5.53 13.13 6.52 13.80 7.36 14.63C8.19 15.47 8.86 16.46 9.31 17.55C9.76 18.64 10 19.81 10 21H12C12 18.08 10.84 15.28 8.77 13.22C6.71 11.15 3.91 10 1 10ZM1 14V16C1.65 16 2.30 16.12 2.91 16.38C3.52 16.63 4.07 17.00 4.53 17.46C4.99 17.92 5.36 18.48 5.61 19.08C5.87 19.69 6 20.34 6 21H8C8 19.14 7.26 17.36 5.94 16.05C4.63 14.73 2.85 14 1 14ZM1 18V21H4C3.99 20.20 3.68 19.44 3.12 18.87C2.55 18.31 1.79 18.00 1 18Z" fill="currentColor"></path></svg>',
                    fullscreen: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 22C6.22876 22 4.34315 22 3.17157 20.8284C2 19.6569 2 17.7712 2 14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M10 2C6.22876 2 4.34315 2 3.17157 3.17157C2.92939 3.41375 2.73727 3.68645 2.58487 4M2 10C2 9.26451 2 8.60074 2.00869 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M14 2C17.7712 2 19.6569 2 20.8284 3.17157C22 4.34315 22 6.22876 22 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M14 22C17.7712 22 19.6569 22 20.8284 20.8284C21.0706 20.5862 21.2627 20.3136 21.4151 20M22 14C22 14.7355 22 15.3993 21.9913 16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
                    fullscreenExit: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2 14C5.77124 14 7.65685 14 8.82843 15.1716C10 16.3431 10 18.2288 10 22" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M2 10C3.16976 10 4.15811 10 5 9.96504M10 2C10 5.77124 10 7.65685 8.82843 8.82843" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M14 22C14 18.2288 14 16.3431 15.1716 15.1716M22 14C20.8302 14 19.8419 14 19 14.035" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M22 10C18.2288 10 16.3431 10 15.1716 8.82843C14 7.65685 14 5.77124 14 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
                    pip: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M11 21H10C6.22876 21 4.34315 21 3.17157 19.8284C2 18.6569 2 16.7712 2 13V11M22 11C22 7.22876 22 5.34315 20.8284 4.17157C19.6569 3 17.7712 3 14 3H10C6.22876 3 4.34315 3 3.17157 4.17157C2.51839 4.82475 2.22937 5.69989 2.10149 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M13 17C13 15.1144 13 14.1716 13.5858 13.5858C14.1716 13 15.1144 13 17 13H18C19.8856 13 20.8284 13 21.4142 13.5858C22 14.1716 22 15.1144 22 17C22 18.8856 22 19.8284 21.4142 20.4142C20.8284 21 19.8856 21 18 21H17C15.1144 21 14.1716 21 13.5858 20.4142C13 19.8284 13 18.8856 13 17Z" stroke="currentColor" stroke-width="1.8"/></svg>'
                };

                const playbackRates = [1, 1.25, 1.5, 1.75, 2];
                let hls = null;
                let scrubActive = false;
                let bufferingVisible = true;
                let userPaused = false;
                let controlsHideTimer = null;
                let currentPlayableUrl = '';
                let refreshIntervalId = null;
                let previewHls = null;
                let previewSeekTimer = null;
                let previewEnabled = false;
                let previewVisible = false;
                let previewTargetTime = null;
                let previewSourceRequested = false;
                let previewSourceReady = false;
                const previewContext = previewCanvas ? previewCanvas.getContext('2d') : null;
                let castFrameworkReady = false;
                let autoCastAttempted = false;
                let startupProfileRestored = false;
                let liveElapsedBaseSeconds = 0;
                let liveElapsedStartedAt = 0;
                let recoveryInFlight = false;
                let lastRecoveryAttemptAt = 0;
                let recoveryAutoplayTimers = [];
                let playbackWatchdogId = null;
                let lastPlaybackProgressAt = Date.now();
                let lastPlaybackPosition = 0;
                let networkRecoveryPending = false;
                let currentSourceStartedAt = Date.now();
                let bufferingStartedAt = 0;
                let playbackStartedForCurrentSource = false;

                function markLiveElapsedRunning() {
                    if (!liveElapsedStartedAt) {
                        liveElapsedStartedAt = Date.now();
                    }
                }

                function markLiveElapsedPaused() {
                    if (liveElapsedStartedAt) {
                        liveElapsedBaseSeconds += Math.max(0, (Date.now() - liveElapsedStartedAt) / 1000);
                        liveElapsedStartedAt = 0;
                    }
                }

                function resetLiveElapsed() {
                    liveElapsedBaseSeconds = 0;
                    liveElapsedStartedAt = 0;
                }

                function getLiveElapsedSeconds() {
                    if (!liveElapsedStartedAt) {
                        return liveElapsedBaseSeconds;
                    }

                    return liveElapsedBaseSeconds + Math.max(0, (Date.now() - liveElapsedStartedAt) / 1000);
                }

                function updatePlaybackProgressSnapshot() {
                    lastPlaybackProgressAt = Date.now();
                    lastPlaybackPosition = Number(video.currentTime || 0);
                }

                function markCurrentSourceStarted() {
                    currentSourceStartedAt = Date.now();
                    bufferingStartedAt = currentSourceStartedAt;
                    playbackStartedForCurrentSource = false;
                    updatePlaybackProgressSnapshot();
                }

                function markCurrentSourcePlayable() {
                    playbackStartedForCurrentSource = true;
                    updatePlaybackProgressSnapshot();
                }

                function clearAutoplayRecoveryTimers() {
                    if (!recoveryAutoplayTimers.length) {
                        return;
                    }

                    recoveryAutoplayTimers.forEach(function (timerId) {
                        clearTimeout(timerId);
                    });
                    recoveryAutoplayTimers = [];
                }

                function scheduleAutoplayRecovery(delay) {
                    const timerId = window.setTimeout(function () {
                        recoveryAutoplayTimers = recoveryAutoplayTimers.filter(function (activeId) {
                            return activeId !== timerId;
                        });
                        if (!userPaused && !video.ended && video.paused) {
                            attemptAutoplay();
                        }
                    }, Math.max(150, Number(delay) || 500));

                    recoveryAutoplayTimers.push(timerId);
                }

                function attemptUnexpectedLiveRecovery(reason, forceRefresh) {
                    const state = getPlaybackState();
                    const now = Date.now();
                    if (!state.live || userPaused || recoveryInFlight) {
                        return Promise.resolve(false);
                    }

                    if ((now - lastRecoveryAttemptAt) < 8000) {
                        return Promise.resolve(false);
                    }

                    recoveryInFlight = true;
                    lastRecoveryAttemptAt = now;
                    networkRecoveryPending = true;

                    const performSourceRecovery = function () {
                        setBufferingState(true);

                        return refreshStreamSource(!!forceRefresh)
                        .then(function (refreshed) {
                            if (!refreshed) {
                                if (hls) {
                                    try {
                                        hls.stopLoad();
                                    } catch (error) {}
                                    try {
                                        hls.startLoad();
                                    } catch (error) {}
                                } else {
                                    try {
                                        video.load();
                                    } catch (error) {}
                                }
                            }

                            scheduleAutoplayRecovery(450);
                            scheduleAutoplayRecovery(1400);
                            return true;
                        });
                    };

                    const recoveryPromise = (!forceRefresh && video.paused && !video.ended)
                        ? attemptAutoplay().then(function (resumed) {
                            if (resumed && !video.paused) {
                                networkRecoveryPending = false;
                                updatePlaybackProgressSnapshot();
                                setBufferingState(false);
                                return true;
                            }

                            return performSourceRecovery();
                        })
                        : performSourceRecovery();

                    return recoveryPromise
                        .catch(function () {
                            return false;
                        })
                        .finally(function () {
                            recoveryInFlight = false;
                        });
                }

                function encodeBase64Url(value) {
                    return btoa(unescape(encodeURIComponent(value)))
                        .replace(/\+/g, '-')
                        .replace(/\//g, '_')
                        .replace(/=+$/g, '');
                }

                function buildProxyUrl(streamUrlOverride, streamRefererOverride) {
                    const proxyUrl = new URL('/stream_proxy.php', window.location.origin);
                    proxyUrl.searchParams.set('url', encodeBase64Url(streamUrlOverride || currentStreamUrl));
                    const referer = streamRefererOverride !== undefined ? streamRefererOverride : currentStreamReferer;
                    if (referer) {
                        proxyUrl.searchParams.set('ref', encodeBase64Url(referer));
                    }
                    return proxyUrl.toString();
                }

                function updateCastUi() {
                    if (!castButton) {
                        return;
                    }

                    if (isSamsungInternet && !supportsAirPlay) {
                        castButton.style.display = 'none';
                        castButton.classList.remove('is-active');
                        return;
                    }

                    if (supportsAirPlay) {
                        const shouldShowAirPlay = airPlayAvailability !== 'not-available';
                        castButton.style.display = shouldShowAirPlay ? 'inline-flex' : 'none';
                        castButton.classList.toggle('is-active', airPlayActive);
                        castButton.setAttribute('aria-label', 'AirPlay');
                        return;
                    }

                    // Inside an iframe the Cast SDK cannot reliably call
                    // requestSession() ? Chrome falls back to tab mirroring.
                    // Show the button based on castMediaUrl alone; its click
                    // handler will open a standalone popup for casting.
                    if (window.top !== window.self && !tvOnlyMode) {
                        castButton.style.display = castMediaUrl ? 'inline-flex' : 'none';
                        castButton.classList.remove('is-active');
                        return;
                    }

                    const shouldShow = !!(castMediaUrl && castFrameworkReady && window.cast && window.chrome && window.chrome.cast);
                    castButton.style.display = shouldShow ? 'inline-flex' : 'none';
                    if (!shouldShow) {
                        castButton.classList.remove('is-active');
                        return;
                    }

                    try {
                        const session = window.cast.framework.CastContext.getInstance().getCurrentSession();
                        castButton.classList.toggle('is-active', !!session);
                        castButton.setAttribute('aria-label', 'Cast to TV');
                    } catch (error) {
                        castButton.classList.remove('is-active');
                    }
                }

                function buildStandaloneCastUrl() {
                    if (standaloneCastUrl) {
                        return standaloneCastUrl;
                    }
                    const url = new URL(window.location.href);
                    url.searchParams.set('tv_only', '1');
                    url.searchParams.set('autocast', '1');
                    return url.toString();
                }

                function openStandaloneCastWindow() {
                    const standaloneUrl = buildStandaloneCastUrl();
                    const features = [
                        'popup=yes',
                        'width=1280',
                        'height=760',
                        'menubar=no',
                        'toolbar=no',
                        'location=yes',
                        'status=no',
                        'resizable=yes',
                        'scrollbars=no'
                    ].join(',');
                    const popup = window.open(standaloneUrl, 'hayaShooutCastPlayer', features);
                    if (popup && typeof popup.focus === 'function') {
                        popup.focus();
                    }
                    return popup;
                }

                function maybeAutoCast() {
                    if (!autoCastRequested || autoCastAttempted || window.top !== window.self) {
                        return;
                    }

                    if (!castFrameworkReady || !castMediaUrl) {
                        return;
                    }

                    autoCastAttempted = true;
                    window.setTimeout(function () {
                        loadCastMedia()
                            .then(function () {
                                window.setTimeout(function () {
                                    try {
                                        window.close();
                                    } catch (error) {}
                                }, 1200);
                            })
                            .catch(function () {
                                autoCastAttempted = false;
                            });
                    }, 180);
                }

                async function resolveCastPlaybackUrl(mediaUrl) {
                    const rawUrl = (mediaUrl || '').trim();
                    if (!rawUrl) {
                        return '';
                    }

                    if (resolvedCastUrlCache.has(rawUrl)) {
                        return resolvedCastUrlCache.get(rawUrl);
                    }

                    let finalUrl = rawUrl;
                    try {
                        const candidateUrl = new URL(rawUrl, window.location.origin);
                        if (candidateUrl.origin === window.location.origin) {
                            const response = await fetch(candidateUrl.toString(), {
                                method: 'GET',
                                credentials: 'same-origin',
                                redirect: 'follow',
                                cache: 'no-store',
                                headers: {
                                    'Accept': 'application/vnd.apple.mpegurl, application/x-mpegURL, */*'
                                }
                            });

                            if (response && response.ok && response.url) {
                                finalUrl = response.url;
                            }
                        }
                    } catch (error) {}

                    resolvedCastUrlCache.set(rawUrl, finalUrl);
                    return finalUrl;
                }

                async function loadCastMedia() {
                    console.log('[CAST-DEBUG] Player.loadCastMedia() called', {
                        castMediaUrl: castMediaUrl,
                        castAppId: castAppId,
                        isTopLevel: window.top === window.self,
                        hasCastFramework: !!(window.cast && window.cast.framework),
                        hasChromeCast: !!(window.chrome && window.chrome.cast)
                    });

                    if (!castMediaUrl || !window.chrome || !window.chrome.cast || !window.cast || !window.cast.framework) {
                        console.error('[CAST-DEBUG] Player.loadCastMedia() → prerequisites missing');
                        return;
                    }

                    const context = window.cast.framework.CastContext.getInstance();
                    let session = context.getCurrentSession();
                    const sessionObj = session && typeof session.getSessionObj === 'function'
                        ? session.getSessionObj()
                        : null;
                    const sessionAppId = sessionObj && sessionObj.appId ? String(sessionObj.appId) : '';
                    console.log('[CAST-DEBUG] Player.loadCastMedia() current session appId:', sessionAppId || '(unknown)');

                    if (session && sessionAppId && castAppId && sessionAppId !== castAppId) {
                        console.warn('[CAST-DEBUG] Player.loadCastMedia() ? existing session app mismatch, restarting session', {
                            existingAppId: sessionAppId,
                            expectedAppId: castAppId
                        });
                        try {
                            context.endCurrentSession(true);
                        } catch (error) {}
                        session = null;
                    }

                    if (!session) {
                        console.log('[CAST-DEBUG] Player.loadCastMedia() ? calling requestSession()');
                        await context.requestSession();
                        session = context.getCurrentSession();
                        console.log('[CAST-DEBUG] Player.loadCastMedia() ? requestSession done, session:', !!session);
                    }

                    if (!session) {
                        console.log('[CAST-DEBUG] Player.loadCastMedia() → calling requestSession()');
                        await context.requestSession();
                        session = context.getCurrentSession();
                        console.log('[CAST-DEBUG] Player.loadCastMedia() → requestSession done, session:', !!session);
                    }

                    if (!session) {
                        console.error('[CAST-DEBUG] Player.loadCastMedia() → no session after requestSession');
                        return;
                    }

                    const preparedCastMediaUrl = await resolveCastPlaybackUrl(castMediaUrl);
                    console.log('[CAST-DEBUG] Player.loadCastMedia() → loadMedia with URL:', preparedCastMediaUrl);
                    const mediaInfo = new window.chrome.cast.media.MediaInfo(preparedCastMediaUrl, 'application/vnd.apple.mpegurl');
                    mediaInfo.streamType = window.chrome.cast.media.StreamType.LIVE;
                    const metadata = new window.chrome.cast.media.GenericMediaMetadata();
                    metadata.title = castMediaTitle || 'Live Stream';
                    mediaInfo.metadata = metadata;

                    const request = new window.chrome.cast.media.LoadRequest(mediaInfo);
                    request.autoplay = true;
                    await session.loadMedia(request);
                    console.log('[CAST-DEBUG] Player.loadCastMedia() → loadMedia SUCCESS');
                    updateCastUi();
                }

                function initCastFramework() {
                    if (supportsAirPlay) {
                        return;
                    }

                    if (!window.cast || !window.cast.framework || !window.chrome || !window.chrome.cast) {
                        console.error('[CAST-DEBUG] Player.initCastFramework() → framework objects missing');
                        return;
                    }

                    if (!castAppId) {
                        console.error('[CAST-DEBUG] Player.initCastFramework() → NO castAppId! Refusing to init with default receiver.');
                        return;
                    }

                    try {
                        const context = window.cast.framework.CastContext.getInstance();
                        console.log('[CAST-DEBUG] Player.initCastFramework() → setOptions appId=' + castAppId);
                        context.setOptions({
                            receiverApplicationId: castAppId,
                            autoJoinPolicy: window.chrome.cast.AutoJoinPolicy.ORIGIN_SCOPED
                        });
                        castFrameworkReady = true;
                        console.log('[CAST-DEBUG] Player.initCastFramework() → ready');
                        updateCastUi();
                        context.addEventListener(window.cast.framework.CastContextEventType.SESSION_STATE_CHANGED, function(ev) {
                            console.log('[CAST-DEBUG] Player: session state changed:', ev.sessionState);
                            updateCastUi();
                        });
                        maybeAutoCast();
                    } catch (error) {
                        console.error('[CAST-DEBUG] Player.initCastFramework() → ERROR', error);
                        castFrameworkReady = false;
                        updateCastUi();
                    }
                }

                window.__onGCastApiAvailable = function (isAvailable) {
                    if (!isAvailable) {
                        castFrameworkReady = false;
                        updateCastUi();
                        return;
                    }

                    initCastFramework();
                };

                if (castMediaUrl && !supportsAirPlay) {
                    const castSdkScript = document.createElement('script');
                    castSdkScript.src = 'https://www.gstatic.com/cv/js/sender/v1/cast_sender.js?loadCastFramework=1';
                    castSdkScript.async = true;
                    document.head.appendChild(castSdkScript);
                }

                function applyStreamSource(streamUrl, referer, forceReload, proxyUrlOverride) {
                    const nextStreamUrl = (streamUrl || '').trim();
                    const nextReferer = (referer || '').trim();
                    if (!nextStreamUrl) {
                        return false;
                    }

                    const nextPlayableUrl = (proxyUrlOverride || '').trim() || buildProxyUrl(nextStreamUrl, nextReferer);
                    const sourceChanged = forceReload || nextPlayableUrl !== currentPlayableUrl;

                    currentStreamUrl = nextStreamUrl;
                    currentStreamReferer = nextReferer;

                    if (!sourceChanged) {
                        return false;
                    }

                    resetLiveElapsed();
                    markCurrentSourceStarted();
                    currentPlayableUrl = nextPlayableUrl;
                    setBufferingState(true);
                    resetPreviewSource();

                    if (hls) {
                        hls.stopLoad();
                        hls.loadSource(currentPlayableUrl);
                        hls.startLoad();
                        scheduleAutoplayRecovery(450);
                        return true;
                    }

                    video.src = currentPlayableUrl;
                    video.load();
                    attemptAutoplay();
                    return true;
                }

                async function refreshStreamSource(forceRefresh) {
                    if (!matchId) {
                        return false;
                    }

                    try {
                        const refreshUrl = new URL('/stream_refresh.php', window.location.origin);
                        refreshUrl.searchParams.set('match_id', String(matchId));
                        if (forceRefresh) {
                            refreshUrl.searchParams.set('force', '1');
                        }

                        const response = await fetch(refreshUrl.toString(), {
                            method: 'GET',
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json'
                            }
                        });

                        if (!response.ok) {
                            return false;
                        }

                        const payload = await response.json();
                        if (!payload || payload.status !== 'success' || !payload.stream_url) {
                            return false;
                        }

                        return applyStreamSource(payload.stream_url, payload.referer || '', !!payload.refreshed, payload.proxy_url || '');
                    } catch (error) {
                        return false;
                    }
                }

                function hidePreviewBubble() {
                    previewVisible = false;
                    previewTargetTime = null;
                    if (previewSeekTimer) {
                        clearTimeout(previewSeekTimer);
                        previewSeekTimer = null;
                    }
                    if (previewBubble) {
                        previewBubble.classList.remove('is-visible');
                        previewBubble.setAttribute('aria-hidden', 'true');
                    }
                }

                function showPreviewBubble() {
                    if (!previewEnabled || !previewBubble) {
                        return;
                    }
                    previewVisible = true;
                    previewBubble.classList.add('is-visible');
                    previewBubble.setAttribute('aria-hidden', 'false');
                }

                function updatePreviewAvailability() {
                    const state = getPlaybackState();
                    previewEnabled = !!(previewVideo && progressWrap && previewSourceReady && !state.live && state.canSeek && state.duration > 1);
                    if (!previewEnabled) {
                        hidePreviewBubble();
                    }
                }

                function drawPreviewFrame() {
                    if (!previewContext || !previewCanvas || !previewVideo) {
                        return;
                    }

                    if (previewVideo.readyState < 2 || !previewVideo.videoWidth || !previewVideo.videoHeight) {
                        return;
                    }

                    previewContext.clearRect(0, 0, previewCanvas.width, previewCanvas.height);
                    previewContext.drawImage(previewVideo, 0, 0, previewCanvas.width, previewCanvas.height);
                }

                function schedulePreviewSeek(absoluteTime) {
                    if (!previewVideo || !previewEnabled || !Number.isFinite(absoluteTime)) {
                        return;
                    }

                    if (previewSeekTimer) {
                        clearTimeout(previewSeekTimer);
                    }

                    previewSeekTimer = window.setTimeout(function () {
                        try {
                            previewVideo.currentTime = absoluteTime;
                        } catch (error) {
                            // Ignore preview seek errors; the bubble will stay hidden or stale.
                        }
                    }, 80);
                }

                function resetPreviewSource() {
                    previewSourceRequested = false;
                    previewSourceReady = false;
                    previewEnabled = false;
                    hidePreviewBubble();

                    if (previewHls) {
                        try {
                            previewHls.destroy();
                        } catch (error) {}
                        previewHls = null;
                    }

                    if (!previewVideo) {
                        return;
                    }

                    try {
                        previewVideo.pause();
                    } catch (error) {}
                    previewVideo.removeAttribute('src');
                    delete previewVideo.dataset.source;
                    previewVideo.load();
                }

                function ensurePreviewSource(forceReload) {
                    if (!previewVideo || !currentPlayableUrl) {
                        return;
                    }

                    const nextSource = currentPlayableUrl;
                    const sourceChanged = forceReload || previewVideo.dataset.source !== nextSource;
                    if (!sourceChanged && previewSourceRequested) {
                        return;
                    }

                    previewSourceRequested = true;
                    previewSourceReady = false;
                    previewVideo.dataset.source = nextSource;
                    previewEnabled = false;
                    hidePreviewBubble();

                    if (previewHls) {
                        try {
                            previewHls.destroy();
                        } catch (error) {}
                        previewHls = null;
                    }

                    previewVideo.pause();
                    previewVideo.removeAttribute('src');
                    previewVideo.load();

                    if (supportsNativeHls) {
                        previewVideo.src = nextSource;
                    } else if (!supportsAirPlay && window.Hls && window.Hls.isSupported()) {
                        previewHls = new window.Hls({
                            autoStartLoad: true,
                            lowLatencyMode: false,
                            backBufferLength: 10,
                            maxBufferLength: 8,
                            enableWorker: false
                        });
                        previewHls.loadSource(nextSource);
                        previewHls.attachMedia(previewVideo);
                    }
                }

                function updatePreviewAtClientX(clientX) {
                    if (!previewEnabled || !progressWrap || !previewBubble || !previewTimeLabel) {
                        return;
                    }

                    const state = getPlaybackState();
                    if (!state.canSeek || state.duration <= 0) {
                        hidePreviewBubble();
                        return;
                    }

                    const rect = progressWrap.getBoundingClientRect();
                    if (!rect.width) {
                        hidePreviewBubble();
                        return;
                    }

                    const relativeX = Math.max(0, Math.min(rect.width, clientX - rect.left));
                    const percent = relativeX / rect.width;
                    const previewPosition = percent * state.duration;
                    const absoluteTime = state.live ? state.start + previewPosition : previewPosition;

                    previewBubble.style.left = relativeX + 'px';
                    previewTimeLabel.textContent = formatTime(previewPosition);
                    showPreviewBubble();
                    previewTargetTime = absoluteTime;
                    schedulePreviewSeek(absoluteTime);
                }

                function formatTime(totalSeconds) {
                    if (!Number.isFinite(totalSeconds) || totalSeconds < 0) {
                        return '--:--';
                    }

                    const seconds = Math.floor(totalSeconds);
                    const hrs = Math.floor(seconds / 3600);
                    const mins = Math.floor((seconds % 3600) / 60);
                    const secs = seconds % 60;

                    if (hrs > 0) {
                        return String(hrs).padStart(2, '0') + ':' + String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
                    }

                    return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
                }

                function setRangeProgress(range, value, max) {
                    const safeMax = max > 0 ? max : 1;
                    const percent = Math.max(0, Math.min(100, (value / safeMax) * 100));
                    range.style.setProperty('--progress', percent + '%');
                }

                function getPlaybackState() {
                    if (video.seekable && video.seekable.length > 0) {
                        const index = video.seekable.length - 1;
                        const start = video.seekable.start(index);
                        const end = video.seekable.end(index);
                        const rawCurrent = Number(video.currentTime || 0);
                        const duration = Math.max(0, end - start);
                        const elapsedFallback = Math.min(Math.max(getLiveElapsedSeconds(), 0), duration || Math.max(getLiveElapsedSeconds(), 0));

                        // Some live HLS streams expose a seekable window with an
                        // absolute/sliding start, while currentTime stays relative
                        // from zero. In that case clamping currentTime to start
                        // makes the UI appear stuck at 00:00 forever.
                        if (
                            duration > 0 &&
                            start > 1 &&
                            Number.isFinite(rawCurrent) &&
                            rawCurrent >= 0 &&
                            rawCurrent <= (duration + 5) &&
                            rawCurrent < start
                        ) {
                            const relativePosition = Math.min(Math.max(rawCurrent, 0), duration);
                            return {
                                live: true,
                                canSeek: duration > 0,
                                start: start,
                                end: end,
                                duration: duration,
                                position: relativePosition,
                                behindLive: Math.max(0, duration - relativePosition)
                            };
                        }

                        if (
                            duration > 0 &&
                            Number.isFinite(rawCurrent) &&
                            rawCurrent >= 0 &&
                            rawCurrent < 1 &&
                            elapsedFallback > 1
                        ) {
                            return {
                                live: true,
                                canSeek: duration > 0,
                                start: start,
                                end: end,
                                duration: duration,
                                position: elapsedFallback,
                                behindLive: Math.max(0, duration - elapsedFallback)
                            };
                        }

                        const current = Math.min(Math.max(rawCurrent || start, start), end);

                        return {
                            live: true,
                            canSeek: duration > 0,
                            start: start,
                            end: end,
                            duration: duration,
                            position: Math.max(0, current - start),
                            behindLive: Math.max(0, end - current)
                        };
                    }

                    if (Number.isFinite(video.duration) && video.duration > 0) {
                        return {
                            live: false,
                            canSeek: true,
                            start: 0,
                            end: video.duration,
                            duration: video.duration,
                            position: Math.max(0, video.currentTime || 0),
                            behindLive: Math.max(0, video.duration - (video.currentTime || 0))
                        };
                    }

                    return {
                        live: true,
                        canSeek: false,
                        start: 0,
                        end: 0,
                        duration: 0,
                        position: getLiveElapsedSeconds(),
                        behindLive: 0
                    };
                }

                function updateIcons() {
                    const recoveringLive = networkRecoveryPending && getPlaybackState().live && !video.ended;
                    const paused = !recoveringLive && (video.paused || video.ended);
                    playPauseButton.innerHTML = paused ? icons.play : icons.pause;
                    centerPlay.innerHTML = icons.play;
                    muteButton.innerHTML = (video.muted || video.volume === 0) ? icons.muted : icons.volume;
                    if (castButton) {
                        castButton.innerHTML = supportsAirPlay ? icons.airplay : icons.cast;
                    }
                    fullscreenButton.innerHTML = isFullscreenActive() ? icons.fullscreenExit : icons.fullscreen;
                    pipButton.innerHTML = icons.pip;
                    updateCastUi();
                }

                function updateUi() {
                    const state = getPlaybackState();
                    const liveStaticMode = !!state.live;

                    currentTimeLabel.textContent = liveStaticMode ? '' : formatTime(state.position);
                    currentTimeLabel.style.display = liveStaticMode ? 'none' : '';
                    durationLabel.textContent = liveStaticMode
                        ? 'LIVE'
                        : '-' + formatTime(Math.max(0, state.duration - state.position));

                    progressRange.classList.toggle('is-live-static', liveStaticMode);
                    progressRange.disabled = liveStaticMode ? true : !state.canSeek;
                    progressRange.max = liveStaticMode ? '100' : (state.duration > 0 ? String(state.duration) : '100');
                    if (!scrubActive) {
                        progressRange.value = liveStaticMode ? '100' : String(state.position);
                    }
                    setRangeProgress(
                        progressRange,
                        liveStaticMode ? 100 : Number(progressRange.value || 0),
                        liveStaticMode ? 100 : (state.duration > 0 ? state.duration : 100)
                    );

                    volumeRange.value = String(video.muted ? 0 : video.volume);
                    setRangeProgress(volumeRange, Number(volumeRange.value || 0), 1);

                    speedButton.textContent = (Math.round(video.playbackRate * 100) / 100).toString().replace('.00', '') + 'x';
                    playerShell.classList.toggle('is-playing', ((!video.paused && !video.ended) || (networkRecoveryPending && !video.ended)));
                    updateIcons();
                }

                function setBufferingState(isBuffering) {
                    const shouldBuffer = !!isBuffering && !video.ended && !userPaused && (!video.paused || networkRecoveryPending);
                    if (shouldBuffer) {
                        if (!bufferingVisible || !bufferingStartedAt) {
                            bufferingStartedAt = Date.now();
                        }
                    } else {
                        bufferingStartedAt = 0;
                    }
                    bufferingVisible = shouldBuffer;
                    playerShell.classList.toggle('is-buffering', bufferingVisible);
                    if (loadingOverlay) {
                        loadingOverlay.setAttribute('aria-hidden', bufferingVisible ? 'false' : 'true');
                    }
                    if (bufferingVisible) {
                        showControls();
                    } else {
                        scheduleControlsHide();
                    }
                }

                function clearControlsHideTimer() {
                    if (controlsHideTimer) {
                        clearTimeout(controlsHideTimer);
                        controlsHideTimer = null;
                    }
                }

                function hideControls() {
                    if (video.paused || video.ended || bufferingVisible || scrubActive) {
                        return;
                    }
                    playerShell.classList.remove('controls-visible');
                    playerShell.classList.add('controls-hidden');
                }

                function scheduleControlsHide() {
                    clearControlsHideTimer();
                    if (video.paused || video.ended || bufferingVisible || scrubActive) {
                        return;
                    }
                    controlsHideTimer = window.setTimeout(function () {
                        hideControls();
                    }, 2200);
                }

                function showControls() {
                    clearControlsHideTimer();
                    playerShell.classList.remove('controls-hidden');
                    playerShell.classList.add('controls-visible');
                    scheduleControlsHide();
                }

                function togglePlay() {
                    if (video.paused || video.ended) {
                        userPaused = false;
                        networkRecoveryPending = false;
                        video.play().catch(function () {
                            showControls();
                        });
                        return;
                    }

                    userPaused = true;
                    networkRecoveryPending = false;
                    clearAutoplayRecoveryTimers();
                    setBufferingState(false);
                    video.pause();
                }

                function setVolume(value) {
                    const volume = Math.max(0, Math.min(1, value));
                    video.volume = volume;
                    video.muted = volume === 0;
                    updateUi();
                }

                function toggleMute() {
                    if (video.muted || video.volume === 0) {
                        video.muted = false;
                        video.volume = Math.max(0.35, Number(volumeRange.value || 0.7));
                    } else {
                        video.muted = true;
                    }
                    updateUi();
                }

                function seekTo(value) {
                    const state = getPlaybackState();
                    if (!state.canSeek) {
                        return;
                    }

                    const targetPosition = Number(value);
                    if (!Number.isFinite(targetPosition)) {
                        return;
                    }

                    video.currentTime = state.live ? state.start + targetPosition : targetPosition;
                }

                function isFullscreenActive() {
                    return !!(
                        document.fullscreenElement ||
                        document.webkitFullscreenElement ||
                        video.webkitDisplayingFullscreen
                    );
                }

                function toggleFullscreen() {
                    if (!isFullscreenActive()) {
                        if (typeof playerShell.requestFullscreen === 'function') {
                            playerShell.requestFullscreen().catch(function () {});
                            return;
                        }

                        if (typeof playerShell.webkitRequestFullscreen === 'function') {
                            try {
                                playerShell.webkitRequestFullscreen();
                            } catch (error) {}
                            return;
                        }

                        if (typeof video.webkitEnterFullscreen === 'function') {
                            try {
                                video.webkitEnterFullscreen();
                            } catch (error) {}
                        }
                        return;
                    }

                    if (typeof document.exitFullscreen === 'function') {
                        document.exitFullscreen().catch(function () {});
                        return;
                    }

                    if (typeof document.webkitExitFullscreen === 'function') {
                        try {
                            document.webkitExitFullscreen();
                        } catch (error) {}
                        return;
                    }

                    if (typeof video.webkitExitFullscreen === 'function') {
                        try {
                            video.webkitExitFullscreen();
                        } catch (error) {}
                    }
                }

                function togglePiP() {
                    if (!document.pictureInPictureEnabled) {
                        return;
                    }

                    if (document.pictureInPictureElement) {
                        document.exitPictureInPicture?.().catch(function () {});
                        return;
                    }

                    video.requestPictureInPicture?.().catch(function () {});
                }

                function cycleSpeed() {
                    const currentIndex = playbackRates.findIndex(function (rate) {
                        return Math.abs(rate - video.playbackRate) < 0.01;
                    });
                    const nextRate = playbackRates[(currentIndex + 1) % playbackRates.length] || playbackRates[0];
                    video.playbackRate = nextRate;
                    updateUi();
                }

                function attemptAutoplay() {
                    return video.play().then(function () {
                        setBufferingState(false);
                        return true;
                    }).catch(function () {
                        setBufferingState(false);
                        updateUi();
                        showControls();
                        return false;
                    });
                }

                playPauseButton.addEventListener('click', function (event) {
                    event.stopPropagation();
                    showControls();
                    togglePlay();
                });

                centerPlay.addEventListener('click', function (event) {
                    event.stopPropagation();
                    showControls();
                    togglePlay();
                });

                muteButton.addEventListener('click', function (event) {
                    event.stopPropagation();
                    showControls();
                    toggleMute();
                });

                volumeRange.addEventListener('input', function (event) {
                    event.stopPropagation();
                    showControls();
                    setVolume(Number(event.target.value || 0));
                });

                progressRange.addEventListener('input', function (event) {
                    scrubActive = true;
                    const currentValue = Number(event.target.value || 0);
                    setRangeProgress(progressRange, currentValue, Number(progressRange.max || 100));
                    currentTimeLabel.textContent = formatTime(currentValue);
                    showControls();
                });

                progressRange.addEventListener('change', function (event) {
                    userPaused = false;
                    seekTo(event.target.value);
                    scrubActive = false;
                    showControls();
                });

                if (progressWrap && previewVideo && previewBubble) {
                    ['pointerenter', 'mouseenter'].forEach(function (eventName) {
                        progressWrap.addEventListener(eventName, function (event) {
                            ensurePreviewSource(false);
                            updatePreviewAvailability();
                            if (!previewEnabled) {
                                return;
                            }
                            const clientX = event.clientX || (event.touches && event.touches[0] ? event.touches[0].clientX : 0);
                            if (clientX) {
                                updatePreviewAtClientX(clientX);
                            }
                        }, { passive: true });
                    });

                    ['pointermove', 'mousemove'].forEach(function (eventName) {
                        progressWrap.addEventListener(eventName, function (event) {
                            ensurePreviewSource(false);
                            if (!previewEnabled) {
                                return;
                            }
                            updatePreviewAtClientX(event.clientX);
                        }, { passive: true });
                    });

                    ['pointerleave', 'mouseleave', 'touchend', 'touchcancel'].forEach(function (eventName) {
                        progressWrap.addEventListener(eventName, function () {
                            hidePreviewBubble();
                        }, { passive: true });
                    });

                    progressWrap.addEventListener('touchmove', function (event) {
                        ensurePreviewSource(false);
                        updatePreviewAvailability();
                        if (!previewEnabled || !event.touches || !event.touches[0]) {
                            return;
                        }
                        updatePreviewAtClientX(event.touches[0].clientX);
                    }, { passive: true });

                    previewVideo.addEventListener('loadedmetadata', function () {
                        previewSourceReady = true;
                        updatePreviewAvailability();
                    });
                    previewVideo.addEventListener('canplay', function () {
                        previewSourceReady = true;
                        updatePreviewAvailability();
                    });
                    previewVideo.addEventListener('seeked', function () {
                        drawPreviewFrame();
                    });
                    previewVideo.addEventListener('loadeddata', function () {
                        drawPreviewFrame();
                    });
                    previewVideo.addEventListener('error', function () {
                        previewSourceReady = false;
                        updatePreviewAvailability();
                    });
                }

                fullscreenButton.addEventListener('click', function (event) {
                    event.stopPropagation();
                    showControls();
                    toggleFullscreen();
                });

                pipButton.addEventListener('click', function (event) {
                    event.stopPropagation();
                    showControls();
                    togglePiP();
                });

                speedButton.addEventListener('click', function (event) {
                    event.stopPropagation();
                    showControls();
                    cycleSpeed();
                });

                if (castButton) {
                    castButton.addEventListener('click', function (event) {
                        event.stopPropagation();
                        showControls();

                        console.log('[CAST-DEBUG] Player: Cast button clicked', {
                            isIframe: window.top !== window.self,
                            tvOnlyMode: tvOnlyMode,
                            castFrameworkReady: castFrameworkReady,
                            castMediaUrl: castMediaUrl,
                            castAppId: castAppId
                        });

                        if (supportsAirPlay) {
                            try {
                                video.webkitShowPlaybackTargetPicker();
                            } catch (error) {
                                console.error('[AIRPLAY-DEBUG] Failed to open AirPlay picker', error);
                            }
                            return;
                        }

                        if (window.top !== window.self && !tvOnlyMode) {
                            try {
                                if (
                                    window.top &&
                                    window.top.HayaTopLevelCastBridge &&
                                    typeof window.top.HayaTopLevelCastBridge.castMedia === 'function'
                                ) {
                                    console.log('[CAST-DEBUG] Player: iframe ? trying top-level bridge cast');
                                    window.top.HayaTopLevelCastBridge.castMedia({
                                        mediaUrl: castMediaUrl,
                                        title: castMediaTitle || 'Live Stream',
                                        appId: castAppId || ''
                                    }).then(function () {
                                        console.log('[CAST-DEBUG] Player: iframe ? top-level bridge SUCCESS');
                                        castButton.classList.add('is-active');
                                    }).catch(function (err) {
                                        console.error('[CAST-DEBUG] Player: iframe ? top-level bridge FAILED', err);
                                        openStandaloneCastWindow();
                                    });
                                    return;
                                }
                            } catch (error) {
                                console.error('[CAST-DEBUG] Player: iframe ? top-level bridge ERROR', error);
                            }

                            console.log('[CAST-DEBUG] Player: iframe ? trying direct iframe loadCastMedia()');
                            loadCastMedia().then(function () {
                                console.log('[CAST-DEBUG] Player: iframe ? direct loadCastMedia SUCCESS');
                                castButton.classList.add('is-active');
                            }).catch(function (err) {
                                console.error('[CAST-DEBUG] Player: iframe ? direct loadCastMedia FAILED', err);
                                console.log('[CAST-DEBUG] Player: iframe ? opening standalone popup');
                                openStandaloneCastWindow();
                            });
                            return;
                        }

                        console.log('[CAST-DEBUG] Player: top-level ? calling loadCastMedia()');
                        loadCastMedia().catch(function (err) {
                            console.error('[CAST-DEBUG] Player: loadCastMedia() FAILED', err);
                        });
                    });
                }

                if (supportsAirPlay) {
                    video.addEventListener('webkitplaybacktargetavailabilitychanged', function (event) {
                        airPlayAvailability = event && event.availability ? String(event.availability) : 'available';
                        updateCastUi();
                    });
                    video.addEventListener('webkitcurrentplaybacktargetiswirelesschanged', function () {
                        airPlayActive = !!video.webkitCurrentPlaybackTargetIsWireless;
                        updateCastUi();
                    });
                }

                video.addEventListener('click', function () {
                    showControls();
                    togglePlay();
                });
                video.addEventListener('timeupdate', function () {
                    updatePlaybackProgressSnapshot();
                    updateUi();
                    updatePreviewAvailability();
                });
                video.addEventListener('progress', function () {
                    updateUi();
                    updatePreviewAvailability();
                });
                video.addEventListener('durationchange', function () {
                    updateUi();
                    updatePreviewAvailability();
                });
                video.addEventListener('loadedmetadata', function () {
                    updatePlaybackProgressSnapshot();
                    setBufferingState(false);
                    updateUi();
                    updatePreviewAvailability();
                });
                video.addEventListener('volumechange', updateUi);
                ['loadstart', 'waiting', 'stalled', 'seeking'].forEach(function (eventName) {
                    video.addEventListener(eventName, function () {
                        if (video.paused || video.ended || userPaused) {
                            setBufferingState(false);
                            return;
                        }
                        setBufferingState(true);
                    });
                });
                ['playing', 'canplay', 'canplaythrough', 'seeked'].forEach(function (eventName) {
                    video.addEventListener(eventName, function () {
                        if (eventName === 'playing' || eventName === 'canplay' || eventName === 'canplaythrough') {
                            userPaused = false;
                            markLiveElapsedRunning();
                            clearAutoplayRecoveryTimers();
                            markCurrentSourcePlayable();
                        }
                        networkRecoveryPending = false;
                        setBufferingState(false);
                    });
                });
                video.addEventListener('loadeddata', function () {
                    markCurrentSourcePlayable();
                    networkRecoveryPending = false;
                    updatePlaybackProgressSnapshot();
                    setBufferingState(false);
                });
                video.addEventListener('play', function () {
                    userPaused = false;
                    markLiveElapsedRunning();
                    networkRecoveryPending = false;
                    clearAutoplayRecoveryTimers();
                    markCurrentSourcePlayable();
                    updatePlaybackProgressSnapshot();
                    setBufferingState(false);
                    updateUi();
                    showControls();
                    if (hls && !startupProfileRestored) {
                        startupProfileRestored = true;
                        try {
                            hls.nextLevel = -1;
                        } catch (error) {}
                    }
                });
                video.addEventListener('pause', function () {
                    markLiveElapsedPaused();
                    const unexpectedLivePause = !userPaused && !video.ended && getPlaybackState().live;
                    networkRecoveryPending = unexpectedLivePause;
                    setBufferingState(unexpectedLivePause);
                    clearControlsHideTimer();
                    playerShell.classList.remove('controls-hidden');
                    playerShell.classList.add('controls-visible');
                    updateUi();
                    window.setTimeout(function () {
                        if (!userPaused && !video.ended && video.paused) {
                            attemptUnexpectedLiveRecovery('unexpected_pause', true);
                        }
                    }, 250);
                });
                video.addEventListener('ended', function () {
                    userPaused = false;
                    markLiveElapsedPaused();
                    networkRecoveryPending = getPlaybackState().live;
                    setBufferingState(networkRecoveryPending);
                    clearControlsHideTimer();
                    playerShell.classList.remove('controls-hidden');
                    playerShell.classList.add('controls-visible');
                    updateUi();
                    if (getPlaybackState().live) {
                        attemptUnexpectedLiveRecovery('unexpected_end', true);
                    }
                });

                ['mousemove', 'mouseenter', 'touchstart', 'touchmove', 'pointerdown'].forEach(function (eventName) {
                    playerShell.addEventListener(eventName, showControls, { passive: true });
                });

                document.addEventListener('fullscreenchange', function () {
                    updateUi();
                    showControls();
                });

                document.addEventListener('webkitfullscreenchange', function () {
                    updateUi();
                    showControls();
                });

                video.addEventListener('webkitbeginfullscreen', function () {
                    updateUi();
                    showControls();
                });

                video.addEventListener('webkitendfullscreen', function () {
                    updateUi();
                    showControls();
                });

                if (!document.pictureInPictureEnabled) {
                    pipButton.style.display = 'none';
                }

                currentPlayableUrl = (initialProxyUrl || '').trim() || buildProxyUrl();
                markCurrentSourceStarted();

                if (supportsNativeHls) {
                    video.src = currentPlayableUrl;
                    video.addEventListener('loadedmetadata', function () {
                        attemptAutoplay();
                        updateUi();
                        updatePreviewAvailability();
                    }, { once: true });
                    video.addEventListener('canplay', function () {
                        networkRecoveryPending = false;
                        setBufferingState(false);
                    });
                    video.addEventListener('error', function () {
                        networkRecoveryPending = !userPaused;
                        setBufferingState(true);
                    });
                } else if (!supportsAirPlay && window.Hls && window.Hls.isSupported()) {
                    hls = new window.Hls({
                        lowLatencyMode: true,
                        startLevel: 0,
                        testBandwidth: false,
                        liveSyncDurationCount: 1,
                        liveMaxLatencyDurationCount: 2,
                        maxBufferLength: 6,
                        maxMaxBufferLength: 12,
                        backBufferLength: 15,
                        enableWorker: false
                    });

                    hls.loadSource(currentPlayableUrl);
                    hls.attachMedia(video);
                    hls.on(window.Hls.Events.MANIFEST_PARSED, function () {
                        updatePlaybackProgressSnapshot();
                        attemptAutoplay();
                        updateUi();
                        updatePreviewAvailability();
                    });
                    hls.on(window.Hls.Events.LEVEL_LOADED, function () {
                        updateUi();
                        updatePreviewAvailability();
                    });
                    hls.on(window.Hls.Events.ERROR, function (event, data) {
                        if (!data) {
                            return;
                        }

                        if (!data.fatal) {
                            return;
                        }

                        if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) {
                            refreshStreamSource(true).then(function (refreshed) {
                                if (refreshed) {
                                    scheduleAutoplayRecovery(450);
                                    return;
                                }
                                try {
                                    hls.startLoad();
                                    setBufferingState(true);
                                    scheduleAutoplayRecovery(450);
                                } catch (error) {
                                    setBufferingState(false);
                                }
                            });
                            return;
                        }

                        if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) {
                            refreshStreamSource(true).then(function (refreshed) {
                                if (refreshed) {
                                    scheduleAutoplayRecovery(450);
                                    return;
                                }
                                try {
                                    hls.recoverMediaError();
                                    setBufferingState(true);
                                    scheduleAutoplayRecovery(450);
                                } catch (error) {
                                    setBufferingState(false);
                                }
                            });
                            return;
                        }

                        setBufferingState(false);
                    });
                }

                updateIcons();
                updateUi();
                updatePreviewAvailability();
                setBufferingState(false);
                showControls();

                if (matchId > 0) {
                    refreshIntervalId = window.setInterval(function () {
                        refreshStreamSource(false);
                    }, 60000);
                }

                playbackWatchdogId = window.setInterval(function () {
                    const state = getPlaybackState();
                    if (!state.live || userPaused || video.ended) {
                        return;
                    }

                    const now = Date.now();
                    const startupStalled = !playbackStartedForCurrentSource
                        && currentSourceStartedAt
                        && (now - currentSourceStartedAt) > 7000;
                    const liveBufferingTooLong = bufferingVisible
                        && bufferingStartedAt
                        && (now - bufferingStartedAt) > 10000;

                    if (startupStalled || liveBufferingTooLong) {
                        attemptUnexpectedLiveRecovery(startupStalled ? 'watchdog_startup' : 'watchdog_buffering', true);
                        return;
                    }

                    if (video.paused || bufferingVisible) {
                        return;
                    }

                    const currentPosition = Number(video.currentTime || 0);
                    if (Math.abs(currentPosition - lastPlaybackPosition) > 0.2) {
                        updatePlaybackProgressSnapshot();
                        return;
                    }

                    if ((now - lastPlaybackProgressAt) > 12000) {
                        attemptUnexpectedLiveRecovery('watchdog_stall', true);
                    }
                }, 8000);

                window.addEventListener('beforeunload', function () {
                    if (refreshIntervalId) {
                        clearInterval(refreshIntervalId);
                    }
                    if (playbackWatchdogId) {
                        clearInterval(playbackWatchdogId);
                    }
                    clearAutoplayRecoveryTimers();
                });
            })();
        </script>
    <?php endif; ?>
</body>
</html>
