<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPORT</title>
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
            <div id="strokeChart" aria-label="SPORT Stroke"></div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    <script>
        const chartDom = document.getElementById('strokeChart');
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
    </script>
    <?php require_once 'includes/public_auto_runner.php'; ?>
</body>
</html>
