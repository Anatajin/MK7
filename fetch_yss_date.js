const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');
const { preparePuppeteerRuntime, buildLaunchOptions } = require('./puppeteer_runtime');

const requestedDate = process.argv[2];

if (!requestedDate) {
    process.stderr.write('Usage: node fetch_yss_date.js <YYYY-MM-DD>\n');
    process.exit(1);
}

const linuxBundledChrome = '/var/www/html/.puppeteer-cache/chrome/linux-145.0.7632.77/chrome-linux64/chrome';
if (!process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(linuxBundledChrome)) {
    process.env.PUPPETEER_EXECUTABLE_PATH = linuxBundledChrome;
}

const runtime = preparePuppeteerRuntime(__dirname);

(async () => {
    let browser;
    try {
        browser = await puppeteer.launch(buildLaunchOptions(puppeteer, runtime));

        const countMatches = (html) => (typeof html === 'string' ? (html.match(/ajax-match-item/g) || []).length : 0);

        const fetchFromPath = async (startPath) => {
            const page = await browser.newPage();
            await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

            await page.setRequestInterception(true);
            page.on('request', (req) => {
                const type = req.resourceType();
                if (['image', 'font', 'media'].includes(type)) {
                    req.abort();
                    return;
                }
                req.continue();
            });

            await page.goto(`https://www.ysscores.com${startPath}`, {
                waitUntil: 'networkidle2',
                timeout: 45000
            });

            const html = await page.evaluate(async (date) => {
                const token = document.querySelector('meta[name="_token"]')?.content || '';
                await fetch('/ar/change_zone', {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'X-CSRF-Token': token,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': '*/*'
                    },
                    body: new URLSearchParams({ zone_is: 'utc' }).toString()
                });

                const response = await fetch('/ar/match_date_to', {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'X-CSRF-Token': token,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': '*/*'
                    },
                    body: new URLSearchParams({ get_date: date }).toString()
                });

                return await response.text();
            }, requestedDate);

            await page.close();
            return html;
        };

        const candidates = [];
        for (const startPath of ['/ar/index', '/ar/today_matches']) {
            try {
                const html = await fetchFromPath(startPath);
                candidates.push({ html, count: countMatches(html) });
            } catch (error) {
                candidates.push({ html: '', count: 0 });
            }
        }

        const html = candidates.reduce((best, candidate) => candidate.count > best.count ? candidate : best, candidates[0]).html;

        process.stdout.write(typeof html === 'string' ? html.trim() : '');
    } catch (error) {
        process.stderr.write((error && error.stack) ? error.stack : String(error));
        process.exit(1);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
})();
