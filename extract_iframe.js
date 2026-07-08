/**
 * 365Scores Live Match Tracker iframe extractor
 * Usage: node extract_iframe.js <match_url>
 * Returns ONLY the <iframe> element if found, or empty string if not found
 */

const fs = require('fs');
const { preparePuppeteerRuntime, buildLaunchOptions } = require('./puppeteer_runtime');
const runtime = preparePuppeteerRuntime(__dirname);
const puppeteer = require('puppeteer');

const matchUrl = process.argv[2];

if (!matchUrl) {
    process.stderr.write('Usage: node extract_iframe.js <match_url>\n');
    process.exit(1);
}

(async () => {
    let browser;
    try {
        browser = await puppeteer.launch(buildLaunchOptions(puppeteer, runtime));

        const page = await browser.newPage();

        // Block unnecessary resources for speed
        await page.setRequestInterception(true);
        page.on('request', (req) => {
            const type = req.resourceType();
            if (['image', 'font', 'media'].includes(type)) {
                req.abort();
            } else {
                req.continue();
            }
        });

        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        // Navigate to the match page
        await page.goto(matchUrl, {
            waitUntil: 'networkidle2',
            timeout: 30000
        });

        // Wait for the iframe to appear (max 7 seconds)
        let iframeHtml = '';
        try {
            await page.waitForSelector('#liveMatchTrackerModule iframe[title="Live Match Tracker"]', {
                timeout: 7000
            });

            // Extract ONLY the <iframe> element's outerHTML
            iframeHtml = await page.evaluate(() => {
                const iframe = document.querySelector('#liveMatchTrackerModule iframe[title="Live Match Tracker"]');
                if (iframe) {
                    return iframe.outerHTML;
                }
                return '';
            });
        } catch (e) {
            // iframe not found within timeout - that's OK, some matches don't have it
            iframeHtml = '';
        }

        // Output the iframe HTML (or empty string)
        process.stdout.write(iframeHtml);

    } catch (err) {
        process.stderr.write('Error: ' + err.message + '\n');
        process.stderr.write('User data dir: ' + runtime.userDataDir + '\n');
        const launchPath = browser && browser.process() ? browser.process().spawnfile : null;
        if (launchPath && fs.existsSync(launchPath)) {
            process.stderr.write('Executable: ' + launchPath + '\n');
        }
        process.exit(1);
    } finally {
        if (browser) await browser.close();
    }
})();
