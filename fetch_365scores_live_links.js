/**
 * 365Scores rendered live-page link extractor.
 * Usage: node fetch_365scores_live_links.js <live_page_url>
 * Returns JSON: { "links": [{ "id": "123", "href": "https://...", "text": "..." }] }
 */

const { preparePuppeteerRuntime, buildLaunchOptions } = require('./puppeteer_runtime');
const runtime = preparePuppeteerRuntime(__dirname);
const puppeteer = require('puppeteer');

const pageUrl = process.argv[2] || 'https://www.365scores.com/ar/football/live';

(async () => {
    let browser;

    try {
        browser = await puppeteer.launch(buildLaunchOptions(puppeteer, runtime));
        const page = await browser.newPage();

        await page.setUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
            '(KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36'
        );
        await page.setExtraHTTPHeaders({
            'Accept-Language': pageUrl.includes('/ar/') ? 'ar,en;q=0.9' : 'en-US,en;q=0.9,ar;q=0.8',
        });

        await page.setRequestInterception(true);
        page.on('request', (req) => {
            if (['font', 'image', 'media'].includes(req.resourceType())) {
                req.abort();
                return;
            }

            req.continue();
        });

        await page.goto(pageUrl, {
            waitUntil: 'networkidle2',
            timeout: 45000,
        });

        try {
            await page.waitForFunction(
                () => document.querySelectorAll('a[href*="/football/match/"][href*="#id="]').length > 0,
                { timeout: 15000 }
            );
        } catch (e) {
            // No rendered match links found. Return an empty payload so PHP can use the API fallback URL.
        }

        const links = await page.evaluate(() => {
            const seen = new Set();

            return Array.from(document.querySelectorAll('a[href*="/football/match/"][href*="#id="]'))
                .map((anchor) => {
                    const href = new URL(anchor.getAttribute('href'), window.location.href).href;
                    const match = href.match(/#id=(\d+)/);
                    if (!match) return null;

                    return {
                        id: match[1],
                        href,
                        text: (anchor.innerText || anchor.textContent || '').replace(/\s+/g, ' ').trim(),
                    };
                })
                .filter((entry) => {
                    if (!entry || seen.has(entry.id)) return false;
                    seen.add(entry.id);
                    return true;
                });
        });

        process.stdout.write(JSON.stringify({ links }));
    } catch (err) {
        process.stderr.write('Error: ' + err.message + '\n');
        process.stdout.write(JSON.stringify({ links: [] }));
    } finally {
        if (browser) await browser.close();
    }
})();
