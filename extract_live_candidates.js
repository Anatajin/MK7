const fs = require('fs');
const puppeteer = require('puppeteer');
const { preparePuppeteerRuntime, buildLaunchOptions } = require('./puppeteer_runtime');

const targetUrl = process.argv[2];

if (!targetUrl) {
  process.stderr.write('Usage: node extract_live_candidates.js <live_url>\n');
  process.exit(1);
}

const linuxBundledChrome = '/var/www/html/.puppeteer-cache/chrome/linux-145.0.7632.77/chrome-linux64/chrome';
if (!process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(linuxBundledChrome)) {
  process.env.PUPPETEER_EXECUTABLE_PATH = linuxBundledChrome;
}

const runtime = preparePuppeteerRuntime(__dirname);

function isInterestingUrl(url) {
  if (!url || !/^https?:\/\//i.test(url)) {
    return false;
  }

  if (/histats|dtscout|doubleclick|googlesyndication|jsdelivr|acscdn|bvtpk|slayingbugeyes|cloudflareinsights|google-analytics/i.test(url)) {
    return false;
  }

  if (/\.(?:css|js|json|png|jpe?g|gif|svg|webp|woff2?|ttf|ico)(?:[?#].*)?$/i.test(url)) {
    return false;
  }

  if (/live-widgets\.com\/football\/(?:player|team)\//i.test(url)) {
    return false;
  }

  return /m3u8|frame\.php|channels\/|stream|player|embed|watch|share\/stream|thesports01\.com\/aa\/3d\/football|\/3d\/football\?/i.test(url);
}

(async () => {
  let browser;
  const candidates = new Set();

  try {
    browser = await puppeteer.launch(buildLaunchOptions(puppeteer, runtime, { headless: 'new' }));
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });
    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36');
    await page.setExtraHTTPHeaders({
      'Accept-Language': 'en-US,en;q=0.9,ar;q=0.8'
    });

    page.on('request', (request) => {
      const url = request.url();
      if (isInterestingUrl(url)) {
        candidates.add(url);
      }
    });

    page.on('response', (response) => {
      const url = response.url();
      if (isInterestingUrl(url)) {
        candidates.add(url);
      }
    });

    await page.goto(targetUrl, { waitUntil: 'networkidle2', timeout: 45000 });
    await new Promise((resolve) => setTimeout(resolve, 3500));

    const clickServerButtons = async () => {
      const selectors = ['button.btn-server', '.btn-server', '.server-button', '[data-server]', '[data-channel]'];
      for (const selector of selectors) {
        const count = await page.$$eval(selector, (elements) => elements.length).catch(() => 0);
        if (!count) {
          continue;
        }

        for (let index = 0; index < Math.min(count, 6); index += 1) {
          const handles = await page.$$(selector);
          if (!handles[index]) {
            continue;
          }

          try {
            await handles[index].click({ delay: 40 });
            await new Promise((resolve) => setTimeout(resolve, 1600));
          } catch (error) {
            // Ignore single button failures and continue gathering from the page.
          }
        }
      }
    };

    await clickServerButtons();

    const clickLabeledTabs = async () => {
      const labels = ['TV', 'Live Stream', 'Match Live', 'Animation', 'البث المباشر', 'بث مباشر'];
      await page.evaluate((targetLabels) => {
        const normalize = (value) => (value || '').replace(/\s+/g, ' ').trim().toLowerCase();
        const wanted = targetLabels.map(normalize);
        const selectors = ['button', 'a', '[role="tab"]', '[role="button"]', 'li', 'span', 'div'];
        const clicked = new Set();

        const clickIfMatch = (element) => {
          if (!element || typeof element.click !== 'function') {
            return;
          }

          const text = normalize(element.textContent || '');
          if (!text) {
            return;
          }

          if (!wanted.some((label) => text === label || text.includes(label))) {
            return;
          }

          const key = `${element.tagName}:${text}`;
          if (clicked.has(key)) {
            return;
          }

          clicked.add(key);
          element.click();
        };

        selectors.forEach((selector) => {
          document.querySelectorAll(selector).forEach(clickIfMatch);
        });
      }, labels).catch(() => {});

      await new Promise((resolve) => setTimeout(resolve, 2000));
    };

    await clickLabeledTabs();

    const domCandidates = await page.evaluate(() => {
      const values = new Set();
      const add = (value) => {
        if (!value || typeof value !== 'string') {
          return;
        }

        const normalized = value.trim();
        if (!/^https?:\/\//i.test(normalized)) {
          return;
        }

        values.add(normalized);
      };

      document.querySelectorAll('iframe[src], source[src], video[src], a[href]').forEach((node) => {
        add(node.src || node.href || '');
      });

      const html = document.documentElement ? document.documentElement.outerHTML : '';
      const matches = html.match(/https?:\/\/[^"'\\\s<>]+/g) || [];
      matches.forEach(add);

      const decodeSlashEscapes = (value) => {
        if (typeof value !== 'string') {
          return value;
        }
        return value.replace(/\\u002F/gi, '/').replace(/\\\//g, '/');
      };

      const walk = (input, depth = 0) => {
        if (depth > 8 || input == null) {
          return;
        }

        if (typeof input === 'string') {
          add(decodeSlashEscapes(input));
          return;
        }

        if (Array.isArray(input)) {
          input.forEach((item) => walk(item, depth + 1));
          return;
        }

        if (typeof input === 'object') {
          Object.values(input).forEach((item) => walk(item, depth + 1));
        }
      };

      [window.__NUXT__, window.__INITIAL_STATE__, window.__APOLLO_STATE__].forEach((state) => walk(state));

      return Array.from(values);
    });

    domCandidates.forEach((url) => {
      if (isInterestingUrl(url)) {
        candidates.add(url);
      }
    });

    process.stdout.write(JSON.stringify(Array.from(candidates)));
  } catch (error) {
    process.stdout.write('[]');
    process.stderr.write(String(error && error.stack ? error.stack : error));
  } finally {
    if (browser) {
      await browser.close();
    }
  }
})();
