<?php
require_once __DIR__ . '/includes/EmbedAccess.php';

error_reporting(0);
ini_set('display_errors', 0);

$encodedUrl = trim((string)($_GET['url'] ?? ''));
$title = trim((string)($_GET['title'] ?? 'Live Frame'));
$requestedNoSandbox = (string)($_GET['nosandbox'] ?? '0') === '1';

$frameUrl = '';
if ($encodedUrl !== '') {
    $decoded = base64_decode(strtr($encodedUrl, '-_', '+/'), true);
    if ($decoded !== false) {
        $frameUrl = trim((string)$decoded);
    }
}

$isValidFrame = (bool)preg_match('#^https?://#i', $frameUrl);
$frameHost = strtolower((string)(parse_url($frameUrl, PHP_URL_HOST) ?: ''));
$supportsRelaxedSandbox = false;
if ($frameHost !== '') {
    foreach (['dynamicsecular.net', 'wgstream.sx'] as $suffix) {
        if ($frameHost === $suffix || substr($frameHost, -strlen('.' . $suffix)) === '.' . $suffix) {
            $supportsRelaxedSandbox = true;
            break;
        }
    }
}
$useSandbox = !($requestedNoSandbox && $supportsRelaxedSandbox);

$incomingOrigin = embedAccessResolveIncomingOrigin();
$embedAccess = embedAccessVerifyRequest($_GET, $incomingOrigin, true);
$frameAncestors = embedAccessBuildFrameAncestorsDirective($embedAccess['allowed_origin'] ?? null);
$showErrorState = !$embedAccess['ok'] || !$isValidFrame;

header('Content-Type: text/html; charset=UTF-8');
if (function_exists('header_remove')) {
    header_remove('X-Frame-Options');
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' https: data: blob:; script-src 'self' 'unsafe-inline' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: blob: https:; connect-src * data: blob:; media-src * data: blob:; frame-src *; frame-ancestors {$frameAncestors};");

if (!$embedAccess['ok']) {
    http_response_code(403);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title !== '' ? $title : 'Live Frame', ENT_QUOTES, 'UTF-8'); ?></title>
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
        }

        html,
        body {
            width: 100%;
            height: 100%;
            overflow: hidden;
        }

        body.error-mode {
            background: #ffffff;
            font-family: 'Outfit', sans-serif;
        }

        .frame-shell {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(circle at top, rgba(25, 118, 210, 0.14), transparent 45%),
                radial-gradient(circle at bottom, rgba(0, 200, 83, 0.10), transparent 35%),
                #05070d;
        }

        .frame-shell iframe {
            width: 100%;
            height: 100%;
            border: 0;
            background: #000;
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

    </style>
</head>
<body class="<?php echo $showErrorState ? 'error-mode' : ''; ?>">
    <?php if ($showErrorState): ?>
        <main class="error-page">
            <div id="strokeChart" class="error-chart" aria-label="Haya Shoout Stroke"></div>
        </main>
    <?php else: ?>
        <div class="frame-shell">
            <iframe src="<?php echo htmlspecialchars($frameUrl, ENT_QUOTES, 'UTF-8'); ?>" allow="autoplay; encrypted-media; picture-in-picture; fullscreen"<?php echo $useSandbox ? ' sandbox="allow-forms allow-same-origin allow-scripts allow-popups allow-presentation"' : ''; ?> referrerpolicy="strict-origin-when-cross-origin"></iframe>
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
    <?php endif; ?>
</body>
</html>
