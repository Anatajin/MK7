const fs = require('fs');
const puppeteer = require('puppeteer');
const { preparePuppeteerRuntime, buildLaunchOptions } = require('./puppeteer_runtime');

const matchUrl = String(process.argv[2] || '').trim();

const linuxBundledChrome = '/var/www/html/.puppeteer-cache/chrome/linux-145.0.7632.77/chrome-linux64/chrome';
if (!process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(linuxBundledChrome)) {
    process.env.PUPPETEER_EXECUTABLE_PATH = linuxBundledChrome;
}

const runtime = preparePuppeteerRuntime(__dirname);
const widgetProfile = process.env.AISCORE_WIDGET_PROFILE || '74rekh26eseunr0';

function writePayload(payload, exitCode = 0) {
    process.stdout.write(JSON.stringify(payload));
    process.exit(exitCode);
}

function normalizeUrl(value) {
    const text = String(value || '').trim().replace(/&amp;/g, '&');
    if (!text) {
        return '';
    }

    if (text.startsWith('//')) {
        return `https:${text}`;
    }

    if (/^https?:\/\//i.test(text)) {
        return text;
    }

    return '';
}

function isAiScoreMatchPage(url) {
    return /^https?:\/\/(?:www\.)?aiscore\.com\/[^/]+\/match-/i.test(String(url || ''));
}

function isRealLiveSource(url) {
    const normalized = normalizeUrl(url);
    if (!normalized || isAiScoreMatchPage(normalized)) {
        return false;
    }

    let parsed;
    try {
        parsed = new URL(normalized);
    } catch (error) {
        return false;
    }

    const host = parsed.hostname.toLowerCase();
    const path = parsed.pathname.toLowerCase();
    if (/\.(?:js|css|png|jpe?g|gif|svg|ico|webp|woff2?)(?:$|[?#])/i.test(path)) {
        return false;
    }

    if (/^widgets\.thesports\d*\.com$/i.test(host)) {
        return /\/(?:[a-z]{2}\/)?(?:3d|2d|animation|live|football|basketball)/i.test(path)
            || (parsed.searchParams.has('profile') && parsed.searchParams.has('id'));
    }

    return /\.(?:m3u8|mpd)(?:[?#]|$)/i.test(path)
        || /\/(?:hls|dash|embed|iframe|stream)(?:\/|$)/i.test(path);
}

function rankCandidate(candidate) {
    const url = normalizeUrl(candidate && candidate.url);
    const type = String(candidate && candidate.type || '');
    let score = 0;

    if (/video/i.test(type)) score += 60;
    if (/state\.video/i.test(type)) score += 50;
    if (/m3u8/i.test(url)) score += 45;
    if (/widgets\.thesports/i.test(url)) score += 40;
    if (/state\.animation/i.test(type)) score += 35;
    if (/iframe/i.test(type)) score += 25;
    if (/aiscore\.com\/[^/]+\/match-/i.test(url)) score -= 200;

    return score;
}

function extractAiScoreMatchId(url) {
    try {
        const parsed = new URL(String(url || ''));
        const parts = parsed.pathname.split('/').filter(Boolean);
        return parts.length ? parts[parts.length - 1] : '';
    } catch (error) {
        const match = String(url || '').match(/\/([a-z0-9]+)(?:[?#]|$)/i);
        return match ? match[1] : '';
    }
}

function readProtoVarint(buffer, offset) {
    let result = 0n;
    let shift = 0n;
    let position = offset;

    while (position < buffer.length) {
        const byte = buffer[position];
        position += 1;
        result |= BigInt(byte & 0x7f) << shift;

        if ((byte & 0x80) === 0) {
            return { value: result, offset: position };
        }

        shift += 7n;
    }

    throw new Error('Unexpected end of protobuf varint');
}

function extractTheSportsIdFromStaticDetail(buffer) {
    if (!Buffer.isBuffer(buffer) || buffer.length < 4 || buffer[0] === 0x3c) {
        return null;
    }

    try {
        let offset = 0;
        const topTag = readProtoVarint(buffer, offset);
        offset = topTag.offset;
        const topField = Number(topTag.value >> 3n);
        const topWireType = Number(topTag.value & 7n);
        if (topField !== 2 || topWireType !== 2) {
            return null;
        }

        const dataLength = readProtoVarint(buffer, offset);
        offset = dataLength.offset;
        const dataEnd = offset + Number(dataLength.value);
        if (dataEnd > buffer.length) {
            return null;
        }

        const data = buffer.subarray(offset, dataEnd);
        let dataOffset = 0;
        const matchIdTag = readProtoVarint(data, dataOffset);
        dataOffset = matchIdTag.offset;
        const field = Number(matchIdTag.value >> 3n);
        const wireType = Number(matchIdTag.value & 7n);
        if (field !== 1 || wireType !== 0) {
            return null;
        }

        const matchId = readProtoVarint(data, dataOffset);
        const numeric = Number(matchId.value);
        return Number.isFinite(numeric) && numeric > 0 ? numeric : null;
    } catch (error) {
        return null;
    }
}

async function fetchTheSportsWidgetUrl(matchPageUrl) {
    const matchId = extractAiScoreMatchId(matchPageUrl);
    if (!matchId) {
        return null;
    }

    const apiUrl = `https://api.thesports01.com/api/f/sd?id=${encodeURIComponent(matchId)}&lang=aa`;
    const widgetUrl = `https://widgets.thesports01.com/aa/3d/football?profile=${encodeURIComponent(widgetProfile)}&id=${encodeURIComponent(matchId)}`;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20000);

    try {
        const response = await fetch(apiUrl, {
            headers: {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
                'Accept': 'application/octet-stream,*/*',
                'Origin': 'https://widgets.thesports01.com',
                'Referer': widgetUrl
            },
            signal: controller.signal
        });

        if (!response.ok) {
            return null;
        }

        const buffer = Buffer.from(await response.arrayBuffer());
        const theSportsId = extractTheSportsIdFromStaticDetail(buffer);
        if (!theSportsId) {
            return null;
        }

        return `https://widgets.thesports01.com/aa/3d/football?profile=${encodeURIComponent(widgetProfile)}&id=${theSportsId}`;
    } catch (error) {
        return null;
    } finally {
        clearTimeout(timer);
    }
}

async function applyBrowserEvasions(page) {
    await page.evaluateOnNewDocument(() => {
        Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
        Object.defineProperty(navigator, 'languages', { get: () => ['ar-MA', 'ar', 'en-US', 'en'] });
        Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });

        const originalQuery = window.navigator.permissions && window.navigator.permissions.query;
        if (originalQuery) {
            window.navigator.permissions.query = (parameters) => (
                parameters && parameters.name === 'notifications'
                    ? Promise.resolve({ state: Notification.permission })
                    : originalQuery(parameters)
            );
        }

        const getParameter = WebGLRenderingContext.prototype.getParameter;
        WebGLRenderingContext.prototype.getParameter = function patchedGetParameter(parameter) {
            if (parameter === 37445) return 'Intel Inc.';
            if (parameter === 37446) return 'Intel Iris OpenGL Engine';
            return getParameter.call(this, parameter);
        };
    });
}

async function extractCandidatesFromPage(page) {
    return page.evaluate(() => {
        const normalize = (value) => {
            const text = String(value || '').trim().replace(/&amp;/g, '&');
            if (!text) return '';
            if (text.startsWith('//')) return `https:${text}`;
            if (/^https?:\/\//i.test(text)) return text;
            return '';
        };

        const candidates = [];
        const push = (type, value) => {
            const url = normalize(value);
            if (!url) return;
            candidates.push({ type, url });
        };

        const state = window.$nuxt?.$store?.state || window.__NUXT__?.state || {};
        const detail = state.football?.detail || {};
        const data = detail.WebMatchData || {};
        const video = data.video || {};
        const animation = data.animation || {};

        push('state.video.url.pc', video?.url?.pc);
        push('state.video.url.mobile', video?.url?.mobile);
        push('state.video.url', typeof video?.url === 'string' ? video.url : '');
        push('state.video.pc', video?.pc);
        push('state.video.mobile', video?.mobile);
        push('state.animation.url', animation?.url);

        const seenObjects = new Set();
        const visit = (value, path, depth) => {
            if (!value || depth > 7) return;

            if (typeof value === 'string') {
                if (/widgets\.thesports|\.m3u8|\/hls\/|player|embed|iframe/i.test(value)) {
                    push(path, value);
                }
                return;
            }

            if (typeof value !== 'object' || seenObjects.has(value)) return;
            seenObjects.add(value);

            Object.entries(value).forEach(([key, child]) => visit(child, `${path}.${key}`, depth + 1));
        };

        visit(data, 'state.WebMatchData', 0);

        document.querySelectorAll('iframe[src]').forEach((iframe, index) => {
            push(`dom.iframe.${index}`, iframe.getAttribute('src'));
        });

        document.querySelectorAll('video[src], source[src]').forEach((node, index) => {
            push(`dom.video.${index}`, node.getAttribute('src'));
        });

        document.querySelectorAll('[data-src], [data-url], [href]').forEach((node, index) => {
            push(`dom.attr.${index}.data-src`, node.getAttribute('data-src'));
            push(`dom.attr.${index}.data-url`, node.getAttribute('data-url'));
            push(`dom.attr.${index}.href`, node.getAttribute('href'));
        });

        const unique = [];
        const seen = new Set();
        candidates.forEach((candidate) => {
            if (!candidate.url || seen.has(candidate.url)) return;
            seen.add(candidate.url);
            unique.push(candidate);
        });

        return unique;
    });
}

(async () => {
    if (!/^https:\/\/www\.aiscore\.com\/[^/]+\/match-/i.test(matchUrl)) {
        writePayload({
            status: 'error',
            message: 'A valid AiScore match URL is required'
        }, 2);
    }

    const widgetUrl = await fetchTheSportsWidgetUrl(matchUrl);
    if (widgetUrl) {
        writePayload({
            status: 'success',
            match_url: matchUrl,
            url: widgetUrl,
            source_type: 'thesports.static_detail.mid',
            candidates: [{ type: 'thesports.static_detail.mid', url: widgetUrl }]
        });
    }

    let browser;
    const networkCandidates = [];

    try {
        browser = await puppeteer.launch(buildLaunchOptions(puppeteer, runtime, {
            args: [
                '--disable-blink-features=AutomationControlled',
                '--lang=ar-MA,ar,en-US,en'
            ]
        }));

        const page = await browser.newPage();
        await applyBrowserEvasions(page);
        await page.setViewport({ width: 1366, height: 768, deviceScaleFactor: 1 });
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36');
        await page.setExtraHTTPHeaders({
            'Accept-Language': 'ar-MA,ar;q=0.9,en-US;q=0.8,en;q=0.7'
        });

        page.on('request', (request) => {
            const url = normalizeUrl(request.url());
            if (isRealLiveSource(url)) {
                networkCandidates.push({ type: 'network.request', url });
            }
        });

        page.on('response', (response) => {
            const url = normalizeUrl(response.url());
            if (isRealLiveSource(url)) {
                networkCandidates.push({ type: 'network.response', url });
            }
        });

        await page.setRequestInterception(true);
        page.on('request', (request) => {
            const type = request.resourceType();
            const url = request.url();

            if (['image', 'font', 'media'].includes(type) && !isRealLiveSource(url)) {
                request.abort();
                return;
            }

            request.continue();
        });

        await page.goto(matchUrl, {
            waitUntil: 'domcontentloaded',
            timeout: 60000
        });

        await page.waitForFunction(() => {
            const state = window.$nuxt?.$store?.state || window.__NUXT__?.state || {};
            const data = state.football?.detail?.WebMatchData || {};
            const animationUrl = data.animation?.url || '';
            const videoUrl = data.video?.url?.pc || data.video?.url?.mobile || data.video?.url || '';
            const iframe = document.querySelector('iframe[src*="thesports"], iframe[src*="player"], iframe[src*="embed"]');

            return !!animationUrl
                || !!videoUrl
                || !!iframe;
        }, { timeout: 75000 }).catch(() => {});

        await new Promise((resolve) => setTimeout(resolve, 2500));

        const pageCandidates = await extractCandidatesFromPage(page).catch(() => []);
        const challenge = await page.evaluate(() => {
            const text = document.body ? document.body.innerText : '';
            const title = document.title || '';
            return /Just a moment|Cloudflare|checking your browser|التحقق|لحظة/i.test(`${title}\n${text}`);
        }).catch(() => false);

        const allCandidates = [...pageCandidates, ...networkCandidates]
            .map((candidate) => ({ ...candidate, url: normalizeUrl(candidate.url) }))
            .filter((candidate) => isRealLiveSource(candidate.url));

        const unique = [];
        const seen = new Set();
        allCandidates.forEach((candidate) => {
            if (seen.has(candidate.url)) return;
            seen.add(candidate.url);
            unique.push(candidate);
        });

        unique.sort((a, b) => rankCandidate(b) - rankCandidate(a));

        if (unique.length > 0) {
            writePayload({
                status: 'success',
                match_url: matchUrl,
                url: unique[0].url,
                source_type: unique[0].type,
                candidates: unique
            });
        }

        writePayload({
            status: 'not_found',
            match_url: matchUrl,
            challenged: challenge,
            candidates: []
        }, 3);
    } catch (error) {
        writePayload({
            status: 'error',
            match_url: matchUrl,
            message: error && error.message ? error.message : String(error)
        }, 1);
    } finally {
        if (browser) {
            await browser.close().catch(() => {});
        }
    }
})();
