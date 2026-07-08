<?php
require_once __DIR__ . '/Database.php';

class ThreeSixFiveScraper {
    private $db;
    private $baseUrl = "https://webws.365scores.com/web";
    private $renderedLiveLinksCache = [];

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Find a match on 365scores and return its details page URL
     */
    public function findMatchLiveUrl($homeTeam, $awayTeam, $matchDate) {
        $formattedDate = date('d/m/Y', strtotime($matchDate));

        foreach ($this->getGamesRequestProfiles() as $profile) {
            $apiUrl = $this->buildGamesApiUrl($formattedDate, $profile);
            $html = $this->fetchUrl($apiUrl, $profile);
            if (!$html) {
                continue;
            }

            $json = json_decode($html, true);
            if (!$json || !isset($json['games'])) {
                continue;
            }

            $bestMatch = null;
            $bestScore = 0;

            $competitions = $json['competitions'] ?? [];
            $competitionById = [];
            foreach ($competitions as $competition) {
                if (isset($competition['id'])) {
                    $competitionById[(string)$competition['id']] = $competition;
                }
            }

            foreach ($json['games'] as $game) {
                if ((int)($game['sportId'] ?? 0) !== 1) {
                    continue;
                }

                $competitionId = (string)($game['competitionId'] ?? '');
                if ($competitionId !== '' && isset($competitionById[$competitionId]) && (int)($competitionById[$competitionId]['sportId'] ?? 1) !== 1) {
                    continue;
                }

                $gHome = $this->buildGameTeamCandidates($game['homeCompetitor'] ?? []);
                $gAway = $this->buildGameTeamCandidates($game['awayCompetitor'] ?? []);
                $finalScore = $this->calculateGameMatchScore($homeTeam, $awayTeam, $gHome, $gAway);

                if ($finalScore > $bestScore && $finalScore >= 62) {
                    $bestScore = $finalScore;
                    $bestMatch = $game;
                }
            }

            if ($bestMatch) {
                $renderedUrl = $this->findRenderedMatchUrlById((string)($bestMatch['id'] ?? ''), $profile);
                if ($renderedUrl) {
                    return $renderedUrl;
                }

                return $this->constructMatchUrl($bestMatch, $competitions, $profile['locale_path'] ?? 'ar');
            }
        }

        return null;
    }

    private function getGamesRequestProfiles() {
        return [
            [
                'lang_id' => 27,
                'user_country_id' => 127,
                'app_type_id' => 5,
                'accept_language' => 'ar,en;q=0.9',
                'referer' => 'https://www.365scores.com/ar/football/live',
                'locale_path' => 'ar',
                'timezone' => 'Africa/Casablanca',
            ],
            [
                'lang_id' => 9,
                'user_country_id' => 4,
                'app_type_id' => 5,
                'accept_language' => 'en-US,en;q=0.9,ar;q=0.8',
                'referer' => 'https://www.365scores.com/en-us/football/live',
                'locale_path' => 'en-us',
                'timezone' => 'Africa/Casablanca',
            ],
            [
                'lang_id' => 9,
                'user_country_id' => 127,
                'app_type_id' => 5,
                'accept_language' => 'en-US,en;q=0.9,ar;q=0.8',
                'referer' => 'https://www.365scores.com/en-us/football/live',
                'locale_path' => 'en-us',
                'timezone' => 'Africa/Casablanca',
            ],
        ];
    }

    private function buildGamesApiUrl($formattedDate, array $profile) {
        $params = [
            'langId' => $profile['lang_id'] ?? 27,
            'timezoneName' => $profile['timezone'] ?? 'Africa/Casablanca',
            'userCountryId' => $profile['user_country_id'] ?? 127,
            'sports' => 1,
            'startDate' => $formattedDate,
            'endDate' => $formattedDate,
            'showOdds' => 'true',
            'onlyLiveGames' => 'false',
            'withTop' => 'true',
        ];

        if (!empty($profile['app_type_id'])) {
            $params['appTypeId'] = $profile['app_type_id'];
        }

        return "{$this->baseUrl}/games/allscores/?" . http_build_query($params);
    }

    private function buildGameTeamCandidates($competitor) {
        if (!is_array($competitor)) {
            return [];
        }

        return [
            $competitor['name'] ?? '',
            $competitor['shortName'] ?? '',
            $competitor['nameForURL'] ?? '',
        ];
    }

    private function extractTeamCandidates($team) {
        $candidates = [];

        $append = static function ($value) use (&$candidates) {
            if (!is_scalar($value)) {
                return;
            }

            $value = trim((string)$value);
            if ($value === '') {
                return;
            }

            $candidates[] = $value;
        };

        if (is_array($team)) {
            foreach ($team as $value) {
                if (is_array($value)) {
                    foreach ($value as $nestedValue) {
                        $append($nestedValue);
                    }
                } else {
                    $append($value);
                }
            }
        } else {
            $append($team);
        }

        $unique = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $key = mb_strtolower($candidate, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    private function constructMatchUrl($game, $competitions, $localePath = 'ar') {
        $homeTemp = $game['homeCompetitor'];
        $awayTemp = $game['awayCompetitor'];

        $homeSlug = $homeTemp['nameForURL'] ?? 'home';
        $awaySlug = $awayTemp['nameForURL'] ?? 'away';
        $homeId = $homeTemp['id'] ?? 0;
        $awayId = $awayTemp['id'] ?? 0;
        $compId = $game['competitionId'] ?? 0;
        $id = $game['id'];

        $compSlug = 'competition';
        foreach ($competitions as $comp) {
            if (($comp['id'] ?? null) == $compId) {
                $compSlug = $comp['nameForURL'] ?? 'competition';
                break;
            }
        }

        $localePath = trim((string)$localePath);
        if ($localePath === '') {
            $localePath = 'ar';
        }

        return "https://www.365scores.com/{$localePath}/football/match/{$compSlug}-{$compId}/{$homeSlug}-{$awaySlug}-{$homeId}-{$awayId}-{$compId}#id={$id}";
    }

    private function findRenderedMatchUrlById($gameId, array $profile) {
        $gameId = trim((string)$gameId);
        if ($gameId === '') {
            return null;
        }

        $links = $this->getRenderedLivePageLinks($profile);
        return $links[$gameId] ?? null;
    }

    private function getRenderedLivePageLinks(array $profile) {
        $pageUrl = trim((string)($profile['referer'] ?? 'https://www.365scores.com/ar/football/live'));
        if ($pageUrl === '') {
            $pageUrl = 'https://www.365scores.com/ar/football/live';
        }

        if (isset($this->renderedLiveLinksCache[$pageUrl])) {
            return $this->renderedLiveLinksCache[$pageUrl];
        }

        $this->renderedLiveLinksCache[$pageUrl] = [];

        $scriptPath = realpath(__DIR__ . '/../fetch_365scores_live_links.js');
        if (!$scriptPath) {
            error_log('[365Scores] fetch_365scores_live_links.js not found');
            return [];
        }

        $output = shell_exec('node ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($pageUrl));
        $payload = json_decode(trim((string)$output), true);
        if (!is_array($payload) || !isset($payload['links']) || !is_array($payload['links'])) {
            error_log('[365Scores] invalid rendered links output: ' . substr((string)$output, 0, 500));
            return [];
        }

        $linksById = [];
        foreach ($payload['links'] as $link) {
            $id = trim((string)($link['id'] ?? ''));
            $href = trim((string)($link['href'] ?? ''));
            if ($id === '' || $href === '' || !preg_match('#^https://www\.365scores\.com/.+/football/match/.+#i', $href)) {
                continue;
            }

            $linksById[$id] = $href;
        }

        $this->renderedLiveLinksCache[$pageUrl] = $linksById;
        return $linksById;
    }

    private function calculateTeamCandidateSetScore($expectedTeam, $actualTeam) {
        $expectedCandidates = $this->extractTeamCandidates($expectedTeam);
        $actualCandidates = $this->extractTeamCandidates($actualTeam);
        $bestScore = 0;

        foreach ($expectedCandidates as $expectedCandidate) {
            foreach ($actualCandidates as $actualCandidate) {
                $bestScore = max($bestScore, $this->calculateSimilarity($expectedCandidate, $actualCandidate));
            }
        }

        return $bestScore;
    }

    private function calculateGameMatchScore($homeExpected, $awayExpected, $homeActual, $awayActual) {
        $directHome = $this->calculateTeamCandidateSetScore($homeExpected, $homeActual);
        $directAway = $this->calculateTeamCandidateSetScore($awayExpected, $awayActual);
        $swappedHome = $this->calculateTeamCandidateSetScore($homeExpected, $awayActual);
        $swappedAway = $this->calculateTeamCandidateSetScore($awayExpected, $homeActual);

        return max(
            ($directHome + $directAway) / 2,
            ($swappedHome + $swappedAway) / 2
        );
    }

    /**
     * Enhanced similarity calculation with multiple strategies
     */
    private function calculateSimilarity($s1, $s2) {
        $n1 = $this->normalize($s1);
        $n2 = $this->normalize($s2);

        if (empty($n1) || empty($n2)) return 0;

        if ($n1 === $n2) return 100;

        if (mb_strpos($n1, $n2) !== false || mb_strpos($n2, $n1) !== false) return 95;

        $wordScore = $this->wordLevelScore($n1, $n2);

        similar_text($n1, $n2, $percent);

        $d1 = $this->deepNormalize($n1);
        $d2 = $this->deepNormalize($n2);

        if ($d1 === $d2) return 98;
        if (mb_strpos($d1, $d2) !== false || mb_strpos($d2, $d1) !== false) return 93;

        $deepWordScore = $this->wordLevelScore($d1, $d2);

        similar_text($d1, $d2, $deepPercent);

        return max($percent, $deepPercent, $wordScore, $deepWordScore);
    }

    private function wordLevelScore($name1, $name2) {
        $words1 = preg_split('/[\s\-_\.]+/u', $name1);
        $words2 = preg_split('/[\s\-_\.]+/u', $name2);

        $words1 = array_filter($words1, function($w) { return mb_strlen($w) >= 4; });
        $words2 = array_filter($words2, function($w) { return mb_strlen($w) >= 4; });

        if (empty($words1) || empty($words2)) return 0;

        $matchedWords = 0;
        $totalSignificant = 0;
        $bestSingleWordScore = 0;

        foreach ($words1 as $w1) {
            $totalSignificant++;
            $bestMatch = 0;

            foreach ($words2 as $w2) {
                if ($w1 === $w2) {
                    $bestMatch = 100;
                    break;
                }

                if (mb_strpos($w1, $w2) !== false || mb_strpos($w2, $w1) !== false) {
                    $bestMatch = max($bestMatch, 90);
                    continue;
                }

                $dw1 = $this->deepNormalize($w1);
                $dw2 = $this->deepNormalize($w2);
                if ($dw1 === $dw2) {
                    $bestMatch = max($bestMatch, 95);
                    continue;
                }

                if (mb_strpos($dw1, $dw2) !== false || mb_strpos($dw2, $dw1) !== false) {
                    $bestMatch = max($bestMatch, 88);
                    continue;
                }

                similar_text($dw1, $dw2, $wPercent);
                if ($wPercent > 70) {
                    $bestMatch = max($bestMatch, $wPercent);
                }
            }

            if ($bestMatch >= 70) $matchedWords++;
            $bestSingleWordScore = max($bestSingleWordScore, $bestMatch);
        }

        if ($matchedWords > 0 && $totalSignificant > 0) {
            $ratio = $matchedWords / $totalSignificant;
            if ($matchedWords >= 2) return max(85, $ratio * 100);
            return max(70, min($bestSingleWordScore, 80));
        }

        return $bestSingleWordScore > 70 ? $bestSingleWordScore : 0;
    }

    private function normalize($text) {
        $diacritics = ['Ù‹', 'ÙŒ', 'Ù', 'ÙŽ', 'Ù', 'Ù', 'Ù‘', 'Ù’', 'Ù€'];
        $text = str_replace($diacritics, '', $text);
        $text = preg_replace('/[Ø£Ø¥Ø¢Ø¡Ù±]/u', 'Ø§', $text);
        $text = str_replace('Ø©', 'Ù‡', $text);
        $text = str_replace('Ù‰', 'ÙŠ', $text);
        $text = str_replace(['Ø¤', 'Ø¦', 'Ú¯', 'Ù¾', 'Ú†', 'Ú˜', 'Ú¤'], ['Ùˆ', 'ÙŠ', 'Ùƒ', 'Ø¨', 'Ø¬', 'Ø²', 'Ù'], $text);
        $text = preg_replace('/\b(Ù†Ø§Ø¯ÙŠ|ÙØ±ÙŠÙ‚|Ù…Ù†ØªØ®Ø¨|Ø§Ù Ø³ÙŠ|Ø§Ù\.Ø³ÙŠ|fc|sfc|sc|afc|club|al)\b/ui', '', $text);
        $text = preg_replace('/\bØ§Ù„(?=\p{L})/u', '', $text);
        $text = preg_replace('/[\-_\.]+/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return mb_strtolower(trim($text), 'UTF-8');
    }

    private function deepNormalize($text) {
        $text = str_replace(['Øº', 'Ø®'], 'Ø¬', $text);
        $text = str_replace(['Ø°', 'Ø¸'], 'Ø²', $text);
        $text = str_replace(['Øµ', 'Ø¶'], 'Ø³', $text);
        $text = str_replace('Ø·', 'Øª', $text);
        $text = str_replace('Ø«', 'Øª', $text);
        $text = str_replace('Ù‚', 'Ùƒ', $text);
        $text = str_replace('Ø­', 'Ù‡', $text);
        $text = preg_replace('/(.)\1+/u', '$1', $text);

        return $text;
    }

    private function fetchUrl($url, array $profile = []) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $headers = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: ' . ($profile['accept_language'] ?? 'ar,en;q=0.9'),
            'Cache-Control: no-cache',
            'Connection: keep-alive',
            'Origin: https://www.365scores.com',
            'Pragma: no-cache',
            'Referer: ' . ($profile['referer'] ?? 'https://www.365scores.com/ar'),
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-site'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }

    private function getLocalEmbedBaseUrl() {
        $baseUrl = trim((string)(getenv('SPORT_STREAM_EMBED_BASE_URL') ?: ''));
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '') {
            return ($https ? 'https://' : 'http://') . $host;
        }

        return 'https://dashboard.hayachoout.space';
    }

    private function buildLocalFramePlayerUrl($frameUrl, array $meta = []) {
        $frameUrl = trim((string)$frameUrl);
        if ($frameUrl === '' || !preg_match('#^https?://#i', $frameUrl)) {
            return null;
        }

        $params = [
            'url' => rtrim(strtr(base64_encode($frameUrl), '+/', '-_'), '='),
        ];

        if (!empty($meta['title'])) {
            $params['title'] = trim((string)$meta['title']);
        }

        return $this->getLocalEmbedBaseUrl() . '/frame_player.php?' . http_build_query($params);
    }

    private function wrapIframeHtmlLocally($iframeHtml, array $meta = []) {
        $iframeHtml = trim((string)$iframeHtml);
        if ($iframeHtml === '' || stripos($iframeHtml, '<iframe') === false) {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->loadHTML('<?xml encoding="utf-8" ?>' . $iframeHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            return $iframeHtml;
        }

        $iframe = $dom->getElementsByTagName('iframe')->item(0);
        if (!$iframe) {
            return $iframeHtml;
        }

        $src = trim((string)$iframe->getAttribute('src'));
        if ($src === '' || !preg_match('#^https?://#i', $src)) {
            return $iframeHtml;
        }

        if (stripos($src, '/frame_player.php?') !== false) {
            return $iframeHtml;
        }

        $localSrc = $this->buildLocalFramePlayerUrl($src, $meta);
        if (!$localSrc) {
            return $iframeHtml;
        }

        $iframe->setAttribute('src', $localSrc);
        $iframe->setAttribute('height', '500px');
        $iframe->setAttribute('width', '100%');

        $html = $dom->saveHTML($iframe);
        if (!$html) {
            return $iframeHtml;
        }

        return $html;
    }

    /**
     * Extract the REAL Live Match Tracker iframe from the match page.
     * Uses headless browser (Puppeteer) because 365scores is an SPA
     * and the iframe is injected by JavaScript after page load.
     * Returns ONLY the <iframe> element as-is, or null if not found.
     */
    public function getLiveIframe($url) {
        if (!$url) return null;

        $scriptPath = __DIR__ . '/../extract_iframe.js';

        $escapedUrl = escapeshellarg($url);
        $command = "node " . escapeshellarg($scriptPath) . " $escapedUrl 2>&1";

        $output = shell_exec($command);
        $output = trim($output ?? '');

        if (!empty($output) && stripos($output, '<iframe') === false) {
            if (stripos($output, 'Error:') !== false || stripos($output, 'Launch Error:') !== false) {
                error_log('[365Scores] Puppeteer output: ' . $output);
            }
        }

        if (!empty($output) && stripos($output, '<iframe') !== false) {
            $output = preg_replace('/height:\s*[0-9.]+px;?/', 'height: 100%;', $output);
            return $this->normalizeTrackerIframeHtml($output);
        }

        return null;
    }

    private function normalizeTrackerIframeHtml($iframeHtml) {
        $iframeHtml = trim((string)$iframeHtml);
        if ($iframeHtml === '' || stripos($iframeHtml, '<iframe') === false) {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->loadHTML('<?xml encoding="utf-8" ?>' . $iframeHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            return $iframeHtml;
        }

        $iframe = $dom->getElementsByTagName('iframe')->item(0);
        if (!$iframe) {
            return $iframeHtml;
        }

        $src = trim((string)$iframe->getAttribute('src'));
        if ($src === '' || !preg_match('#^https?://#i', $src)) {
            return null;
        }

        $iframe->setAttribute('src', $src);
        $iframe->setAttribute('height', '500px');
        $iframe->setAttribute('width', '100%');
        $iframe->setAttribute('style', 'border:0;width:100%;height:500px;');
        $iframe->setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; fullscreen');
        $iframe->setAttribute('allowfullscreen', 'allowfullscreen');
        $iframe->setAttribute('loading', 'lazy');
        $iframe->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');

        return $dom->saveHTML($iframe) ?: $iframeHtml;
    }
}
?>
