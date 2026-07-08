const fs = require('fs');
const puppeteer = require('puppeteer');
const { preparePuppeteerRuntime, buildLaunchOptions } = require('./puppeteer_runtime');

const requestedDate = process.argv[2] || new Date().toISOString().slice(0, 10);

const linuxBundledChrome = '/var/www/html/.puppeteer-cache/chrome/linux-145.0.7632.77/chrome-linux64/chrome';
if (!process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(linuxBundledChrome)) {
    process.env.PUPPETEER_EXECUTABLE_PATH = linuxBundledChrome;
}

const runtime = preparePuppeteerRuntime(__dirname);

function normalizeDate(value) {
    const text = String(value || '').trim();
    return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : new Date().toISOString().slice(0, 10);
}

function nonEmptyBefore(lines, index, limit = 10) {
    for (let i = index - 1, seen = 0; i >= 0 && seen < limit; i -= 1) {
        const line = String(lines[i] || '').trim();
        if (!line) {
            continue;
        }
        seen += 1;
        return { line, index: i };
    }

    return null;
}

function nonEmptyAfter(lines, index, limit = 10) {
    for (let i = index + 1, seen = 0; i < lines.length && seen < limit; i += 1) {
        const line = String(lines[i] || '').trim();
        if (!line) {
            continue;
        }
        seen += 1;
        return { line, index: i };
    }

    return null;
}

function parseTeamLine(line) {
    const match = String(line || '').trim().match(/^\[([^\]]+)\]\(https:\/\/www\.aiscore\.com\/aa\/team-([^/]+)\/[^)]+\)$/i);
    if (!match) {
        return null;
    }

    return {
        name: match[1].trim(),
        slug: match[2].trim()
    };
}

function parseJinaMarkdown(markdown, date) {
    const lines = String(markdown || '').split(/\r?\n/);
    const matches = [];
    let currentLeague = '';

    for (let i = 0; i < lines.length; i += 1) {
        const line = lines[i].trim();
        const leagueMatch = line.match(/^[^:]+:\[([^\]]+)\]\(https:\/\/www\.aiscore\.com\/aa\/tournament-[^)]+\)$/i);
        if (leagueMatch) {
            currentLeague = leagueMatch[1].trim();
            continue;
        }

        const scoreMatch = line.match(/^\[([^\]]+)\]\((https:\/\/www\.aiscore\.com\/aa\/match-([^/]+)\/([a-z0-9]+))\)$/i);
        if (!scoreMatch) {
            continue;
        }

        const homeCandidate = nonEmptyBefore(lines, i, 6);
        const awayCandidate = nonEmptyAfter(lines, i, 6);
        const home = parseTeamLine(homeCandidate ? homeCandidate.line : '');
        const away = parseTeamLine(awayCandidate ? awayCandidate.line : '');
        if (!home || !away) {
            continue;
        }

        let clock = '';
        let finished = false;
        for (let j = i - 1, seen = 0; j >= 0 && seen < 10; j -= 1) {
            const candidate = lines[j].trim();
            if (!candidate) {
                continue;
            }
            seen += 1;
            if (/^\d{1,2}:\d{2}$/.test(candidate)) {
                clock = candidate.padStart(5, '0');
                break;
            }
            if (/^(FT|AET|AP|Pen)$/i.test(candidate)) {
                finished = true;
            }
        }

        const scoreText = scoreMatch[1].trim();
        const scoreParts = scoreText.match(/^(\d+)\s*-\s*(\d+)$/);
        const isLive = !!scoreParts && !finished;
        const isScheduled = /^VS$/i.test(scoreText);
        const statusText = finished ? 'انتهت' : (isScheduled ? 'لم تبدأ' : (isLive ? 'مباشر' : ''));

        matches.push({
            id: scoreMatch[4],
            source: 'aiscore.com',
            home_team: home.name,
            away_team: away.name,
            home_slug: home.slug,
            away_slug: away.slug,
            home_logo: '',
            away_logo: '',
            league: currentLeague,
            competition_slug: '',
            status_id: finished ? 8 : (isLive ? 2 : 1),
            match_status: finished ? 3 : (isLive ? 2 : 1),
            status_text: statusText,
            menu: 0,
            has_match_live: isLive,
            has_live_stream: isLive,
            match_time: 0,
            match_date: date,
            match_clock: clock,
            score_home: scoreParts ? Number(scoreParts[1]) : 0,
            score_away: scoreParts ? Number(scoreParts[2]) : 0,
            is_live: isLive,
            url: scoreMatch[2]
        });
    }

    return matches;
}

async function fetchJinaTodayMatches(date) {
    const target = 'https://www.aiscore.com/aa/today-matches/football';
    const readerUrl = `https://r.jina.ai/http://r.jina.ai/http://${target}`;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 60000);

    try {
        const response = await fetch(readerUrl, {
            headers: {
                'User-Agent': 'Mozilla/5.0 (compatible; SportLiveBot/1.0)',
                'Accept': 'text/markdown,text/plain,*/*'
            },
            signal: controller.signal
        });

        if (!response.ok) {
            return [];
        }

        const markdown = await response.text();
        return parseJinaMarkdown(markdown, date);
    } catch (error) {
        return [];
    } finally {
        clearTimeout(timer);
    }
}

(async () => {
    let browser;
    try {
        browser = await puppeteer.launch(buildLaunchOptions(puppeteer, runtime));
        const page = await browser.newPage();

        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        await page.setExtraHTTPHeaders({
            'Accept-Language': 'ar,en;q=0.9'
        });

        await page.setRequestInterception(true);
        page.on('request', (req) => {
            const type = req.resourceType();
            if (['image', 'font', 'media'].includes(type)) {
                req.abort();
                return;
            }
            req.continue();
        });

        await page.goto('https://www.aiscore.com/aa', {
            waitUntil: 'domcontentloaded',
            timeout: 60000
        });

        let payload = [];
        const challenged = await page.evaluate(() => {
            const text = document.body ? document.body.innerText : '';
            return document.title.includes('لحظة') || text.includes('Cloudflare') || text.includes('التحقق من الأمان');
        }).catch(() => false);

        if (process.env.AISCORE_FORCE_JINA !== '1' && !challenged) {
            await page.waitForFunction(() => {
                const state = window.$nuxt?.$store?.state || window.__NUXT__?.state || {};
                const homeRoot = state.football && state.football['home-new'];
                const home = homeRoot && (homeRoot.matchesData || homeRoot);
                if (!home) return false;
                if (Array.isArray(home.matches) && home.matches.length > 0) return true;
                return !!(home.matchesMap && Object.keys(home.matchesMap).length > 0);
            }, { timeout: 45000 }).catch(() => {});

            await new Promise((resolve) => setTimeout(resolve, 2000));

            payload = await page.evaluate((date) => {
            const state = window.$nuxt?.$store?.state || window.__NUXT__?.state || {};
            const homeRoot = (state.football && state.football['home-new']) || {};
            const home = homeRoot.matchesData || homeRoot;
            const teamsMap = home.teamsMap || {};
            const competitionsMap = home.competitionsMap || {};
            const matchesMap = home.matchesMap || {};

            const toMap = (list) => {
                const map = {};
                (Array.isArray(list) ? list : []).forEach((item) => {
                    if (item && item.id) {
                        map[String(item.id)] = item;
                    }
                });
                return map;
            };

            const teamById = { ...toMap(home.teams), ...teamsMap };
            const competitionById = { ...toMap(home.competitions), ...competitionsMap };
            const matchById = { ...toMap(home.matches), ...matchesMap };
            const rawMatches = Array.isArray(home.matches) ? home.matches : Object.values(matchById);

            const bitEnabled = (value, bit) => {
                const numeric = Number(value || 0);
                return (numeric & (1 << bit)) > 0;
            };

            const statusText = (match) => {
                const matchStatus = Number(match.matchStatus || 0);
                const statusId = Number(match.statusId || 0);
                if (matchStatus === 1) return 'لم تبدأ';
                if (matchStatus === 3) return 'انتهت';
                if (statusId === 2) return 'الشوط الأول';
                if (statusId === 3) return 'استراحة';
                if (statusId === 4) return 'الشوط الثاني';
                if (statusId === 5) return 'وقت إضافي';
                if (statusId === 6) return 'ركلات الترجيح';
                if (matchStatus === 2) return 'مباشر';
                return '';
            };

            const formatDate = (timestamp) => {
                const value = Number(timestamp || 0);
                if (!value) return '';
                const dateObj = new Date(value * 1000);
                const yyyy = dateObj.getFullYear();
                const mm = String(dateObj.getMonth() + 1).padStart(2, '0');
                const dd = String(dateObj.getDate()).padStart(2, '0');
                return `${yyyy}-${mm}-${dd}`;
            };

            const formatTime = (timestamp) => {
                const value = Number(timestamp || 0);
                if (!value) return '';
                const dateObj = new Date(value * 1000);
                return `${String(dateObj.getHours()).padStart(2, '0')}:${String(dateObj.getMinutes()).padStart(2, '0')}`;
            };

            const normalizeMatch = (item) => {
                const match = typeof item === 'string' ? matchById[item] : item;
                if (!match || !match.id) return null;

                const homeId = String(match.homeTeam?.id || '');
                const awayId = String(match.awayTeam?.id || '');
                const competitionId = String(match.competition?.id || '');
                const homeTeam = teamById[homeId] || match.homeTeam || {};
                const awayTeam = teamById[awayId] || match.awayTeam || {};
                const competition = competitionById[competitionId] || match.competition || {};
                const menu = Number(match.menu || 0);
                const statusId = Number(match.statusId || 0);
                const matchStatus = Number(match.matchStatus || 0);
                const homeSlug = homeTeam.slug || 'home';
                const awaySlug = awayTeam.slug || 'away';

                return {
                    id: String(match.id),
                    source: 'aiscore.com',
                    home_team: homeTeam.name || match.homeName || '',
                    away_team: awayTeam.name || match.awayName || '',
                    home_slug: homeSlug,
                    away_slug: awaySlug,
                    home_logo: homeTeam.logo || '',
                    away_logo: awayTeam.logo || '',
                    league: competition.name || '',
                    competition_slug: competition.slug || '',
                    status_id: statusId,
                    match_status: matchStatus,
                    status_text: statusText(match),
                    menu,
                    has_match_live: bitEnabled(menu, 6) || bitEnabled(menu, 7),
                    has_live_stream: bitEnabled(menu, 7) || bitEnabled(menu, 8) || bitEnabled(menu, 9) || Number(match.ext?.hasMedia || 0) > 0,
                    match_time: Number(match.matchTime || 0),
                    match_date: formatDate(match.matchTime),
                    match_clock: formatTime(match.matchTime),
                    score_home: Number(match.homeScore ?? match.homeScores?.[0] ?? 0),
                    score_away: Number(match.awayScore ?? match.awayScores?.[0] ?? 0),
                    is_live: matchStatus === 2 || [2, 3, 4, 5, 6, 7].includes(statusId),
                    url: `https://www.aiscore.com/aa/match-${homeSlug}-${awaySlug}/${match.id}`
                };
            };

            return rawMatches.map(normalizeMatch).filter(Boolean);
            }, normalizeDate(requestedDate));
        }

        if (!payload.length) {
            payload = await fetchJinaTodayMatches(normalizeDate(requestedDate));
        }

        process.stdout.write(JSON.stringify({
            status: 'success',
            date: normalizeDate(requestedDate),
            source: 'aiscore.com',
            count: payload.length,
            matches: payload
        }));
    } catch (error) {
        const fallbackPayload = await fetchJinaTodayMatches(normalizeDate(requestedDate));
        if (fallbackPayload.length > 0) {
            process.stdout.write(JSON.stringify({
                status: 'success',
                date: normalizeDate(requestedDate),
                source: 'aiscore.com',
                fallback: 'jina-markdown',
                count: fallbackPayload.length,
                matches: fallbackPayload
            }));
            return;
        }

        process.stderr.write((error && error.stack) ? error.stack : String(error));
        process.exit(1);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
})();
