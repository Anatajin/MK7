<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/NYallaShootScraper.php';

class Scraper {
    private $db;
    private $nyallaScraper;
    private $yssUtcSessionReady = false;

    public function __construct($db) {
        $this->db = $db;
        $this->nyallaScraper = new NYallaShootScraper($db);
    }

    private function isClockTimeValue($value) {
        return preg_match('/^\d{1,2}:\d{2}$/', trim((string)$value)) === 1;
    }

    private function normalizeYssLiveTimeValue($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{1,3})\s*:\s*\d{1,2}$/u', $value, $matches)) {
            return $matches[1] . "'";
        }

        if (preg_match('/^(\d{1,3})\s*(?:\+\d+)?$/u', $value, $matches)) {
            return $matches[1] . "'";
        }

        return $value;
    }

    private function normalizeYssLeagueName($leagueName) {
        $leagueName = html_entity_decode(trim((string)$leagueName), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $leagueName = preg_replace('/\s+/u', ' ', $leagueName);
        $leagueName = trim((string)$leagueName);

        if ($leagueName === '') {
            return 'Unknown';
        }

        $original = $leagueName;
        $leagueName = preg_replace('/\s*(?:[-–—]\s*)?مباراة\s+(?:الذهاب|الإياب|الاياب|ذهاب|إياب)\s*$/u', '', $leagueName);
        $leagueName = trim((string)$leagueName);

        foreach (['الجولة', 'جولة', 'الأسبوع', 'الاسبوع', 'أسبوع', 'اسبوع', 'دور الـ', 'دور ال', 'الدور', ' round', 'round', 'matchday', 'week'] as $marker) {
            $position = mb_stripos($leagueName, $marker, 0, 'UTF-8');
            if ($position !== false && $position > 0) {
                $leagueName = mb_substr($leagueName, 0, $position, 'UTF-8');
                break;
            }
        }

        $leagueName = preg_replace('/\s*(?:[-–—:|،,]\s*)+$/u', '', $leagueName);
        $leagueName = trim((string)$leagueName);

        return $leagueName !== '' ? $leagueName : $original;
    }

    private function isYssFinishedStatusText($text) {
        $text = trim((string)$text);
        if ($text === '') {
            return false;
        }

        $needles = ['انتهت', 'إنتهت', 'finished', 'ft', 'full time'];
        foreach ($needles as $needle) {
            if (mb_stripos($text, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function isYssPostponedStatusText($text) {
        $text = trim((string)$text);
        if ($text === '') {
            return false;
        }

        $needles = ['مؤجلة', 'تأجلت', 'postponed', 'cancelled', 'ملغاة'];
        foreach ($needles as $needle) {
            if (mb_stripos($text, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function isYssLiveSignalText($text) {
        $text = trim((string)$text);
        if ($text === '') {
            return false;
        }

        if ($this->isClockTimeValue($text)) {
            return false;
        }

        if (preg_match("/\d{1,3}\s*(?:\+\d+)?\s*'?$/u", $text)) {
            return true;
        }

        if (preg_match('/^\d{1,3}\s*:\s*\d{1,2}$/u', $text)) {
            return true;
        }

        $needles = [
            'مباشر', 'استراحة', 'الشوط', 'دقيقة', 'الدقيقة', 'إضافي', 'ركلات', 'بين الشوطين',
            'live', 'break', 'half', 'extra', 'pen', 'playing'
        ];
        foreach ($needles as $needle) {
            if (mb_stripos($text, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function inferYssStatus($statusText, $liveTime = '', $hasMinutesNode = false) {
        $statusText = trim((string)$statusText);
        $liveTime = trim((string)$liveTime);

        if ($this->isYssFinishedStatusText($statusText) || $this->isYssFinishedStatusText($liveTime)) {
            return 'Finished';
        }

        if ($this->isYssPostponedStatusText($statusText) || $this->isYssPostponedStatusText($liveTime)) {
            return 'Postponed';
        }

        if ($hasMinutesNode || $this->isYssLiveSignalText($liveTime) || $this->isYssLiveSignalText($statusText)) {
            return 'Live';
        }

        return 'Scheduled';
    }

    private function getAppTimezoneName() {
        return defined('APP_TIMEZONE') ? APP_TIMEZONE : 'UTC';
    }

    private function getMatchStorageTimezoneName() {
        return defined('MATCH_STORAGE_TIMEZONE') ? MATCH_STORAGE_TIMEZONE : 'UTC';
    }

    private function isYssLiveAllowedByKickoff($matchDate, $scheduledTime, $graceMinutes = 10) {
        $matchDate = trim((string)$matchDate);
        $scheduledTime = trim((string)$scheduledTime);
        if ($matchDate === '' || !$this->isClockTimeValue($scheduledTime)) {
            return true;
        }

        try {
            $sourceTimezone = new DateTimeZone($this->getAppTimezoneName());
            $storageTimezone = new DateTimeZone($this->getMatchStorageTimezoneName());
            $kickoff = new DateTimeImmutable($matchDate . ' ' . $scheduledTime . ':00', $sourceTimezone);
            $kickoff = $kickoff->setTimezone($storageTimezone);
            $now = new DateTimeImmutable('now', $storageTimezone);

            return $kickoff->getTimestamp() <= ($now->getTimestamp() + ($graceMinutes * 60));
        } catch (Exception $e) {
            return true;
        }
    }

    private function getStoredMatchKickoffTimestamp(array $match) {
        $timezone = new DateTimeZone('UTC');

        $startTime = trim((string)($match['start_time'] ?? ''));
        if ($startTime !== '' && $startTime !== '0000-00-00 00:00:00') {
            try {
                return (new DateTimeImmutable($startTime, $timezone))->getTimestamp();
            } catch (Exception $e) {
                // Fall through.
            }
        }

        $matchDate = trim((string)($match['match_date'] ?? ''));
        $timeCandidates = [
            trim((string)($match['details_match_time'] ?? '')),
            trim((string)($match['match_time'] ?? '')),
        ];

        foreach ($timeCandidates as $candidate) {
            if ($matchDate === '' || !$this->isClockTimeValue($candidate)) {
                continue;
            }

            try {
                return (new DateTimeImmutable($matchDate . ' ' . $candidate, $timezone))->getTimestamp();
            } catch (Exception $e) {
                // Try next candidate.
            }
        }

        return null;
    }

    private function cleanMatchUrlTeamCandidate($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $value = rawurldecode($value);
        $value = preg_replace('/[\-_]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    private function extractMatchUrlTeamCandidates($matchUrl) {
        $matchUrl = trim((string)$matchUrl);
        if ($matchUrl === '') {
            return ['home' => [], 'away' => []];
        }

        $path = (string)(parse_url($matchUrl, PHP_URL_PATH) ?: '');
        $slug = trim((string)basename($path));
        if ($slug === '' || stripos($slug, '-vs-') === false) {
            return ['home' => [], 'away' => []];
        }

        $parts = preg_split('/-vs-/i', $slug, 2);
        if (!is_array($parts) || count($parts) !== 2) {
            return ['home' => [], 'away' => []];
        }

        $buildCandidates = function ($value) {
            $candidates = [];
            $seen = [];

            $append = static function ($candidate) use (&$candidates, &$seen) {
                $candidate = trim((string)$candidate);
                if ($candidate === '') {
                    return;
                }

                $key = mb_strtolower($candidate, 'UTF-8');
                if (isset($seen[$key])) {
                    return;
                }

                $seen[$key] = true;
                $candidates[] = $candidate;
            };

            $clean = $this->cleanMatchUrlTeamCandidate($value);
            if ($clean === null) {
                return [];
            }

            $append($clean);

            $withoutSuffix = trim((string)preg_replace('/\b(?:fc|sfc|sc|club)\b/iu', '', $clean));
            $withoutSuffix = preg_replace('/\s+/u', ' ', $withoutSuffix);
            $append($withoutSuffix);

            $withoutArticle = trim((string)preg_replace('/\bal\b/iu', '', $withoutSuffix));
            $withoutArticle = preg_replace('/\s+/u', ' ', $withoutArticle);
            $append($withoutArticle);

            return $candidates;
        };

        return [
            'home' => $buildCandidates($parts[0]),
            'away' => $buildCandidates($parts[1]),
        ];
    }

    private function buildLiveTeamCandidates(array $match, $side) {
        $side = $side === 'away' ? 'away' : 'home';
        $candidates = [];
        $seen = [];

        $append = static function ($value) use (&$candidates, &$seen) {
            if (!is_scalar($value)) {
                return;
            }

            $value = trim((string)$value);
            if ($value === '') {
                return;
            }

            $key = mb_strtolower($value, 'UTF-8');
            if (isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $candidates[] = $value;
        };

        $urlCandidates = $this->extractMatchUrlTeamCandidates($match['match_url'] ?? '');
        foreach ($urlCandidates[$side] ?? [] as $candidate) {
            $append($candidate);
        }

        $append($match[$side . '_team_en'] ?? null);
        $append($match[$side . '_team'] ?? null);
        $append($match[$side . '_team_ar'] ?? null);

        return $candidates;
    }

    private function shouldKeepYssMatchLive($incomingStatus, $incomingMatchTime, $scoreHome, $scoreAway, $existingMatch = null) {
        return false;

        if ($incomingStatus !== 'Scheduled' || !is_array($existingMatch) || empty($existingMatch)) {
            return false;
        }

        $existingStatus = trim((string)($existingMatch['status'] ?? ''));
        if (!in_array($existingStatus, ['Live', 'مباشر'], true)) {
            return false;
        }

        if ($this->isYssLiveSignalText($incomingMatchTime)) {
            return true;
        }

        if ((int)$scoreHome > 0 || (int)$scoreAway > 0) {
            return true;
        }

        $existingMatchTime = trim((string)($existingMatch['match_time'] ?? ''));
        if ($this->isYssLiveSignalText($existingMatchTime)) {
            return true;
        }

        if (!empty($existingMatch['live_url']) || !empty($existingMatch['live_iframe'])) {
            return true;
        }

        $kickoffTimestamp = $this->getStoredMatchKickoffTimestamp($existingMatch);
        if ($kickoffTimestamp === null) {
            return false;
        }

        $elapsed = time() - $kickoffTimestamp;
        return $elapsed >= 0 && $elapsed <= (4 * 3600);
    }

    public function scrapeAndSaveMatchByUrl($url, $explicitMatchId = null) {
        $html = $this->fetchUrl($url);
        if (!$html) return null;

        if (strpos($url, 'ysscores.com') !== false) {
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            $xpath = new DOMXPath($dom);
            
            $homeNode = $xpath->query("//div[contains(@class, 'right-team')]//h3")->item(0);
            $awayNode = $xpath->query("//div[contains(@class, 'left-team')]//h3")->item(0);
            if (!$homeNode || !$awayNode) return null;
            
            $home = trim($homeNode->textContent);
            $away = trim($awayNode->textContent);
            $generalInfo = $this->extractGeneralMatchInfoFromYssDOM($html);
            
            $date = $generalInfo['details_date'] ?? null;
            if (!$date) {
                $dateNodes = $xpath->query("//div[contains(@class, 'match-info-item')]//div[contains(@class, 'content')]");
                foreach ($dateNodes as $dateNode) {
                    $parsedDate = $this->parseMatchDateText(trim($dateNode->textContent));
                    if ($parsedDate !== null) {
                        $date = $parsedDate;
                        break;
                    }
                }
            }
            if (!$date) {
                // IMPORTANT: If we are updating an existing match, DO NOT default to today.
                // Defaulting to today was causing past matches to jump to today's schedule.
                if ($explicitMatchId) {
                    $existing = $this->db->getMatchById($explicitMatchId);
                    if ($existing) $date = $existing['match_date'];
                }
                if (!$date) $date = date('Y-m-d');
            }
            
            // If doesn't exist yet, try to gather league information
            $leagueNode = $xpath->query("//a[contains(@href, '/championship/') and not(contains(@class, 'champ-title'))]")->item(0);
            $league = $leagueNode ? trim($leagueNode->textContent) : ($generalInfo['league'] ?? 'Unknown');
            $league = $this->normalizeYssLeagueName($league);
            
            $homeLogo = $xpath->query("//div[contains(@class, 'right-team')]//img")->item(0);
            $awayLogo = $xpath->query("//div[contains(@class, 'left-team')]//img")->item(0);
            $homeLogoUrl = $homeLogo ? $homeLogo->getAttribute('src') : '';
            $awayLogoUrl = $awayLogo ? $awayLogo->getAttribute('src') : '';

            if ($homeLogoUrl && strpos($homeLogoUrl, 'http') === false) {
                $homeLogoUrl = "https://www.ysscores.com" . (strpos($homeLogoUrl, '/') === 0 ? "" : "/") . $homeLogoUrl;
            }
            if ($awayLogoUrl && strpos($awayLogoUrl, 'http') === false) {
                $awayLogoUrl = "https://www.ysscores.com" . (strpos($awayLogoUrl, '/') === 0 ? "" : "/") . $awayLogoUrl;
            }

            $homeTeamLinkNode = $xpath->query("//div[contains(@class, 'right-team')]/ancestor::a[contains(@href, '/team/')][1]")->item(0);
            $awayTeamLinkNode = $xpath->query("//div[contains(@class, 'left-team')]/ancestor::a[contains(@href, '/team/')][1]")->item(0);
            $homeTeamExternalId = $this->extractYssTeamExternalId($homeTeamLinkNode ? $homeTeamLinkNode->getAttribute('href') : '', $homeLogoUrl);
            $awayTeamExternalId = $this->extractYssTeamExternalId($awayTeamLinkNode ? $awayTeamLinkNode->getAttribute('href') : '', $awayLogoUrl);

        preg_match('/match\/(\d+)/', $url, $urlMatches);
        $matchId = $urlMatches[1] ?? '';

        $mainResultNodes = $xpath->query("//div[contains(@class, 'main-result')]//b");
        $scoreHome = 0;
        $scoreAway = 0;
        if ($mainResultNodes->length >= 2) {
            $scoreHome = (int)trim($mainResultNodes->item(0)->textContent);
            $scoreAway = (int)trim($mainResultNodes->item($mainResultNodes->length - 1)->textContent);
        } else {
            $homeScoreNode = $xpath->query("//span[contains(@class, 'right-team-result')]")->item(0);
            $awayScoreNode = $xpath->query("//span[contains(@class, 'left-team-result')]")->item(0);
            $scoreHome = $homeScoreNode ? (int)trim($homeScoreNode->textContent) : 0;
            $scoreAway = $awayScoreNode ? (int)trim($awayScoreNode->textContent) : 0;
        }

        $statusTextNode = null;
        if ($matchId) {
            $statusTextNode = $xpath->query("//span[@id='result-detail-status-$matchId']")->item(0);
            if (!$statusTextNode) {
                $statusTextNode = $xpath->query("//span[@id='match-detail-status-end-$matchId']")->item(0);
            }
        }
        if (!$statusTextNode) {
            $statusTextNode = $xpath->query("//span[contains(@class, 'result-status-text') and not(contains(@id, 'end'))]")->item(0);
        }
        if (!$statusTextNode) {
            $statusTextNode = $xpath->query("//span[contains(@class, 'result-status-text')]")->item(0);
        }
        if (!$statusTextNode) {
            $statusTextNode = $xpath->query("//div[contains(@class, 'main-result')]//span")->item(0);
        }

        $statusText = $statusTextNode ? trim($statusTextNode->textContent) : '';

        $matchTimeStr = '';
        $timeNode = null;
        if ($matchId) {
            $timeNode = $xpath->query("//div[@id='match-detail-time-$matchId']")->item(0);
        }
        if ($timeNode) {
            $matchTimeStr = trim($timeNode->textContent);
            if (preg_match('/^(\d+):/', $matchTimeStr, $tm)) {
                $matchTimeStr = $tm[1] . "'";
            } elseif (is_numeric($matchTimeStr)) {
                $matchTimeStr = $matchTimeStr . "'";
            }
        }

        if (!$matchTimeStr) {
            $minutesNode = $xpath->query("//div[contains(@class, 'match-inner-progress-wrap')]")->item(0);
            if ($minutesNode && ($mins = $minutesNode->getAttribute('data-minutes'))) {
                $matchTimeStr = $mins . "'";
            }
        }

        $status = 'Scheduled';
            
            if (empty($statusText)) {
                // Find time block if status text is not available
                $timeNode = $xpath->query("//div[contains(@class, 'match-info-center')]//b")->item(0);
                if ($timeNode) {
                    $statusText = trim($timeNode->textContent);
                }
            }

            if (!empty($statusText)) {
                if (strpos($statusText, 'إنتهت') !== false || strpos($statusText, 'Finished') !== false) {
                    $status = 'Finished';
                    if (empty($matchTimeStr)) $matchTimeStr = 'Fin';
                } elseif (strpos($statusText, 'الشوط') !== false || strpos($statusText, 'مباشر') !== false || strpos($statusText, 'Live') !== false || strpos($statusText, 'استراحة') !== false || strpos($statusText, 'إضافي') !== false || $minutesNode) {
                    $status = 'Live';
                    if (empty($matchTimeStr) && $statusTextNode) {
                         $matchTimeStr = trim($statusTextNode->textContent);
                    }
                } elseif (preg_match('/(\d{2}:\d{2})/', $statusText, $tm)) {
                    $matchTimeStr = $tm[1];
                }
            } elseif ($matchTimeStr && $matchTimeStr !== '00:00') {
                $status = 'Live';
            }

            if (
                ($status === 'Scheduled' || $status === 'Postponed') &&
                !empty($generalInfo['details_time'])
            ) {
                $matchTimeStr = $generalInfo['details_time'];
            } elseif (empty($matchTimeStr)) {
                 $matchTimeStr = '00:00';
            }

        $matchTimeStr = $this->normalizeYssLiveTimeValue($matchTimeStr);
        $status = $this->inferYssStatus($statusText, $matchTimeStr, isset($minutesNode) && $minutesNode !== null);
        $scheduledGuardTime = $generalInfo['details_time'] ?? null;
        if ($status === 'Live' && !$this->isYssLiveAllowedByKickoff($date, $scheduledGuardTime)) {
            $status = 'Scheduled';
            $matchTimeStr = $scheduledGuardTime ?: $matchTimeStr;
        }

        $existingMatch = $explicitMatchId
            ? $this->db->getMatchById($explicitMatchId)
            : $this->db->findMatchByNames($home, $away, $date);

        if ($this->shouldKeepYssMatchLive($status, $matchTimeStr, $scoreHome, $scoreAway, $existingMatch)) {
            $status = 'Live';
            if ($matchTimeStr === '' || $this->isClockTimeValue($matchTimeStr)) {
                $existingMatchTime = trim((string)($existingMatch['match_time'] ?? ''));
                $matchTimeStr = $this->isYssLiveSignalText($existingMatchTime) ? $existingMatchTime : '0';
            }
        }

        if ($status === 'Finished') {
            if ($matchTimeStr === '') {
                $matchTimeStr = 'Fin';
            }
        } elseif ($status === 'Live') {
            if ($matchTimeStr === '' || $this->isClockTimeValue($matchTimeStr)) {
                $matchTimeStr = $this->isClockTimeValue($matchTimeStr) ? '0' : $matchTimeStr;
            }
            if ($matchTimeStr === '') {
                $matchTimeStr = '0';
            }
        } elseif (($status === 'Scheduled' || $status === 'Postponed') && !empty($generalInfo['details_time'])) {
            $matchTimeStr = $generalInfo['details_time'];
        } elseif ($matchTimeStr === '') {
            $matchTimeStr = '00:00';
        }

            $matchData = [
            'league' => $league,
            'home_team' => $home,
            'away_team' => $away,
            'score_home' => $scoreHome,
            'score_away' => $scoreAway,
            'status' => $status,
            'match_time' => $matchTimeStr,
            'details_match_time' => $scheduledGuardTime,
            'home_team_logo' => $homeLogoUrl,
            'away_team_logo' => $awayLogoUrl,
            'home_team_external_id' => $homeTeamExternalId,
            'away_team_external_id' => $awayTeamExternalId,
            'match_url' => $url,
            'match_date' => $date
        ];
        
        // $this->db->addScrapeLog('info', "Extracted ysscores matchData: " . json_encode($matchData, JSON_UNESCAPED_UNICODE));

        if ($explicitMatchId) {
            $this->db->updateMatch($explicitMatchId, [
                'home_team' => $home,
                'away_team' => $away,
                'home_team_logo' => $matchData['home_team_logo'],
                'away_team_logo' => $matchData['away_team_logo'],
                'match_date' => $date,
                'match_time' => $matchTimeStr,
                'status' => $status,
                'score_home' => $scoreHome,
                'score_away' => $scoreAway,
                'league_name' => $league,
                'match_url' => $url,
                'home_team_external_id' => $homeTeamExternalId,
                'away_team_external_id' => $awayTeamExternalId
            ]);
            // $this->db->addScrapeLog('info', "Updated explicit match ID: " . $explicitMatchId);
            return $explicitMatchId;
        }

        $existingMatch = $this->db->findMatchByNames($home, $away, $date);
        
        if ($existingMatch) {
            $this->db->updateMatch($existingMatch['id'], [
                'home_team' => $home,
                'away_team' => $away,
                'home_team_logo' => $matchData['home_team_logo'],
                'away_team_logo' => $matchData['away_team_logo'],
                'match_date' => $date,
                'match_time' => $matchTimeStr,
                'status' => $status,
                'score_home' => $scoreHome,
                'score_away' => $scoreAway,
                'league_name' => $league,
                'match_url' => $url,
                'home_team_external_id' => $homeTeamExternalId,
                'away_team_external_id' => $awayTeamExternalId
            ]);
            // $this->db->addScrapeLog('info', "Updated existing match ID: " . $existingMatch['id']);
            return $existingMatch['id'];
        }
        
        $this->db->addScrapeLog('info', "Match not found by names. Creating new...");
        return $this->db->saveMatch($matchData);
        }

        $startTag = '<script id="__NEXT_DATA__" type="application/json">';
        $startPos = strpos($html, $startTag);
        if ($startPos !== false) {
            $jsonStart = $startPos + strlen($startTag);
            $endPos = strpos($html, '</script>', $jsonStart);
            if ($endPos === false) return null;
            $jsonStr = substr($html, $jsonStart, $endPos - $jsonStart);
            $json = json_decode($jsonStr, true);

            $m = $json['props']['pageProps']['data']['match'] ?? 
                 $json['props']['pageProps']['initialState']['matchPage']['match'] ?? 
                 null;

            if (!$m) return null;

            $status = strtoupper($m['status'] ?? '');
            $mappedStatus = 'Scheduled';
            if (in_array($status, ['RESULT', 'FINISHED', 'FT', 'AET'])) $mappedStatus = 'Finished';
            elseif (in_array($status, ['LIVE', 'HT', 'HALFTIME', 'BREAK', 'INT', 'PLAYING'])) $mappedStatus = 'Live';
            elseif (in_array($status, ['POSTPONED', 'CANCELLED'])) $mappedStatus = 'Postponed';

            $utcParts = isset($m['startDate']) ? $this->parseExternalDateTimeToUtcParts($m['startDate']) : null;
            $matchDate = $utcParts['match_date'] ?? null;
            if (!$matchDate || $matchDate === '1970-01-01') {
                if ($explicitMatchId) {
                    $existing = $this->db->getMatchById($explicitMatchId);
                    if ($existing) $matchDate = $existing['match_date'];
                }
                if (!$matchDate) $matchDate = date('Y-m-d');
            }
            $matchTime = $utcParts['match_time'] ?? '00:00';

            if ($mappedStatus === 'Live') {
                if (isset($m['liveTime'])) $matchTime = $m['liveTime'];
                elseif (isset($m['minute'])) $matchTime = $m['minute'] . "'";
                elseif (isset($m['period']['minute'])) $matchTime = $m['period']['minute'] . "'";
                elseif (in_array($status, ['HT', 'HALFTIME', 'BREAK', 'INT'])) $matchTime = 'HT';
            }

            $matchData = [
                'league' => $m['competition']['name'] ?? 'Unknown',
                'league_logo' => $m['competition']['image']['url'] ?? null,
                'league_country' => $m['competition']['area']['name'] ?? null,
                'home_team' => $m['teamA']['name'] ?? '',
                'away_team' => $m['teamB']['name'] ?? '',
                'score_home' => $m['score']['teamA'] ?? 0,
                'score_away' => $m['score']['teamB'] ?? 0,
                'status' => $mappedStatus,
                'match_time' => $matchTime,
                'home_team_logo' => $m['teamA']['image']['url'] ?? '',
                'away_team_logo' => $m['teamB']['image']['url'] ?? '',
                'match_url' => $url,
                'match_date' => $matchDate
            ];

            // $this->db->addScrapeLog('info', "Extracted kooora matchData: " . json_encode($matchData, JSON_UNESCAPED_UNICODE));

            if ($explicitMatchId) {
                $this->db->updateMatch($explicitMatchId, [
                    'home_team' => $matchData['home_team'],
                    'away_team' => $matchData['away_team'],
                    'home_team_logo' => $matchData['home_team_logo'],
                    'away_team_logo' => $matchData['away_team_logo'],
                    'match_date' => $matchData['match_date'],
                    'match_time' => $matchData['match_time'],
                    'status' => $matchData['status'],
                    'score_home' => $matchData['score_home'],
                    'score_away' => $matchData['score_away'],
                    'league_name' => $matchData['league'],
                    'match_url' => $url
                ]);
                // $this->db->addScrapeLog('info', "Updated explicit match ID (Kooora): " . $explicitMatchId);
                return $explicitMatchId;
            }

            return $this->db->saveMatch($matchData);
        }
        return null;
    }

    public function scrapeKooora($date = null, $clearLogs = true) {
        $requestedDate = $date ?: date('Y-m-d');
        if ($date) {
            // Correct URL for matches by date
            $url = "https://www.kooora.com/%D9%83%D8%B1%D8%A9-%D8%A7%D9%84%D9%82%D8%AF%D9%85/%D9%85%D9%88%D8%A7%D8%B9%D9%8A%D8%AF-%D8%A7%D9%84%D9%85%D8%A8%D8%A7%D8%B1%D9%8A%D8%A7%D8%AA/$date?t=" . time();
        } else {
            $url = "https://www.kooora.com/default.aspx?region=-1&area=0";
        }
        
        if ($clearLogs) $this->db->clearScrapeLogs();
        $this->db->addScrapeLog('info', "بدء جلب المباريات لتاريخ: $requestedDate [0%]");
        
        $this->db->addScrapeLog('info', "جاري الاتصال بموقع كووورة... [10%]");
        $html = $this->fetchUrl($url);
        if (!$html) {
            $this->db->addScrapeLog('error', "فشل في جلب الصفحة من Kooora [100%]");
            return ["status" => "error", "message" => "Failed to fetch Kooora"];
        }
        $this->db->addScrapeLog('info', "تم جلب البيانات بنجاح، جاري التحليل... [30%]");

        // We don't overwrite $requestedDate with $realDate from title anymore,
        // because we want to strictly filter matches for the date we asked for.
        // However, we can use $realDate as a fallback if no date was requested.
        $realDate = $this->extractDateFromTitle($html);
        if (!$date && $realDate) $requestedDate = $realDate;

        $this->db->addScrapeLog('info', "جاري استخراج المباريات... [40%]");
        // Try JSON extraction first (more reliable for scores/status)
        $matches = $this->extractMatchesFromJson($html, $requestedDate);
        
        // Fallback to DOM if JSON fails
        if (empty($matches)) {
            $this->db->addScrapeLog('info', "لم يتم العثور على بيانات JSON، جاري استخدام التحليل التقليدي... [45%]");
            $matches = $this->extractMatchesNative($html, $requestedDate);
        }

        $count = 0;
        
        if (is_array($matches)) {
            $totalMatches = count($matches);
            $this->db->addScrapeLog('info', "تم العثور على $totalMatches مباراة، جاري المعالجة... [50%]");
            
            foreach ($matches as $index => &$match) {
                $progress = 50 + round((($index + 1) / $totalMatches) * 45);
                
                // Ensure we have a match date from the extraction
                $actualMatchDate = $match['match_date'] ?? $requestedDate;
                
                // CRITICAL: Verify the match date matches the requested date
                if ($actualMatchDate !== $requestedDate) {
                    $this->db->addScrapeLog('filter', "تخطي مباراة: {$match['home_team']} vs {$match['away_team']} (تاريخ مختلف: $actualMatchDate) [$progress%]");
                    continue;
                }

                if (!empty($match['home_team_logo']) && strpos($match['home_team_logo'], 'http') === false) {
                    $match['home_team_logo'] = "https://www.kooora.com/" . ltrim($match['home_team_logo'], '/');
                }
                if (!empty($match['away_team_logo']) && strpos($match['away_team_logo'], 'http') === false) {
                    $match['away_team_logo'] = "https://www.kooora.com/" . ltrim($match['away_team_logo'], '/');
                }
                
                if (!$this->db->isLeagueActive($match['league'])) {
                    $this->db->addScrapeLog('filter', "تخطي مباراة: {$match['home_team']} vs {$match['away_team']} (دوري أو دولة غير مفعلة: {$match['league']}). يمكنك تفعيلها من إعدادات الدوريات والدول. [$progress%]");
                    continue;
                }
                
                $this->db->saveMatch($match);
                $this->db->addScrapeLog('success', "تم حفظ مباراة: {$match['home_team']} vs {$match['away_team']} ({$match['league']}) [$progress%]");
                $count++;
            }
        }
        $this->db->addScrapeLog('info', "اكتمل الجلب: تم حفظ $count مباراة بنجاح [100%]");
        return ["status" => "success", "message" => "Scraped $count matches", "total" => $count, "date" => $requestedDate];
    }




    public function scrapeYssScore($date = null, $clearLogs = true) {
        $requestedDate = $date ?: date('Y-m-d');
        // Base URL
        $url = "https://www.ysscores.com/ar/index";
        $legacyUrl = "https://www.ysscores.com/ar/today_matches";
        
        if ($clearLogs) $this->db->clearScrapeLogs();
        $this->db->addScrapeLog('info', "بدء جلب المباريات من yssScore لتاريخ: $requestedDate [0%]");
        
        $html = null;
        if ($date && $date !== date('Y-m-d')) {
            $this->db->addScrapeLog('info', "جاري الحصول على تصريح الوصول لتاريخ $requestedDate... [10%]");
            // 1. Get main page to obtain session cookies and CSRF token
            $mainHtml = $this->fetchUrl($url, null, [], true);
            if (!$this->looksLikeYssMatchesHtml($mainHtml)) {
                $legacyMainHtml = $this->fetchUrl($legacyUrl, null, [], true);
                if ($this->looksLikeYssMatchesHtml($legacyMainHtml)) {
                    $mainHtml = $legacyMainHtml;
                }
            }
            
            if (preg_match('/<meta name="_token" content="([^"]+)"/', $mainHtml, $tokenMatches)) {
                $token = $tokenMatches[1];
                $this->db->addScrapeLog('info', "تم الحصول على تصريح الوصول، جاري جلب مباريات التاريخ المطلوب... [20%]");

                $cookieFile = __DIR__ . '/scraper_cookies.txt';
                $utcHeaders = [
                    "X-CSRF-Token: $token",
                    "X-Requested-With: XMLHttpRequest",
                    "Origin: https://www.ysscores.com",
                    "Referer: {$url}",
                    "Accept: */*",
                    "Accept-Language: ar,en;q=0.8"
                ];

                $this->performCurlRequest(
                    "https://www.ysscores.com/ar/change_zone",
                    ['zone_is' => 'utc'],
                    $utcHeaders,
                    $cookieFile
                );
                
                $postUrl = "https://www.ysscores.com/ar/match_date_to";
                $postData = [
                    'get_date' => $requestedDate,
                ];
                $html = $this->performCurlRequest($postUrl, $postData, $utcHeaders, $cookieFile);

                if (!$this->looksLikeYssMatchesHtml($html)) {
                    $this->db->addScrapeLog('warning', "استجابة yssScore للتاريخ $requestedDate لم تحتوِ على مباريات صالحة، جاري استخدام fallback المتصفح... [25%]");
                    $browserHtml = $this->fetchYssDateWithBrowser($requestedDate);
                    if ($this->looksLikeYssMatchesHtml($browserHtml)) {
                        $html = $browserHtml;
                        $this->db->addScrapeLog('info', "نجح fallback المتصفح في جلب مباريات تاريخ $requestedDate [28%]");
                    } else {
                        $this->db->addScrapeLog('warning', "فشل fallback المتصفح في إرجاع مباريات صالحة لتاريخ $requestedDate [28%]");
                    }
                }
            } else {
                $this->db->addScrapeLog('warning', "لم يتم العثور على رمز الحماية، محاولة الجلب بالطريقة العادية... [20%]");
                $html = $mainHtml; 
            }
        } else {
            $this->db->addScrapeLog('info', "جاري الاتصال بموقع yssScore... [10%]");
            $html = $this->fetchUrl($url);
            $legacyHtml = $this->fetchUrl($legacyUrl);
            $html = $this->selectMostCompleteYssHtml($html, $legacyHtml, $requestedDate);
        }
        
        if (!$html) {
            $this->db->addScrapeLog('error', "فشل في جلب الصفحة من yssScore [100%]");
            return ["status" => "error", "message" => "Failed to fetch YssScore"];
        }
        $this->db->addScrapeLog('info', "تم جلب البيانات بنجاح، جاري التحليل... [30%]");

        $matches = $this->extractMatchesFromYssDOM($html, $requestedDate);
        
        $count = 0;
        if (is_array($matches)) {
            $totalMatches = count($matches);
            $this->db->addScrapeLog('info', "تم العثور على $totalMatches مباراة في الصفحات، جاري المعالجة... [50%]");
            
            foreach ($matches as $index => &$match) {
                $progress = 50 + round((($index + 1) / $totalMatches) * 45);
                
                if (!$this->db->isLeagueActive($match['league'])) {
                    $this->db->addScrapeLog('filter', "تخطي مباراة: {$match['home_team']} vs {$match['away_team']} (دوري أو دولة غير مفعلة: {$match['league']}) [$progress%]");
                    continue;
                }
                
                $this->db->saveMatch($match);
                $this->db->addScrapeLog('success', "تم حفظ مباراة: {$match['home_team']} vs {$match['away_team']} ({$match['league']}) [$progress%]");
                $count++;
            }
        } else {
            $this->db->addScrapeLog('warning', "لم يتم العثور على أي مباريات في الصفحة المحللة [100%]");
        }
        
        $this->db->addScrapeLog('info', "اكتمل الجلب: تم حفظ $count مباراة بنجاح [100%]");
        return ["status" => "success", "message" => "Scraped $count matches", "total" => $count, "date" => $requestedDate];
    }

    private function countYssMatchesInHtml($html, $date = null) {
        $matches = $this->extractMatchesFromYssDOM($html, $date ?: date('Y-m-d'));
        return is_array($matches) ? count($matches) : 0;
    }

    private function selectMostCompleteYssHtml($primaryHtml, $secondaryHtml, $date = null) {
        if (!$this->looksLikeYssMatchesHtml($primaryHtml)) {
            return $this->looksLikeYssMatchesHtml($secondaryHtml) ? $secondaryHtml : $primaryHtml;
        }

        if (!$this->looksLikeYssMatchesHtml($secondaryHtml)) {
            return $primaryHtml;
        }

        $primaryCount = $this->countYssMatchesInHtml($primaryHtml, $date);
        $secondaryCount = $this->countYssMatchesInHtml($secondaryHtml, $date);

        return $secondaryCount > $primaryCount ? $secondaryHtml : $primaryHtml;
    }

    private function extractMatchesFromYssDOM($html, $date) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        $matches = [];

        $wrappers = $xpath->query("//div[contains(@class, 'matches-wrapper')]");

        foreach ($wrappers as $wrapper) {
            $leagueNode = $xpath->query(".//a[contains(@class, 'champ-title')]//b", $wrapper)->item(0);
            $leagueName = $this->normalizeYssLeagueName($leagueNode ? trim($leagueNode->textContent) : 'Unknown');

            $leagueImg = $xpath->query(".//a[contains(@class, 'champ-title')]//img", $wrapper)->item(0);
            $leagueLogo = $leagueImg ? $leagueImg->getAttribute('src') : null;

            $matchNodes = $xpath->query(".//a[contains(@class, 'ajax-match-item')]", $wrapper);

            foreach ($matchNodes as $node) {
                $m = ['match_date' => $date];
                $m['league'] = $leagueName;
                $m['league_logo'] = $leagueLogo;

                $classes = (string)$node->getAttribute('class');
                $m['home_team'] = trim((string)$node->getAttribute('home_name'));
                $m['away_team'] = trim((string)$node->getAttribute('away_name'));
                $m['home_team_logo'] = (string)$node->getAttribute('home_image');
                $m['away_team_logo'] = (string)$node->getAttribute('away_image');
                $m['home_team_external_id'] = $this->extractYssTeamExternalId((string)$node->getAttribute('home_link'), $m['home_team_logo']);
                $m['away_team_external_id'] = $this->extractYssTeamExternalId((string)$node->getAttribute('away_link'), $m['away_team_logo']);

                $url = (string)$node->getAttribute('href');
                if ($url && strpos($url, 'http') === false) {
                    $url = "https://www.ysscores.com" . (strpos($url, '/') === 0 ? "" : "/") . $url;
                }
                $m['match_url'] = $url;

                if ($m['home_team_logo'] && strpos($m['home_team_logo'], 'http') === false) {
                    $m['home_team_logo'] = "https://www.ysscores.com" . (strpos($m['home_team_logo'], '/') === 0 ? "" : "/") . $m['home_team_logo'];
                }
                if ($m['away_team_logo'] && strpos($m['away_team_logo'], 'http') === false) {
                    $m['away_team_logo'] = "https://www.ysscores.com" . (strpos($m['away_team_logo'], '/') === 0 ? "" : "/") . $m['away_team_logo'];
                }

                $m['status'] = 'Scheduled';
                $m['score_home'] = 0;
                $m['score_away'] = 0;
                $m['match_time'] = '';

                $minutesNode = $xpath->query(".//div[contains(@class, 'match-inner-progress-wrap')]", $node)->item(0);
                $minutesValue = $minutesNode ? trim((string)$minutesNode->getAttribute('data-minutes')) : '';

                $statusNode = $xpath->query(".//span[contains(@class, 'live-match-status')]", $node)->item(0);
                if (!$statusNode) {
                    $statusNode = $xpath->query(".//span[contains(@class, 'result-status-text')]", $node)->item(0);
                }
                $statusText = $statusNode ? trim($statusNode->textContent) : '';
                $liveTimeValue = $minutesValue !== '' ? ($minutesValue . "'") : $this->normalizeYssLiveTimeValue($statusText);

                $scheduledTimeNode = $xpath->query(".//div[contains(@class, 'result-wrap')]//b[contains(@class, 'match-date')]", $node)->item(0);
                $scheduledTimeText = $scheduledTimeNode ? trim($scheduledTimeNode->textContent) : '';
                $scheduledTime = $this->normalizeTime($scheduledTimeText);
                $m['details_match_time'] = $scheduledTime;

                $detectedStatus = $this->inferYssStatus(
                    $statusText,
                    $liveTimeValue,
                    $minutesNode !== null || strpos($classes, 'live-match') !== false || strpos($classes, 'active-match') !== false
                );
                if ($detectedStatus === 'Live' && !$this->isYssLiveAllowedByKickoff($date, $scheduledTime)) {
                    $detectedStatus = 'Scheduled';
                }

                if ($detectedStatus === 'Live') {
                    $m['status'] = 'Live';

                    $homeScoreNode = $xpath->query(".//div[contains(@class, 'first-team')]//div[contains(@class, 'team-result')]", $node)->item(0);
                    $awayScoreNode = $xpath->query(".//div[contains(@class, 'second-team')]//div[contains(@class, 'team-result')]", $node)->item(0);
                    if (!$homeScoreNode) {
                        $homeScoreNode = $xpath->query(".//div[contains(@class, 'result-wrap')]//span[contains(@class, 'first-team-result')]", $node)->item(0);
                    }
                    if (!$awayScoreNode) {
                        $awayScoreNode = $xpath->query(".//div[contains(@class, 'result-wrap')]//span[contains(@class, 'second-team-result')]", $node)->item(0);
                    }

                    $m['score_home'] = $homeScoreNode ? (int)trim($homeScoreNode->textContent) : 0;
                    $m['score_away'] = $awayScoreNode ? (int)trim($awayScoreNode->textContent) : 0;
                    $m['match_time'] = $liveTimeValue !== '' ? $liveTimeValue : '0';
                } elseif ($detectedStatus === 'Finished') {
                    $m['status'] = 'Finished';

                    $homeScoreNode = $xpath->query(".//div[contains(@class, 'result-wrap')]//b//span[contains(@class, 'first-team-result')]", $node)->item(0);
                    $awayScoreNode = $xpath->query(".//div[contains(@class, 'result-wrap')]//b//span[contains(@class, 'second-team-result')]", $node)->item(0);

                    $m['score_home'] = $homeScoreNode ? (int)trim($homeScoreNode->textContent) : 0;
                    $m['score_away'] = $awayScoreNode ? (int)trim($awayScoreNode->textContent) : 0;
                    $m['match_time'] = 'Fin';
                } elseif ($detectedStatus === 'Postponed') {
                    $m['status'] = 'Postponed';
                    $m['match_time'] = 'Postponed';
                } else {
                    $m['status'] = 'Scheduled';
                    $m['match_time'] = $scheduledTime;
                }

                $matches[] = $m;
            }
        }

        return $matches;
    }

    private function extractYssTeamExternalId($teamUrl, $logoUrl = '') {
        $teamUrl = trim((string)$teamUrl);

        if ($teamUrl !== '' && preg_match('~(?:^|/)team/(\d+)(?:/|$)~i', $teamUrl, $matches)) {
            return 'yss-team:' . $matches[1];
        }

        $logoUrl = trim((string)$logoUrl);
        if ($logoUrl !== '' && preg_match('~/teams/\d+/([^/?#]+)~i', $logoUrl, $matches)) {
            return 'yss-team-img:' . pathinfo($matches[1], PATHINFO_FILENAME);
        }

        return null;
    }

    private function extractMatchesFromJson($html, $date) {
        $startTag = '<script id="__NEXT_DATA__" type="application/json">';
        $startPos = strpos($html, $startTag);
        if ($startPos === false) return null;

        $jsonStart = $startPos + strlen($startTag);
        $endPos = strpos($html, '</script>', $jsonStart);
        if ($endPos === false) return null;

        $jsonStr = substr($html, $jsonStart, $endPos - $jsonStart);
        $json = json_decode($jsonStr, true);
        if (!$json) return null;

        // Map IDs to URLs from DOM
        $urlMap = [];
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        $links = $xpath->query("//a[@id]");
        foreach ($links as $link) {
            $id = $link->getAttribute('id');
            $href = $link->getAttribute('href');
            if ($id && $href) {
                $urlMap[$id] = $href;
            }
        }

        $competitions = null;
        $data = $json['props']['pageProps']['data'] ?? null;
        
        if ($data) {
            if (isset($data['scoreboard']['competitions'])) {
                $competitions = $data['scoreboard']['competitions'];
            } elseif (is_array($data) && isset($data[0]['matches'])) {
                $competitions = $data;
            }
        }
        
        if (!$competitions) {
            $competitions = $json['props']['pageProps']['initialState']['common']['scoreboard']['competitions'] ?? null;
        }

        if (!$competitions) return null;

        $extracted = [];
        foreach ($competitions as $comp) {
            $leagueName = $comp['name'] ?? $comp['competition']['name'] ?? 'Unknown';
            if (!isset($comp['matches'])) continue;

            foreach ($comp['matches'] as $m) {
                $status = strtoupper($m['status'] ?? '');
                $mappedStatus = 'Scheduled';
                if (in_array($status, ['RESULT', 'FINISHED', 'FT', 'AET'])) $mappedStatus = 'Finished';
                elseif (in_array($status, ['LIVE', 'HT', 'HALFTIME', 'BREAK', 'INT', 'PLAYING'])) $mappedStatus = 'Live';
                elseif (in_array($status, ['POSTPONED', 'CANCELLED'])) $mappedStatus = 'Postponed';

                $matchTime = '';
                $matchDate = $date;
                if (isset($m['startDate'])) {
                    $utcParts = $this->parseExternalDateTimeToUtcParts($m['startDate']);
                    if ($utcParts !== null) {
                        $matchTime = $utcParts['match_time'];
                        $matchDate = $utcParts['match_date'];
                    }
                }
                
                if ($mappedStatus === 'Live') {
                    if (isset($m['liveTime'])) $matchTime = $m['liveTime'];
                    elseif (isset($m['minute'])) $matchTime = $m['minute'] . "'";
                    elseif (isset($m['period']['minute'])) {
                        $matchTime = $m['period']['minute'] . "'";
                        if (isset($m['period']['extra']) && $m['period']['extra'] > 0) $matchTime = $m['period']['minute'] . "+" . $m['period']['extra'] . "'";
                    } elseif (in_array($status, ['HT', 'HALFTIME', 'BREAK', 'INT'])) {
                        $matchTime = 'HT';
                    }
                }

                $homeName = $m['teamA']['name'] ?? '';
                $awayName = $m['teamB']['name'] ?? '';
                
                $matchId = $m['id'] ?? null;
                $matchUrl = $m['link']['url'] ?? '';
                
                if ($matchUrl && strpos($matchUrl, 'http') === false) {
                    $matchUrl = "https://www.kooora.com" . $matchUrl;
                }

                if (!$matchUrl && $matchId) {
                    // Fallback construction if not in JSON (less reliable)
                    $cleanHome = str_replace([' ', '/'], '-', $homeName);
                    $cleanAway = str_replace([' ', '/'], '-', $awayName);
                    
                    $cleanHome = trim(preg_replace('/-+/', '-', $cleanHome), '-');
                    $cleanAway = trim(preg_replace('/-+/', '-', $cleanAway), '-');
                    
                    $slug = urlencode($cleanHome . '-v-' . $cleanAway);
                    $cat = urlencode("كرة-القدم");
                    $type = urlencode("مباراة");
                    $matchUrl = "https://www.kooora.com/$cat/$type/$slug/" . $matchId;
                }

                $leagueLogo = $comp['image']['url'] ?? $comp['competition']['image']['url'] ?? null;
                $leagueCountry = $comp['area']['name'] ?? $comp['competition']['area']['name'] ?? null;

                $match = [
                    'league' => $leagueName,
                    'league_logo' => $leagueLogo,
                    'league_country' => $leagueCountry,
                    'home_team' => $homeName,
                    'away_team' => $awayName,
                    'score_home' => $m['score']['teamA'] ?? 0,
                    'score_away' => $m['score']['teamB'] ?? 0,
                    'status' => $mappedStatus,
                    'match_time' => ($mappedStatus === 'Postponed') ? 'Postponed' : $matchTime,
                    'home_team_logo' => $m['teamA']['image']['url'] ?? '',
                    'away_team_logo' => $m['teamB']['image']['url'] ?? '',
                    'match_url' => $matchUrl,
                    'match_date' => $matchDate
                ];
                
                $extracted[] = $match;
            }
        }
        return $extracted;
    }

    private function extractDateFromTitle($html) {
        $months = ['يناير'=>'01','فبراير'=>'02','مارس'=>'03','أبريل'=>'04','مايو'=>'05','يونيو'=>'06','يوليو'=>'07','أغسطس'=>'08','سبتمبر'=>'09','أكتوبر'=>'10','نوفمبر'=>'11','ديسمبر'=>'12'];
        if (preg_match('/<title[^>]*>(.*?)<\/title>/iu', $html, $matches)) {
            if (preg_match('/(\d{1,2})\s+([\p{L}\s]+)\s+(\d{4})/u', $matches[1], $dateMatches)) {
                $m = trim($dateMatches[2]);
                foreach ($months as $ar => $en) { if (mb_strpos($m, $ar) !== false) return "{$dateMatches[3]}-$en-".str_pad($dateMatches[1],2,'0',STR_PAD_LEFT); }
            }
        }
        return null;
    }

    private function extractMatchesNative($html, $date = null) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        $matches = [];

        // New Style (fco-match-list-item)
        $nodes = $xpath->query("//div[contains(@class, 'fco-match-list-item')]");
        foreach ($nodes as $node) {
            $m = ['match_date' => $date];
            
            // League
            $ln = $xpath->query("./ancestor::div[contains(@class, 'match-list_livescores-match-list__section')]//span[contains(@class, 'fco-competition-section__header-name')]", $node)->item(0);
            if (!$ln) $ln = $xpath->query("./ancestor::div//span[contains(@class, 'fco-competition-section__header-name')]", $node)->item(0);
            $m['league'] = $ln ? trim($ln->textContent) : 'Unknown';
            
            // Teams
            $ht = $xpath->query(".//div[@data-side='team-a']//div[contains(@class, 'fco-full-name')]", $node)->item(0);
            $at = $xpath->query(".//div[@data-side='team-b']//div[contains(@class, 'fco-full-name')]", $node)->item(0);
            if (!$ht || !$at) continue;

            $m['home_team'] = trim($ht->textContent);
            $m['away_team'] = trim($at->textContent);
            
            // Scores
            $hs = $xpath->query(".//div[@data-side='team-a']//div[contains(@class, 'fco-match-score')]", $node)->item(0);
            $as = $xpath->query(".//div[@data-side='team-b']//div[contains(@class, 'fco-match-score')]", $node)->item(0);
            if (!$hs) $hs = $xpath->query(".//div[@data-side='team-a']//span[contains(@class, 'fco-match-score')]", $node)->item(0);
            if (!$as) $as = $xpath->query(".//div[@data-side='team-b']//span[contains(@class, 'fco-match-score')]", $node)->item(0);
            
            $m['score_home'] = $hs ? (int)trim($hs->textContent) : 0;
            $m['score_away'] = $as ? (int)trim($as->textContent) : 0;
            
            // Status and Time
            $stn = $xpath->query(".//div[contains(@class, 'fco-match-status')]", $node)->item(0);
            $st = $stn ? trim($stn->textContent) : '';
            $ltn = $xpath->query(".//div[contains(@class, 'fco-match-live-time')]", $node)->item(0);
            $lt = $ltn ? trim($ltn->textContent) : '';
            
            $m['status'] = ($lt || strpos($st, "'") !== false || strpos($st, 'استراحة') !== false || strpos($st, 'دقيق') !== false || $st === 'HT' || $st === 'Break') ? 'Live' : (($st === 'انتهت' || $st === 'FT' || $st === 'Finished') ? 'Finished' : (($st === 'مؤجلة' || $st === 'Postponed') ? 'Postponed' : 'Scheduled'));
            
            $tn = $xpath->query(".//time", $node)->item(0);
            if ($tn) {
                $dt_str = $tn->getAttribute('dateTime'); // Note the uppercase T in dateTime common in Next.js
                if (!$dt_str) $dt_str = $tn->getAttribute('datetime');
                if ($dt_str) {
                    $utcParts = $this->parseExternalDateTimeToUtcParts($dt_str);
                    if ($utcParts !== null) {
                        $m['match_date'] = $utcParts['match_date'];
                        if ($m['status'] === 'Scheduled') {
                            $m['match_time'] = $utcParts['match_time'];
                        }
                    }
                }
            }

            if ($m['status'] === 'Live') {
                $m['match_time'] = $lt ?: $st;
            } elseif ($m['status'] === 'Finished') {
                $m['match_time'] = 'FT';
            } elseif ($m['status'] === 'Postponed') {
                $m['match_time'] = 'Postponed';
            } elseif (empty($m['match_time'])) {
                $m['match_time'] = '00:00';
            }
            
            // Logos
            $hl = $xpath->query(".//div[@data-side='team-a']//img", $node)->item(0);
            $al = $xpath->query(".//div[@data-side='team-b']//img", $node)->item(0);
            $m['home_team_logo'] = $hl ? $hl->getAttribute('src') : '';
            $m['away_team_logo'] = $al ? $al->getAttribute('src') : '';
            
            // URL
            $un = $xpath->query(".//a[contains(@class, 'fco-match-data')]", $node)->item(0);
            $m['match_url'] = $un ? "https://www.kooora.com" . $un->getAttribute('href') : '';
            
            $matches[] = $m;
        }

        if (!empty($matches)) return $matches;

        // Legacy Card Style
        $nodes = $xpath->query("//a[contains(@class, 'fco-match-card')]");
        foreach ($nodes as $node) {
            $m = ['match_date' => $date];
            $ln = $xpath->query(".//span[contains(@class, 'fco-match-card__competition')]", $node)->item(0);
            $m['league'] = $ln ? trim($ln->textContent) : 'Unknown';
            
            $ht = $xpath->query(".//div[contains(@class, 'fco-match-card__team--home')]//div[contains(@class, 'fco-team-name')]", $node)->item(0);
            $at = $xpath->query(".//div[contains(@class, 'fco-match-card__team--away')]//div[contains(@class, 'fco-team-name')]", $node)->item(0);
            if (!$ht || !$at) continue;

            $m['home_team'] = trim($ht->textContent);
            $m['away_team'] = trim($at->textContent);
            
            $hs = $xpath->query(".//span[contains(@class, 'fco-match-card__score--home')]", $node)->item(0);
            $as = $xpath->query(".//span[contains(@class, 'fco-match-card__score--away')]", $node)->item(0);
            $m['score_home'] = $hs ? (int)trim($hs->textContent) : 0;
            $m['score_away'] = $as ? (int)trim($as->textContent) : 0;
            
            $stn = $xpath->query(".//span[contains(@class, 'fco-match-state')]", $node)->item(0);
            $st = $stn ? trim($stn->textContent) : '';
            $ltn = $xpath->query(".//span[contains(@class, 'fco-match-time')]", $node)->item(0);
            $lt = $ltn ? trim($ltn->textContent) : '';
            
            $m['status'] = ($lt || strpos($st, "'") !== false || strpos($st, 'استراحة') !== false || strpos($st, 'دقيقة') !== false || $st === 'HT' || $st === 'Break') ? 'Live' : (($st === 'انتهت' || $st === 'FT' || $st === 'Finished') ? 'Finished' : (($st === 'مؤجلة' || $st === 'Postponed') ? 'Postponed' : 'Scheduled'));
            
            $tn = $xpath->query(".//time", $node)->item(0);
            if ($tn) {
                $dt_str = $tn->getAttribute('datetime');
                if ($dt_str) {
                    $utcParts = $this->parseExternalDateTimeToUtcParts($dt_str);
                    if ($utcParts !== null) {
                        $m['match_date'] = $utcParts['match_date'];
                        if ($m['status'] === 'Scheduled') {
                            $m['match_time'] = $utcParts['match_time'];
                        }
                    }
                }
            }

            if ($m['status'] === 'Live') {
                $m['match_time'] = $lt ?: $st;
            } elseif ($m['status'] === 'Finished') {
                $m['match_time'] = 'FT';
            } elseif ($m['status'] === 'Postponed') {
                $m['match_time'] = 'Postponed';
            } elseif (empty($m['match_time'])) {
                $m['match_time'] = '00:00';
            }
            
            $hl = $xpath->query(".//div[contains(@class, 'fco-match-card__team--home')]//img", $node)->item(0);
            $al = $xpath->query(".//div[contains(@class, 'fco-match-card__team--away')]//img", $node)->item(0);
            $m['home_team_logo'] = $hl ? $hl->getAttribute('src') : '';
            $m['away_team_logo'] = $al ? $al->getAttribute('src') : '';
            
            $m['match_url'] = "https://www.kooora.com" . $node->getAttribute('href');
            $matches[] = $m;
        }

        // Row Style
        $nodes = $xpath->query("//div[contains(@class, 'fco-match-row')]");
        foreach ($nodes as $node) {
            $m = ['match_date' => $date];
            $ln = $xpath->query("./ancestor::div[contains(@class, 'fco-competition-section')]//span[contains(@class, 'header-name')]", $node)->item(0);
            $m['league'] = $ln ? trim($ln->textContent) : 'Unknown';
            
            $ht = $xpath->query(".//div[contains(@class, 'team-a')]//div[contains(@class, 'fco-team-name')]", $node)->item(0);
            $at = $xpath->query(".//div[contains(@class, 'team-b')]//div[contains(@class, 'fco-team-name')]", $node)->item(0);
            if (!$ht || !$at) continue;

            $m['home_team'] = trim($ht->textContent);
            $m['away_team'] = trim($at->textContent);
            
            $hs = $xpath->query(".//div[@data-side='team-a']", $node)->item(0);
            $as = $xpath->query(".//div[@data-side='team-b']", $node)->item(0);
            $m['score_home'] = $hs ? (int)trim($hs->textContent) : 0;
            $m['score_away'] = $as ? (int)trim($as->textContent) : 0;
            
            $stn = $xpath->query(".//a[contains(@class, 'fco-match-state')]", $node)->item(0);
            $st = $stn ? trim($stn->textContent) : '';
            
            $m['status'] = (strpos($st, "'") !== false || strpos($st, 'استراحة') !== false || strpos($st, 'دقيقة') !== false || $st === 'HT' || $st === 'Break') ? 'Live' : (($st === 'انتهت' || $st === 'FT' || $st === 'Finished') ? 'Finished' : (($st === 'مؤجلة' || $st === 'Postponed') ? 'Postponed' : 'Scheduled'));
            
            $tn = $xpath->query(".//time", $node)->item(0);
            if ($tn) {
                $dt_str = $tn->getAttribute('datetime');
                if ($dt_str) {
                    $utcParts = $this->parseExternalDateTimeToUtcParts($dt_str);
                    if ($utcParts !== null) {
                        $m['match_date'] = $utcParts['match_date'];
                        if ($m['status'] === 'Scheduled') {
                            $m['match_time'] = $utcParts['match_time'];
                        }
                    }
                }
            }

            if ($m['status'] === 'Live') {
                $m['match_time'] = $st;
            } elseif ($m['status'] === 'Finished') {
                $m['match_time'] = 'FT';
            } elseif ($m['status'] === 'Postponed') {
                $m['match_time'] = 'Postponed';
            } elseif (empty($m['match_time'])) {
                $m['match_time'] = '00:00';
            }
            
            $hl = $xpath->query(".//div[contains(@class, 'team-a')]//img", $node)->item(0);
            $al = $xpath->query(".//div[contains(@class, 'team-b')]//img", $node)->item(0);
            $m['home_team_logo'] = $hl ? $hl->getAttribute('src') : '';
            $m['away_team_logo'] = $al ? $al->getAttribute('src') : '';
            
            $un = $xpath->query(".//a[contains(@class, 'container')]", $node)->item(0);
            $m['match_url'] = $un ? "https://www.kooora.com" . $un->getAttribute('href') : '';
            $matches[] = $m;
        }
        return $matches;
    }

    public function scrapeAllStandings($date = null, $clearLogs = true) {
        $date = $date ?: date('Y-m-d');
        if ($clearLogs) $this->db->clearScrapeLogs();
        $this->db->addScrapeLog('info', "بدء تحديث جداول ترتيب الدوريات لتاريخ: $date [0%]");

        $matches = $this->db->getMatches($date);
        if (empty($matches)) {
            $this->db->addScrapeLog('info', "لا توجد مباريات لتحديث ترتيبها اليوم [100%]");
            return ['status' => 'error', 'message' => 'No matches'];
        }
        
        $total = count($matches);
        $this->db->addScrapeLog('info', "تم العثور على $total مباراة، جاري جلب جداول الترتيب... [10%]");

        $updated = 0; $cache = [];
        foreach ($matches as $index => $match) {
            $progress = 10 + round((($index + 1) / $total) * 85);
            if (empty($match['match_url'])) {
                $this->db->addScrapeLog('filter', "تخطي مباراة: {$match['home_team']} vs {$match['away_team']} (لا يوجد رابط) [$progress%]");
                continue;
            }
            
            $this->db->addScrapeLog('info', "جاري جلب ترتيب: {$match['home_team']} vs {$match['away_team']} [$progress%]");
            $html = $this->fetchUrl($match['match_url']);
            
            $isYss = (strpos($match['match_url'], 'ysscores.com') !== false);
            $data = [];
            
            // Priority 1 for YssScore: Use API-based extraction (most reliable)
            if ($isYss) {
                $data = $this->extractStandingsFromYssAPI($html);
            }
            
            // Priority 2: Try to get standings from championship page URL
            if (empty($data)) {
                $url = $this->extractStandingsUrl($html);
                if ($url) {
                    if (strpos($url, 'http') === false) {
                        $baseUrl = $isYss ? "https://www.ysscores.com" : "https://www.kooora.com";
                        $url = $baseUrl . (strpos($url, '/') === 0 ? "" : "/") . $url;
                    }
                    if (!isset($cache[$url])) $cache[$url] = $this->fetchUrl($url);
                    
                    if ($isYss) {
                        $data = $this->extractStandingsFromYssDOM($cache[$url]);
                    } else {
                        // Strict matching: only accept table if it contains our teams
                        $data = $this->parseRelevantTable($cache[$url], $match['home_team'], $match['away_team']);
                        // Fallback to JSON if HTML table parsing failed
                        if (empty($data)) {
                            $data = $this->extractStandingsFromJson($cache[$url]);
                        }
                    }
                }
            }

            // Priority 3 (Fallback): Parse match page directly
            if (empty($data)) {
                $matchPageData = $isYss ? $this->extractStandingsFromYssDOM($html) : $this->extractStandingsFromJson($html);
                if (!empty($matchPageData)) {
                    $data = $matchPageData;
                }
            }

            // Final safety check: Does the data actually contain at least one of our teams?
            if (!empty($data) && !$this->isTableRelevant($data, $match['home_team'], $match['away_team'])) {
                $data = null; // Reject irrelevant data
            }

            if (!empty($data)) { 
                // Only accept tables with more than 3 teams (+3 requirement)
                if (count($data) <= 3) {
                    $this->db->addScrapeLog('filter', "تجاهل جدول صغير جداً (<= 3 فرق) لـ: {$match['home_team']} vs {$match['away_team']}");
                    continue;
                }

                // Always save to match
                $this->db->updateMatchStandings($match['id'], $data); 
                
                // Only save to global leagues table if it has 10 or more teams
                if (count($data) >= 10 && !empty($match['league'])) {
                    $this->db->updateLeagueStandings($match['league'], $data);
                    $this->db->addScrapeLog('success', "تم تحديث جدول ترتيب دوري: {$match['league']} [" . count($data) . " فريق] [$progress%]");
                } else {
                    $this->db->addScrapeLog('success', "تم تحديث ترتيب مباراة: {$match['home_team']} vs {$match['away_team']} [" . count($data) . " فريق] [$progress%]");
                }
                
                $updated++; 
            } else {
                $this->db->addScrapeLog('filter', "لم يتم العثور على جدول ترتيب صالح لـ: {$match['home_team']} vs {$match['away_team']} [$progress%]");
            }
        }
        $this->db->addScrapeLog('info', "اكتمل التحديث: تم تحديث جداول ترتيب لـ $updated مباراة [100%]");
        return ['status' => 'success', 'message' => "Updated $updated matches", 'count' => $updated];
    }

    private function isTableRelevant($data, $home, $away) {
        $nh = $this->norm($home); $na = $this->norm($away);
        foreach ($data as $r) {
            $rt = $this->norm($r['team']);
            if (($nh && strpos($rt, $nh)!==false) || ($na && strpos($rt, $na)!==false) || ($rt && strpos($nh, $rt)!==false) || ($rt && strpos($na, $rt)!==false)) return true;
        }
        return false;
    }

    private function extractStandingsFromJson($html) {
        $startTag = '<script id="__NEXT_DATA__" type="application/json">';
        $startPos = strpos($html, $startTag);
        if ($startPos !== false) {
            $jsonStart = $startPos + strlen($startTag);
            $endPos = strpos($html, '</script>', $jsonStart);
            if ($endPos === false) return null;
            $jsonStr = substr($html, $jsonStart, $endPos - $jsonStart);
            $json = json_decode($jsonStr, true); if (!$json) return null;
            $s = $json['props']['pageProps']['initialState']['matchPage']['summaryStandings'] ?? 
                 $json['props']['pageProps']['data']['summaryStandings'] ?? 
                 $json['props']['pageProps']['data']['standings'] ?? 
                 $json['props']['pageProps']['initialState']['matchPage']['standings'] ?? 
                 null;
            if (!$s || empty($s['table']['rankings'])) return null;
            $st = [];
            foreach ($s['table']['rankings'] as $r) {
                $st[] = ['rank'=>$r['position'],'team'=>$r['team']['name'],'team_logo'=>$r['team']['image']['url'],'played'=>$r['played'],'won'=>$r['win'],'drawn'=>$r['draw'],'lost'=>$r['lose'],'goals_for'=>$r['goalsFor'],'goals_against'=>$r['goalsAgainst'],'goal_diff'=>$r['goalsDifference'],'points'=>$r['points']];
            }
            return $st;
        }
        return null;
    }

    private function extractStandingsUrl($html) {
        // Kooora Priority: Look for breadcrumb link to competition
        if (preg_match('/class="fco-match-header-breadcrumb__anchor"[^>]*href="([^"]+)"/iu', $html, $m)) {
            $url = $m[1];
            // If it's a competition link, try to point it to the standings (table/جدول) page
            if (strpos($url, '/مسابقة/') !== false || strpos($url, '/competition/') !== false) {
                if (strpos($url, '/جدول/') === false && strpos($url, '/table/') === false) {
                    // Turn /slug/id into /slug/جدول/id
                    if (preg_match('/(.*\/)([^\/]+)$/', $url, $parts)) {
                        return $parts[1] . "جدول/" . $parts[2];
                    }
                }
            }
            return $url;
        }

        // YssScore Priority: Link in the specific competition info block
        if (preg_match('/<div[^>]*class="title"[^>]*>البطولة<\/div>\s*<div[^>]*class="content"[^>]*>\s*<a[^>]*href="([^"]+)"/iu', $html, $m)) {
            return $m[1];
        }

        $startTag = '<script id="__NEXT_DATA__" type="application/json">';
        $startPos = strpos($html, $startTag);
        if ($startPos !== false) {
            $jsonStart = $startPos + strlen($startTag);
            $endPos = strpos($html, '</script>', $jsonStart);
            if ($endPos === false) return null;
            $jsonStr = substr($html, $jsonStart, $endPos - $jsonStart);
            $json = json_decode($jsonStr, true); if (!$json) return null;
            
            // Try to find competition link or ID (Support both old and new Kooora/Yss structures)
            $matchData = $json['props']['pageProps']['data']['match'] ?? $json['props']['pageProps']['initialState']['matchPage']['match'] ?? null;
            $compId = $matchData['competition']['id'] ?? null;
            $compName = $matchData['competition']['name'] ?? $matchData['competition']['slug'] ?? null;
            
            if ($compId && $compName) {
                // Construct URL for Kooora if possible
                if (strpos($html, 'kooora.com') !== false) {
                    // Try to guess the slugified name for the URL
                    $slug = str_replace(' ', '-', trim($compName));
                    return "/كرة-القدم/مسابقة/" . $slug . "/جدول/" . $compId;
                }
            }
        }
        return null;
    }

    public function scrapeYssMatchHistory($url) {
        $html = $this->fetchUrl($url);
        if (!$html) return null;
        return $this->extractHistoryFromYssDOM($html);
    }
    
    private function extractHistoryFromYssDOM($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        
        $stdData = [];

        // --- 1. Process Head-to-Head (H2H) ---
        $h2hMatches = [];
        $h2hStats = ['teamAWins'=>0, 'teamBWins'=>0, 'draws'=>0, 'teamAGoals'=>0, 'teamBGoals'=>0, 'over25'=>0, 'btts'=>0];
        
        $h2hNodes = $xpath->query("//div[contains(@class, 'teams-last-10-matches')]//div[contains(@class, 'full-m-item')]");
        if ($h2hNodes->length === 0) {
            $h2hNodes = $xpath->query("//div[contains(@class, 'section-title') and contains(text(), 'لقاءات')]/following-sibling::div//div[contains(@class, 'full-m-item')]");
        }

        foreach ($h2hNodes as $node) {
            $teamANode = $xpath->query(".//span[contains(@class, 'team-a')]", $node)->item(0);
            $teamBNode = $xpath->query(".//span[contains(@class, 'team-b')]", $node)->item(0);
            $resultNode = $xpath->query(".//span[contains(@class, 'result')]", $node)->item(0);
            $dateNode = $xpath->query(".//div[contains(@class, 'date')]", $node)->item(0);
            
            $scoreStr = $resultNode ? trim($resultNode->textContent) : '';
            $homeTeam = $teamANode ? trim($teamANode->textContent) : 'Unknown';
            $awayTeam = $teamBNode ? trim($teamBNode->textContent) : 'Unknown';
            
            // Expected score format "2 - 1" or similar
            $scoreHome = 0; $scoreAway = 0;
            if (preg_match('/(\d+)\s*[\-:]\s*(\d+)/', $scoreStr, $matches)) {
                $scoreHome = (int)$matches[1];
                $scoreAway = (int)$matches[2];
            }

            // Stats Calculation
            if ($scoreHome > $scoreAway) $h2hStats['teamAWins']++;
            elseif ($scoreAway > $scoreHome) $h2hStats['teamBWins']++;
            else $h2hStats['draws']++;
            
            $h2hStats['teamAGoals'] += $scoreHome;
            $h2hStats['teamBGoals'] += $scoreAway;
            if (($scoreHome + $scoreAway) > 2.5) $h2hStats['over25']++;
            if ($scoreHome > 0 && $scoreAway > 0) $h2hStats['btts']++;

            $h2hMatches[] = [
                'date' => $dateNode ? trim($dateNode->textContent) : null,
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'score_home' => $scoreHome,
                'score_away' => $scoreAway,
                'status' => 'Finished'
            ];
        }

        if (!empty($h2hMatches)) {
            $stdData['h2h'] = [
                'matches' => $h2hMatches,
                'stats' => $h2hStats
            ];
        }

        // --- 2. Process Team Forms ---
        // Helper to process a list of matches (circles)
        $processForm = function($nodes) {
            $results = [];
            $stats = ['wins'=>0, 'draws'=>0, 'losses'=>0, 'goals_scored'=>0, 'goals_conceded'=>0];
            
            foreach ($nodes as $node) {
                $title = $node->getAttribute('title'); // e.g. "TeamA 2 - 1 TeamB"
                $url = $node->getAttribute('href');
                $class = $node->getAttribute('class');
                
                // Parse Title for info
                // Try format: "NameA scoreA - scoreB NameB"
                if (preg_match('/^(.*?)\s+(\d+)\s*[\-:]\s*(\d+)\s+(.*?)$/u', $title, $m)) {
                    $team1 = trim($m[1]); 
                    $s1 = (int)$m[2]; 
                    $s2 = (int)$m[3]; 
                    $team2 = trim($m[4]);
                    
                    // Identify result from class if possible, or assume order
                    $isWin = strpos($class, 'win') !== false;
                    $isLose = strpos($class, 'lose') !== false;
                    $resultLabel = $isWin ? 'WIN' : ($isLose ? 'LOSE' : 'DRAW');
                    
                    if ($resultLabel === 'WIN') $stats['wins']++;
                    elseif ($resultLabel === 'LOSE') $stats['losses']++;
                    else $stats['draws']++; // Assume draw if regular match

                    // Heuristic: usually the first team in title is the 'subject' team if it's their form line?
                    // Actually Yss usually puts the home team first in the title string "Home X-Y Away"
                    // We can't easily know which one is 'this' team without more context, but standard display is enough.
                    
                    $results[] = [
                        'date' => null, // Date not available in the circle title
                        'home_team' => $team1,
                        'away_team' => $team2,
                        'score_home' => $s1,
                        'score_away' => $s2,
                        'result' => $resultLabel,
                        'status' => 'Finished'
                    ];

                    // Rough stats (assuming first team is us? No, that's unsafe)
                    // For "Form" stats, usually we need to know who 'we' are.
                    // But since we can't reliably parse 'goals scored' for *us* specifically from just "A 1-1 B" without knowing if we are A or B.
                    // However, the classes 'win', 'lose' tell us the outcome for the *subject* team.
                }
            }
            return ['results' => $results, 'stats' => $stats];
        };

        // Home Team Form
        // Avoid H2H section
        $homeNodes = $xpath->query("//div[contains(@class, 'teams-last-matches') and not(contains(@class, 'teams-last-10-matches'))]//div[contains(@class, 'team-a')]//a[contains(@class, 'circle-result')]");
        if ($homeNodes->length === 0) $homeNodes = $xpath->query("//div[contains(@class, 'team-item') and contains(@class, 'team-a')]//a[contains(@class, 'circle-result')]");
        
        $homeData = $processForm($homeNodes);
        if (!empty($homeData['results'])) {
             $stdData['teamA'] = $homeData;
        }

        // Away Team Form
        $awayNodes = $xpath->query("//div[contains(@class, 'teams-last-matches') and not(contains(@class, 'teams-last-10-matches'))]//div[contains(@class, 'team-b')]//a[contains(@class, 'circle-result')]");
        if ($awayNodes->length === 0) $awayNodes = $xpath->query("//div[contains(@class, 'team-item') and contains(@class, 'team-b')]//a[contains(@class, 'circle-result')]");
        
        $awayData = $processForm($awayNodes);
        if (!empty($awayData['results'])) {
             $stdData['teamB'] = $awayData;
        }

        return $stdData;
    }


    private function parseRelevantTable($html, $home, $away) {
        $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html); $xpath = new DOMXPath($dom);
        $tables = $xpath->query('//table'); if ($tables->length === 0) return [];
        $nh = $this->norm($home); $na = $this->norm($away);
        foreach ($tables as $t) {
            $d = $this->parseRows($xpath, $t); if (empty($d)) continue;
            foreach ($d as $r) {
                $rt = $this->norm($r['team']);
                if (($nh && strpos($rt, $nh)!==false) || ($na && strpos($rt, $na)!==false) || ($rt && strpos($nh, $rt)!==false) || ($rt && strpos($na, $rt)!==false)) return $this->format($d);
            }
        }
        return []; // Return empty if no relevant table found
    }

    private function norm($n) { return mb_strtolower(preg_replace('/\s+/', '', str_replace(['ال ', 'ال'], '', $n)), 'UTF-8'); }

    private function parseExternalDateTimeToUtcParts($dateTimeString) {
        $dateTimeString = trim((string)$dateTimeString);
        if ($dateTimeString === '') {
            return null;
        }

        try {
            $utc = new DateTimeZone('UTC');
            $dateTime = new DateTimeImmutable($dateTimeString, $utc);
            $dateTime = $dateTime->setTimezone($utc);

            return [
                'match_date' => $dateTime->format('Y-m-d'),
                'match_time' => $dateTime->format('H:i')
            ];
        } catch (Exception $e) {
            $timestamp = strtotime($dateTimeString);
            if ($timestamp === false) {
                return null;
            }

            return [
                'match_date' => gmdate('Y-m-d', $timestamp),
                'match_time' => gmdate('H:i', $timestamp)
            ];
        }
    }

    private function normalizeTime($timeStr) {
        if (!$timeStr) return '';
        $timeStr = $this->normalizeAsciiDigits(trim($timeStr));
        
        // Handle Arabic/English AM/PM
        $isPM = (strpos($timeStr, 'م') !== false || stripos($timeStr, 'PM') !== false);
        $isAM = (strpos($timeStr, 'ص') !== false || stripos($timeStr, 'AM') !== false);

        // Extract HH:MM
        if (preg_match('/(\d{1,2}):(\d{2})/', $timeStr, $m)) {
            $h = (int)$m[1];
            $min = $m[2];
            
            if ($isPM && $h < 12) $h += 12;
            if ($isAM && $h == 12) $h = 0;
            
            return sprintf("%02d:%02d", $h, $min);
        }
        
        // Return original if no time pattern found
        return $timeStr;
    }

    private function normalizeAsciiDigits($value) {
        return strtr((string)$value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'
        ]);
    }

    private function parseMatchDateText($text) {
        $text = $this->normalizeAsciiDigits(trim((string)$text));
        if ($text === '') return null;

        if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[3], (int)$matches[2], (int)$matches[1]);
        }

        if (preg_match('/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }

        return null;
    }

    private function shouldScrapeSmart($match) {
        // If not today, default to allow (assuming user wants to fill history or check future explicitly)
        // But for "Smart" logic on "Events/Stats", usually we care about Today's live updates.
        // If date is different from today, typically we either force scrape (past) or ignore (future).
        // Let's assume this logic applies primarily to TODAY matches to save resources.
        
        $matchDate = $match['match_date'];
        $today = date('Y-m-d');
        
        if ($matchDate !== $today) {
            // For past dates, ideally we only scrape Finished matches that miss data?
            // For now, let's pass non-today matches to ensure we don't block history fixes.
            return true;
        }

        $status = strtoupper($match['status']);
        
        // Always scrape Live, Finished, Halftime, etc.
        if (in_array($status, ['LIVE', 'FINISHED', 'FT', 'AET', 'HT', 'BREAK', 'INT', 'PEN', 'PENALTIES'])) {
            return true;
        }

        // Skip Postponed/Cancelled
        if (in_array($status, ['POSTPONED', 'CANCELLED'])) {
            return false;
        }

        // For Scheduled matches, check start time
        if ($status === 'SCHEDULED' || $status === '') {
            $time = $this->normalizeTime($match['match_time']);
            
            // If we have a valid time
            if (preg_match('/^\d{2}:\d{2}$/', $time)) {
                // Check if start time has passed (or is effectively now)
                $matchTimestamp = strtotime("$matchDate $time");
                
                // Allow scraping if time() >= match_time
                // This covers matches that just started but status isn't 'Live' yet
                if (time() >= $matchTimestamp) {
                    return true;
                }
                
                // If match is in future, skip
                return false;
            }
        }

        // Default: Scrape if unsure
        return true;
    }

    private function format($raw) {
        $f = []; foreach ($raw as $r) { $f[] = ['rank'=>$r['rank'],'team'=>$r['team'],'team_logo'=>$r['team_logo'],'played'=>$r['played'],'won'=>$r['won'],'drawn'=>$r['drawn'],'lost'=>$r['lost'],'goals_for'=>$r['goals_for'],'goals_against'=>$r['goals_against'],'goal_diff'=>$r['goal_diff'],'points'=>$r['points']]; }
        return $f;
    }

    private function parseRows($xpath, $table) {
        $rows = $xpath->query('.//tr', $table); $st = [];
        foreach ($rows as $row) {
            $cols = $xpath->query('.//td', $row); if ($cols->length < 5) continue;
            $r=0;$tn='';$tl='';$p=0;$w=0;$d=0;$l=0;$gf=0;$ga=0;$df=0;$pts=0;
            foreach ($cols as $i => $c) {
                $v = trim($c->textContent); $cl = $c->getAttribute('class');
                if (strpos($cl, 'rank')!==false || ($i==1 && is_numeric($v))) $r=$v;
                elseif (strpos($cl, 'team')!==false || $i==2) {
                    $tnn = $xpath->query('.//span[contains(@class, "team-name--long")]', $c)->item(0);
                    $tn = $tnn ? trim($tnn->textContent) : $v;
                    $img = $xpath->query('.//img', $c)->item(0); if ($img) { $tl = $img->getAttribute('src'); if (strpos($tl, '//')===0) $tl = "https:".$tl; }
                }
                elseif ($i==4) $p=$v; elseif ($i==5) $w=$v; elseif ($i==6) $d=$v; elseif ($i==7) $l=$v; elseif ($i==8) $gf=$v; elseif ($i==9) $ga=$v; elseif ($i==10) $df=$v; elseif ($i==11) $pts=$v;
            }
            if (!empty($tn) && $tn !== 'الفريق') $st[] = ['rank'=>$r,'team'=>$tn,'team_logo'=>$tl,'played'=>$p,'won'=>$w,'drawn'=>$d,'lost'=>$l,'goals_for'=>$gf,'goals_against'=>$ga,'goal_diff'=>$df,'points'=>$pts];
        }
        return $st;
    }

    private function fetchUrl($url, $postData = null, $headers = [], $useCookie = false) {
        $url = $this->encodeUrl($url);
        $isYssRequest = (strpos($url, 'ysscores.com') !== false);
        $cookieFile = __DIR__ . '/scraper_cookies.txt';

        if ($isYssRequest) {
            $useCookie = true;
            $this->initializeYssUtcSession($cookieFile);
        }

        return $this->performCurlRequest($url, $postData, $headers, $useCookie ? $cookieFile : null);
    }

    private function looksLikeYssMatchesHtml($html) {
        if (!is_string($html) || trim($html) === '') {
            return false;
        }

        return strpos($html, 'matches-wrapper') !== false || strpos($html, 'ajax-match-item') !== false;
    }

    private function fetchYssDateWithBrowser($requestedDate) {
        $scriptPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fetch_yss_date.js';
        if (!is_file($scriptPath)) {
            return null;
        }

        $nodeBinary = $this->resolveNodeBinary();
        if ($nodeBinary === null) {
            return null;
        }

        $command = [$nodeBinary, $scriptPath, $requestedDate];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, dirname($scriptPath));
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            if (!empty($stderr)) {
                $this->db->addScrapeLog('warning', "YssScore browser fallback error: " . trim($stderr));
            }
            return null;
        }

        return is_string($stdout) ? trim($stdout) : null;
    }

    private function resolveNodeBinary() {
        static $resolvedBinary = false;
        if ($resolvedBinary !== false) {
            return $resolvedBinary;
        }

        $candidates = ['node'];
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = 'node.exe';
            $localAppData = getenv('LOCALAPPDATA');
            if ($localAppData) {
                $candidates[] = $localAppData . '\\Programs\\nodejs\\node.exe';
            }
            $programFiles = getenv('ProgramFiles');
            if ($programFiles) {
                $candidates[] = $programFiles . '\\nodejs\\node.exe';
            }
        }

        foreach ($candidates as $candidate) {
            $output = [];
            $exitCode = 1;
            $redirect = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
            @exec(escapeshellcmd($candidate) . ' -v ' . $redirect, $output, $exitCode);
            if ($exitCode === 0) {
                $resolvedBinary = $candidate;
                return $resolvedBinary;
            }
        }

        $resolvedBinary = null;
        return $resolvedBinary;
    }

    private function performCurlRequest($url, $postData = null, $headers = [], $cookieFile = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        if ($cookieFile !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        }

        if ($postData) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($postData) ? http_build_query($postData) : $postData);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $h = curl_exec($ch);
        curl_close($ch);
        return $h;
    }

    private function initializeYssUtcSession($cookieFile) {
        if ($this->yssUtcSessionReady) {
            return;
        }

        $cookieStub = "# Netscape HTTP Cookie File\n# https://curl.se/docs/http-cookies.html\n# Generated by SPORT scraper.\n\n";
        @file_put_contents($cookieFile, $cookieStub);

        $homeUrl = "https://www.ysscores.com/ar/index";
        $homeHtml = $this->performCurlRequest($homeUrl, null, [
            "Accept-Language: ar,en;q=0.8",
            "Referer: https://www.ysscores.com/ar/index"
        ], $cookieFile);

        if (!$homeHtml || !preg_match('/<meta name="_token" content="([^"]+)"/', $homeHtml, $tokenMatches)) {
            return;
        }

        $token = $tokenMatches[1];
        $this->performCurlRequest(
            "https://www.ysscores.com/ar/change_zone",
            ['zone_is' => 'utc'],
            [
                "X-CSRF-Token: {$token}",
                "X-Requested-With: XMLHttpRequest",
                "Origin: https://www.ysscores.com",
                "Referer: https://www.ysscores.com/ar/index",
                "Accept: */*",
                "Accept-Language: ar,en;q=0.8"
            ],
            $cookieFile
        );

        $this->yssUtcSessionReady = true;
    }

    private function encodeUrl($url) {
        return preg_replace_callback('/[^\x20-\x7f]/', function($match) {
            return urlencode($match[0]);
        }, $url);
    }
    public function scrapeAllLineups($date = null, $clearLogs = true) {
        $date = $date ?: date('Y-m-d');
        if ($clearLogs) $this->db->clearScrapeLogs();
        $this->db->addScrapeLog('info', "بدء تحديث تشكيلات المباريات لتاريخ: $date [0%]");

        $matches = $this->db->getMatches($date);
        if (empty($matches)) {
            $this->db->addScrapeLog('info', "لا توجد مباريات لتحديث تشكيلاتها اليوم [100%]");
            return ['status' => 'error', 'message' => 'No matches'];
        }
        
        $total = count($matches);
        $this->db->addScrapeLog('info', "تم العثور على $total مباراة، جاري جلب التشكيلات... [10%]");

        $updated = 0;
        foreach ($matches as $index => $match) {
            $progress = 10 + round((($index + 1) / $total) * 85);
            if (empty($match['match_url'])) {
                $this->db->addScrapeLog('filter', "تخطي مباراة: {$match['home_team']} vs {$match['away_team']} (لا يوجد رابط) [$progress%]");
                continue;
            }
            
            $this->db->addScrapeLog('info', "جاري جلب تشكيلة: {$match['home_team']} vs {$match['away_team']} [$progress%]");
            
            $isYss = (strpos($match['match_url'], 'ysscores.com') !== false);
            $lineups = null;

            if ($isYss) {
                // Use new DOM-based method
                $lineups = $this->scrapeYssLineup($match['match_url'], $match);
            }

            // Fallback for non-YSS or failed YSS (empty result)
            if (!$lineups || (empty($lineups['home']['starting']) && empty($lineups['away']['starting']))) {
                if ($isYss) {
                    // Try legacy JSON method for YSS only if DOM failed
                    $html = $this->fetchUrl($match['match_url']);
                } else {
                    // Normal fetching for others (Kooora or different source)
                    $html = $this->fetchUrl($match['match_url']);
                }
                
                $homeTeamData = [
                    'id' => $match['home_team_id'],
                    'name_ar' => $match['home_team_ar'],
                    'name_en' => $match['home_team_en'],
                    'logo_url' => $match['home_logo']
                ];
                $awayTeamData = [
                    'id' => $match['away_team_id'],
                    'name_ar' => $match['away_team_ar'],
                    'name_en' => $match['away_team_en'],
                    'logo_url' => $match['away_logo']
                ];
                
                // If we already tried Yss DOM and it failed, maybe JSON works? Or this handles non-Yss cases.
                // Note: scrapeYssLineup does fetchUrl internally so we act carefully to avoid double fetching if possible,
                // but fetchUrl is cheap if cached or necessary.
                $lineups = $this->extractLineupsFromJson($html, $homeTeamData, $awayTeamData);
            }
            
            if ($lineups && (!empty($lineups['home']['starting']) || !empty($lineups['away']['starting']))) {
                $homeLineup = json_encode($lineups['home'], JSON_UNESCAPED_UNICODE);
                $awayLineup = json_encode($lineups['away'], JSON_UNESCAPED_UNICODE);
                
                if ($this->db->updateMatchLineup($match['id'], $homeLineup, $awayLineup)) {
                    $this->db->addScrapeLog('success', "تم تحديث تشكيلة: {$match['home_team']} vs {$match['away_team']} [$progress%]");
                    $updated++;
                }
            } else {
                $this->db->addScrapeLog('filter', "لم تتوفر التشكيلة بعد لـ: {$match['home_team']} vs {$match['away_team']} [$progress%]");
            }
        }
        $this->db->addScrapeLog('info', "اكتمل التحديث: تم تحديث $updated تشكيلة من أصل $total [100%]");
        return ['status' => 'success', 'message' => "Updated lineups for $updated matches", 'count' => $updated];
    }

    private function extractLineupsFromJson($html, $homeTeamData = null, $awayTeamData = null) {
        $startTag = '<script id="__NEXT_DATA__" type="application/json">';
        $startPos = strpos($html, $startTag);
        if ($startPos !== false) {
            $jsonStart = $startPos + strlen($startTag);
            $endPos = strpos($html, '</script>', $jsonStart);
            if ($endPos === false) return null;
            $jsonStr = substr($html, $jsonStart, $endPos - $jsonStart);
            $json = json_decode($jsonStr, true);
            if (!$json) return null;

            // Find all occurrences of 'lineups'
            $candidates = [];
            $this->findAllKeysRecursive($json, 'lineups', $candidates);
            
            foreach ($candidates as $lineupsData) {
                // Check if this candidate has valid data structure
                if ($this->isValidLineupData($lineupsData)) {
                    return [
                        'home' => $this->formatTeamLineup($lineupsData['teamA'] ?? [], $homeTeamData),
                        'away' => $this->formatTeamLineup($lineupsData['teamB'] ?? [], $awayTeamData)
                    ];
                }
            }
        }
        return null;
    }

    private function findAllKeysRecursive($array, $keySearch, &$results) {
        foreach ($array as $key => $value) {
            if ($key === $keySearch) {
                $results[] = $value;
            }
            if (is_array($value)) {
                $this->findAllKeysRecursive($value, $keySearch, $results);
            }
        }
    }

    private function isValidLineupData($data) {
        // Check if it has teamA/teamB and at least one player with 'person' key
        if (isset($data['teamA']['lineup']) && is_array($data['teamA']['lineup'])) {
            foreach ($data['teamA']['lineup'] as $p) {
                if (isset($p['person'])) return true;
            }
        }
        if (isset($data['teamB']['lineup']) && is_array($data['teamB']['lineup'])) {
            foreach ($data['teamB']['lineup'] as $p) {
                if (isset($p['person'])) return true;
            }
        }
        return false;
    }

    private function formatTeamLineup($teamData, $teamInfo = null) {
        $teamId = is_array($teamInfo) ? ($teamInfo['id'] ?? null) : $teamInfo;
        
        $formation = $teamData['formation'] ?? null;
        if ($formation && is_numeric($formation)) {
            $formation = implode('-', str_split($formation));
        }

        $coachName = $teamData['manager']['name'] ?? $teamData['coach']['name'] ?? null;
        if ($coachName) $coachName = trim($coachName);
        $coachImage = $teamData['manager']['image']['url'] ?? $teamData['coach']['image']['url'] ?? null;
        
        $coachAr = null;
        $coachEn = null;

        if ($coachName && $teamId) {
            if ($coachImage && strpos($coachImage, 'http') === false) {
                $coachImage = "https://www.kooora.com" . $coachImage;
            }
            $coachRecord = $this->db->syncPlayer($coachName, $teamId, null, 'Coach', $coachImage);
            if ($coachRecord) {
                $coachAr = $coachRecord['name_ar'] ?? null;
                $coachEn = $coachRecord['name_en'] ?? null;
                $coachImage = $coachRecord['image_url'] ?: $coachImage;
            }
        }

        $formatted = [
            'formation' => $formation,
            'team_name_ar' => is_array($teamInfo) ? ($teamInfo['name_ar'] ?? null) : null,
            'team_name_en' => is_array($teamInfo) ? ($teamInfo['name_en'] ?? null) : null,
            'team_logo' => is_array($teamInfo) ? ($teamInfo['logo_url'] ?? null) : null,
            'coach' => $coachName,
            'coach_ar' => $coachAr,
            'coach_en' => $coachEn,
            'coach_image' => $coachImage,
            'starting' => [],
            'substitutes' => [],
            'injuries' => [],
            'suspensions' => []
        ];

        if (isset($teamData['lineup']) && is_array($teamData['lineup'])) {
            foreach ($teamData['lineup'] as $p) {
                $rawPos = $p['pitchPosition'] ?? $p['position'] ?? null;
                $posEn = $p['position_en'] ?? null;
                
                // If position is Arabic string, keep it for distribution logic later
                // but set a basic English shorthand for DB
                if (is_string($rawPos) && preg_match('/[\x{0600}-\x{06FF}]/u', $rawPos)) {
                    $posEn = $this->translateArabicPositionToEn($rawPos);
                }

                $player = [
                    'name' => $p['person']['name'] ?? $p['name'] ?? 'Unknown',
                    'number' => $p['shirtNumber'] ?? $p['number'] ?? 0,
                    'position' => $rawPos, // Keep as string if it's Arabic
                    'position_en' => $posEn,
                    'id' => $p['person']['id'] ?? $p['id'] ?? null,
                    'image' => $p['person']['image']['url'] ?? $p['image'] ?? null,
                    'is_captain' => $p['isCaptain'] ?? false,
                    'events' => []
                ];
                
                // Process Events
                if (isset($p['events']) && is_array($p['events'])) {
                    foreach ($p['events'] as $e) {
                        $type = $e['__typename'] ?? '';
                        if ($type === 'MatchGoalEvent') {
                            $scorerId = $e['scorer']['id'] ?? null;
                            $assistId = $e['assist']['id'] ?? null;
                            $playerId = $player['id'];
                            $goalType = $e['type'] ?? '';
                            
                            if ($scorerId && $scorerId == $playerId) {
                                $isShootout = strpos($goalType, 'SHOOTOUT') !== false;
                                $isMiss = strpos($goalType, 'MISS') !== false;
                                $isPenalty = strpos($goalType, 'PENALTY') !== false;

                                if ($isShootout) {
                                    if ($isMiss) {
                                        $player['events']['shootout_missed'] = ($player['events']['shootout_missed'] ?? 0) + 1;
                                    } else {
                                        $player['events']['shootout_scored'] = ($player['events']['shootout_scored'] ?? 0) + 1;
                                    }
                                } elseif ($isMiss) {
                                    if ($isPenalty) {
                                        $player['events']['penalties_missed'] = ($player['events']['penalties_missed'] ?? 0) + 1;
                                    }
                                } else {
                                    $player['events']['goals'] = ($player['events']['goals'] ?? 0) + 1;
                                    if ($isPenalty) {
                                        $player['events']['penalties_scored'] = ($player['events']['penalties_scored'] ?? 0) + 1;
                                    }
                                }
                            }
                            if ($assistId && $assistId == $playerId) {
                                $player['events']['assists'] = ($player['events']['assists'] ?? 0) + 1;
                            }
                        } elseif ($type === 'MatchCardEvent') {
                            $cardType = $e['type'] ?? '';
                            if ($cardType === 'CARD_YELLOW') $player['events']['yellow_cards'] = ($player['events']['yellow_cards'] ?? 0) + 1;
                            if ($cardType === 'CARD_RED') $player['events']['red_cards'] = ($player['events']['red_cards'] ?? 0) + 1;
                        } elseif ($type === 'MatchSubstitutionEvent') {
                            $player['events']['subbed_out'] = $e['period']['minute'] ?? true;
                        }
                    }
                }

                // Fix image URL if relative
                if (!empty($player['image']) && strpos($player['image'], 'http') === false) {
                    $player['image'] = "https://www.kooora.com" . $player['image'];
                }

                if ($teamId) {
                    // Use translated position for DB if available
                    $posForDb = $p['position_en'] ?? $p['position'] ?? null;
                    if (is_array($posForDb)) {
                        $posForDb = $this->calculatePosition($posForDb) ?: 'MF';
                    }
                    
                    $dbPlayer = $this->db->syncPlayer($player['name'], $teamId, $player['number'], $posForDb, $player['image']);
                    if ($dbPlayer) {
                        $player['image'] = $dbPlayer['image_url'] ?: $player['image'];
                        $player['name_en'] = $dbPlayer['name_en'] ?? null;
                        $player['name_ar'] = $dbPlayer['name_ar'] ?? null;
                    }
                }

                $formatted['starting'][] = $player;
            }
        }

        if (isset($teamData['substitutes']) && is_array($teamData['substitutes'])) {
            foreach ($teamData['substitutes'] as $p) {
                $rawPos = $p['pitchPosition'] ?? $p['position'] ?? null;
                $posEn = $p['position_en'] ?? null;
                if (is_string($rawPos) && preg_match('/[\x{0600}-\x{06FF}]/u', $rawPos)) {
                    $posEn = $this->translateArabicPositionToEn($rawPos);
                }

                $player = [
                    'name' => $p['person']['name'] ?? $p['name'] ?? 'Unknown',
                    'number' => $p['shirtNumber'] ?? $p['number'] ?? 0,
                    'position' => $rawPos,
                    'position_en' => $posEn,
                    'id' => $p['person']['id'] ?? $p['id'] ?? null,
                    'image' => $p['person']['image']['url'] ?? $p['image'] ?? null,
                    'is_captain' => $p['isCaptain'] ?? false,
                    'events' => []
                ];

                if (isset($p['events']) && is_array($p['events'])) {
                    foreach ($p['events'] as $e) {
                        $type = $e['__typename'] ?? '';
                        if ($type === 'MatchGoalEvent') {
                            $scorerId = $e['scorer']['id'] ?? null;
                            $assistId = $e['assist']['id'] ?? null;
                            $playerId = $player['id'];
                            $goalType = $e['type'] ?? '';
                            
                            if ($scorerId && $scorerId == $playerId) {
                                $isShootout = strpos($goalType, 'SHOOTOUT') !== false;
                                $isMiss = strpos($goalType, 'MISS') !== false;
                                $isPenalty = strpos($goalType, 'PENALTY') !== false;

                                if ($isShootout) {
                                    if ($isMiss) {
                                        $player['events']['shootout_missed'] = ($player['events']['shootout_missed'] ?? 0) + 1;
                                    } else {
                                        $player['events']['shootout_scored'] = ($player['events']['shootout_scored'] ?? 0) + 1;
                                    }
                                } elseif ($isMiss) {
                                    if ($isPenalty) {
                                        $player['events']['penalties_missed'] = ($player['events']['penalties_missed'] ?? 0) + 1;
                                    }
                                } else {
                                    $player['events']['goals'] = ($player['events']['goals'] ?? 0) + 1;
                                    if ($isPenalty) {
                                        $player['events']['penalties_scored'] = ($player['events']['penalties_scored'] ?? 0) + 1;
                                    }
                                }
                            }
                            if ($assistId && $assistId == $playerId) {
                                $player['events']['assists'] = ($player['events']['assists'] ?? 0) + 1;
                            }
                        } elseif ($type === 'MatchCardEvent') {
                            $cardType = $e['type'] ?? '';
                            if ($cardType === 'CARD_YELLOW') $player['events']['yellow_cards'] = ($player['events']['yellow_cards'] ?? 0) + 1;
                            if ($cardType === 'CARD_RED') $player['events']['red_cards'] = ($player['events']['red_cards'] ?? 0) + 1;
                        } elseif ($type === 'MatchSubstitutionEvent') {
                            $player['events']['subbed_in'] = $e['period']['minute'] ?? true;
                        }
                    }
                }

                if (!empty($player['image']) && strpos($player['image'], 'http') === false) {
                    $player['image'] = "https://www.kooora.com" . $player['image'];
                }

                if ($teamId) {
                    $posForDb = $p['position_en'] ?? $p['position'] ?? null;
                    if (is_array($posForDb)) {
                         $posForDb = $this->calculatePosition($posForDb) ?: 'MF';
                    }
                    $dbPlayer = $this->db->syncPlayer($player['name'], $teamId, $player['number'], $posForDb, $player['image']);
                    if ($dbPlayer) {
                        $player['image'] = $dbPlayer['image_url'] ?: $player['image'];
                        $player['name_en'] = $dbPlayer['name_en'] ?? null;
                        $player['name_ar'] = $dbPlayer['name_ar'] ?? null;
                    }
                }

                $formatted['substitutes'][] = $player;
            }
        }

        // Process Missing Players (Injuries/Suspensions)
        $missingKeys = [
            'missing' => 'injuries',
            'missing_players' => 'injuries',
            'injured' => 'injuries',
            'unavailable' => 'injuries',
            'injuries' => 'injuries',
            'suspensions' => 'suspensions',
            'suspended' => 'suspensions'
        ];

        foreach ($missingKeys as $key => $target) {
            if (isset($teamData[$key]) && is_array($teamData[$key])) {
                foreach ($teamData[$key] as $p) {
                    $player = [
                        'name' => $p['person']['name'] ?? $p['name'] ?? $p['player_name'] ?? 'Unknown',
                        'reason' => $p['reason'] ?? null,
                        'image' => $p['person']['image']['url'] ?? $p['image'] ?? null
                    ];

                    if (!empty($player['image']) && strpos($player['image'], 'http') === false) {
                        $player['image'] = "https://www.kooora.com" . $player['image'];
                    }

                    if ($teamId) {
                        $dbPlayer = $this->db->syncPlayer($player['name'], $teamId, null, null, $player['image']);
                        if ($dbPlayer) {
                            $player['image'] = $dbPlayer['image_url'] ?: $player['image'];
                            $player['name_en'] = $dbPlayer['name_en'] ?? null;
                            $player['name_ar'] = $dbPlayer['name_ar'] ?? null;
                        }
                    }
                    $formatted[$target][] = $player;
                }
            }
        }

        // Process Injuries
        return $formatted;
    }
    public function scrapeAllStatistics($date = null, $clearLogs = true) {
        $date = $date ?: date('Y-m-d');
        if ($clearLogs) $this->db->clearScrapeLogs();
        $this->db->addScrapeLog('info', "بدء تحديث إحصائيات الفرق لتاريخ: $date [0%]");

        $matches = $this->db->getMatches($date);
        if (empty($matches)) {
            $this->db->addScrapeLog('info', "لا توجد مباريات لتحديث إحصائياتها اليوم [100%]");
            return ['status' => 'error', 'message' => 'No matches'];
        }
        
        $total = count($matches);
        $this->db->addScrapeLog('info', "تم العثور على $total مباراة، جاري جلب الإحصائيات... [10%]");

        $updated = 0;
        foreach ($matches as $index => $match) {
            $progress = 10 + round((($index + 1) / $total) * 85);
            if (empty($match['match_url'])) {
                $this->db->addScrapeLog('filter', "تخطي مباراة: {$match['home_team']} vs {$match['away_team']} (لا يوجد رابط) [$progress%]");
                continue;
            }
            
            $this->db->addScrapeLog('info', "جاري جلب إحصائيات: {$match['home_team']} vs {$match['away_team']} [$progress%]");
            
            // Smart Filter
            if (!$this->shouldScrapeSmart($match)) {
                $this->db->addScrapeLog('filter', "تخطي: {$match['home_team']} vs {$match['away_team']} (لم تبدأ بعد أو غير نشطة) [$progress%]");
                continue;
            }

            $result = $this->scrapeMatchStatistics($match['id']);
            
            if ($result['status'] === 'success') {
                $this->db->addScrapeLog('success', "تم تحديث إحصائيات: {$match['home_team']} vs {$match['away_team']} [$progress%]");
                $updated++;
            } else {
                $this->db->addScrapeLog('filter', "لم تتوفر إحصائيات بعد لـ: {$match['home_team']} vs {$match['away_team']} [$progress%]");
            }
        }
        $this->db->addScrapeLog('info', "اكتمل التحديث: تم تحديث إحصائيات $updated مباراة من أصل $total [100%]");
        return ['status' => 'success', 'message' => "Updated statistics for $updated matches", 'count' => $updated];
    }

    public function scrapeMatchStatistics($matchId) {
        $match = $this->db->getMatchById($matchId);
        if (!$match || empty($match['match_url'])) {
            return ['status' => 'error', 'message' => 'Match not found or no URL'];
        }

        $html = $this->fetchUrl($match['match_url']);
        if (!$html) return ['status' => 'error', 'message' => 'Failed to fetch match details page'];

        $stats = null;
        if (strpos($match['match_url'], 'ysscores.com') !== false) {
            $stats = $this->extractMatchStatsFromYssDOM($html);
        } else {
            $stats = $this->extractStatisticsFromJson($html);
        }

        if ($stats) {
            if ($this->db->updateMatchStatistics($matchId, $stats)) {
                return ['status' => 'success', 'message' => 'تم تحديث الإحصائيات بنجاح', 'stats' => $stats];
            }
        }
        return ['status' => 'error', 'message' => 'فشل في جلب الإحصائيات'];
    }

    private function extractMatchStatsFromYssDOM($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        $stats = [
            'summary' => [],
            'attacking' => [],
            'passing' => [],
            'defence' => [],
            'discipline' => []
        ];

        $mapping = [
            'الاستحواذ' => ['type' => 'POSSESSION', 'cat' => 'summary'],
            'التسديدات' => ['type' => 'SHOT_TOTAL', 'cat' => 'attacking'],
            'التسديد علي المرمي' => ['type' => 'SHOT_ON_TARGET', 'cat' => 'attacking'],
            'التسديد بعيدا عن المرمي' => ['type' => 'SHOT_OFF_TARGET', 'cat' => 'attacking'],
            'تسديدات تم اعتراضها' => ['type' => 'SHOT_BLOCKED', 'cat' => 'attacking'],
            'التسديدات من داخل الصندوق' => ['type' => 'SHOT_INSIDE_BOX', 'cat' => 'attacking'],
            'التسديدات من خارج الصندوق' => ['type' => 'SHOT_OUTSIDE_BOX', 'cat' => 'attacking'],
            'مجموع التمريرات' => ['type' => 'PASS_TOTAL', 'cat' => 'passing'],
            'نسبة دقة التمرير' => ['type' => 'PASS_ACCURACY', 'cat' => 'passing'],
            'الركنيات' => ['type' => 'CORNER_TOTAL', 'cat' => 'attacking'],
            'التسللات' => ['type' => 'OFFSIDE_TOTAL', 'cat' => 'attacking'],
            'مخالفات' => ['type' => 'FOUL_COMMITED', 'cat' => 'defence'],
            'تصديات حارس المرمى' => ['type' => 'SAVE_TOTAL', 'cat' => 'defence'],
            'البطاقات الصفراء' => ['type' => 'YELLOW_CARD_TOTAL', 'cat' => 'discipline'],
            'البطاقات الحمراء' => ['type' => 'RED_CARD_TOTAL', 'cat' => 'discipline']
        ];

        // 1. Extract Possession
        $possessionWrapper = $xpath->query("//div[contains(@class, 'progress-wrapper')]")->item(0);
        if ($possessionWrapper) {
            $homePoss = $xpath->query(".//div[contains(@class, 'team-a')]", $possessionWrapper)->item(0);
            $awayPoss = $xpath->query(".//div[contains(@class, 'team-b')]", $possessionWrapper)->item(0);
            if ($homePoss && $awayPoss) {
                $stats['summary'][] = [
                    'type' => 'POSSESSION',
                    'teamA' => trim(str_replace('%', '', $homePoss->textContent)),
                    'teamB' => trim(str_replace('%', '', $awayPoss->textContent))
                ];
            }
        }

        // 2. Extract other stats
        $items = $xpath->query("//div[contains(@class, 'progress-state-item')]");
        foreach ($items as $item) {
            $titleNode = $xpath->query(".//span[contains(@class, 'title')]", $item)->item(0);
            if ($titleNode) {
                $title = trim($titleNode->textContent);
                if (isset($mapping[$title])) {
                    $values = $xpath->query(".//div[contains(@class, 'text')]//span", $item);
                    if ($values->length >= 3) {
                        $homeVal = trim($values->item(0)->textContent);
                        $awayVal = trim($values->item(2)->textContent);
                        
                        $map = $mapping[$title];
                        $stats[$map['cat']][] = [
                            'type' => $map['type'],
                            'teamA' => str_replace('%', '', $homeVal),
                            'teamB' => str_replace('%', '', $awayVal)
                        ];
                    }
                }
            }
        }

        // Clean empty categories
        foreach ($stats as $key => $val) {
            if (empty($val)) unset($stats[$key]);
        }

        return !empty($stats) ? $stats : null;
    }

    public function scrapeMatchLineup($matchId) {
        $match = $this->db->getMatchById($matchId);
        if (!$match || empty($match['match_url'])) {
            return ['status' => 'error', 'message' => 'Match not found or no URL'];
        }

        $isYss = (strpos($match['match_url'], 'ysscores.com') !== false);

        if ($isYss) {
            $lineups = $this->scrapeYssLineup($match['match_url'], $match);
            if ($lineups) {
                // Ensure we have at least some data
                if (!empty($lineups['home']['starting']) || !empty($lineups['away']['starting'])) {
                     if ($this->db->saveLineups($matchId, $lineups)) {
                        return ['status' => 'success', 'message' => 'تم تحديث التشكيلة بنجاح (YSS)', 'lineup' => $lineups];
                    }
                }
            }
        }

        // Fallback or Legacy JSON method
        $html = $this->fetchUrl($match['match_url']);
        $lineups = $this->extractLineupsFromJson($html, $match['home_team_id'], $match['away_team_id']);

        if ($lineups) {
            if ($this->db->saveLineups($matchId, $lineups)) {
                return ['status' => 'success', 'message' => 'تم تحديث التشكيلة بنجاح', 'lineup' => $lineups];
            }
        }
        return ['status' => 'error', 'message' => 'فشل في جلب التشكيلة'];
    }

    private function scrapeYssLineup($url, $matchInfo = null) {
    if (!$url) return null;

    // The match code is usually in the URL: /match/12345/
    $matchCode = null;
    if (preg_match('/\/match\/(\d+)/i', $url, $m)) {
        $matchCode = $m[1];
    }

    $html = null; // Initialize $html to null

    // If matchCode was found in the URL, try API first
    if ($matchCode) {
        $lineups = $this->extractLineupsFromYssAPI($matchCode, $matchInfo);
        if ($lineups && (!empty($lineups['home']['starting']) || !empty($lineups['away']['starting']))) {
            return $lineups;
        }
    }

    // If API call failed or matchCode wasn't in URL, fetch HTML and try to get matchCode from it
    // Or proceed with DOM parsing if API fails
    $html = $this->fetchUrl($url);
    if (!$html) return null;

    // Check for match_code in HTML as fallback if not already found or API failed
    if (!$matchCode && preg_match('/<input[^>]*id="match_code"[^>]*value="(\d+)"/i', $html, $m)) {
        $matchCode = $m[1];
        // If matchCode found from HTML, try API again
        $lineups = $this->extractLineupsFromYssAPI($matchCode, $matchInfo);
        if ($lineups && (!empty($lineups['home']['starting']) || !empty($lineups['away']['starting']))) {
            return $lineups;
        }
    }

    // If API still didn't work or no matchCode found, proceed with DOM parsing
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($dom);

    $lineups = [
        'home' => $this->initLineupStructure($matchInfo['home_team_id'] ?? null, $matchInfo['home_team'] ?? null, $matchInfo['home_logo'] ?? null),
        'away' => $this->initLineupStructure($matchInfo['away_team_id'] ?? null, $matchInfo['away_team'] ?? null, $matchInfo['away_logo'] ?? null)
    ];

        // 1. Extract Players from Visual Field (Starting XI) & Substitutes
        // Classes detected: lineup-row g home_g, lineup-row d home_d, lineup-row m home_m, lineup-row f home_f
        // Substitutes: likely lineup-row s home_s (based on pattern) or separate list
        
        $rows = $xpath->query("//div[contains(@class, 'lineup-row')]");
        
        foreach ($rows as $row) {
            $class = $row->getAttribute('class');
            
            // Determine Side
            $side = (strpos($class, 'home') !== false) ? 'home' : ((strpos($class, 'away') !== false) ? 'away' : null);
            if (!$side) continue;

            // Determine Type
            $isSub = (strpos($class, '_s') !== false); // Hypothesis: home_s for substitutes
            
            // Iterate Players in this row
            // Structure seems to be: row -> col/item -> content
            // We'll look for any element that looks like a player item (has img and name)
            $playerNodes = $xpath->query(".//div[contains(@class, 'player-item')]", $row);
            if ($playerNodes->length === 0) {
                 // Fallback: look for generic containers if specific class not found
                 // Using a relaxed search for containers with images inside
                 $playerNodes = $xpath->query(".//div[.//img]", $row); 
            }

            foreach ($playerNodes as $node) {
                // Extract Info
                $nameNode = $xpath->query(".//span[contains(@class, 'name')]", $node)->item(0);
                $numNode = $xpath->query(".//span[contains(@class, 'number')]", $node)->item(0);
                $imgNode = $xpath->query(".//img", $node)->item(0);
                
                // If explicit nodes not found, try generic text parsing or attributes
                $name = $nameNode ? trim($nameNode->textContent) : ($node->getAttribute('title') ?: 'Unknown');
                $number = $numNode ? trim($numNode->textContent) : 0;
                $image = $imgNode ? $imgNode->getAttribute('src') : null;

                // Fix Image URL
                if ($image && strpos($image, 'http') === false) {
                    $image = "https://www.ysscores.com" . (strpos($image, '/') === 0 ? '' : '/') . $image;
                }

                $playerData = [
                    'name' => $name,
                    'number' => $number,
                    'position' => null, // Inferred from row class (g/d/m/f) if needed
                    'image' => $image,
                    'is_captain' => false, // Hard to detect without specific icon class
                    'events' => []
                ];

                // Sync with DB
                $teamId = ($side == 'home') ? ($matchInfo['home_team_id'] ?? null) : ($matchInfo['away_team_id'] ?? null);
                if ($teamId) {
                    $dbPlayer = $this->db->syncPlayer($name, $teamId, $number, null, $image);
                    if ($dbPlayer) {
                        $playerData['name_en'] = $dbPlayer['name_en'] ?? null;
                        $playerData['name_ar'] = $dbPlayer['name_ar'] ?? null;
                        $playerData['image'] = $dbPlayer['image_url'] ?: $image;
                    }
                }

                if ($isSub) {
                    $lineups[$side]['substitutes'][] = $playerData;
                } else {
                    $lineups[$side]['starting'][] = $playerData;
                }
            }
        }

        // 2. Extract Manager/Coach if available
        // Usually separate class .coach-item or similar
        // Or in the header info
        $coachNodes = $xpath->query("//div[contains(@class, 'coach-info')]");
        // Implementation for coach extraction if structure known

        return $lineups;
}

/**
 * Extract lineups using the direct API for YssScores
 */
private function extractLineupsFromYssAPI($matchCode, $matchInfo = null) {
    $apiUrl = "https://www.ysscores.com/ar/match_lineup?match_code=" . $matchCode;
    
    // API requires XMLHttpRequest header and Referer for some matches
    $headers = [
        "X-Requested-With: XMLHttpRequest"
    ];
    
    $apiResponse = $this->fetchUrl($apiUrl, null, $headers);
    if (!$apiResponse) return null;
    
    $data = json_decode($apiResponse, true);
    if (!$data) return null;
    
    // We need to map team IDs from $data['info']['home_team'] and 'away_team' 
    // to our 'home' and 'away' sides
    $ysHomeId = $data['info']['home_team'] ?? null;
    $ysAwayId = $data['info']['away_team'] ?? null;
    
    $lineups = [
        'home' => $this->initLineupStructure($matchInfo['home_team_id'] ?? null, $matchInfo['home_team'] ?? null, $matchInfo['home_logo'] ?? null),
        'away' => $this->initLineupStructure($matchInfo['away_team_id'] ?? null, $matchInfo['away_team'] ?? null, $matchInfo['away_logo'] ?? null)
    ];
    
    // Set Formations
    $lineups['home']['formation'] = $data['info']['home_formation'] ?? null;
    $lineups['away']['formation'] = $data['info']['away_formation'] ?? null;
    
    // Set Coaches
    if (isset($data['info']['home_coach'])) {
        $lineups['home']['coach'] = [
            'name' => $data['info']['home_coach']['title'] ?? null,
            'image' => ['url' => $data['info']['home_coach']['image'] ?? null]
        ];
    }
    if (isset($data['info']['away_coach'])) {
        $lineups['away']['coach'] = [
            'name' => $data['info']['away_coach']['title'] ?? null,
            'image' => ['url' => $data['info']['away_coach']['image'] ?? null]
        ];
    }

    $map = [
        $ysHomeId => 'home',
        $ysAwayId => 'away'
    ];

    // 1. Process Starting Lineups
    if (!empty($data['lineup'])) {
        foreach ($data['lineup'] as $teamId => $positions) {
            $side = $map[$teamId] ?? null;
            if (!$side) continue;
            
            foreach ($positions as $posKey => $players) {
                foreach ($players as $pData) {
                    $p = $pData['player'] ?? null;
                    if (!$p) continue;
                    
                    $player = [
                        'name' => $p['title'] ?? 'Unknown',
                        'number' => $p['player_number'] ?? 0,
                        'position' => $p['position'] ?? $posKey,
                        'image' => $p['image'] ?? null,
                        'isCaptain' => (isset($pData['captain']) && $pData['captain'] == 1),
                        'id' => $p['row_id'] ?? $p['id'] ?? null,
                        'events' => []
                    ];

                    // Map Events to Kooora-like structure for formatTeamLineup
                    $playerId = $p['row_id'] ?? $p['id'] ?? null;
                    if (!empty($pData['yellow'])) {
                        $player['events'][] = ['__typename' => 'MatchCardEvent', 'type' => 'CARD_YELLOW', 'period' => ['minute' => $pData['yellow']]];
                    }
                    if (!empty($pData['red'])) {
                        $player['events'][] = ['__typename' => 'MatchCardEvent', 'type' => 'CARD_RED', 'period' => ['minute' => $pData['red']]];
                    }
                    if (!empty($pData['goal'])) {
                        $goals = is_array($pData['goal']) ? $pData['goal'] : [$pData['goal']];
                        foreach($goals as $g) {
                            $player['events'][] = ['__typename' => 'MatchGoalEvent', 'type' => 'GOAL', 'scorer' => ['id' => $playerId], 'period' => ['minute' => $g]];
                        }
                    }
                    if (!empty($pData['own_goal'])) {
                        $goals = is_array($pData['own_goal']) ? $pData['own_goal'] : [$pData['own_goal']];
                        foreach($goals as $g) {
                            $player['events'][] = ['__typename' => 'MatchGoalEvent', 'type' => 'OWN_GOAL', 'scorer' => ['id' => $playerId], 'period' => ['minute' => $g]];
                        }
                    }
                    if (!empty($pData['assist'])) {
                        $assists = is_array($pData['assist']) ? $pData['assist'] : [$pData['assist']];
                        foreach($assists as $a) {
                            $player['events'][] = ['__typename' => 'MatchGoalEvent', 'type' => 'GOAL', 'assist' => ['id' => $playerId], 'period' => ['minute' => $a]];
                        }
                    }
                    if (!empty($pData['substitute_time'])) {
                        $player['events'][] = ['__typename' => 'MatchSubstitutionEvent', 'period' => ['minute' => $pData['substitute_time']]];
                    }

                    $lineups[$side]['lineup'][] = $player;
                }
            }
        }
    }

    // 2. Process Substitutes
    if (!empty($data['substitutions'])) {
        foreach ($data['substitutions'] as $teamId => $subGroup) {
            $side = $map[$teamId] ?? null;
            if (!$side) continue;
            
            $players = $subGroup['sub'] ?? [];
            foreach ($players as $pData) {
                $p = $pData['player'] ?? null;
                if (!$p) continue;
                
                $player = [
                    'name' => $p['title'] ?? 'Unknown',
                    'number' => $p['player_number'] ?? 0,
                    'position' => $p['position'] ?? null,
                    'image' => $p['image'] ?? null,
                    'isCaptain' => false,
                    'id' => $p['row_id'] ?? $p['id'] ?? null,
                    'events' => []
                ];
                
                if (!empty($pData['substitute_time'])) {
                    $player['events'][] = ['__typename' => 'MatchSubstitutionEvent', 'period' => ['minute' => $pData['substitute_time']]];
                    
                    // Also find the player who was subbed OUT and add the event to them
                    if (!empty($pData['player_lineup']['row_id'])) {
                        $outPlayerId = $pData['player_lineup']['row_id'];
                        if (isset($lineups[$side]['lineup'])) {
                            foreach ($lineups[$side]['lineup'] as &$starter) {
                                if (($starter['id'] ?? null) == $outPlayerId) {
                                    $starter['events'][] = ['__typename' => 'MatchSubstitutionEvent', 'period' => ['minute' => $pData['substitute_time']]];
                                    break; // Found and updated
                                }
                            }
                        }
                    }
                }

                // Also process other events for substitutes (Goals, Cards)
                // Using the same logic as for starting players
                $playerId = $p['row_id'] ?? $p['id'] ?? null;
                if (!empty($pData['yellow'])) {
                    $player['events'][] = ['__typename' => 'MatchCardEvent', 'type' => 'CARD_YELLOW', 'period' => ['minute' => $pData['yellow']]];
                }
                if (!empty($pData['red'])) {
                    $player['events'][] = ['__typename' => 'MatchCardEvent', 'type' => 'CARD_RED', 'period' => ['minute' => $pData['red']]];
                }
                if (!empty($pData['goal'])) {
                    $goals = is_array($pData['goal']) ? $pData['goal'] : [$pData['goal']];
                    foreach($goals as $g) {
                        $player['events'][] = ['__typename' => 'MatchGoalEvent', 'type' => 'GOAL', 'scorer' => ['id' => $playerId], 'period' => ['minute' => $g]];
                    }
                }
                if (!empty($pData['own_goal'])) {
                    $goals = is_array($pData['own_goal']) ? $pData['own_goal'] : [$pData['own_goal']];
                    foreach($goals as $g) {
                        $player['events'][] = ['__typename' => 'MatchGoalEvent', 'type' => 'OWN_GOAL', 'scorer' => ['id' => $playerId], 'period' => ['minute' => $g]];
                    }
                }
                if (!empty($pData['assist'])) {
                    $assists = is_array($pData['assist']) ? $pData['assist'] : [$pData['assist']];
                    foreach($assists as $a) {
                        $player['events'][] = ['__typename' => 'MatchGoalEvent', 'type' => 'GOAL', 'assist' => ['id' => $playerId], 'period' => ['minute' => $a]];
                    }
                }
                
                $lineups[$side]['substitutes'][] = $player;
            }
        }
    }

    // 3. Process Injuries/Absent
    if (!empty($data['lineup_injureds'])) {
        foreach ($data['lineup_injureds'] as $teamId => $players) {
            $side = $map[$teamId] ?? null;
            if (!$side) continue;
            
            foreach ($players as $pData) {
                $p = $pData['player'] ?? null;
                if (!$p) continue;
                
                $player = [
                    'name' => $p['title'] ?? 'Unknown',
                    'number' => $p['player_number'] ?? 0,
                    'position' => $p['position'] ?? null,
                    'image' => $p['image'] ?? null,
                    'reason' => $pData['type_name'] ?? null
                ];
                
                $lineups[$side]['injuries'][] = $player;
            }
        }
    }

    // Final formatting via standardized method
    $finalLineups = [
        'home' => $this->formatTeamLineup($lineups['home'], $matchInfo['home_team_id'] ?? null),
        'away' => $this->formatTeamLineup($lineups['away'], $matchInfo['away_team_id'] ?? null)
    ];

    // Apply Formation-based Distribution
    $this->assignLineupCoordinates($finalLineups['home']['starting'], $finalLineups['home']['formation']);
    $this->assignLineupCoordinates($finalLineups['away']['starting'], $finalLineups['away']['formation']);

    return $finalLineups;
}

/**
 * Distributes players on the pitch based on their formation and tactical roles
 */
private function assignLineupCoordinates(&$players, $formation = null) {
    if (empty($players)) return;

    // 1. Separate GK and Field Players
    $gk = null;
    $fieldPlayers = [];
    
    foreach ($players as &$p) {
        // Skip if already has precise coords (e.g. from Kooora)
        if (isset($p['position']) && is_array($p['position']) && isset($p['position']['y'])) continue;

        $pos = $p['position'] ?? $p['position_en'] ?? '';
        
        // Depth mapping for global sorting
        $depth = 30; // Default Midfield
        if (mb_strpos($pos, 'حارس') !== false || mb_strpos($pos, 'GK') !== false) $depth = 0;
        elseif (mb_strpos($pos, 'دفاع') !== false || mb_strpos($pos, 'ظهير') !== false || mb_strpos($pos, 'DF') !== false) $depth = 10;
        elseif (mb_strpos($pos, 'مدافع') !== false || mb_strpos($pos, 'DM') !== false || mb_strpos($pos, 'محور') !== false) $depth = 20;
        elseif (mb_strpos($pos, 'هجوم') !== false || mb_strpos($pos, 'مهاجم') !== false || mb_strpos($pos, 'FW') !== false || mb_strpos($pos, 'ST') !== false) $depth = 50;
        elseif (mb_strpos($pos, 'جناح') !== false || mb_strpos($pos, 'AM') !== false || mb_strpos($pos, 'صانع') !== false) $depth = 40;

        // X-axis weight
        $weight = 50; 
        if (mb_strpos($pos, 'أيمن') !== false || mb_strpos($pos, 'RB') !== false || mb_strpos($pos, 'RM') !== false || mb_strpos($pos, 'RW') !== false) $weight = 20; 
        if (mb_strpos($pos, 'أيسر') !== false || mb_strpos($pos, 'LB') !== false || mb_strpos($pos, 'LM') !== false || mb_strpos($pos, 'LW') !== false) $weight = 80; 

        $p['_depth'] = $depth;
        $p['_weight'] = $weight;

        if ($depth === 0) {
            $gk = &$p;
        } else {
            $fieldPlayers[] = &$p;
        }
    }

    // Position GK
    if ($gk) {
        $gk['position'] = ['x' => 50, 'y' => 12];
        unset($gk['_depth'], $gk['_weight']);
    }

    if (empty($fieldPlayers)) return;

    // 2. Determine Rows from Formation
    // Handle "4-4-2", "4-2-3-1", etc.
    $formParts = [];
    if ($formation && preg_match_all('/\d+/', $formation, $matches)) {
        $formParts = array_map('intval', $matches[0]);
    }

    // Validate formation sum, otherwise fallback to default rows
    if (array_sum($formParts) !== count($fieldPlayers)) {
        // Fallback: Group by depth if formation is missing or mismatch
        $formParts = [];
        $tempRows = [];
        foreach ($fieldPlayers as $fp) $tempRows[$fp['_depth']][] = $fp;
        ksort($tempRows);
        foreach ($tempRows as $tr) $formParts[] = count($tr);
    }

    // 3. Sort players by tactical depth to slice them correctly
    usort($fieldPlayers, function($a, $b) {
        if ($a['_depth'] !== $b['_depth']) return $a['_depth'] <=> $b['_depth'];
        return $a['_weight'] <=> $b['_weight'];
    });

    // 4. Distribute into formation rows
    $yStart = 32;
    $yEnd = 90;
    $rowCount = count($formParts);
    $yStep = ($rowCount > 1) ? ($yEnd - $yStart) / ($rowCount - 1) : 0;

    $playerIdx = 0;
    for ($i = 0; $i < $rowCount; $i++) {
        $countInRow = $formParts[$i];
        $rowPlayers = array_slice($fieldPlayers, $playerIdx, $countInRow);
        $playerIdx += $countInRow;

        // Sort row players by weight for horizontal distribution
        usort($rowPlayers, function($a, $b) {
            return $a['_weight'] <=> $b['_weight'];
        });

        $yBase = $yStart + ($i * $yStep);
        $n = count($rowPlayers);
        
        // Arc Effect: If more than 2 players, curve the line slightly
        // Outer players move slightly back/forward to create a crescent shape
        $arcStrength = ($n > 2) ? 4 : 0; 

        for ($j = 0; $j < $n; $j++) {
            $xStep = 100 / ($n + 1);
            $x = ($j + 1) * $xStep;
            
            // Calculate individual Y with arc
            // Middle of the row (relative 0 to 1)
            $relativeX = ($n > 1) ? ($j / ($n - 1)) : 0.5;
            // Parabolic curve: -4 * (x - 0.5)^2 + 1  (values from 0 to 1)
            $curve = -4 * pow($relativeX - 0.5, 2) + 1;
            
            // Apply curve to Y (center players pushed slightly back, outer forward)
            $yOffset = (0.5 - $curve) * $arcStrength;
            $y = $yBase + $yOffset;

            $rowPlayers[$j]['position'] = ['x' => round($x, 1), 'y' => round($y, 1)];
        }
    }

    // Cleanup
    foreach ($fieldPlayers as &$p) {
        unset($p['_depth'], $p['_weight']);
    }
}

private function translateArabicPositionToEn($pos) {
    if (mb_strpos($pos, 'حارس') !== false) return 'GK';
    if (mb_strpos($pos, 'دفاع') !== false || mb_strpos($pos, 'ظهير') !== false) return 'DF';
    if (mb_strpos($pos, 'هجوم') !== false || mb_strpos($pos, 'مهاجم') !== false || mb_strpos($pos, 'هجوم') !== false) return 'FW';
    if (mb_strpos($pos, 'وسط' ) !== false || mb_strpos($pos, 'محور') !== false) return 'MF';
    return is_string($pos) ? $pos : 'MF';
}



    private function initLineupStructure($teamId, $teamName, $logo) {
        return [
            'formation' => null,
            'team_name_ar' => $teamName, // Assume AR passed
            'team_logo' => $logo,
            'coach' => null,
            'coach_image' => null,
            'starting' => [],
            'substitutes' => [],
            'injuries' => [],
            'suspensions' => []
        ];
    }

    public function scrapeMatchLive($matchId) {
        $match = $this->db->getMatchById($matchId);
        if (!$match) return ['status' => 'error', 'message' => 'Match not found'];

        $liveData = $this->nyallaScraper->getMatchLive(
            [
                $match['home_team'] ?? null,
                $match['home_team_ar'] ?? null,
                $match['home_team_en'] ?? null,
            ],
            [
                $match['away_team'] ?? null,
                $match['away_team_ar'] ?? null,
                $match['away_team_en'] ?? null,
            ],
            $match['match_date'],
            $match['id']
        );

        if ($liveData['success']) {
            $this->db->updateMatchLive(
                $match['id'],
                $liveData['live_url'],
                $liveData['live_iframe']
            );
            return ['status' => 'success', 'message' => 'تم العثور على رابط بث', 'source' => $liveData['source']];
        }
        return ['status' => 'error', 'message' => 'لم يتم العثور على بث'];
    }

    public function scrapeMatchStandings($matchId) {
        $match = $this->db->getMatchById($matchId);
        if (!$match || empty($match['match_url'])) {
            return ['status' => 'error', 'message' => 'Match not found or no URL'];
        }

        $html = $this->fetchUrl($match['match_url']);
        if (!$html) return ['status' => 'error', 'message' => 'Failed to fetch match details page'];

        $isYss = (strpos($match['match_url'], 'ysscores.com') !== false);
        
        $standings = [];
        
        // Priority 1 for YssScore: Use API-based extraction (most reliable, provides full data)
        if ($isYss) {
            $standings = $this->extractStandingsFromYssAPI($html);
        }
        
        // Priority 2: Try to get standings from championship page
        if (empty($standings)) {
            $url = $this->extractStandingsUrl($html);
            if ($url) {
                if (strpos($url, 'http') === false) {
                    $baseUrl = $isYss ? "https://www.ysscores.com" : "https://www.kooora.com";
                    $url = $baseUrl . (strpos($url, '/') === 0 ? "" : "/") . $url;
                }
                $standingsHtml = $this->fetchUrl($url);
                if ($isYss) {
                    $standings = $this->extractStandingsFromYssDOM($standingsHtml);
                } else {
                    $standings = $this->parseRelevantTable($standingsHtml, $match['home_team'], $match['away_team']);
                    // Fallback to JSON if HTML table parsing failed on the standings page
                    if (empty($standings)) {
                        $standings = $this->extractStandingsFromJson($standingsHtml);
                    }
                }
            }
        }

        // Priority 3 (Fallback): Parse match page directly
        if (empty($standings)) {
            if ($isYss) {
                $standings = $this->extractStandingsFromYssDOM($html);
            } else {
                $standings = $this->extractStandingsFromJson($html);
            }
        }

        if ($standings) {
            if ($this->db->saveStandings($matchId, $standings)) {
                return ['status' => 'success', 'message' => 'تم تحديث الترتيب بنجاح', 'standings' => $standings];
            }
        }
        return ['status' => 'error', 'message' => 'فشل في جلب الترتيب'];
    }

    public function scrapeAllEvents($date = null, $clearLogs = true) {
        $date = $date ?: date('Y-m-d');
        if ($clearLogs) $this->db->clearScrapeLogs();
        $this->db->addScrapeLog('info', "بدء تحديث أحداث المباريات لتاريخ: $date [0%]");

        $matches = $this->db->getMatches($date);
        if (empty($matches)) {
            $this->db->addScrapeLog('info', "لا توجد مباريات لتحديث أحداثها اليوم [100%]");
            return ['status' => 'error', 'message' => 'No matches'];
        }
        
        $total = count($matches);
        $this->db->addScrapeLog('info', "تم العثور على $total مباراة، جاري جلب الأحداث... [10%]");

        $updated = 0;
        foreach ($matches as $index => $match) {
            $progress = 10 + round((($index + 1) / $total) * 85);
            if (empty($match['match_url'])) {
                $this->db->addScrapeLog('filter', "تخطي مباراة: {$match['home_team']} vs {$match['away_team']} (لا يوجد رابط) [$progress%]");
                continue;
            }
            
            $this->db->addScrapeLog('info', "جاري جلب أحداث: {$match['home_team']} vs {$match['away_team']} [$progress%]");
            
            // Smart Filter
            if (!$this->shouldScrapeSmart($match)) {
                $this->db->addScrapeLog('filter', "تخطي: {$match['home_team']} vs {$match['away_team']} (لم تبدأ بعد أو غير نشطة) [$progress%]");
                continue;
            }

            $result = $this->scrapeMatchEvents($match['id']);
            
            if ($result['status'] === 'success') {
                $this->db->addScrapeLog('success', "تم تحديث أحداث: {$match['home_team']} vs {$match['away_team']} [$progress%]");
                $updated++;
            } else {
                $this->db->addScrapeLog('filter', "لم تتوفر أحداث بعد لـ: {$match['home_team']} vs {$match['away_team']} [$progress%]");
            }
        }
        $this->db->addScrapeLog('info', "اكتمل التحديث: تم تحديث أحداث $updated مباراة من أصل $total [100%]");
        return ['status' => 'success', 'message' => "Updated events for $updated matches", 'count' => $updated];
    }

    public function scrapeMatchEvents($matchId) {
        $match = $this->db->getMatchById($matchId);
        if (!$match || empty($match['match_url'])) {
            return ['status' => 'error', 'message' => 'Match not found or no URL'];
        }

        $html = $this->fetchUrl($match['match_url']);
        if (!$html) return ['status' => 'error', 'message' => 'Failed to fetch URL'];

        $events = null;
        if (strpos($match['match_url'], 'ysscores.com') !== false) {
            $events = $this->extractMatchEventsFromYssDOM($html, $matchId);
        } else {
            $events = $this->extractEventsFromJson($html);
        }

        if ($events) {
            if ($this->db->saveMatchEvents($matchId, $events)) {
                return ['status' => 'success', 'message' => 'تم تحديث الأحداث بنجاح', 'events' => $events];
            }
        }
        return ['status' => 'error', 'message' => 'فشل في جلب الأحداث'];
    }

    private function extractMatchEventsFromYssDOM($html, $matchId) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        $events = [];

        $eventNodes = $xpath->query("//div[contains(@class, 'full-events')]//a[contains(@class, 'comm_pop')]");
        if ($eventNodes->length === 0) {
            // Fallback if full-events is not present
            $eventNodes = $xpath->query("//div[contains(@class, 'match-events-wrap')]//a[contains(@class, 'comm_pop')]");
        }
        
        foreach ($eventNodes as $node) {
            $typeAr = $node->getAttribute('event_name');
            $status = $node->getAttribute('status'); // 1:goal, 2:yellow, 3:red, 8:sub, 7:cancelled goal
            
            $type = 'OTHER';
            $typeAr = trim($typeAr);
            
            // Check for missed penalty first regardless of status as it might be reported as a generic event
            if ((mb_strpos($typeAr, 'ركلة جزاء') !== false || mb_strpos($typeAr, 'ضربة جزاء') !== false) && 
                (mb_strpos($typeAr, 'ضائعة') !== false || mb_strpos($typeAr, 'أهدر') !== false || mb_strpos($typeAr, 'ضاعت') !== false || mb_strpos($typeAr, 'أضاع') !== false)) {
                $type = 'PENALTY_MISSED';
            }
            elseif ($status == '1') {
                $type = 'GOAL';
                if (mb_strpos($typeAr, 'ركلة جزاء') !== false || mb_strpos($typeAr, 'ضربة جزاء') !== false) {
                    $type = 'PENALTY_GOAL';
                } elseif (mb_strpos($typeAr, 'هدف في مرماه') !== false || mb_strpos($typeAr, 'هدف عكسي') !== false) {
                    $type = 'OWN_GOAL';
                }
            }
            elseif ($status == '2') $type = 'CARD_YELLOW';
            elseif ($status == '3') $type = 'CARD_RED';
            elseif ($status == '8') $type = 'SUBSTITUTION';
            elseif ($status == '5') {
                $type = 'PENALTY_GOAL';
            }
            elseif ($status == '6') {
                $type = 'PENALTY_MISSED';
            }
            elseif ($status == '22' || mb_strpos($typeAr, 'العارضة') !== false || mb_strpos($typeAr, 'القائم') !== false) {
                $type = 'WOODWORK'; 
            }
            elseif ($status == '7') $type = 'GOAL_VAR_CANCELLED';
            elseif (mb_strpos($typeAr, 'هدف') !== false) {
                $type = 'GOAL';
                if (mb_strpos($typeAr, 'ركلة جزاء') !== false || mb_strpos($typeAr, 'ضربة جزاء') !== false) {
                    $type = 'PENALTY_GOAL';
                } elseif (mb_strpos($typeAr, 'هدف في مرماه') !== false || mb_strpos($typeAr, 'هدف عكسي') !== false) {
                    $type = 'OWN_GOAL';
                }
            }
            elseif (mb_strpos($typeAr, 'صفراء') !== false || mb_strpos($typeAr, 'إنذار') !== false) $type = 'CARD_YELLOW';
            elseif (mb_strpos($typeAr, 'حمراء') !== false || mb_strpos($typeAr, 'طرد') !== false) $type = 'CARD_RED';
            elseif (mb_strpos($typeAr, 'تبديل') !== false || mb_strpos($typeAr, 'تغيير') !== false) $type = 'SUBSTITUTION';

            $side = 'home';
            if (strpos($node->getAttribute('class'), 'team-b') !== false) $side = 'away';
            
            // Extract full minute (e.g., 90+2) from the .time div if available
            $minute = $node->getAttribute('min');
            $parent = $xpath->query("..", $node)->item(0);
            if ($parent) {
                $timeNode = $xpath->query(".//div[contains(@class, 'time')]", $parent)->item(0);
                if ($timeNode) {
                    $baseMin = trim(str_replace('’', '', $timeNode->textContent));
                    // Check if there is extra time in <i>
                    $extraNode = $xpath->query(".//i", $timeNode)->item(0);
                    if ($extraNode) {
                        $extra = trim($extraNode->textContent);
                        $baseOnly = trim(str_replace($extra, '', $baseMin));
                        $minute = $baseOnly . $extra;
                    } else {
                        $minute = $baseMin;
                    }
                }
            }
            // Ensure no spaces or unwanted characters in minute
            $minute = str_replace(' ', '', $minute);

            $events[] = [
                'match_id' => $matchId,
                'event_type' => $type,
                'event_minute' => $minute,
                'team_side' => $side,
                'player_name' => $node->getAttribute('player_a'),
                'player_name_secondary' => $node->getAttribute('player_s'),
                'event_details' => trim(preg_replace('/\s+/', ' ', $typeAr)),
                'event_order' => 0 // Will be set after reversing
            ];
        }

        $events = array_reverse($events);
        foreach ($events as $i => &$event) {
            $event['event_order'] = $i;
        }

        return !empty($events) ? $events : null;
    }

    /**
     * Extract standings from YssScore API using match_code
     * This method directly calls the API endpoint which provides complete standings data
     */
    private function extractStandingsFromYssAPI($html) {
        if (!$html) return null;
        
        // Extract match_code from the HTML
        if (!preg_match('/<input[^>]*id="match_code"[^>]*value="(\d+)"/i', $html, $m)) {
            return null;
        }
        $matchCode = $m[1];
        
        // Call the YssScore API
        $apiUrl = "https://www.ysscores.com/ar/get_league_rank?match_code=" . $matchCode;
        $apiResponse = $this->fetchUrl($apiUrl);
        if (!$apiResponse) return null;
        
        $data = json_decode($apiResponse, true);
        if (!$data || empty($data['list_match'])) return null;
        
        $standings = [];
        $rankIndex = 1;
        
        // Loop through all stages/groups
        foreach ($data['list_match'] as $stage) {
            // Check the structure: if first item has 'team_name', it's a flat list (no groups)
            // Otherwise, it's a grouped structure (e.g., A, B groups)
            $firstKey = array_key_first($stage);
            $firstItem = $stage[$firstKey] ?? null;
            
            // Flat structure (e.g., Saudi League): teams are directly numbered
            if (is_array($firstItem) && isset($firstItem['team_name'])) {
                foreach ($stage as $position => $team) {
                    $teamData = $team['team_name'] ?? [];
                    $teamName = $teamData['short_title'] ?? $teamData['title'] ?? '';
                    if (empty($teamName)) continue;
                    
                    $standings[] = [
                        'rank' => $rankIndex,
                        'team' => $teamName,
                        'team_logo' => $teamData['image'] ?? '',
                        'played' => (int)($team['play'] ?? 0),
                        'won' => (int)($team['wins'] ?? 0),
                        'drawn' => (int)($team['draw'] ?? 0),
                        'lost' => (int)($team['lose'] ?? 0),
                        'goals_for' => (int)($team['for'] ?? 0),
                        'goals_against' => (int)($team['against'] ?? 0),
                        'goal_diff' => (int)($team['diff'] ?? 0),
                        'points' => (int)($team['points'] ?? 0),
                        'group' => $team['group_name'] ?? null
                    ];
                    $rankIndex++;
                }
            } else {
                // Grouped structure (e.g., Argentine League with A/B groups)
                foreach ($stage as $groupKey => $groupTeams) {
                    if (!is_array($groupTeams)) continue;
                    
                    foreach ($groupTeams as $position => $team) {
                        if (!is_array($team) || !isset($team['team_name'])) continue;
                        
                        $teamData = $team['team_name'] ?? [];
                        $teamName = $teamData['short_title'] ?? $teamData['title'] ?? '';
                        if (empty($teamName)) continue;
                        
                        $standings[] = [
                            'rank' => $rankIndex,
                            'team' => $teamName,
                            'team_logo' => $teamData['image'] ?? '',
                            'played' => (int)($team['play'] ?? 0),
                            'won' => (int)($team['wins'] ?? 0),
                            'drawn' => (int)($team['draw'] ?? 0),
                            'lost' => (int)($team['lose'] ?? 0),
                            'goals_for' => (int)($team['for'] ?? 0),
                            'goals_against' => (int)($team['against'] ?? 0),
                            'goal_diff' => (int)($team['diff'] ?? 0),
                            'points' => (int)($team['points'] ?? 0),
                            'group' => $team['group_name'] ?? $groupKey
                        ];
                        $rankIndex++;
                    }
                }
            }
        }
        
        // Only return if we have meaningful data (more than 3 teams)
        return count($standings) > 3 ? $standings : null;
    }

    private function extractStandingsFromYssDOM($html) {
        if (!$html) return null;
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        
        // Priority 1: Specific IDs for full tables
        // Priority 2: Generic ranking-table class
        $containers = $xpath->query("//div[@id='main_table'] | //div[@id='standing_rank0'] | //div[contains(@class, 'ranking-table') and not(contains(@class, 'players-table'))]");
        
        if ($containers->length === 0) return null;

        $bestStandings = null;
        $maxTeams = 0;

        foreach ($containers as $container) {
            // Find all rank rows within this container
            $rows = $xpath->query(".//div[contains(@class, 'rank-row') and not(contains(@class, 'header'))]", $container);
            
            // Skip containers with 3 or fewer teams (+3 requirement)
            if ($rows->length <= 3) continue;

            $currentStandings = [];
            foreach ($rows as $row) {
                // Determine Rank
                $rankCol = $xpath->query(".//div[contains(@class, 'rank-col number')]", $row)->item(0);
                
                // Determine Team Name and Logo
                // Check multiple depths as requested by the user's snippet
                $teamNameCol = $xpath->query(".//div[contains(@class, 'team-name')]", $row)->item(0);
                if (!$teamNameCol) continue;

                $infoDiv = $xpath->query(".//div[contains(@class, 'info')]", $teamNameCol)->item(0);
                $teamName = $infoDiv ? trim($infoDiv->textContent) : trim($teamNameCol->textContent);
                if (empty($teamName)) continue;

                $logoImg = $xpath->query(".//img", $teamNameCol)->item(0);
                
                // Stats columns
                $playedCol = $xpath->query(".//div[contains(@class, 'rank-col played')]", $row)->item(0);
                $goalsCol = $xpath->query(".//div[contains(@class, 'rank-col goals')]", $row)->item(0);
                $diffCol = $xpath->query(".//div[contains(@class, 'rank-col diff')]", $row)->item(0);
                $winCol = $xpath->query(".//div[contains(@class, 'rank-col win')]", $row)->item(0);
                $equalCol = $xpath->query(".//div[contains(@class, 'rank-col equal')]", $row)->item(0);
                $loseCol = $xpath->query(".//div[contains(@class, 'rank-col lose')]", $row)->item(0);
                $pointsCol = $xpath->query(".//div[contains(@class, 'rank-col points')]", $row)->item(0);

                // Goals parsing (handle Against:For format)
                $goalsText = $goalsCol ? trim($goalsCol->textContent) : '0';
                $gd = $diffCol ? (int)trim($diffCol->textContent) : 0;
                if (strpos($goalsText, ':') !== false) {
                    $parts = explode(':', $goalsText);
                    $ga = (int)$parts[0];
                    $gf = (int)$parts[1];
                } else {
                    $gf = (int)$goalsText;
                    $ga = $gf - $gd;
                }
                
                $currentStandings[] = [
                    'rank' => $rankCol ? trim($rankCol->textContent) : '',
                    'team' => $teamName,
                    'team_logo' => $logoImg ? $logoImg->getAttribute('src') : '',
                    'played' => $playedCol ? (int)trim($playedCol->textContent) : 0,
                    'won' => $winCol ? (int)trim($winCol->textContent) : 0,
                    'drawn' => $equalCol ? (int)trim($equalCol->textContent) : 0,
                    'lost' => $loseCol ? (int)trim($loseCol->textContent) : 0,
                    'goals_for' => $gf,
                    'goals_against' => $ga,
                    'goal_diff' => $gd,
                    'points' => $pointsCol ? (int)trim($pointsCol->textContent) : 0
                ];
            }

            // If this container produced more valid teams than previous ones, keep it
            if (count($currentStandings) > $maxTeams && count($currentStandings) > 3) {
                $maxTeams = count($currentStandings);
                $bestStandings = $currentStandings;
            }
        }

        return $bestStandings;
    }

    private function extractEventsFromJson($html) {
        $startTag = '<script id="__NEXT_DATA__" type="application/json">';
        $startPos = strpos($html, $startTag);
        if ($startPos !== false) {
            $jsonStart = $startPos + strlen($startTag);
            $endPos = strpos($html, '</script>', $jsonStart);
            if ($endPos === false) return null;
            $jsonStr = substr($html, $jsonStart, $endPos - $jsonStart);
            $json = json_decode($jsonStr, true);
            if (!$json) return null;

            $matchData = $json['props']['pageProps']['data']['match'] ?? 
                         $json['props']['pageProps']['initialState']['matchPage']['match'] ?? 
                         null;

            if (!$matchData) return null;

            $formattedEvents = [];

            // 1. Process Commentary and Key Events (Goals, Cards, etc.)
            $eventsSource = [];
            
            // Add commentary events
            $commentary = $matchData['commentary'] ?? [];
            foreach ($commentary as $item) {
                if (isset($item['event']) && $item['event']) {
                    $eventsSource[] = $item['event'];
                }
            }
            
            // Add keyEvents (often used instead of commentary for main events)
            $keyEvents = $matchData['keyEvents'] ?? [];
            foreach ($keyEvents as $event) {
                $eventsSource[] = $event;
            }

            foreach ($eventsSource as $event) {
                $parsedEvent = $this->parseEvent($event);
                if ($parsedEvent) {
                    // Check for duplicates (same type, minute, and player)
                    $isDuplicate = false;
                    foreach ($formattedEvents as $existing) {
                        if ($existing['event_type'] === $parsedEvent['event_type'] && 
                            $existing['event_minute'] === $parsedEvent['event_minute'] && 
                            $existing['player_name'] === $parsedEvent['player_name']) {
                            $isDuplicate = true;
                            break;
                        }
                    }
                    
                    if (!$isDuplicate) {
                        $formattedEvents[] = $parsedEvent;
                    }
                }
            }
            // 2. Process Lineups (Substitutions)
            $lineups = $matchData['lineups'] ?? [];
            $teams = ['teamA', 'teamB'];
            foreach ($teams as $teamKey) {
                $lineup = $lineups[$teamKey]['lineup'] ?? [];
                foreach ($lineup as $playerEntry) {
                    $playerEvents = $playerEntry['events'] ?? [];
                    foreach ($playerEvents as $event) {
                        // Only add substitutions from lineups to avoid duplicates with commentary
                        // (though commentary often misses substitutions, so this is crucial)
                        if (($event['__typename'] ?? '') === 'MatchSubstitutionEvent') {
                            $parsedEvent = $this->parseEvent($event);
                            if ($parsedEvent) {
                                $formattedEvents[] = $parsedEvent;
                            }
                        }
                    }
                }
            }

            // 3. Sort by minute
            usort($formattedEvents, function($a, $b) {
                $minA = $this->parseMinuteForSort($a['event_minute']);
                $minB = $this->parseMinuteForSort($b['event_minute']);
                return $minA - $minB;
            });

            // Set order after sorting
            foreach ($formattedEvents as $index => &$event) {
                $event['event_order'] = $index;
            }

            return $formattedEvents;
        }
        return null;
    }

    private function parseEvent($event) {
        $type = $event['type'] ?? '';
        $minute = $event['period']['minute'] ?? '';
        if (isset($event['period']['extra']) && $event['period']['extra'] > 0) {
            $minute .= '+' . $event['period']['extra'];
        }

        $side = ($event['side'] === 'TEAM_A') ? 'home' : 'away';
        $mappedType = $this->mapEventType($type);

        $playerName = '';
        $playerNameSecondary = '';
        $details = $event['reason'] ?? '';
        $typename = $event['__typename'] ?? '';

        if ($typename === 'MatchGoalEvent') {
            $playerName = $event['scorer']['name'] ?? '';
            $playerNameSecondary = $event['assist']['name'] ?? '';
        } elseif ($typename === 'MatchSubstitutionEvent') {
            $playerName = $event['in']['name'] ?? '';
            $playerNameSecondary = $event['out']['name'] ?? '';
        } elseif (isset($event['player'])) {
            $playerName = $event['player']['name'] ?? '';
        }

        return [
            'event_type' => $mappedType,
            'event_minute' => $minute,
            'team_side' => $side,
            'player_name' => $playerName,
            'player_name_secondary' => $playerNameSecondary,
            'event_details' => $details,
            'event_order' => 0 // Placeholder, sorting handles order
        ];
    }

    private function parseMinuteForSort($minStr) {
        if (strpos($minStr, '+') !== false) {
            $parts = explode('+', $minStr);
            return (int)$parts[0] + (int)$parts[1];
        }
        return (int)$minStr;
    }

    private function mapEventType($kooraType) {
        $map = [
            'GOAL' => 'GOAL',
            'CARD_YELLOW' => 'CARD_YELLOW',
            'CARD_RED' => 'CARD_RED',
            'SUBSTITUTION' => 'SUBSTITUTION',
            'PENALTY_MISSED' => 'PENALTY_MISSED',
            'GOAL_PENALTY_MISS' => 'PENALTY_MISSED',
            'GOAL_VAR_CANCELLED' => 'GOAL_VAR_CANCELLED',
            'OWN_GOAL' => 'OWN_GOAL',
            'PENALTY_GOAL' => 'PENALTY_GOAL',
            'GOAL_PENALTY' => 'PENALTY_GOAL',
            'WOODWORK' => 'WOODWORK',
            'GOAL_PENALTY_SHOOTOUT' => 'GOAL_PENALTY_SHOOTOUT',
            'GOAL_PENALTY_SHOOTOUT_MISS' => 'GOAL_PENALTY_SHOOTOUT_MISS'
        ];
        return $map[$kooraType] ?? $kooraType;
    }

    private function extractStatisticsFromJson($html) {
        $startTag = '<script id="__NEXT_DATA__" type="application/json">';
        $startPos = strpos($html, $startTag);
        if ($startPos !== false) {
            $jsonStart = $startPos + strlen($startTag);
            $endPos = strpos($html, '</script>', $jsonStart);
            if ($endPos === false) return null;
            $jsonStr = substr($html, $jsonStart, $endPos - $jsonStart);
            $json = json_decode($jsonStr, true);
            if (!$json) return null;

            // Find stats in the JSON
            $stats = $json['props']['pageProps']['data']['match']['stats'] ?? 
                     $json['props']['pageProps']['initialState']['matchPage']['match']['stats'] ?? 
                     null;
            
            if ($stats && is_array($stats)) {
                return $stats;
            }
        }
        return null;
    }

    public function scrapeAllPreviousMatches($date = null, $clearLogs = true) {
        $date = $date ?: date('Y-m-d');
        if ($clearLogs) $this->db->clearScrapeLogs();
        $this->db->addScrapeLog('info', "بدء تحديث المواجهات السابقة ومعلومات الملعب لتاريخ: $date [0%]");

        $matches = $this->db->getMatches($date);
        if (empty($matches)) {
            $this->db->addScrapeLog('info', "لا توجد مباريات لتحديث مواجهاتها السابقة اليوم [100%]");
            return ['status' => 'error', 'message' => 'No matches'];
        }
        
        $total = count($matches);
        $this->db->addScrapeLog('info', "تم العثور على $total مباراة، جاري جلب البيانات... [10%]");

        $updated = 0;
        foreach ($matches as $index => $match) {
            $progress = 10 + round((($index + 1) / $total) * 85);
            if (empty($match['match_url'])) {
                $this->db->addScrapeLog('filter', "تخطي مباراة: {$match['home_team']} vs {$match['away_team']} (لا يوجد رابط) [$progress%]");
                continue;
            }
            
            $this->db->addScrapeLog('info', "جاري جلب المواجهات السابقة لـ: {$match['home_team']} vs {$match['away_team']} [$progress%]");
            
            $success = false;
            $isYss = (strpos($match['match_url'], 'ysscores.com') !== false);

            if ($isYss) {
                $history = $this->scrapeYssMatchHistory($match['match_url']);
                // Check new standardized keys
                if ($history && (!empty($history['teamA']) || !empty($history['teamB']) || !empty($history['h2h']))) {
                    $this->db->updateMatchPreviousMatches($match['id'], $history);
                    $success = true;
                }
            } else {
                // Fallback for others (Legacy JSON)
                $html = $this->fetchUrl($match['match_url']);
                $data = $this->extractPreviousMatchesFromJson($html);
                
                if ($data) {
                    if (isset($data['previous_matches'])) {
                        $this->db->updateMatchPreviousMatches($match['id'], $data['previous_matches']);
                        $success = true;
                    }
                    if (isset($data['stadium'])) {
                        $this->db->updateMatchStadium($match['id'], $data['stadium']);
                        $success = true;
                    }
                }
            }

            if ($success) {
                $this->db->addScrapeLog('success', "تم تحديث البيانات لـ: {$match['home_team']} vs {$match['away_team']} [$progress%]");
                $updated++;
            } else {
                $this->db->addScrapeLog('filter', "لم تتوفر بيانات إضافية لـ: {$match['home_team']} vs {$match['away_team']} [$progress%]");
            }
        }
        $this->db->addScrapeLog('info', "اكتمل التحديث: تم تحديث بيانات $updated مباراة من أصل $total [100%]");
        return ['status' => 'success', 'message' => "Updated previous matches and stadium for $updated matches", 'count' => $updated];
    }

    public function scrapeMatchPreviousMatches($matchId) {
        $match = $this->db->getMatchById($matchId);
        if (!$match || empty($match['match_url'])) {
            return ['status' => 'error', 'message' => 'Match not found or no URL'];
        }

        $isYss = (strpos($match['match_url'], 'ysscores.com') !== false);
        
        if ($isYss) {
            // Use DOM-based extraction for YssScores
            $history = $this->scrapeYssMatchHistory($match['match_url']);
            
            if ($history && (!empty($history['teamA']) || !empty($history['teamB']) || !empty($history['h2h']))) {
                // Save directly structure: {h2h: [], teamA: [], teamB: []}
                $this->db->updateMatchPreviousMatches($matchId, $history);
                return ['status' => 'success', 'message' => 'تم تحديث المواجهات السابقة', 'data' => $history];
            }
        }

        // Fallback or Legacy Logic
        $html = $this->fetchUrl($match['match_url']);
        $data = $this->extractPreviousMatchesFromJson($html);

        if ($data) {
            $msg = [];
            if (isset($data['previous_matches'])) {
                $this->db->updateMatchPreviousMatches($matchId, $data['previous_matches']);
                $msg[] = 'Previous matches updated';
            }
            if (isset($data['stadium'])) {
                $this->db->updateMatchStadium($matchId, $data['stadium']);
                $msg[] = 'Stadium updated';
            }
            
            if (!empty($msg)) {
                return ['status' => 'success', 'message' => implode(', ', $msg), 'data' => $data];
            }
        }
        return ['status' => 'error', 'message' => 'فشل في جلب النتائج السابقة أو الملعب'];
    }

    private function extractPreviousMatchesFromJson($html) {
        $startTag = '<script id="__NEXT_DATA__" type="application/json">';
        $startPos = strpos($html, $startTag);
        if ($startPos !== false) {
            $jsonStart = $startPos + strlen($startTag);
            $endPos = strpos($html, '</script>', $jsonStart);
            if ($endPos === false) return null;
            $jsonStr = substr($html, $jsonStart, $endPos - $jsonStart);
            $json = json_decode($jsonStr, true);
            if (!$json) return null;

            $data = $json['props']['pageProps']['data'] ?? $json['props']['pageProps']['initialState']['matchPage'] ?? null;
            if (!$data) return null;

            $result = [];
            $previousData = [];

            // 1. Extract Head-to-Head (H2H)
            $h2h = $data['h2h'] ?? $data['match']['h2h'] ?? null;
            if ($h2h) {
                $previousData['h2h'] = [
                    'matches' => isset($h2h['matches']) ? $this->formatPreviousMatches($h2h['matches']) : [],
                    'stats' => [
                        'teamAWins' => $h2h['stats']['teamAWins'] ?? 0,
                        'teamBWins' => $h2h['stats']['teamBWins'] ?? 0,
                        'draws' => $h2h['stats']['draws'] ?? 0,
                        'teamAGoals' => $h2h['stats']['teamAGoals'] ?? 0,
                        'teamBGoals' => $h2h['stats']['teamBGoals'] ?? 0,
                        'over25' => $h2h['stats']['gamesOverTwoAndHalf'] ?? 0,
                        'btts' => $h2h['stats']['gamesBothTeamsScored'] ?? 0
                    ]
                ];
            }

            // 2. Extract Team Form (Results against others)
            $form = $data['form'] ?? $data['match']['form'] ?? null;
            if ($form) {
                // Team A (Home Team)
                if (isset($form['totalTeamA'])) {
                    $previousData['teamA'] = [
                        'results' => $this->formatFormMatches($form['totalTeamA']['matches'] ?? []),
                        'stats' => $this->formatFormStats($form['totalTeamA']['stats'] ?? [])
                    ];
                }
                // Team B (Away Team)
                if (isset($form['totalTeamB'])) {
                    $previousData['teamB'] = [
                        'results' => $this->formatFormMatches($form['totalTeamB']['matches'] ?? []),
                        'stats' => $this->formatFormStats($form['totalTeamB']['stats'] ?? [])
                    ];
                }
            }

            if (!empty($previousData)) {
                $result['previous_matches'] = $previousData;
            }

            // Extract Stadium
            $matchInfo = $data['match'] ?? null;
            if ($matchInfo && isset($matchInfo['venue']['name'])) {
                $result['stadium'] = $matchInfo['venue']['name'];
            }

            return $result;
        }
        return null;
    }

    private function formatPreviousMatches($matches) {
        $formatted = [];
        foreach ($matches as $m) {
            $formatted[] = [
                'date' => $m['startDate'] ?? $m['date'] ?? null,
                'home_team' => $m['teamA']['name'] ?? $m['homeTeam']['name'] ?? 'Unknown',
                'away_team' => $m['teamB']['name'] ?? $m['awayTeam']['name'] ?? 'Unknown',
                'score_home' => $m['score']['teamA'] ?? $m['score']['home'] ?? 0,
                'score_away' => $m['score']['teamB'] ?? $m['score']['away'] ?? 0,
                'status' => $m['status'] ?? 'Finished'
            ];
        }
        return $formatted;
    }

    private function formatFormMatches($matches) {
        $formatted = [];
        foreach ($matches as $m) {
            $matchDetails = $m['match'] ?? $m;
            $formatted[] = [
                'date' => $matchDetails['startDate'] ?? $matchDetails['date'] ?? null,
                'home_team' => $matchDetails['teamA']['name'] ?? $matchDetails['homeTeam']['name'] ?? 'Unknown',
                'away_team' => $matchDetails['teamB']['name'] ?? $matchDetails['awayTeam']['name'] ?? 'Unknown',
                'score_home' => $matchDetails['score']['teamA'] ?? $matchDetails['score']['home'] ?? 0,
                'score_away' => $matchDetails['score']['teamB'] ?? $matchDetails['score']['away'] ?? 0,
                'result' => $m['wdl'] ?? null, // WIN, DRAW, LOSE
                'status' => $matchDetails['status'] ?? 'Finished'
            ];
        }
        return $formatted;
    }

    private function formatFormStats($stats) {
        if (empty($stats)) return [];
        return [
            'goals_scored' => $stats['goalsScored'] ?? 0,
            'goals_conceded' => $stats['goalsConceded'] ?? 0,
            'over25' => $stats['gamesOverTwoAndHalfGoals'] ?? 0,
            'btts' => $stats['gamesBothTeamsScored'] ?? 0,
            'total_games' => $stats['gamesTotal'] ?? 0
        ];
    }

    private function calculatePosition($pos) {
        if (!$pos || !isset($pos['y'])) return null;
        
        $y = $pos['y'];
        
        // Y coordinates mapping to 5 lines:
        // GK: ~12
        // DF: ~32
        // MF: ~50
        // AM: ~70
        // FW: ~90
        
        if ($y <= 20) return 'GK';
        if ($y <= 40) return 'DF';
        if ($y <= 60) return 'MF';
        if ($y <= 80) return 'AM';
        return 'FW';
    }

    public function scrapeAllDetails($date = null, $clearLogs = true) {
        $date = $date ?: date('Y-m-d');
        if ($clearLogs) $this->db->clearScrapeLogs();
        $this->db->addScrapeLog('info', "بدء تحديث تفاصيل المباريات لتاريخ: $date [0%]");

        $matches = $this->db->getMatches($date);
        if (empty($matches)) {
            $this->db->addScrapeLog('info', "لا توجد مباريات لتحديث تفاصيلها اليوم [100%]");
            return ['status' => 'error', 'message' => 'No matches'];
        }
        
        $total = count($matches);
        $this->db->addScrapeLog('info', "تم العثور على $total مباراة، جاري جلب التفاصيل... [10%]");

        $updated = 0;
        foreach ($matches as $index => $match) {
            $progress = 10 + round((($index + 1) / $total) * 85);
            if (empty($match['match_url'])) {
                $this->db->addScrapeLog('filter', "تخطي مباراة: {$match['home_team']} vs {$match['away_team']} (لا يوجد رابط تفاصيل) [$progress%]");
                continue;
            }
            
            $this->db->addScrapeLog('info', "جاري جلب تفاصيل: {$match['home_team']} vs {$match['away_team']} [$progress%]");
            $result = $this->scrapeMatchDetails($match['id']);
            
            if ($result['status'] === 'success') {
                $this->db->addScrapeLog('success', "تم تحديث تفاصيل: {$match['home_team']} vs {$match['away_team']} [$progress%]");
                $updated++;
            } else {
                $this->db->addScrapeLog('filter', "لم يتم العثور على تفاصيل جديدة لـ: {$match['home_team']} vs {$match['away_team']} [$progress%]");
            }
        }
        $this->db->addScrapeLog('info', "اكتمل التحديث: تم تحديث $updated مباراة من أصل $total [100%]");
        return ['status' => 'success', 'message' => "Updated details for $updated matches", 'count' => $updated];
    }

    public function scrapeMatchDetails($matchId) {
        $match = $this->db->getMatchById($matchId);
        if (!$match || empty($match['match_url'])) {
            return ['status' => 'error', 'message' => 'Match not found or no URL'];
        }

        $html = $this->fetchUrl($match['match_url']);
        if (!$html) return ['status' => 'error', 'message' => 'Failed to fetch match details page'];

        $details = null;
        if (strpos($match['match_url'], 'ysscores.com') !== false) {
            $details = $this->extractGeneralMatchInfoFromYssDOM($html);
        } else {
            $details = $this->extractDetailsFromHtml($html);
        }
        
        if ($details) {
            // Update metadata (channel, stadium, referee, etc.)
            $this->db->updateMatchDetails($matchId, $details);
            
            // If YssScore and we have updated scores/status, sync them too
            if (strpos($match['match_url'], 'ysscores.com') !== false && isset($details['score_home'])) {
                $effectiveStatus = $details['status'] ?? $match['status'];
                $effectiveMatchTime = $details['live_time'] ?? $details['details_time'] ?? $match['match_time'];

                if ($effectiveStatus === 'Live' && $this->isClockTimeValue($effectiveMatchTime)) {
                    $effectiveMatchTime = '0';
                }

                $syncData = [
                    'league' => $this->normalizeYssLeagueName($details['league'] ?? $match['league_name']),
                    'home_team' => $match['home_team'],
                    'away_team' => $match['away_team'],
                    'score_home' => $details['score_home'],
                    'score_away' => $details['score_away'],
                    'status' => $effectiveStatus,
                    'match_date' => $details['details_date'] ?? $match['match_date'],
                    'match_time' => $effectiveMatchTime,
                    'details_match_time' => $details['details_time'] ?? ($match['details_match_time'] ?? null),
                    'match_url' => $match['match_url'],
                    'home_team_logo' => $details['home_team_logo'] ?? $match['home_team_logo'],
                    'away_team_logo' => $details['away_team_logo'] ?? $match['away_team_logo'],
                    'home_team_external_id' => $details['home_team_external_id'] ?? null,
                    'away_team_external_id' => $details['away_team_external_id'] ?? null
                ];
                $this->db->saveMatch($syncData);
            }
            
            return ['status' => 'success', 'message' => 'تم تحديث التفاصيل بنجاح', 'details' => $details];
        }
        return ['status' => 'error', 'message' => 'فشل في جلب التفاصيل'];
    }

    private function extractGeneralMatchInfoFromYssDOM($html) {
        if (!$html) return null;
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        $info = ['channels' => []];

        // 1. Extract Match Code (needed for API calls)
        $matchCodeNode = $xpath->query("//input[@id='match_code']")->item(0);
        if ($matchCodeNode) {
            $info['match_code'] = $matchCodeNode->getAttribute('value');
        }

        // 2. Extract match-info-items (Standard YssScore structure)
        $infoItems = $xpath->query("//div[contains(@class, 'match-info-item')]");
        foreach ($infoItems as $item) {
            $titleNode = $xpath->query(".//div[contains(@class, 'title')]", $item)->item(0);
            $contentNode = $xpath->query(".//div[contains(@class, 'content')]", $item)->item(0);
            
            if ($titleNode && $contentNode) {
                $title = trim($titleNode->textContent);
                $content = trim(preg_replace('/\s+/', ' ', $contentNode->textContent));
                $contentDate = $this->parseMatchDateText($content);
                if ($contentDate !== null) {
                    $info['details_date'] = $contentDate;
                }
                
                if (strpos($title, 'البطولة') !== false) {
                    $info['league'] = $this->normalizeYssLeagueName($content);
                } elseif (strpos($title, 'القناة') !== false) {
                    $info['channel'] = $content;
                    if (!isset($info['channels'][$content])) {
                        $info['channels'][$content] = ['name' => $content, 'logo' => null, 'url' => null];
                    }
                } elseif (strpos($title, 'الملعب') !== false || strpos($title, 'ملعب المباراة') !== false) {
                    $info['stadium'] = $content;
                } elseif (strpos($title, 'الحكم') !== false || strpos($title, 'حكم') !== false || strpos($title, 'VAR') !== false) {
                    // Collect all referees
                    if (!isset($info['referees_list'])) $info['referees_list'] = [];
                    $info['referees_list'][] = [
                        'type' => $title,
                        'name' => $content
                    ];
                    
                    // Also maintain a primary referee for compatibility
                    if (strpos($title, 'حكم الساحة') !== false || ($title === 'الحكم' && !isset($info['referee']))) {
                        $info['referee_name'] = $content; 
                    }
                } elseif (strpos($title, 'المعلق') !== false) {
                    $info['commentator'] = $content;
                    // If we already have a channel, associate commentator with it
                    if (isset($info['channel'])) {
                        $info['channels'][$info['channel']]['commentator'] = $content;
                    }
                } elseif (strpos($title, 'وقت') !== false || strpos($title, 'توقيت') !== false || strpos($title, 'الحقيقي') !== false || strpos($title, 'موعد') !== false) {
                    $info['details_time'] = $this->normalizeTime($content);
                } elseif (strpos($title, 'الجولة') !== false || strpos($title, 'الأسبوع') !== false) {
                    $info['round'] = $content;
                } elseif (strpos($title, 'المجموعة') !== false) {
                    $info['group'] = $content;
                }
            }
        }

        // 2.5 Extract Live Time / Minute if available
        if (isset($info['match_code'])) {
            $timeNode = $xpath->query("//div[@id='match-detail-time-{$info['match_code']}']")->item(0);
            if ($timeNode) {
                $liveTime = trim($timeNode->textContent);
                if (preg_match('/^(\d+):/', $liveTime, $tm)) {
                    $info['live_time'] = $tm[1] . "'";
                } elseif (is_numeric($liveTime)) {
                    $info['live_time'] = $liveTime . "'";
                } else {
                    $info['live_time'] = $liveTime;
                }
            }
        }
        
        // 3. Extract Score and Status (same logic as list page)
        $header = $xpath->query("//div[contains(@class, 'match-profile-details')]")->item(0);
        if ($header) {
             $homeScoreNode = $xpath->query(".//div[contains(@class, 'right-team')]//div[contains(@class, 'team-result')]", $header)->item(0);
             $awayScoreNode = $xpath->query(".//div[contains(@class, 'left-team')]//div[contains(@class, 'team-result')]", $header)->item(0);
             
             if ($homeScoreNode && $awayScoreNode) {
                 $info['score_home'] = (int)trim($homeScoreNode->textContent);
                 $info['score_away'] = (int)trim($awayScoreNode->textContent);
                 
                 // Extract logos while we are at it
                 $homeLogoNode = $xpath->query(".//div[contains(@class, 'right-team')]//img", $header)->item(0);
                 $awayLogoNode = $xpath->query(".//div[contains(@class, 'left-team')]//img", $header)->item(0);
                 $homeTeamLinkNode = $xpath->query(".//div[contains(@class, 'right-team')]/ancestor::a[contains(@href, '/team/')][1]", $header)->item(0);
                 $awayTeamLinkNode = $xpath->query(".//div[contains(@class, 'left-team')]/ancestor::a[contains(@href, '/team/')][1]", $header)->item(0);
                  if ($homeLogoNode) {
                      $hLogo = $homeLogoNode->getAttribute('src');
                      if ($hLogo && strpos($hLogo, 'http') === false) {
                          $hLogo = "https://www.ysscores.com" . (strpos($hLogo, '/') === 0 ? "" : "/") . $hLogo;
                      }
                      $info['home_team_logo'] = $hLogo;
                      $info['home_team_external_id'] = $this->extractYssTeamExternalId($homeTeamLinkNode ? $homeTeamLinkNode->getAttribute('href') : '', $hLogo);
                  }
                  if ($awayLogoNode) {
                      $aLogo = $awayLogoNode->getAttribute('src');
                      if ($aLogo && strpos($aLogo, 'http') === false) {
                          $aLogo = "https://www.ysscores.com" . (strpos($aLogo, '/') === 0 ? "" : "/") . $aLogo;
                      }
                      $info['away_team_logo'] = $aLogo;
                      $info['away_team_external_id'] = $this->extractYssTeamExternalId($awayTeamLinkNode ? $awayTeamLinkNode->getAttribute('href') : '', $aLogo);
                  }

                 // Status detection from timer/status span
                 $timerNode = $xpath->query(".//div[contains(@class, 'match-details')]//span[contains(@class, 'timer')]", $header)->item(0);
                 if (!$timerNode) $timerNode = $xpath->query(".//div[contains(@class, 'match-details')]//span[contains(@class, 'time')]", $header)->item(0);
                 
                 $statusText = $timerNode ? trim($timerNode->textContent) : '';
                 
                 if (strpos($statusText, 'إنتهت') !== false || strpos($statusText, 'Finished') !== false) {
                      $info['status'] = 'Finished';
                      $info['live_time'] = 'Fin';
                 } elseif (!empty($statusText) && (strpos($statusText, ':') !== false || strpos($statusText, "'") !== false) && strpos($statusText, 'تبدأ') === false) {
                      $info['status'] = 'Live';
                      if (!isset($info['live_time']) && !empty($statusText)) {
                          $info['live_time'] = $statusText;
                      }
                 } elseif (isset($info['live_time']) && $info['live_time'] !== '00:00') {
                      // If we have live_time from step 2.5, it's a live match
                      $info['status'] = 'Live';
                 }

                 $normalizedLiveTime = $this->normalizeYssLiveTimeValue($info['live_time'] ?? '');
                 $inferredStatus = $this->inferYssStatus($statusText, $normalizedLiveTime, !empty($normalizedLiveTime));
                 if ($inferredStatus === 'Finished') {
                      $info['status'] = 'Finished';
                      $info['live_time'] = 'Fin';
                 } elseif ($inferredStatus === 'Live') {
                      $info['status'] = 'Live';
                      if ($normalizedLiveTime !== '') {
                          $info['live_time'] = $normalizedLiveTime;
                      } elseif (!empty($statusText) && !$this->isClockTimeValue($statusText)) {
                          $info['live_time'] = $this->normalizeYssLiveTimeValue($statusText);
                      } else {
                          $info['live_time'] = '0';
                      }
                 } elseif ($inferredStatus === 'Postponed') {
                      $info['status'] = 'Postponed';
                      $info['live_time'] = 'Postponed';
                 }

                 if (($info['status'] ?? '') === 'Live') {
                      $effectiveDate = $info['details_date'] ?? date('Y-m-d');
                      $effectiveTime = $info['details_time'] ?? '';
                      if (!$this->isYssLiveAllowedByKickoff($effectiveDate, $effectiveTime)) {
                          $info['status'] = 'Scheduled';
                          unset($info['live_time']);
                      }
                 }
             }
        }

        // Preserve the full referee list when available.
        if (!empty($info['referees_list'])) {
            $info['referee'] = $info['referees_list'];
        } elseif (isset($info['referee_name'])) {
            $info['referee'] = $info['referee_name'];
        }
        unset($info['referees_list'], $info['referee_name']);

        return !empty($info) ? $info : null;
    }

    private function extractDetailsFromHtml($html) {
        $details = [];
        $details['channels'] = [];

        // 1. Try JSON extraction
        $startTag = '<script id="__NEXT_DATA__" type="application/json">';
        $startPos = strpos($html, $startTag);
        if ($startPos !== false) {
            $jsonStart = $startPos + strlen($startTag);
            $endPos = strpos($html, '</script>', $jsonStart);
            if ($endPos === false) return null;
            $jsonStr = substr($html, $jsonStart, $endPos - $jsonStart);
            $json = json_decode($jsonStr, true);
            if ($json) {
                $data = $json['props']['pageProps']['data']['match'] ?? 
                        $json['props']['pageProps']['initialState']['matchPage']['match'] ?? 
                        null;
                
                if ($data) {
                    // Channels from JSON
                    $channels = [];
                    if (!empty($data['broadcasters']) && is_array($data['broadcasters'])) {
                        foreach ($data['broadcasters'] as $broadcaster) {
                            $name = trim($broadcaster['name'] ?? 'Unknown');
                            if ($name === 'Unknown' || empty($name)) continue;
                            
                            $channels[$name] = [
                                'name' => $name,
                                'logo' => $broadcaster['image']['url'] ?? null,
                                'url'  => null
                            ];
                        }
                    }
                    $details['channels'] = $channels;
                    
                    // Set primary channel for backward compatibility
                    if (!empty($details['channels'])) {
                        $firstChannel = reset($details['channels']);
                        $details['channel'] = $firstChannel['name'];
                        $details['channel_logo'] = $firstChannel['logo'];
                    }
                    
                    // Stadium
                    $details['stadium'] = $data['venue']['name'] ?? null;
                    
                    // League
                    $competitionName = $data['competition']['name'] ?? null;
                    $details['league'] = $competitionName !== null ? $this->normalizeYssLeagueName($competitionName) : null;

                    // Time (Detailed)
                    if (!empty($data['date'])) {
                        $utcParts = $this->parseExternalDateTimeToUtcParts($data['date']);
                        if ($utcParts !== null) {
                            $details['details_date'] = $utcParts['match_date'];
                            $details['details_time'] = $utcParts['match_time'];
                        }
                    }
                }
            }
        }

        // 2. Try DOM extraction for Channels (Primary source for URLs)
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        
        // Query for channels
        $channelNodes = $xpath->query('//div[contains(@class, "fco-match-ott__channels")]/a');
        
        if ($channelNodes->length > 0) {
            foreach ($channelNodes as $node) {
                $url = $node->getAttribute('href');
                $nameNode = $xpath->query('.//p[contains(@class, "fco-match-ott__channel-name")]', $node)->item(0);
                $name = $nameNode ? trim($nameNode->textContent) : 'Unknown';
                
                if ($name === 'Unknown' || empty($name)) continue;

                $imgNode = $xpath->query('.//span[contains(@class, "fco-image")]/img', $node)->item(0);
                $logo = $imgNode ? $imgNode->getAttribute('src') : null;
                
                // DOM channels take priority or are added
                $details['channels'][$name] = [
                    'name' => $name,
                    'url'  => $url,
                    'logo' => $logo ?: ($details['channels'][$name]['logo'] ?? null)
                ];
            }
            // Re-update primary channel after DOM extraction
            if (!empty($details['channels'])) {
                $firstChannel = reset($details['channels']);
                $details['channel'] = $firstChannel['name'];
                $details['channel_logo'] = $firstChannel['logo'];
            }
        }

        // 3. Time Extraction from DOM (Backup or Primary if JSON fails/is different)
        // Look for the list item with the timer icon
        $timeNode = $xpath->query('//li[contains(@class, "fco-match-details__list-item")]/i[contains(@class, "fco-icon-timer")]/following-sibling::span')->item(0);
        
        if ($timeNode) {
            $timeText = $this->normalizeAsciiDigits(trim($timeNode->textContent));
            $utcParts = $this->parseExternalDateTimeToUtcParts($timeText);
            if ($utcParts !== null) {
                $details['details_date'] = $utcParts['match_date'];
                $details['details_time'] = $utcParts['match_time'];
            }
            // Try to extract HH:mm (e.g., "8 يناير 2026, 20:00" -> "20:00")
            elseif (preg_match('/(\d{1,2}:\d{2})/', $timeText, $timeMatches)) {
                $details['details_time'] = $timeMatches[1];
            }
        }

        // 4. Stadium Extraction from DOM
        $stadiumNode = $xpath->query('//li[contains(@class, "fco-match-details__list-item")]/i[contains(@class, "fco-icon-stadium")]/following-sibling::span')->item(0);
        if ($stadiumNode) {
            $details['stadium'] = trim($stadiumNode->textContent);
        }

        if (isset($details['channels'])) {
            $details['channels'] = array_values($details['channels']);
        }

        return !empty($details) ? $details : null;
    }
    private function isThreeSixFiveFallbackEnabledForLive() {
        if (!$this->db || !method_exists($this->db, 'getApiSettings')) {
            return true;
        }

        try {
            $settings = $this->db->getApiSettings();
            return (string)($settings['enable_365scores_fallback'] ?? '1') === '1';
        } catch (Throwable $e) {
            return true;
        }
    }

    private function purgeLiveStreamsByUrlPattern($targetDate, $urlPattern) {
        if (empty($this->db) || empty($this->db->pdo)) {
            return ['matches' => 0, 'channels' => 0];
        }

        try {
            $select = $this->db->pdo->prepare("
                SELECT id, channel, live_url, live_iframe
                FROM matches
                WHERE match_date = ?
                  AND (
                      live_url LIKE ?
                      OR live_iframe LIKE ?
                  )
            ");
            $select->execute([$targetDate, $urlPattern, $urlPattern]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($rows)) {
                return ['matches' => 0, 'channels' => 0];
            }

            $clearMatch = $this->db->pdo->prepare("
                UPDATE matches
                SET live_url = NULL, live_iframe = NULL
                WHERE id = ?
            ");
            $clearChannel = $this->db->pdo->prepare("
                UPDATE channels
                SET stream_url = NULL
                WHERE name = ?
                  AND stream_url = ?
            ");

            $matchesCleared = 0;
            $channelsCleared = 0;

            foreach ($rows as $row) {
                $stream = trim((string)($row['live_iframe'] ?: $row['live_url']));
                $clearMatch->execute([(int)$row['id']]);
                $matchesCleared += $clearMatch->rowCount() > 0 ? 1 : 0;

                $channelName = trim((string)($row['channel'] ?? ''));
                if ($channelName !== '' && $stream !== '') {
                    $clearChannel->execute([$channelName, $stream]);
                    $channelsCleared += $clearChannel->rowCount();
                }
            }

            return ['matches' => $matchesCleared, 'channels' => $channelsCleared];
        } catch (Throwable $e) {
            return ['matches' => 0, 'channels' => 0];
        }
    }

    private function purgeAiScoreStreams($targetDate) {
        $total = ['matches' => 0, 'channels' => 0];
        foreach (['%aiscore.com%', '%thesports01.com%', '%thesports.com%'] as $pattern) {
            $result = $this->purgeLiveStreamsByUrlPattern($targetDate, $pattern);
            $total['matches'] += (int)($result['matches'] ?? 0);
            $total['channels'] += (int)($result['channels'] ?? 0);
        }

        return $total;
    }

    private function purgeDisabled365ScoresStreams($targetDate) {
        $total = ['matches' => 0, 'channels' => 0];
        foreach (['%365scores.com%', '%lmtsrcf.365scores.com%', '%sportradar.com%'] as $pattern) {
            $result = $this->purgeLiveStreamsByUrlPattern($targetDate, $pattern);
            $total['matches'] += (int)($result['matches'] ?? 0);
            $total['channels'] += (int)($result['channels'] ?? 0);
        }

        return $total;
    }

    private function purgeNonLiveStreams($targetDate) {
        if (empty($this->db) || empty($this->db->pdo)) {
            return ['matches' => 0, 'channels' => 0];
        }

        try {
            $select = $this->db->pdo->prepare("
                SELECT id, channel, live_url, live_iframe
                FROM matches
                WHERE match_date = ?
                  AND status <> 'Live'
                  AND (
                      live_url IS NOT NULL
                      OR live_iframe IS NOT NULL
                  )
            ");
            $select->execute([$targetDate]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($rows)) {
                return ['matches' => 0, 'channels' => 0];
            }

            $clearMatch = $this->db->pdo->prepare("
                UPDATE matches
                SET live_url = NULL, live_iframe = NULL
                WHERE id = ?
            ");
            $clearChannel = $this->db->pdo->prepare("
                UPDATE channels
                SET stream_url = NULL
                WHERE name = ?
                  AND stream_url = ?
            ");

            $matchesCleared = 0;
            $channelsCleared = 0;

            foreach ($rows as $row) {
                $stream = trim((string)($row['live_iframe'] ?: $row['live_url']));
                $clearMatch->execute([(int)$row['id']]);
                $matchesCleared += $clearMatch->rowCount() > 0 ? 1 : 0;

                $channelName = trim((string)($row['channel'] ?? ''));
                if ($channelName !== '' && $stream !== '') {
                    $clearChannel->execute([$channelName, $stream]);
                    $channelsCleared += $clearChannel->rowCount();
                }
            }

            return ['matches' => $matchesCleared, 'channels' => $channelsCleared];
        } catch (Throwable $e) {
            return ['matches' => 0, 'channels' => 0];
        }
    }

    private function syncSourceStatusesBeforeLiveScrape($targetDate) {
        $source = null;
        try {
            $source = $this->getActiveScraperSource('matches');
        } catch (Throwable $e) {
            return ['source' => null, 'status' => 'skipped', 'message' => $e->getMessage()];
        }

        $normalizedSource = strtolower(trim((string)$source));
        if ($normalizedSource === 'yssscore' || $normalizedSource === 'ysscores') {
            try {
                $result = $this->scrapeYssScore($targetDate, false);
                return [
                    'source' => $normalizedSource,
                    'status' => $result['status'] ?? 'unknown',
                    'total' => $result['total'] ?? null,
                ];
            } catch (Throwable $e) {
                return ['source' => $normalizedSource, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return ['source' => $normalizedSource, 'status' => 'skipped'];
    }

    private function finishExpiredLiveMatches($maxAgeMinutes = 240) {
        if (empty($this->db) || empty($this->db->pdo)) {
            return ['matches' => 0, 'channels' => 0];
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - ((int)$maxAgeMinutes * 60));

        try {
            $select = $this->db->pdo->prepare("
                SELECT id, channel, live_url, live_iframe
                FROM matches
                WHERE status = 'Live'
                  AND start_time IS NOT NULL
                  AND start_time <= ?
            ");
            $select->execute([$cutoff]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($rows)) {
                return ['matches' => 0, 'channels' => 0];
            }

            $finishMatch = $this->db->pdo->prepare("
                UPDATE matches
                SET status = 'Finished',
                    match_time = 'Fin',
                    live_url = NULL,
                    live_iframe = NULL
                WHERE id = ?
                  AND status = 'Live'
            ");
            $clearChannel = $this->db->pdo->prepare("
                UPDATE channels
                SET stream_url = NULL
                WHERE name = ?
                  AND stream_url = ?
            ");

            $matchesFinished = 0;
            $channelsCleared = 0;

            foreach ($rows as $row) {
                $stream = trim((string)($row['live_iframe'] ?: $row['live_url']));
                $finishMatch->execute([(int)$row['id']]);
                $matchesFinished += $finishMatch->rowCount() > 0 ? 1 : 0;

                $channelName = trim((string)($row['channel'] ?? ''));
                if ($channelName !== '' && $stream !== '') {
                    $clearChannel->execute([$channelName, $stream]);
                    $channelsCleared += $clearChannel->rowCount();
                }
            }

            return ['matches' => $matchesFinished, 'channels' => $channelsCleared];
        } catch (Throwable $e) {
            return ['matches' => 0, 'channels' => 0];
        }
    }

    public function scrapeLive($date = null, $clearLogs = true) {
        $targetDate = $date ?: date('Y-m-d');
        $today = date('Y-m-d');
        
        if ($targetDate !== $today) {
            $this->db->addScrapeLog('info', "تجاهل البحث عن روابط البث لتاريخ: $targetDate (يتم جلب روابط اليوم فقط) [100%]");
            return ["status" => "success", "message" => "Skipped: only today matches are allowed for live streams", "total" => 0];
        }

        if ($clearLogs) $this->db->clearScrapeLogs();
        $this->db->addScrapeLog('info', "بدء البحث عن روابط البث المباشر لتاريخ: $targetDate [0%]");

        $statusSync = $this->syncSourceStatusesBeforeLiveScrape($targetDate);
        if (($statusSync['status'] ?? 'skipped') !== 'skipped') {
            $syncedTotal = $statusSync['total'] ?? 0;
            $this->db->addScrapeLog('info', "Synced match statuses before live scrape from {$statusSync['source']} ({$statusSync['status']}, total: {$syncedTotal}) [2%]");
        }

        $expiredCleanup = $this->finishExpiredLiveMatches(240);
        if ((int)($expiredCleanup['matches'] ?? 0) > 0 || (int)($expiredCleanup['channels'] ?? 0) > 0) {
            $this->db->addScrapeLog(
                'info',
                "Finished expired live matches: {$expiredCleanup['matches']} matches and cleared {$expiredCleanup['channels']} channel streams [3%]"
            );
        }

        $legacyCleanup = $this->purgeAiScoreStreams($targetDate);
        if ((int)($legacyCleanup['matches'] ?? 0) > 0 || (int)($legacyCleanup['channels'] ?? 0) > 0) {
            $this->db->addScrapeLog(
                'info',
                "تم تنظيف روابط AiScore القديمة: {$legacyCleanup['matches']} مباراة و {$legacyCleanup['channels']} قناة [3%]"
            );
        }

        if (!$this->isThreeSixFiveFallbackEnabledForLive()) {
            $cleanup = $this->purgeDisabled365ScoresStreams($targetDate);
            if ((int)($cleanup['matches'] ?? 0) > 0 || (int)($cleanup['channels'] ?? 0) > 0) {
                $this->db->addScrapeLog(
                    'info',
                    "تم تنظيف روابط 365Scores المعطلة: {$cleanup['matches']} مباراة و {$cleanup['channels']} قناة [3%]"
                );
            }
        }

        $nonLiveCleanup = $this->purgeNonLiveStreams($targetDate);
        if ((int)($nonLiveCleanup['matches'] ?? 0) > 0 || (int)($nonLiveCleanup['channels'] ?? 0) > 0) {
            $this->db->addScrapeLog(
                'info',
                "Cleared stale non-live streams: {$nonLiveCleanup['matches']} matches and {$nonLiveCleanup['channels']} channels [4%]"
            );
        }

        // Get live matches without stream URLs for the target date.
        $sql = "SELECT m.id, t1.name as home_team, t1.name_en as home_team_en, t1.name_ar as home_team_ar,
                       t2.name as away_team, t2.name_en as away_team_en, t2.name_ar as away_team_ar,
                       m.match_date, m.status, m.match_url 
                FROM matches m
                JOIN teams t1 ON m.home_team_id = t1.id
                JOIN teams t2 ON m.away_team_id = t2.id
                WHERE m.match_date = '{$targetDate}' 
                AND (m.live_url IS NULL OR m.live_url = '')
                AND m.status = 'Live'
                AND (
                    m.start_time IS NULL
                    OR m.start_time <= DATE_ADD(NOW(), INTERVAL 10 MINUTE)
                )
                ORDER BY m.match_time ASC";
        
        $stmt = $this->db->pdo->query($sql);
        $matches = $stmt->fetchAll();
        
        $total = count($matches);
        if ($total === 0) {
            $this->db->addScrapeLog('info', "لا توجد مباريات تحتاج لتحديث روابط البث حالياً [100%]");
            return ["status" => "success", "message" => "No matches to update", "total" => 0];
        }

        $this->db->addScrapeLog('info', "تم العثور على $total مباراة تحتاج لتحديث، جاري البحث... [10%]");
        
        $processed = 0;
        $success = 0;
        $sources = [];
        
        foreach ($matches as $index => $match) {
            $progress = 10 + round((($index + 1) / $total) * 85);
            try {
                $liveUrl = null;
                $liveIframe = null;
                $foundSource = null;

                $this->db->addScrapeLog('info', "جاري البحث عن بث لمباراة: {$match['home_team']} vs {$match['away_team']} [$progress%]");

                // 1. Try NYallaScraper (for actual streams)
                $liveData = $this->nyallaScraper->getMatchLive(
                    $this->buildLiveTeamCandidates($match, 'home'),
                    $this->buildLiveTeamCandidates($match, 'away'),
                    $match['match_date'],
                    (int)($match['id'] ?? 0)
                );
                
                if ($liveData['success']) {
                    $this->db->updateMatchLive(
                        $match['id'],
                        $liveData['live_url'],
                        $liveData['live_iframe']
                    );
                    $success++;
                    
                    $foundSource = $liveData['source'] ?? 'external';
                    if (!isset($sources[$foundSource])) {
                        $sources[$foundSource] = 0;
                    }
                    $sources[$foundSource]++;
                    $this->db->addScrapeLog('success', "تم العثور على رابط بث لمباراة: {$match['home_team']} vs {$match['away_team']} (المصدر: $foundSource) [$progress%]");
                } else {
                    $this->db->addScrapeLog('filter', "لم يتم العثور على بث لمباراة: {$match['home_team']} vs {$match['away_team']} [$progress%]");
                }
            } catch (Exception $e) {
                $this->db->addScrapeLog('error', "خطأ أثناء البحث عن بث لمباراة {$match['id']}: " . $e->getMessage() . " [$progress%]");
                error_log("Failed to fetch live URL for match {$match['id']}: " . $e->getMessage());
            }
            $processed++;
        }
        
        $this->db->addScrapeLog('info', "اكتمل البحث: تم العثور على $success رابط بث من أصل $total مباراة [100%]");
        
        return [
            "status" => "success", 
            "message" => "Processed $processed matches, found $success live streams",
            "total" => $total,
            "processed" => $processed,
            "success" => $success,
            "sources" => $sources
        ];
    }

    // --- News Scraping Methods ---

    public function scrapeNews($limit = 20) {
        $source = $this->getActiveScraperSource('news');
        $this->db->addScrapeLog('info', "المصدر الحالي للأخبار: $source");

        if (!$source) {
            $this->db->addScrapeLog('filter', "لم يتم تحديد مصدر للأخبار (جميع المصادر معطلة)");
            return [];
        }

        if ($source === 'ysscores' || $source === 'yssscore') {
            return $this->scrapeYssNews($limit);
        } else {
            return $this->scrapeKoooraNews($limit);
        }
    }

    private function getActiveScraperSource($type = 'matches') {
        // 1. Give priority to the global scraper setting from Database class
        // This is what the UI (Settings Page) most likely updates (scraper_source_settings table)
        if (method_exists($this->db, 'getActiveScraperSource')) {
            $dbSource = $this->db->getActiveScraperSource();
            if ($dbSource) return $dbSource;
        }

        // 2. Fallback: Query the settings table (for legacy or specific overrides)
        try {
            $stmt = $this->db->pdo->prepare("SELECT setting_value FROM settings WHERE `setting_key` = :k");
            $key = 'scrape_source_' . $type; 
            
            $stmt->execute([':k' => $key]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) return $res['setting_value'];

            // Fallback: check general source in settings table
            $stmt->execute([':k' => 'scrape_source']); 
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) return $res['setting_value'];
            
        } catch (Exception $e) {
            // silent fail
        }
        return false;
    }

    public function scrapeKoooraNews($limit = 20) {
        $url = "https://www.kooora.com/%D8%A3%D8%AE%D8%A8%D8%A7%D8%B1";
        $headers = ["User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36"];
        
        $html = $this->fetchUrl($url, null, $headers);
        if (!$html) return [];

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html);
        libxml_clear_errors();
        
        $scripts = $dom->getElementsByTagName('script');
        $jsonData = null;
        
        foreach ($scripts as $script) {
            if ($script->getAttribute('type') === 'application/ld+json') {
                $content = $script->nodeValue;
                if (strpos($content, 'NewsArticle') !== false) {
                    $jsonData = json_decode($content, true);
                    break;
                }
            }
        }
        
        $newsItems = [];
        if ($jsonData && isset($jsonData['itemListElement'])) {
            foreach ($jsonData['itemListElement'] as $element) {
                if (count($newsItems) >= $limit) break;
                
                if (isset($element['item'])) {
                    $item = $element['item'];
                    $link = $item['url'] ?? '#';
                    if (strpos($link, 'http') === false) $link = 'https://www.kooora.com' . $link;

                    $publishDate = $item['datePublished'] ?? date('Y-m-d H:i:s');
                    try {
                        $dt = new DateTime($publishDate);
                        $publishDate = $dt->format('Y-m-d H:i:s');
                    } catch (Exception $e) { }

                    $newsItems[] = [
                        'title' => $item['name'] ?? '',
                        'image' => $item['image'] ?? '',
                        'url' => $link,
                        'date' => $publishDate,
                        'source' => 'kooora'
                    ];
                }
            }
        }
        return $newsItems;
    }

    public function scrapeYssNews($limit = 20) {
        $url = "https://www.ysscores.com/ar/news";
        $html = $this->fetchUrl($url);
        if (!$html) {
            $this->db->addScrapeLog('error', "فشل في الوصول إلى موقع YssScores للأخبار");
            return [];
        }

        return $this->extractNewsFromYssDOM($html, $limit);
    }

    private function extractNewsFromYssDOM($html, $limit) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $newsItems = [];
        
        // Combined extraction: Big + Standard + Mini
        $queries = [
            "//div[contains(@class, 'big-news-item')]//a[contains(@class, 'news-item')]",
            "//div[contains(@class, 'news-row')]//a[contains(@class, 'news-item')]",
            "//div[contains(@class, 'mini-news-items')]//a[contains(@class, 'news-item')]"
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            foreach ($nodes as $node) {
                if (count($newsItems) >= $limit) break 2;

                $url = $node->getAttribute('href');
                if (!$url || $url === 'javascript:void(0)') continue;
                
                if (strpos($url, 'http') === false) $url = 'https://www.ysscores.com' . $url;

                // Title
                $titleNode = $xpath->query(".//h3[contains(@class, 'news-title')]", $node)->item(0);
                $title = $titleNode ? trim($titleNode->textContent) : '';

                // Image
                $imgNode = $xpath->query(".//img", $node)->item(0);
                $image = $imgNode ? $imgNode->getAttribute('src') : '';

                // Date
                $dateNode = $xpath->query(".//div[contains(@class, 'news-date')]//span", $node)->item(0);
                $dateText = $dateNode ? trim($dateNode->textContent) : '';
                // Assume Yss date is usable or fallback to Now
                // Simple parsing for now. Yss has "2026-02-04" or text.
                $date = date('Y-m-d H:i:s');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateText)) {
                     $date = $dateText . ' ' . date('H:i:s');
                }

                if ($title && $url) {
                    $newsItems[] = [
                        'title' => $title,
                        'image' => $image,
                        'url' => $url,
                        'date' => $date,
                        'source' => 'ysscores'
                    ];
                }
            }
        }
        return $newsItems;
    }

    public function scrapeNewsDetails($url, $source = 'kooora') {
        if ($source == 'ysscores' || $source == 'yssscore' || strpos($url, 'ysscores.com') !== false) {
            return $this->scrapeYssNewsDetails($url);
        } else {
            return $this->scrapeKoooraNewsDetails($url);
        }
    }

    private function scrapeYssNewsDetails($url) {
        $html = $this->fetchUrl($url);
        if (!$html) return null;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $article = [
            'body' => '',
            'image' => ''
        ];

        // Image extraction (priority for article page)
        $imgNode = $xpath->query("//div[contains(@class, 'main-media')]//img")->item(0);
        if (!$imgNode) $imgNode = $xpath->query("//div[contains(@class, 'news-details')]//img")->item(0);
        if ($imgNode) {
            $article['image'] = $imgNode->getAttribute('src');
        }

        // Body: .news-content (p tags only)
        $bodyNode = $xpath->query(".//div[contains(@class, 'news-content')]")->item(0);
        if ($bodyNode) {
            $pNodes = $xpath->query(".//p", $bodyNode);
            $bodyHtml = '';
            foreach ($pNodes as $p) {
                // Strip all tags inside P except strong/em if wanted, but user asked for p only
                $text = trim($p->textContent);
                if (!empty($text)) {
                    $bodyHtml .= '<p>' . htmlspecialchars($text) . '</p>';
                }
            }
            $article['body'] = $bodyHtml;
        }

        return $article;
    }

    private function scrapeKoooraNewsDetails($url) {
        $headers = ["User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36"];
        $html = $this->fetchUrl($url, null, $headers);
        if (!$html) return null;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        
        $article = ['body' => '', 'image' => ''];

        // High-res Image (main media)
        $imgNode = $xpath->query('//div[contains(@class, "fco-main-media")]//img')->item(0);
        if ($imgNode) {
            $article['image'] = $imgNode->getAttribute('src');
        }
        
        // Teaser + Body (p tags only)
        $teaserNode = $xpath->query('//p[contains(@class, "fco-article-teaser")]')->item(0);
        $teaser = $teaserNode ? trim($teaserNode->textContent) : '';

        $bodyNode = $xpath->query('//div[contains(@class, "fco-article-body")]')->item(0);
        if ($bodyNode) {
            $article['body'] = '';
            if ($teaser) {
                $article['body'] .= '<p><strong>' . htmlspecialchars($teaser) . '</strong></p>';
            }
            
            $pNodes = $xpath->query(".//p", $bodyNode);
            foreach ($pNodes as $p) {
                $text = trim($p->textContent);
                if (!empty($text)) {
                    $article['body'] .= '<p>' . htmlspecialchars($text) . '</p>';
                }
            }
        }

        return $article;
    }
}
?>
