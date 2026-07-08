<?php
require_once __DIR__ . '/Database.php';

class AiScoreScraper {
    private const AISCORE_WIDGET_PROFILE = '74rekh26eseunr0';

    private $db;
    private $cache = [];
    private $liveSourceCache = [];
    private $lastMatchedMatch = null;
    private $lastMatchDate = null;
    private $cacheTtlSeconds = 25;

    public function __construct($db = null) {
        $this->db = $db;
    }

    public function findMatchLiveUrl($homeTeam, $awayTeam, $matchDate) {
        $matches = $this->fetchMatches($matchDate);
        $bestMatch = null;
        $bestScore = 0;
        $bestPriority = -1;

        foreach ($matches as $match) {
            $homeActual = [
                $match['home_team'] ?? '',
                $match['home_slug'] ?? '',
                $this->slugToWords($match['home_slug'] ?? '')
            ];
            $awayActual = [
                $match['away_team'] ?? '',
                $match['away_slug'] ?? '',
                $this->slugToWords($match['away_slug'] ?? '')
            ];

            $score = $this->calculateGameMatchScore($homeTeam, $awayTeam, $homeActual, $awayActual);
            if ($score < 55) {
                continue;
            }

            $priority = 0;
            $priority += !empty($match['is_live']) ? 8 : 0;
            $priority += !empty($match['has_live_stream']) ? 4 : 0;
            $priority += !empty($match['has_match_live']) ? 2 : 0;

            if ($score > $bestScore || (abs($score - $bestScore) < 0.001 && $priority > $bestPriority)) {
                $bestScore = $score;
                $bestPriority = $priority;
                $bestMatch = $match;
            }
        }

        if (!$bestMatch || empty($bestMatch['url'])) {
            $this->lastMatchedMatch = null;
            $this->lastMatchDate = null;
            return null;
        }

        $liveSourceUrl = $this->fetchLiveSourceUrl($bestMatch['url']);
        if (!$liveSourceUrl) {
            error_log('[AiScore] real live source not found for match page: ' . $bestMatch['url']);
            $this->lastMatchedMatch = null;
            $this->lastMatchDate = null;
            return null;
        }

        $bestMatch['match_page_url'] = $bestMatch['url'];
        $bestMatch['live_source_url'] = $liveSourceUrl;
        $this->lastMatchedMatch = $bestMatch;
        $this->lastMatchDate = $matchDate;
        return $liveSourceUrl;
    }

    public function getMatchById($matchId, $matchDate = null) {
        $matchId = trim((string)$matchId);
        if ($matchId === '') {
            return null;
        }

        $matches = $this->fetchMatches($matchDate ?: date('Y-m-d'));
        foreach ($matches as $match) {
            if ((string)($match['id'] ?? '') === $matchId) {
                return $match;
            }
        }

        return null;
    }

    public function getLiveIframe($url) {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }

        $liveSourceUrl = $this->normalizeLiveSourceUrl($url);
        if (!$liveSourceUrl || $this->isAiScoreMatchPageUrl($liveSourceUrl)) {
            return null;
        }

        $src = htmlspecialchars($liveSourceUrl, ENT_QUOTES, 'UTF-8');
        return '<iframe src="' . $src . '" height="500px" width="100%" style="border:0;width:100%;height:500px;" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>';
    }

    private function fetchLiveSourceUrl($matchPageUrl) {
        $matchPageUrl = trim((string)$matchPageUrl);
        if ($matchPageUrl === '') {
            return null;
        }

        $directUrl = $this->normalizeLiveSourceUrl($matchPageUrl);
        if ($directUrl && !$this->isAiScoreMatchPageUrl($directUrl)) {
            return $directUrl;
        }

        if (!$this->isAiScoreMatchPageUrl($matchPageUrl)) {
            return null;
        }

        if (isset($this->liveSourceCache[$matchPageUrl])) {
            return $this->liveSourceCache[$matchPageUrl];
        }

        $widgetUrl = $this->fetchTheSportsWidgetUrlFromMatchPage($matchPageUrl);
        if ($widgetUrl) {
            $this->liveSourceCache[$matchPageUrl] = $widgetUrl;
            return $widgetUrl;
        }

        $scriptPath = realpath(__DIR__ . '/../fetch_aiscore_live_source.js');
        if (!$scriptPath || !is_file($scriptPath)) {
            error_log('[AiScore] fetch_aiscore_live_source.js not found');
            $this->liveSourceCache[$matchPageUrl] = null;
            return null;
        }

        $command = 'node ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($matchPageUrl) . ' 2>&1';
        $output = trim((string)shell_exec($command));
        if ($output === '') {
            error_log('[AiScore] empty live source output for: ' . $matchPageUrl);
            $this->liveSourceCache[$matchPageUrl] = null;
            return null;
        }

        $jsonStart = strpos($output, '{');
        if ($jsonStart !== false && $jsonStart > 0) {
            $output = substr($output, $jsonStart);
        }

        $payload = json_decode($output, true);
        if (!is_array($payload) || ($payload['status'] ?? '') !== 'success') {
            error_log('[AiScore] invalid live source output: ' . substr($output, 0, 500));
            $this->liveSourceCache[$matchPageUrl] = null;
            return null;
        }

        $liveSourceUrl = $this->normalizeLiveSourceUrl($payload['url'] ?? '');
        if (!$liveSourceUrl || $this->isAiScoreMatchPageUrl($liveSourceUrl)) {
            error_log('[AiScore] rejected non-live source URL: ' . substr((string)($payload['url'] ?? ''), 0, 300));
            $this->liveSourceCache[$matchPageUrl] = null;
            return null;
        }

        $this->liveSourceCache[$matchPageUrl] = $liveSourceUrl;
        return $liveSourceUrl;
    }

    private function fetchTheSportsWidgetUrlFromMatchPage($matchPageUrl) {
        $matchId = $this->extractAiScoreMatchId($matchPageUrl);
        if ($matchId === '') {
            return null;
        }

        $placeholderWidgetUrl = $this->buildTheSportsWidgetUrl($matchId);
        $apiUrl = 'https://api.thesports01.com/api/f/sd?id=' . rawurlencode($matchId) . '&lang=aa';
        $payload = $this->fetchBinaryUrl($apiUrl, [
            'Accept: application/octet-stream,*/*',
            'Origin: https://widgets.thesports01.com',
            'Referer: ' . $placeholderWidgetUrl
        ]);

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $theSportsMatchId = $this->extractTheSportsIdFromStaticDetail($payload);
        if (!$theSportsMatchId) {
            return null;
        }

        return $this->buildTheSportsWidgetUrl((string)$theSportsMatchId);
    }

    private function buildTheSportsWidgetUrl($matchId) {
        return 'https://widgets.thesports01.com/aa/3d/football?' . http_build_query([
            'profile' => self::AISCORE_WIDGET_PROFILE,
            'id' => (string)$matchId
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function extractAiScoreMatchId($matchPageUrl) {
        $path = parse_url((string)$matchPageUrl, PHP_URL_PATH);
        $parts = array_values(array_filter(explode('/', trim((string)$path, '/'))));
        if (empty($parts)) {
            return '';
        }

        return preg_match('/^[a-z0-9]+$/i', end($parts)) ? end($parts) : '';
    }

    private function fetchBinaryUrl($url, array $headers = []) {
        $defaultHeaders = array_merge([
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36'
        ], $headers);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => $defaultHeaders,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ($status >= 200 && $status < 300 && is_string($body)) ? $body : null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'header' => implode("\r\n", $defaultHeaders)
            ]
        ]);

        $body = @file_get_contents($url, false, $context);
        return is_string($body) ? $body : null;
    }

    private function extractTheSportsIdFromStaticDetail($payload) {
        if (!is_string($payload) || $payload === '' || $payload[0] === '<') {
            return null;
        }

        $offset = 0;
        $topTag = $this->readProtoVarint($payload, $offset);
        if ($topTag === null || (($topTag >> 3) !== 2) || (($topTag & 7) !== 2)) {
            return null;
        }

        $dataLength = $this->readProtoVarint($payload, $offset);
        if ($dataLength === null || $dataLength <= 0 || ($offset + $dataLength) > strlen($payload)) {
            return null;
        }

        $data = substr($payload, $offset, $dataLength);
        $dataOffset = 0;
        $matchIdTag = $this->readProtoVarint($data, $dataOffset);
        if ($matchIdTag === null || (($matchIdTag >> 3) !== 1) || (($matchIdTag & 7) !== 0)) {
            return null;
        }

        $matchId = $this->readProtoVarint($data, $dataOffset);
        return ($matchId !== null && $matchId > 0) ? $matchId : null;
    }

    private function readProtoVarint($bytes, &$offset) {
        $result = 0;
        $shift = 0;
        $length = strlen($bytes);

        while ($offset < $length && $shift < 63) {
            $byte = ord($bytes[$offset]);
            $offset++;
            $result |= (($byte & 0x7f) << $shift);

            if (($byte & 0x80) === 0) {
                return $result;
            }

            $shift += 7;
        }

        return null;
    }

    private function normalizeLiveSourceUrl($url) {
        $url = html_entity_decode(trim((string)$url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '') {
            return null;
        }

        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }

    private function isAiScoreMatchPageUrl($url) {
        return (bool)preg_match('#^https?://(?:www\.)?aiscore\.com/[^/]+/match-#i', (string)$url);
    }

    private function fetchMatches($matchDate) {
        $date = $this->normalizeDate($matchDate);
        if (isset($this->cache[$date])) {
            return $this->cache[$date];
        }

        $cached = $this->readFileCache($date);
        if (is_array($cached)) {
            $this->cache[$date] = $cached;
            return $cached;
        }

        $scriptPath = realpath(__DIR__ . '/../fetch_aiscore_matches.js');
        if (!$scriptPath || !is_file($scriptPath)) {
            error_log('[AiScore] fetch_aiscore_matches.js not found');
            return [];
        }

        $command = 'node ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($date) . ' 2>&1';
        $output = trim((string)shell_exec($command));
        if ($output === '') {
            error_log('[AiScore] empty browser output');
            return [];
        }

        $jsonStart = strpos($output, '{');
        if ($jsonStart !== false && $jsonStart > 0) {
            $output = substr($output, $jsonStart);
        }

        $payload = json_decode($output, true);
        if (!is_array($payload) || ($payload['status'] ?? '') !== 'success' || !isset($payload['matches']) || !is_array($payload['matches'])) {
            error_log('[AiScore] invalid browser output: ' . substr($output, 0, 500));
            return [];
        }

        $matches = $payload['matches'];
        $this->writeFileCache($date, $matches);
        $this->cache[$date] = $matches;
        return $matches;
    }

    private function normalizeDate($date) {
        $timestamp = strtotime((string)$date);
        return $timestamp ? date('Y-m-d', $timestamp) : date('Y-m-d');
    }

    private function cachePath($date) {
        $dir = dirname(__DIR__) . '/.api-response-cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @chmod($dir, 02775);

        return $dir . '/aiscore_matches_' . preg_replace('/[^0-9-]/', '', $date) . '.json';
    }

    private function readFileCache($date) {
        $path = $this->cachePath($date);
        if (!is_file($path) || (time() - filemtime($path)) > $this->cacheTtlSeconds) {
            return null;
        }

        $json = file_get_contents($path);
        $data = json_decode((string)$json, true);
        return is_array($data) ? $data : null;
    }

    private function writeFileCache($date, array $matches) {
        $path = $this->cachePath($date);
        if (@file_put_contents($path, json_encode($matches, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @chmod($path, 0664);
        }
    }

    private function extractTeamCandidates($team) {
        $candidates = [];
        $append = static function ($value) use (&$candidates) {
            if (!is_scalar($value)) {
                return;
            }

            $value = trim((string)$value);
            if ($value !== '') {
                $candidates[] = $value;
            }
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

    private function calculateSimilarity($s1, $s2) {
        $n1 = $this->normalize($s1);
        $n2 = $this->normalize($s2);

        if ($n1 === '' || $n2 === '') return 0;
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
        $words1 = preg_split('/[\s\-_\.]+/u', $name1) ?: [];
        $words2 = preg_split('/[\s\-_\.]+/u', $name2) ?: [];

        $words1 = array_filter($words1, static function ($word) {
            return mb_strlen($word, 'UTF-8') >= 3;
        });
        $words2 = array_filter($words2, static function ($word) {
            return mb_strlen($word, 'UTF-8') >= 3;
        });

        if (empty($words1) || empty($words2)) return 0;

        $matchedWords = 0;
        $totalSignificant = 0;
        $bestSingleWordScore = 0;

        foreach ($words1 as $word1) {
            $totalSignificant++;
            $bestMatch = 0;

            foreach ($words2 as $word2) {
                if ($word1 === $word2) {
                    $bestMatch = 100;
                    break;
                }

                if (mb_strpos($word1, $word2) !== false || mb_strpos($word2, $word1) !== false) {
                    $bestMatch = max($bestMatch, 90);
                    continue;
                }

                $deep1 = $this->deepNormalize($word1);
                $deep2 = $this->deepNormalize($word2);
                if ($deep1 === $deep2) {
                    $bestMatch = max($bestMatch, 95);
                    continue;
                }

                similar_text($deep1, $deep2, $wordPercent);
                if ($wordPercent > 70) {
                    $bestMatch = max($bestMatch, $wordPercent);
                }
            }

            if ($bestMatch >= 70) {
                $matchedWords++;
            }
            $bestSingleWordScore = max($bestSingleWordScore, $bestMatch);
        }

        if ($matchedWords > 0 && $totalSignificant > 0) {
            $ratio = $matchedWords / $totalSignificant;
            if ($matchedWords >= 2) return max(85, $ratio * 100);
            return max(70, min($bestSingleWordScore, 82));
        }

        return $bestSingleWordScore > 70 ? $bestSingleWordScore : 0;
    }

    private function slugToWords($slug) {
        return preg_replace('/[\-_]+/', ' ', trim((string)$slug));
    }

    private function normalize($text) {
        $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ', 'ء'], ['ا', 'ا', 'ا', 'ا', ''], $text);
        $text = str_replace(['ة', 'ى', 'ؤ', 'ئ', 'گ', 'پ', 'چ', 'ژ', 'ڤ'], ['ه', 'ي', 'و', 'ي', 'ك', 'ب', 'ج', 'ز', 'ف'], $text);
        $text = preg_replace('/\b(نادي|فريق|منتخب|اف سي|اف\.سي|fc|sfc|sc|afc|club|cf|cd|al)\b/ui', ' ', $text);
        $text = preg_replace('/\bال(?=\p{L})/u', '', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return mb_strtolower(trim((string)$text), 'UTF-8');
    }

    private function deepNormalize($text) {
        $text = str_replace(['غ', 'خ'], 'ج', $text);
        $text = str_replace(['ذ', 'ظ'], 'ز', $text);
        $text = str_replace(['ص', 'ض'], 'س', $text);
        $text = str_replace(['ط', 'ث'], 'ت', $text);
        $text = str_replace('ق', 'ك', $text);
        $text = str_replace('ح', 'ه', $text);
        $text = preg_replace('/(.)\1+/u', '$1', $text);

        return $text;
    }
}
?>
