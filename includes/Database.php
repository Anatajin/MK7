<?php
class Database {
    public $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        try {
            $this->pdo->exec("SET time_zone = '+00:00'");
        } catch (Throwable $e) {
            // Keep using the connection even if the server rejects session timezone changes.
        }
    }

    /**
     * Normalize Arabic text for comparison
     * Removes diacritics and standardizes similar letters
     */
    private function normalizeArabicText($text) {
        if (empty($text)) return '';
        
        $text = trim($text);
        
        // Remove Arabic diacritics (تشكيل)
        $diacritics = ['ً', 'ٌ', 'ٍ', 'َ', 'ُ', 'ِ', 'ّ', 'ْ', 'ـ'];
        $text = str_replace($diacritics, '', $text);
        
        // Normalize Alef variations (أ إ آ ا) -> ا
        $text = preg_replace('/[أإآءٱ]/u', 'ا', $text);
        
        // Normalize Taa Marbuta (ة) -> ه
        $text = str_replace('ة', 'ه', $text);
        
        // Normalize Alef Maksura (ى) -> ي
        $text = str_replace('ى', 'ي', $text);
        
        // Normalize similar sounding letters
        $text = str_replace(['ؤ', 'ئ', 'گ', 'پ', 'چ', 'ژ', 'ڤ'], ['و', 'ي', 'ك', 'ب', 'ج', 'ز', 'ف'], $text);
        
        // Remove common noise words
        $noiseWords = ['نادي', 'فريق', 'منتخب', 'للشباب', 'الشباب', 'التحت', 'fc', 'sc', 'club', 'u23', 'u21', 'u19'];
        foreach ($noiseWords as $word) {
            $text = preg_replace('/\b' . preg_quote($word, '/') . '\b/u', '', $text);
        }

        // Remove "ال" prefix
        $text = preg_replace('/\bال(?=\p{L})/u', '', $text);
        
        // Remove double spaces and non-alphanumeric
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Lowercase for English parts
        $text = mb_strtolower(trim($text), 'UTF-8');
        
        return $text;
    }

    private function prepareRefereeValueForStorage($referee) {
        if ($referee === null) {
            return null;
        }

        if (is_array($referee)) {
            $normalized = [];

            foreach ($referee as $item) {
                if (is_array($item)) {
                    $normalized[] = [
                        'type' => trim((string)($item['type'] ?? '')),
                        'name' => trim((string)($item['name'] ?? ''))
                    ];
                    continue;
                }

                $value = trim((string)$item);
                if ($value !== '') {
                    $normalized[] = $value;
                }
            }

            if (empty($normalized)) {
                return null;
            }

            $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);
            return $json === false ? null : $json;
        }

        $referee = trim((string)$referee);
        return $referee === '' ? null : $referee;
    }

    private function getAppTimezoneName() {
        return defined('APP_TIMEZONE') ? APP_TIMEZONE : date_default_timezone_get();
    }

    private function getMatchStorageTimezoneName() {
        return defined('MATCH_STORAGE_TIMEZONE') ? MATCH_STORAGE_TIMEZONE : 'UTC';
    }

    private function normalizeApiLogIpCandidate($candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            return null;
        }

        if (stripos($candidate, 'for=') === 0) {
            $candidate = preg_replace('/^for=/i', '', $candidate);
        }

        if (strpos($candidate, ';') !== false) {
            $candidate = strstr($candidate, ';', true);
        }

        $candidate = trim($candidate, " \t\n\r\0\x0B\"'");

        if (strpos($candidate, ',') !== false) {
            foreach (explode(',', $candidate) as $part) {
                $normalized = $this->normalizeApiLogIpCandidate($part);
                if ($normalized !== null) {
                    return $normalized;
                }
            }

            return null;
        }

        if (preg_match('/^\d+\.\d+\.\d+\.\d+:\d+$/', $candidate)) {
            $candidate = preg_replace('/:\d+$/', '', $candidate);
        }

        if (str_starts_with($candidate, '[') && str_contains($candidate, ']')) {
            $candidate = substr($candidate, 1, strpos($candidate, ']') - 1);
        }

        return $candidate === '' ? null : $candidate;
    }

    private function isPublicApiLogIp($ip) {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function resolveApiLogIpAddress() {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_TRUE_CLIENT_IP'] ?? null,
            $_SERVER['HTTP_X_CLIENT_IP'] ?? null,
            $_SERVER['HTTP_CLIENT_IP'] ?? null,
            $_SERVER['HTTP_FASTLY_CLIENT_IP'] ?? null,
            $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'] ?? null,
            $_SERVER['HTTP_FORWARDED'] ?? null,
            $_SERVER['HTTP_X_REAL_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['HTTP_X_FORWARDED'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null
        ];

        $fallbackIp = null;

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeApiLogIpCandidate($candidate);
            if ($normalized === null || filter_var($normalized, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            if ($this->isPublicApiLogIp($normalized)) {
                return $normalized;
            }

            if ($fallbackIp === null) {
                $fallbackIp = $normalized;
            }
        }

        return $fallbackIp;
    }

    private function resolveApiLogCountryCode($ip = null) {
        $candidates = [
            $_GET['country_code'] ?? null,
            $_GET['country'] ?? null,
            $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null,
            $_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] ?? null,
            $_SERVER['HTTP_X_COUNTRY_CODE'] ?? null,
            $_SERVER['HTTP_COUNTRY_CODE'] ?? null,
            $_SERVER['HTTP_X_VIEWER_COUNTRY'] ?? null,
            $_SERVER['HTTP_X_APPENGINE_COUNTRY'] ?? null,
            $_SERVER['HTTP_X_GEO_COUNTRY'] ?? null,
            $_SERVER['GEOIP_COUNTRY_CODE'] ?? null
        ];

        foreach ($candidates as $candidate) {
            $candidate = strtoupper(trim((string)$candidate));
            if (preg_match('/^[A-Z]{2}$/', $candidate)) {
                return $candidate;
            }
        }

        $ip = $this->normalizeApiLogIpCandidate($ip);
        if ($ip === null) {
            return null;
        }

        try {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            $url = ($this->isPublicApiLogIp($ip))
                ? "http://ip-api.com/json/{$ip}?fields=countryCode"
                : "http://ip-api.com/json/?fields=countryCode";

            $geo = @file_get_contents($url, false, $ctx);
            if ($geo) {
                $geoData = json_decode($geo, true);
                $countryCode = strtoupper(trim((string)($geoData['countryCode'] ?? '')));
                if (preg_match('/^[A-Z]{2}$/', $countryCode)) {
                    return $countryCode;
                }
            }
        } catch (Exception $e) {
            // Ignore geo lookup failures for analytics logging.
        }

        return null;
    }

    private function normalizeScheduledTime($time) {
        $time = trim((string)$time);
        if ($time === '') {
            return null;
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            return null;
        }

        $hour = (int)$matches[1];
        $minute = (int)$matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function isClockMatchTimeValue($time) {
        $time = trim((string)$time);
        return $time !== '' && preg_match('/^\d{1,2}:\d{2}$/', $time) === 1;
    }

    private function isLiveStatusValue($status) {
        $status = trim((string)$status);
        return in_array($status, ['Live', 'مباشر'], true);
    }

    private function normalizePersistedMatchTime($status, $matchTime) {
        $matchTime = trim((string)$matchTime);
        if ($matchTime === '') {
            return $matchTime;
        }

        if ($this->isLiveStatusValue($status) && $this->isClockMatchTimeValue($matchTime)) {
            return '0';
        }

        return $matchTime;
    }

    private function extractScheduledTimeFromData($data) {
        $candidates = [
            $data['details_time'] ?? null,
            $data['details_match_time'] ?? null,
            $data['match_time'] ?? null
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeScheduledTime($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function buildUtcStartTime($matchDate, $scheduledTime) {
        $matchDate = trim((string)$matchDate);
        $scheduledTime = $this->normalizeScheduledTime($scheduledTime);

        if ($matchDate === '' || $scheduledTime === null) {
            return null;
        }

        try {
            $sourceTimezone = new DateTimeZone($this->getAppTimezoneName());
            $storageTimezone = new DateTimeZone($this->getMatchStorageTimezoneName());
            $localDateTime = new DateTimeImmutable($matchDate . ' ' . $scheduledTime . ':00', $sourceTimezone);
            return $localDateTime->setTimezone($storageTimezone)->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Predefined aliases for teams that have completely different names
     */
    private function getTeamAliases($name) {
        $aliases = [
            'نوتنجهام' => ['فورست', 'نوتنجهام فورست', 'nottingham', 'forest'],
            'فورست' => ['نوتنجهام', 'نوتنجهام فورست', 'nottingham', 'forest'],
            'موستار' => ['زريننيسكي', 'زرينيسكي', 'zrinjski', 'mostar', 'زرينجسكي'],
            'زريننيسكي' => ['موستار', 'زرينيسكي', 'zrinjski', 'mostar', 'زرينجسكي'],
            'الزمالك' => ['مدرسه الفن والهندسه', 'الفارس الابيض', 'zamalek'],
            'الاهلي' => ['الشياطين الحمر', 'نادي القرن', 'ahly'],
            'ريال مدريد' => ['الميرينجي', 'الملكي', 'real madrid'],
            'برشلونه' => ['البلوجرانا', 'البارسا', 'barcelona'],
            'ميلان' => ['الروسونيري', 'milan'],
            'انتر ميلان' => ['النيراتزوري', 'inter milan', 'انتر'],
            'يوفنتوس' => ['البيانكونيري', 'السيده العجوز', 'juventus'],
            'ليفربول' => ['الريدز', 'liverpool'],
            'مانشستر يونايتد' => ['الشياطين الحمر', 'manchester united', 'مان يونايتد'],
            'مانشستر سيتي' => ['السيتيزنس', 'manchester city', 'مان سيتي'],
            'تشيلسي' => ['البلوز', 'chelsea'],
            'ارسنال' => ['الجانرز', 'المدفعجيه', 'arsenal'],
            'بايرن ميونخ' => ['البافاري', 'bayern munich'],
            'باريس سان جيرمان' => ['بي اس جي', 'paris saint germain', 'paris sg'],
            'الهلال' => ['الزعيم', 'al hilal'],
            'النصر' => ['العالمي', 'al nassr'],
            'الاتحاد' => ['العميد', 'al ittihad'],
            'الشباب' => ['الليث', 'al shabab'],
        ];
        
        $clean = $this->normalizeArabicText($name);
        foreach ($aliases as $key => $list) {
            $cleanKey = $this->normalizeArabicText($key);
            if ($clean === $cleanKey || (mb_strlen($clean) > 3 && mb_strpos($cleanKey, $clean) !== false)) {
                return $list;
            }
        }
        return [];
    }

    /**
     * Calculate similarity between two strings
     * Uses Levenshtein distance for short strings and similar_text for longer
     */
    private function calculateSimilarity($str1, $str2) {
        $norm1 = $this->normalizeArabicText($str1);
        $norm2 = $this->normalizeArabicText($str2);
        
        if (empty($norm1) || empty($norm2)) return 0;
        
        // 1. Exact match after normalization
        if ($norm1 === $norm2) return 100;
        
        // 2. Substring match
        if (mb_strpos($norm1, $norm2) !== false || mb_strpos($norm2, $norm1) !== false) return 95;
        
        // 3. Alias match
        $aliases = $this->getTeamAliases($str1);
        foreach ($aliases as $alias) {
            if ($this->normalizeArabicText($alias) === $norm2) return 100;
        }
        
        $aliases2 = $this->getTeamAliases($str2);
        foreach ($aliases2 as $alias) {
            if ($this->normalizeArabicText($alias) === $norm1) return 100;
        }
        
        // 4. Word-by-word match — require majority of words to match, not just one.
        // A single shared city name (أوساكا, طوكيو, etc.) should NOT be enough to merge teams.
        $words1 = array_filter(explode(' ', $norm1), fn($w) => mb_strlen($w) >= 3);
        $words2 = array_filter(explode(' ', $norm2), fn($w) => mb_strlen($w) >= 3);
        
        if (!empty($words1) && !empty($words2)) {
            $matchingWords = 0;
            foreach ($words1 as $w1) {
                foreach ($words2 as $w2) {
                    if ($w1 === $w2) {
                        $matchingWords++;
                        break;
                    }
                }
            }
            
            $totalWords = max(count($words1), count($words2));
            // Only consider it a match if the majority of words match (> 50% of the longer name)
            if ($matchingWords > 0 && ($matchingWords / $totalWords) > 0.5) {
                return 85 + (($matchingWords / $totalWords) * 10);
            }
        }
        
        // 5. Use similar_text for percentage as fallback
        similar_text($norm1, $norm2, $percent);
        return $percent;
    }

    /**
     * Find team with fuzzy matching
     */
    private function findTeamFuzzy($name) {
        $normalizedInput = $this->normalizeArabicText($name);
        
        // Get all teams for comparison
        $stmt = $this->pdo->query("SELECT * FROM teams");
        $allTeams = $stmt->fetchAll();
        
        $bestMatch = null;
        $bestScore = 0;
        $threshold = 85; // Minimum similarity percentage
        
        foreach ($allTeams as $team) {
            // Check against all name fields
            $namesToCheck = [
                $team['name'],
                $team['name_ar'],
                $team['name_en']
            ];
            
            foreach ($namesToCheck as $teamName) {
                if (empty($teamName)) continue;
                
                $score = $this->calculateSimilarity($name, $teamName);
                
                if ($score >= $threshold && $score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $team;
                }
            }
        }
        
        return $bestMatch;
    }

    private function extractTeamLogoIdentity($logo) {
        $logo = trim((string)($logo ?? ''));
        if ($logo === '') {
            return null;
        }

        $path = (string)(parse_url($logo, PHP_URL_PATH) ?: $logo);
        if (preg_match('~/teams/\d+/([^/?#]+)~i', $path, $matches)) {
            $filename = pathinfo($matches[1], PATHINFO_FILENAME);
            return $filename !== '' ? 'yss-team-img:' . $filename : null;
        }

        return rtrim(strtolower($logo), '/');
    }

    private function teamLogosMatch($storedLogo, $incomingLogo) {
        $storedLogo = trim((string)($storedLogo ?? ''));
        $incomingLogo = trim((string)($incomingLogo ?? ''));
        if ($storedLogo === '' || $incomingLogo === '') {
            return false;
        }

        $storedIdentity = $this->extractTeamLogoIdentity($storedLogo);
        $incomingIdentity = $this->extractTeamLogoIdentity($incomingLogo);

        if ($storedIdentity !== null && $incomingIdentity !== null) {
            return $storedIdentity === $incomingIdentity;
        }

        return rtrim(strtolower($storedLogo), '/') === rtrim(strtolower($incomingLogo), '/');
    }

    private function teamLogoConflicts($storedLogo, $incomingLogo) {
        $storedLogo = trim((string)($storedLogo ?? ''));
        $incomingLogo = trim((string)($incomingLogo ?? ''));

        return $storedLogo !== '' && $incomingLogo !== '' && !$this->teamLogosMatch($storedLogo, $incomingLogo);
    }

    private function findTeamByNameAndLogo($name, $logo) {
        $name = trim((string)$name);
        $logo = trim((string)($logo ?? ''));
        if ($name === '' || $logo === '') {
            return null;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM teams WHERE name = ? OR name_ar = ? OR name_en = ?");
        $stmt->execute([$name, $name, $name]);
        $teams = $stmt->fetchAll();

        foreach ($teams as $team) {
            if ($this->teamLogosMatch($team['logo_url'] ?? null, $logo)) {
                return $team;
            }
        }

        return null;
    }

    private function canAttachExternalIdToTeam(array $team, $externalId) {
        $externalId = trim((string)$externalId);
        $current = trim((string)($team['external_id'] ?? ''));

        if ($externalId === '') {
            return false;
        }

        return $current === '' || strpos($current, 'yss-team-img:') === 0;
    }

    public function getOrCreateTeam($name, $logo = null, $externalId = null) {
        $name = trim($name);
        if (empty($name)) return null;
        $externalId = trim((string)($externalId ?? ''));
        $logo = trim((string)($logo ?? '')) ?: null;

        if ($externalId !== '') {
            $stmt = $this->pdo->prepare("SELECT * FROM teams WHERE external_id = ?");
            $stmt->execute([$externalId]);
            $team = $stmt->fetch();

            if ($team) {
                $updates = [];
                $params = [];

                if ($name !== '' && $team['name'] !== $name) {
                    $updates[] = "name = ?";
                    $params[] = $name;
                }

                if ($logo && $team['logo_url'] !== $logo) {
                    $updates[] = "logo_url = ?";
                    $params[] = $logo;
                }

                if (!empty($updates)) {
                    $sql = "UPDATE teams SET " . implode(', ', $updates) . " WHERE id = ?";
                    $params[] = $team['id'];
                    $update = $this->pdo->prepare($sql);
                    $update->execute($params);

                    if ($name !== '') {
                        $team['name'] = $name;
                    }
                    if ($logo) {
                        $team['logo_url'] = $logo;
                    }
                }

                return $team;
            }

            if ($logo) {
                $team = $this->findTeamByNameAndLogo($name, $logo);

                if ($team && $this->canAttachExternalIdToTeam($team, $externalId)) {
                    $updates = ["external_id = ?"];
                    $params = [$externalId];
                    if ($name !== '' && $team['name'] !== $name) {
                        $updates[] = "name = ?";
                        $params[] = $name;
                    }
                    if ($logo && !$this->teamLogosMatch($team['logo_url'] ?? null, $logo)) {
                        $updates[] = "logo_url = ?";
                        $params[] = $logo;
                    }

                    $params[] = $team['id'];
                    $update = $this->pdo->prepare("UPDATE teams SET " . implode(', ', $updates) . " WHERE id = ?");
                    $update->execute($params);
                    $team['external_id'] = $externalId;
                    $team['name'] = $name;
                    $team['logo_url'] = $logo;
                    return $team;
                }
            }

            $stmt = $this->pdo->prepare("INSERT INTO teams (name, logo_url, external_id) VALUES (?, ?, ?)");
            $stmt->execute([$name, $logo, $externalId]);
            $id = $this->pdo->lastInsertId();
            return [
                'id' => $id,
                'name' => $name,
                'name_ar' => null,
                'name_en' => null,
                'logo_url' => $logo,
                'external_id' => $externalId
            ];
        }

        if ($logo) {
            $team = $this->findTeamByNameAndLogo($name, $logo);
            if ($team) {
                return $team;
            }
        }

        // Try exact match first (fast path)
        $stmt = $this->pdo->prepare("SELECT * FROM teams WHERE name = ?");
        $stmt->execute([$name]);
        $team = $stmt->fetch();

        if ($team && $this->teamLogoConflicts($team['logo_url'] ?? null, $logo)) {
            $team = null;
        }

        if (!$team) {
            // Try exact match on name_ar or name_en
            $stmt = $this->pdo->prepare("SELECT * FROM teams WHERE name_ar = ? OR name_en = ?");
            $stmt->execute([$name, $name]);
            $team = $stmt->fetch();

            if ($team && $this->teamLogoConflicts($team['logo_url'] ?? null, $logo)) {
                $team = null;
            }
        }

        if (!$team) {
            // Try fuzzy matching for similar names
            $team = $this->findTeamFuzzy($name);
            if ($team && $this->teamLogoConflicts($team['logo_url'] ?? null, $logo)) {
                $team = null;
            }
        }

        if ($team) {
            // Update logo if provided and currently empty
            if ($logo && empty($team['logo_url'])) {
                $update = $this->pdo->prepare("UPDATE teams SET logo_url = ? WHERE id = ?");
                $update->execute([$logo, $team['id']]);
                $team['logo_url'] = $logo;
            }
            return $team;
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO teams (name, logo_url) VALUES (?, ?)");
            $stmt->execute([$name, $logo]);
            $id = $this->pdo->lastInsertId();
            return [
                'id' => $id,
                'name' => $name,
                'name_ar' => null,
                'name_en' => null,
                'logo_url' => $logo
            ];
        }
    }

    private function extractExternalMatchIdentifier(array $matchData) {
        $explicit = trim((string)($matchData['external_id'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $candidates = [
            $matchData['match_url'] ?? null,
            $matchData['detail_url'] ?? null,
            $matchData['live_url'] ?? null
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }

            if (preg_match('~ysscores\.com/.*/match/(\d+)~i', $candidate, $matches)) {
                return 'yss:' . $matches[1];
            }

            if (preg_match('~kooora\.com/\?m=(\d+)~i', $candidate, $matches)) {
                return 'kooora:' . $matches[1];
            }

            if (preg_match('~[?&]m=(\d+)~', $candidate, $matches)) {
                return 'mid:' . $matches[1];
            }
        }

        return null;
    }

    private function hasMeaningfulMatchValue($value) {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' && $trimmed !== '[]' && $trimmed !== '{}';
        }

        return true;
    }

    private function calculateMatchRowCompletenessScore(array $match) {
        $score = 0;
        $importantFields = [
            'match_url', 'detail_url', 'start_time', 'details_match_time', 'channel', 'channel_logo',
            'channels_data', 'stadium_name', 'referee', 'match_summary', 'lineup', 'lineup_home',
            'lineup_away', 'standings_data', 'statistics_data', 'previous_matches_data', 'events',
            'live_url', 'live_iframe', 'external_id'
        ];

        foreach ($importantFields as $field) {
            if ($this->hasMeaningfulMatchValue($match[$field] ?? null)) {
                $score += 10;
            }
        }

        if (($match['status'] ?? '') === 'Live') {
            $score += 8;
        } elseif (($match['status'] ?? '') === 'Finished') {
            $score += 6;
        }

        if ($this->hasMeaningfulMatchValue($match['live_iframe'] ?? null)) {
            $score += 25;
        } elseif ($this->hasMeaningfulMatchValue($match['live_url'] ?? null)) {
            $score += 10;
        }

        return $score;
    }

    private function sortDuplicateMatchCandidates(array $matches) {
        usort($matches, function ($a, $b) {
            $updatedA = strtotime((string)($a['updated_at'] ?? '')) ?: 0;
            $updatedB = strtotime((string)($b['updated_at'] ?? '')) ?: 0;
            if ($updatedA !== $updatedB) {
                return $updatedB <=> $updatedA;
            }

            $scoreA = $this->calculateMatchRowCompletenessScore($a);
            $scoreB = $this->calculateMatchRowCompletenessScore($b);
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });

        return $matches;
    }

    private function findPotentialDuplicateMatches($homeTeamId, $awayTeamId, $matchDate, $matchUrl = null, $externalId = null, $utcStartTime = null) {
        $conditions = [];
        $params = [];

        if ($externalId !== null && $externalId !== '') {
            $conditions[] = "external_id = ?";
            $params[] = $externalId;
        }

        if ($matchUrl !== null && $matchUrl !== '') {
            $conditions[] = "match_url = ?";
            $params[] = $matchUrl;
        }

        if ($homeTeamId !== null && $awayTeamId !== null && !empty($matchDate)) {
            $conditions[] = "(home_team_id = ? AND away_team_id = ? AND match_date = ?)";
            $params[] = $homeTeamId;
            $params[] = $awayTeamId;
            $params[] = $matchDate;
        }

        if ($homeTeamId !== null && $awayTeamId !== null && $utcStartTime !== null) {
            $conditions[] = "(home_team_id = ? AND away_team_id = ? AND start_time = ?)";
            $params[] = $homeTeamId;
            $params[] = $awayTeamId;
            $params[] = $utcStartTime;
        }

        if (empty($conditions)) {
            return [];
        }

        $sql = "SELECT * FROM matches WHERE " . implode(' OR ', $conditions) . " ORDER BY updated_at DESC, id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function mergeDuplicateMatchRows(array $keeper, array $matches) {
        $merged = $keeper;
        $mergeFields = [
            'external_id', 'start_time', 'match_url', 'detail_url', 'details_match_time',
            'channels_data', 'match_summary', 'lineup', 'lineup_home', 'lineup_away',
            'standings_data', 'statistics_data', 'previous_matches_data', 'stadium_name',
            'referee', 'channel', 'channel_logo', 'commentator', 'events', 'last_scrape_attempt'
        ];

        if (($keeper['status'] ?? '') !== 'Finished') {
            $mergeFields[] = 'live_url';
            $mergeFields[] = 'live_iframe';
        }

        foreach ($matches as $match) {
            if ((int)($match['id'] ?? 0) === (int)($keeper['id'] ?? 0)) {
                continue;
            }

            foreach ($mergeFields as $field) {
                if (
                    !$this->hasMeaningfulMatchValue($merged[$field] ?? null) &&
                    $this->hasMeaningfulMatchValue($match[$field] ?? null)
                ) {
                    $merged[$field] = $match[$field];
                }
            }
        }

        return $merged;
    }

    private function persistMergedMatchRow($matchId, array $merged) {
        $fields = [
            'external_id', 'start_time', 'match_url', 'detail_url', 'details_match_time',
            'channels_data', 'match_summary', 'lineup', 'lineup_home', 'lineup_away',
            'standings_data', 'statistics_data', 'previous_matches_data', 'stadium_name',
            'referee', 'channel', 'channel_logo', 'commentator', 'events', 'last_scrape_attempt',
            'live_url', 'live_iframe'
        ];

        $updates = [];
        $params = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $merged)) {
                $updates[] = "{$field} = ?";
                $params[] = $merged[$field];
            }
        }

        if (empty($updates)) {
            return;
        }

        $params[] = $matchId;
        $sql = "UPDATE matches SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function deleteDuplicateMatchesByIds(array $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("DELETE FROM match_lineups WHERE match_id IN ($placeholders)")->execute($ids);
        $this->pdo->prepare("DELETE FROM ai_cache WHERE match_id IN ($placeholders)")->execute($ids);
        $this->pdo->prepare("DELETE FROM matches WHERE id IN ($placeholders)")->execute($ids);
    }

    private function resolveCanonicalExistingMatch($homeTeamId, $awayTeamId, $matchDate, $matchUrl = null, $externalId = null, $utcStartTime = null) {
        $candidates = $this->findPotentialDuplicateMatches($homeTeamId, $awayTeamId, $matchDate, $matchUrl, $externalId, $utcStartTime);
        if (empty($candidates)) {
            return null;
        }

        $sorted = $this->sortDuplicateMatchCandidates($candidates);
        $keeper = $sorted[0];

        if (count($sorted) > 1) {
            $merged = $this->mergeDuplicateMatchRows($keeper, $sorted);
            $this->persistMergedMatchRow($keeper['id'], $merged);

            $duplicateIds = [];
            foreach ($sorted as $row) {
                if ((int)($row['id'] ?? 0) !== (int)$keeper['id']) {
                    $duplicateIds[] = (int)$row['id'];
                }
            }
            $this->deleteDuplicateMatchesByIds($duplicateIds);
            $keeper = array_merge($keeper, $merged);
        }

        return $keeper;
    }

    public function saveMatch($matchData) {
        $this->syncLeague($matchData['league'], $matchData['league_logo'] ?? null, $matchData['league_country'] ?? null); // Sync league
        $homeTeam = $this->getOrCreateTeam($matchData['home_team'], $matchData['home_team_logo'] ?? null, $matchData['home_team_external_id'] ?? null);
        $awayTeam = $this->getOrCreateTeam($matchData['away_team'], $matchData['away_team_logo'] ?? null, $matchData['away_team_external_id'] ?? null);

        $homeTeamId = $homeTeam['id'] ?? null;
        $awayTeamId = $awayTeam['id'] ?? null;
        $normalizedMatchTime = $this->normalizePersistedMatchTime($matchData['status'] ?? '', $matchData['match_time'] ?? '');
        $scheduledTime = $this->extractScheduledTimeFromData($matchData);
        $utcStartTime = $this->buildUtcStartTime($matchData['match_date'] ?? null, $scheduledTime);
        $externalId = $this->extractExternalMatchIdentifier($matchData);
        $matchUrl = trim((string)($matchData['match_url'] ?? '')) ?: null;
        $incomingStatus = trim((string)($matchData['status'] ?? ''));

        // Check if match exists using stable identifiers first, then fallback to teams/date.
        $existingMatch = $this->resolveCanonicalExistingMatch(
            $homeTeamId,
            $awayTeamId,
            $matchData['match_date'] ?? null,
            $matchUrl,
            $externalId,
            $utcStartTime
        );

        if ($existingMatch) {
            // Update existing match
            // Build query dynamically to exclude match_time if it is 'Fin' or empty for finished matches
            $updates = [
                "external_id = ?",
                "home_team_id = ?",
                "away_team_id = ?",
                "status = ?", 
                "score_home = ?", 
                "score_away = ?", 
                "league_name = ?"
            ];
            $params = [
                $externalId,
                $homeTeamId,
                $awayTeamId,
                $matchData['status'], 
                $matchData['score_home'], 
                $matchData['score_away'], 
                $matchData['league']
            ];

            // Only update time if we have a valid time (not 'Fin' or empty), OR if we really want to update it
            // Assuming 'Fin' or empty means "we don't have the time but we know it's finished"
            if ($normalizedMatchTime !== '' && $normalizedMatchTime !== 'Fin' && $normalizedMatchTime !== 'Finished') {
                $updates[] = "match_time = ?";
                $params[] = $normalizedMatchTime;
            }

            if ($utcStartTime !== null) {
                $updates[] = "start_time = ?";
                $params[] = $utcStartTime;
            }

            if ($scheduledTime !== null) {
                $updates[] = "details_match_time = ?";
                $params[] = $scheduledTime;
            }

            if ($matchData['status'] === 'Finished' || $matchData['status'] === 'إنتهت' || !$this->isLiveStatusValue($incomingStatus)) {
                $updates[] = "live_url = NULL";
                $updates[] = "live_iframe = NULL";
            } else {
                if (!empty($matchData['live_url'])) {
                    $updates[] = "live_url = ?";
                    $params[] = $matchData['live_url'];
                }
                if (!empty($matchData['live_iframe'])) {
                    $updates[] = "live_iframe = ?";
                    $params[] = $matchData['live_iframe'];
                }
            }
            if (!empty($matchData['match_url'])) {
                $updates[] = "match_url = ?";
                $params[] = $matchData['match_url'];
            }
            if (!empty($matchData['channel'])) {
                $updates[] = "channel = ?";
                $params[] = $matchData['channel'];
            }
            if (!empty($matchData['channel_logo'])) {
                $updates[] = "channel_logo = ?";
                $params[] = $matchData['channel_logo'];
            }
            
            $sql = "UPDATE matches SET " . implode(', ', $updates) . " WHERE id = ?";
            $params[] = $existingMatch['id'];
            
            $update = $this->pdo->prepare($sql);
            $update->execute($params);
            $matchId = $existingMatch['id'];
        } else {
            // Insert new match
            $insert = $this->pdo->prepare("INSERT INTO matches (home_team_id, away_team_id, start_time, match_time, match_date, status, score_home, score_away, league_name, external_id, live_url, live_iframe, match_url, channel, channel_logo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert->execute([
                $homeTeamId, 
                $awayTeamId, 
                $utcStartTime,
                $normalizedMatchTime,
                $matchData['match_date'], 
                $matchData['status'], 
                $matchData['score_home'], 
                $matchData['score_away'], 
                $matchData['league'],
                $externalId,
                $this->isLiveStatusValue($incomingStatus) ? ($matchData['live_url'] ?? null) : null,
                $this->isLiveStatusValue($incomingStatus) ? ($matchData['live_iframe'] ?? null) : null,
                $matchData['match_url'] ?? null,
                $matchData['channel'] ?? null,
                $matchData['channel_logo'] ?? null
            ]);
            $matchId = $this->pdo->lastInsertId();
        }

        // Sync with channels table
        $match = $this->getMatchById($matchId);
        if ($match && !empty($match['channel'])) {
            $stream = !empty($match['live_iframe']) ? $match['live_iframe'] : $match['live_url'];
            if (!empty($stream)) {
                $this->syncChannelStream($match['channel'], $stream, $match['channel_logo']);
            }
        }

        return $matchId;
    }

    public function getMatches($date = null) {
        if (!$date) $date = date('Y-m-d');
        
        $sql = "SELECT m.*, 
                t1.name as home_team, t1.name_ar as home_team_ar, t1.name_en as home_team_en, t1.logo_url as home_logo,
                t2.name as away_team, t2.name_ar as away_team_ar, t2.name_en as away_team_en, t2.logo_url as away_logo,
                l.name_ar as league_name_ar, l.name_en as league_name_en, l.logo_url as league_logo, l.country as league_country
                FROM matches m
                JOIN teams t1 ON m.home_team_id = t1.id
                JOIN teams t2 ON m.away_team_id = t2.id
                LEFT JOIN leagues l ON m.league_name = l.name
                LEFT JOIN countries c ON l.country = c.name
                WHERE m.match_date = ?
                AND (l.is_active = 1 OR l.is_active IS NULL)
                AND (c.is_active = 1 OR c.is_active IS NULL)
                ORDER BY m.match_time ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date]);
        return $stmt->fetchAll();
    }

    public function getMatchById($id) {
        $sql = "SELECT m.*, 
                t1.name as home_team, t1.name_ar as home_team_ar, t1.name_en as home_team_en, t1.logo_url as home_logo,
                t2.name as away_team, t2.name_ar as away_team_ar, t2.name_en as away_team_en, t2.logo_url as away_logo,
                l.name_ar as league_name_ar, l.name_en as league_name_en, l.logo_url as league_logo, l.country as league_country
                FROM matches m
                JOIN teams t1 ON m.home_team_id = t1.id
                JOIN teams t2 ON m.away_team_id = t2.id
                LEFT JOIN leagues l ON m.league_name = l.name
                WHERE m.id = ?";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function findMatchByNames($homeTeam, $awayTeam, $date) {
        $sql = "SELECT m.*, 
                t1.name as home_team, t1.name_ar as home_team_ar, t1.name_en as home_team_en, t1.logo_url as home_logo,
                t2.name as away_team, t2.name_ar as away_team_ar, t2.name_en as away_team_en, t2.logo_url as away_logo
                FROM matches m
                JOIN teams t1 ON m.home_team_id = t1.id
                JOIN teams t2 ON m.away_team_id = t2.id
                WHERE t1.name = ? AND t2.name = ? AND m.match_date = ?";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$homeTeam, $awayTeam, $date]);
        return $stmt->fetch();
    }

    // Admin Methods
    public function getAllMatches($date = null, $limit = null, $offset = 0, $search = null) {
        $sql = "SELECT m.*, 
                t1.name as home_team, t1.name_ar as home_team_ar, t1.name_en as home_team_en, t1.logo_url as home_logo,
                t2.name as away_team, t2.name_ar as away_team_ar, t2.name_en as away_team_en, t2.logo_url as away_logo
                FROM matches m
                LEFT JOIN teams t1 ON m.home_team_id = t1.id
                LEFT JOIN teams t2 ON m.away_team_id = t2.id";
        
        $params = [];
        $where = [];

        if ($date === 'missing') {
            $where[] = "(m.home_team_id IS NULL OR m.away_team_id IS NULL OR m.league_id IS NULL)";
        } elseif ($date) {
            $where[] = "m.match_date = ?";
            $params[] = $date;
        }

        if ($search) {
            $where[] = "(t1.name LIKE ? OR t2.name LIKE ? OR m.league_name LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY m.match_date DESC, m.match_time DESC";

        if ($limit !== null) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = (int)$limit;
            $params[] = (int)$offset;
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getMatchesCount($date = null, $search = null) {
        $sql = "SELECT COUNT(*) FROM matches m
                LEFT JOIN teams t1 ON m.home_team_id = t1.id
                LEFT JOIN teams t2 ON m.away_team_id = t2.id";
        $params = [];
        $where = [];

        if ($date === 'missing') {
            $where[] = "(m.home_team_id IS NULL OR m.away_team_id IS NULL OR m.league_id IS NULL)";
        } elseif ($date) {
            $where[] = "m.match_date = ?";
            $params[] = $date;
        }

        if ($search) {
            $where[] = "(t1.name LIKE ? OR t2.name LIKE ? OR m.league_name LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function deleteMatch($id) {
        // Delete related data first to ensure clean state
        // (Even though database might have ON DELETE CASCADE, manual delete is safer)
        $this->pdo->prepare("DELETE FROM match_lineups WHERE match_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM ai_cache WHERE match_id = ?")->execute([$id]);
        
        // Delete the match
        $stmt = $this->pdo->prepare("DELETE FROM matches WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function updateMatch($id, $data) {
        // Resolve Team IDs (Create if not exists, similar to saveMatch)
        $homeTeam = $this->getOrCreateTeam($data['home_team'], $data['home_team_logo'] ?? null, $data['home_team_external_id'] ?? null);
        $awayTeam = $this->getOrCreateTeam($data['away_team'], $data['away_team_logo'] ?? null, $data['away_team_external_id'] ?? null);
        
        // getOrCreateTeam returns full array, so extract ID
        $homeTeamId = is_array($homeTeam) ? ($homeTeam['id'] ?? null) : $homeTeam;
        $awayTeamId = is_array($awayTeam) ? ($awayTeam['id'] ?? null) : $awayTeam;
        $normalizedMatchTime = $this->normalizePersistedMatchTime($data['status'] ?? '', $data['match_time'] ?? '');
        $scheduledTime = $this->extractScheduledTimeFromData($data);
        $utcStartTime = $this->buildUtcStartTime($data['match_date'] ?? null, $scheduledTime);
        $externalId = $this->extractExternalMatchIdentifier($data);
        $incomingStatus = trim((string)($data['status'] ?? ''));

        $updates = [
            "home_team_id = ?", 
            "away_team_id = ?",
            "match_date = ?", 
            "match_time = ?", 
            "status = ?", 
            "score_home = ?", 
            "score_away = ?", 
            "league_name = ?",
            "external_id = ?"
        ];
        $params = [
            $homeTeamId, 
            $awayTeamId,
            $data['match_date'], 
            $normalizedMatchTime,
            $data['status'],
            $data['score_home'], 
            $data['score_away'], 
            $data['league_name'],
            $externalId
        ];

        if ($data['status'] === 'Finished' || $data['status'] === 'إنتهت' || !$this->isLiveStatusValue($incomingStatus)) {
            $updates[] = "live_url = NULL";
            $updates[] = "live_iframe = NULL";
        } else {
            if (isset($data['live_url'])) {
                $updates[] = "live_url = ?";
                $params[] = $data['live_url'];
            }
            if (isset($data['live_iframe'])) {
                $updates[] = "live_iframe = ?";
                $params[] = $data['live_iframe'];
            }
        }
        if ($utcStartTime !== null) {
            $updates[] = "start_time = ?";
            $params[] = $utcStartTime;
        }
        if ($scheduledTime !== null) {
            $updates[] = "details_match_time = ?";
            $params[] = $scheduledTime;
        }
        if (isset($data['match_url'])) {
            $updates[] = "match_url = ?";
            $params[] = $data['match_url'];
        }
        if (isset($data['channel'])) {
            $updates[] = "channel = ?";
            $params[] = $data['channel'];
        }
        if (isset($data['channel_logo'])) {
            $updates[] = "channel_logo = ?";
            $params[] = $data['channel_logo'];
        }

        $sql = "UPDATE matches SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $id;

        $stmt = $this->pdo->prepare($sql);
        $res = $stmt->execute($params);
        
        // Sync with channels table
        if ($res) {
            $match = $this->getMatchById($id);
            if ($match && !empty($match['channel'])) {
                $stream = !empty($match['live_iframe']) ? $match['live_iframe'] : $match['live_url'];
                if (!empty($stream)) {
                    $this->syncChannelStream($match['channel'], $stream, $match['channel_logo']);
                }
            }
        }
        
        return $res;
    }

    public function getAllTeams($date = null, $limit = null, $offset = 0, $search = null, $filterType = 'all') {
        $sql = "SELECT DISTINCT t.* FROM teams t";
        $params = [];
        $where = [];

        if ($date) {
            $sql .= " JOIN matches m ON (t.id = m.home_team_id OR t.id = m.away_team_id)";
            $where[] = "m.match_date = ?";
            $params[] = $date;
        }

        if ($search) {
            $where[] = "(t.name LIKE ? OR t.name_ar LIKE ? OR t.name_en LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Advanced Filters
        $defaultLogoUrl = 'https://cdn.sportfeeds.io/sdl/images/team/crest/medium/default.png';
        $officialPrefix = 'https://cdn.sportfeeds.io/sdl/images/%';

        if ($filterType === 'default_logo') {
            $where[] = "t.logo_url = ?";
            $params[] = $defaultLogoUrl;
        } elseif ($filterType === 'external_logo') {
            $where[] = "t.logo_url NOT LIKE ? AND t.logo_url != ? AND t.logo_url IS NOT NULL AND t.logo_url != ''";
            $params[] = $officialPrefix;
            $params[] = $defaultLogoUrl;
        } elseif ($filterType === 'both') {
            $where[] = "t.name_ar IS NOT NULL AND t.name_ar != '' AND t.name_en IS NOT NULL AND t.name_en != ''";
        } elseif ($filterType === 'ar') {
            $where[] = "t.name_ar IS NOT NULL AND t.name_ar != ''";
        } elseif ($filterType === 'en') {
            $where[] = "t.name_en IS NOT NULL AND t.name_en != ''";
        } elseif ($filterType === 'missing') {
            $where[] = "(t.name_ar IS NULL OR t.name_ar = '' OR t.name_en IS NULL OR t.name_en = '')";
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY t.name ASC";

        if ($limit !== null) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = (int)$limit;
            $params[] = (int)$offset;
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getTeamsCount($date = null, $search = null, $filterType = 'all') {
        $sql = "SELECT COUNT(DISTINCT t.id) FROM teams t";
        $params = [];
        $where = [];

        if ($date) {
            $sql .= " JOIN matches m ON (t.id = m.home_team_id OR t.id = m.away_team_id)";
            $where[] = "m.match_date = ?";
            $params[] = $date;
        }

        if ($search) {
            $where[] = "(t.name LIKE ? OR t.name_ar LIKE ? OR t.name_en LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Advanced Filters
        $defaultLogoUrl = 'https://cdn.sportfeeds.io/sdl/images/team/crest/medium/default.png';
        $officialPrefix = 'https://cdn.sportfeeds.io/sdl/images/%';

        if ($filterType === 'default_logo') {
            $where[] = "t.logo_url = ?";
            $params[] = $defaultLogoUrl;
        } elseif ($filterType === 'external_logo') {
            $where[] = "t.logo_url NOT LIKE ? AND t.logo_url != ? AND t.logo_url IS NOT NULL AND t.logo_url != ''";
            $params[] = $officialPrefix;
            $params[] = $defaultLogoUrl;
        } elseif ($filterType === 'both') {
            $where[] = "t.name_ar IS NOT NULL AND t.name_ar != '' AND t.name_en IS NOT NULL AND t.name_en != ''";
        } elseif ($filterType === 'ar') {
            $where[] = "t.name_ar IS NOT NULL AND t.name_ar != ''";
        } elseif ($filterType === 'en') {
            $where[] = "t.name_en IS NOT NULL AND t.name_en != ''";
        } elseif ($filterType === 'missing') {
            $where[] = "(t.name_ar IS NULL OR t.name_ar = '' OR t.name_en IS NULL OR t.name_en = '')";
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function updateTeam($id, $logo, $nameEn = null, $nameAr = null) {
        $stmt = $this->pdo->prepare("UPDATE teams SET logo_url = ?, name_en = ?, name_ar = ? WHERE id = ?");
        return $stmt->execute([$logo, $nameEn, $nameAr, $id]);
    }

    public function addTeam($name, $logo = null, $nameEn = null, $nameAr = null) {
        $stmt = $this->pdo->prepare("INSERT INTO teams (name, logo_url, name_en, name_ar) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$name, $logo, $nameEn, $nameAr]);
    }

    public function deleteTeam($id) {
        try {
            $this->pdo->beginTransaction();

            // 1. Get all match IDs involving this team
            $stmt = $this->pdo->prepare("SELECT id FROM matches WHERE home_team_id = ? OR away_team_id = ?");
            $stmt->execute([$id, $id]);
            $matchIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($matchIds)) {
                $inQuery = implode(',', array_fill(0, count($matchIds), '?'));
                
                // 2. Delete all related data for these matches
                $this->pdo->prepare("DELETE FROM match_lineups WHERE match_id IN ($inQuery)")->execute($matchIds);
                $this->pdo->prepare("DELETE FROM ai_cache WHERE match_id IN ($inQuery)")->execute($matchIds);

                // 3. Delete the matches
                $this->pdo->prepare("DELETE FROM matches WHERE id IN ($inQuery)")->execute($matchIds);
            }

            // 4. Delete the team
            $stmt = $this->pdo->prepare("DELETE FROM teams WHERE id = ?");
            $stmt->execute([$id]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Log error or re-throw
            error_log("Error deleting team: " . $e->getMessage());
            return false;
        }
    }

    public function getStats() {
        $today = date('Y-m-d');
        return [
            'total_matches' => $this->pdo->query("SELECT COUNT(*) FROM matches")->fetchColumn(),
            'live_matches' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE status = 'Live'")->fetchColumn(),
            'teams_count' => $this->pdo->query("SELECT COUNT(*) FROM teams")->fetchColumn(),
            'leagues_count' => $this->pdo->query("SELECT COUNT(*) FROM leagues")->fetchColumn(),
            'countries_count' => $this->pdo->query("SELECT COUNT(*) FROM countries")->fetchColumn(),
            'today_matches' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$today}'")->fetchColumn(),
            'today_live' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$today}' AND status = 'Live'")->fetchColumn(),
            'today_finished' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$today}' AND status = 'Finished'")->fetchColumn(),
            'today_scheduled' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$today}' AND (status != 'Live' AND status != 'Finished')")->fetchColumn()
        ];
    }

    public function updateMatchLineup($matchId, $homeLineup, $awayLineup) {
        $stmt = $this->pdo->prepare("UPDATE matches SET lineup_home = ?, lineup_away = ? WHERE id = ?");
        return $stmt->execute([$homeLineup, $awayLineup, $matchId]);
    }

    public function getTodayStats() {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $after10Days = date('Y-m-d', strtotime('+10 days'));

        return [
            'total' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$today}'")->fetchColumn(),
            'with_summary' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$today}' AND (channels_data IS NOT NULL AND channels_data != '' OR stadium_name IS NOT NULL AND stadium_name != '')")->fetchColumn(),
            'with_live' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$today}' AND (live_url IS NOT NULL OR live_iframe IS NOT NULL)")->fetchColumn(),
            'with_lineups' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$today}' AND lineup_home IS NOT NULL AND lineup_home != ''")->fetchColumn(),
            'with_stats' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$today}' AND statistics_data IS NOT NULL AND statistics_data != ''")->fetchColumn(),
            'with_events' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$today}' AND events IS NOT NULL AND events != '[]' AND events != ''")->fetchColumn(),
            'with_previous_matches' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$today}' AND previous_matches_data IS NOT NULL AND previous_matches_data != ''")->fetchColumn(),
            'with_standings' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$today}' AND standings_data IS NOT NULL AND standings_data != ''")->fetchColumn(),
            'tomorrow_matches' => $this->pdo->query("SELECT COUNT(*) FROM matches WHERE match_date = '{$tomorrow}'")->fetchColumn(),
            'next_10_days_coverage' => $this->pdo->query("SELECT COUNT(DISTINCT match_date) FROM matches WHERE match_date >= '{$tomorrow}' AND match_date <= '{$after10Days}'")->fetchColumn()
        ];
    }

    public function updateMatchSummary($matchId, $detailUrl, $summary) {
        $stmt = $this->pdo->prepare("UPDATE matches SET detail_url = ?, match_summary = ? WHERE id = ?");
        return $stmt->execute([$detailUrl, $summary, $matchId]);
    }

    public function updateMatchLive($matchId, $liveUrl, $liveIframe) {
        $match = $this->getMatchById($matchId);
        if ($match && ($match['status'] === 'Finished' || $match['status'] === 'إنتهت')) {
            $liveUrl = NULL;
            $liveIframe = NULL;
        }

        if ($match && !$this->isLiveStatusValue($match['status'] ?? '')) {
            $liveUrl = NULL;
            $liveIframe = NULL;
        }

        $stmt = $this->pdo->prepare("UPDATE matches SET live_url = ?, live_iframe = ? WHERE id = ?");
        $res = $stmt->execute([$liveUrl, $liveIframe, $matchId]);
        
        // Sync with channels table
        if ($res) {
            $match = $this->getMatchById($matchId);
            if ($match && !empty($match['channel'])) {
                $stream = !empty($liveIframe) ? $liveIframe : $liveUrl;
                if (!empty($stream)) {
                    $this->syncChannelStream($match['channel'], $stream, $match['channel_logo']);
                }
            }
        }
        
        return $res;
    }

    /**
     * حفظ أحداث المباراة
     */
    public function saveMatchEvents($matchId, $events) {
        try {
            $eventsJson = json_encode($events, JSON_UNESCAPED_UNICODE);
            $stmt = $this->pdo->prepare("UPDATE matches SET events = ? WHERE id = ?");
            $result = $stmt->execute([$eventsJson, $matchId]);
            
            if ($result) {
                error_log("Successfully saved events for match {$matchId}");
                return true;
            }
            return false;
        } catch (PDOException $e) {
            error_log("Error saving match events: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * جلب أحداث المباراة
     */
    public function getMatchEvents($matchId) {
        $stmt = $this->pdo->prepare("SELECT events FROM matches WHERE id = ?");
        $stmt->execute([$matchId]);
        $eventsJson = $stmt->fetchColumn();
        
        if ($eventsJson) {
            $events = json_decode($eventsJson, true);
            // Sort events by event_order if possible or leave them as is
            return is_array($events) ? $events : [];
        }
        return [];
    }

    /**
     * التحقق من وجود أحداث للمباراة
     */
    public function hasMatchEvents($matchId) {
        $stmt = $this->pdo->prepare("SELECT events FROM matches WHERE id = ?");
        $stmt->execute([$matchId]);
        $events = $stmt->fetchColumn();
        return !empty($events) && $events !== '[]';
    }

    /**
     * إنشاء مفتاح API جديد
     */
    private function getDatabaseSchemaName() {
        static $schemaName = null;

        if ($schemaName !== null) {
            return $schemaName;
        }

        try {
            $schemaName = (string)($this->pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $schemaName = '';
        }

        return $schemaName;
    }

    private function tableColumnExists($tableName, $columnName) {
        $schemaName = $this->getDatabaseSchemaName();
        if ($schemaName === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$schemaName, $tableName, $columnName]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function ensureTableColumn($tableName, $columnName, $definition) {
        try {
            if ($this->tableColumnExists($tableName, $columnName)) {
                return;
            }

            $this->pdo->exec(
                'ALTER TABLE `' . $tableName . '` ADD COLUMN `' . $columnName . '` ' . $definition
            );
        } catch (Throwable $e) {
            // Ignore schema migration races and keep runtime working.
        }
    }

    private function ensureApiOwnerColumns() {
        $this->ensureTableColumn('api_keys', 'owner_platform', "VARCHAR(40) NULL DEFAULT NULL");
        $this->ensureTableColumn('api_keys', 'owner_user_id', "INT NULL DEFAULT NULL");
        $this->ensureTableColumn('api_keys', 'owner_email', "VARCHAR(190) NULL DEFAULT NULL");
        $this->ensureTableColumn('api_keys', 'owner_name', "VARCHAR(190) NULL DEFAULT NULL");
        $this->ensureTableColumn('api_keys', 'owner_plan_slug', "VARCHAR(170) NULL DEFAULT NULL");
        $this->ensureTableColumn('api_keys', 'owner_plan_name', "VARCHAR(190) NULL DEFAULT NULL");
        $this->ensureTableColumn('api_keys', 'owner_resource_id', "VARCHAR(120) NULL DEFAULT NULL");
    }

    private function ensureApiFilterColumns() {
        $this->ensureTableColumn('api_keys', 'allowed_country_names', "LONGTEXT NULL");
        $this->ensureTableColumn('api_keys', 'allowed_league_ids', "LONGTEXT NULL");
        $this->ensureTableColumn('api_keys', 'restrict_country_scope', "TINYINT(1) NOT NULL DEFAULT 0");
        $this->ensureTableColumn('api_keys', 'restrict_league_scope', "TINYINT(1) NOT NULL DEFAULT 0");
    }

    private function ensureApiUsageDailyTable() {
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS api_usage_daily (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        api_key TEXT NOT NULL,
                        usage_date TEXT NOT NULL,
                        request_count INTEGER NOT NULL DEFAULT 0,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )
                ");
                $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_api_usage_daily_key_date ON api_usage_daily(api_key, usage_date)");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_usage_daily_date ON api_usage_daily(usage_date)");
                return;
            }

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS api_usage_daily (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    api_key VARCHAR(255) NOT NULL,
                    usage_date DATE NOT NULL,
                    request_count INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_api_usage_daily_key_date (api_key, usage_date),
                    KEY idx_api_usage_daily_date (usage_date),
                    KEY idx_api_usage_daily_key (api_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            // Ignore schema races and keep runtime stable.
        }
    }

    private function decodeJsonStringList($value) {
        if (is_array($value)) {
            $decoded = $value;
        } else {
            $decoded = json_decode((string)$value, true);
        }

        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function decodeJsonIntList($value) {
        if (is_array($value)) {
            $decoded = $value;
        } else {
            $decoded = json_decode((string)$value, true);
        }

        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $item) {
            $item = (int)$item;
            if ($item > 0) {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function encodeJsonStringList(array $values) {
        return json_encode($this->decodeJsonStringList($values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function encodeJsonIntList(array $values) {
        return json_encode($this->decodeJsonIntList($values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function decodeBoolFlag($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }

        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    private function hydrateApiKeyFilterPayload($row) {
        if (!is_array($row)) {
            return $row;
        }

        $row['allowed_country_names'] = $this->decodeJsonStringList($row['allowed_country_names'] ?? []);
        $row['allowed_league_ids'] = $this->decodeJsonIntList($row['allowed_league_ids'] ?? []);
        $row['restrict_country_scope'] = $this->decodeBoolFlag($row['restrict_country_scope'] ?? 0) ? 1 : 0;
        $row['restrict_league_scope'] = $this->decodeBoolFlag($row['restrict_league_scope'] ?? 0) ? 1 : 0;
        $row['origin_is_active'] = (int)($row['origin_is_active'] ?? 1) === 1 ? 1 : 0;

        $expiresAt = trim((string)($row['expires_at'] ?? ''));
        $isExpired = $expiresAt !== '' && strtotime($expiresAt) < time();
        $requestLimit = (int)($row['request_limit'] ?? 0);
        $totalOriginRequests = (int)($row['total_origin_requests'] ?? $row['request_count'] ?? 0);
        $limitReached = $requestLimit > 0 && $totalOriginRequests >= $requestLimit;
        $originEffectiveIsActive = !$isExpired && !$limitReached && ((int)$row['origin_is_active'] === 1);

        $row['origin_is_expired'] = $isExpired ? 1 : 0;
        $row['origin_limit_reached'] = $limitReached ? 1 : 0;
        $row['origin_effective_is_active'] = $originEffectiveIsActive ? 1 : 0;
        $row['effective_is_active'] = ((int)($row['is_active'] ?? 0) === 1 && $originEffectiveIsActive) ? 1 : 0;

        return $row;
    }

    private function hydrateOwnedAllowedOriginPayload($row) {
        if (!is_array($row)) {
            return $row;
        }

        $row['keys_count'] = (int)($row['keys_count'] ?? 0);
        $row['total_requests'] = (int)($row['total_requests'] ?? 0);
        $row['is_active'] = (int)($row['is_active'] ?? 1) === 1 ? 1 : 0;
        $row['request_limit'] = (int)($row['request_limit'] ?? 0);

        $expiresAt = trim((string)($row['expires_at'] ?? ''));
        $isExpired = $expiresAt !== '' && strtotime($expiresAt) < time();
        $limitReached = $row['request_limit'] > 0 && $row['total_requests'] >= $row['request_limit'];
        $effectiveIsActive = !$isExpired && !$limitReached && $row['is_active'] === 1;

        $row['is_expired'] = $isExpired ? 1 : 0;
        $row['limit_reached'] = $limitReached ? 1 : 0;
        $row['effective_is_active'] = $effectiveIsActive ? 1 : 0;

        return $row;
    }

    private function normalizeOptionalDateTimeValue($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeAllowedOriginValue($origin) {
        $origin = trim((string)$origin);
        if ($origin === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $origin)) {
            $origin = 'https://' . ltrim($origin, '/');
        }

        $parts = parse_url($origin);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = strtolower((string)$parts['host']);
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    private function sumOwnedApiRequestsLast24Hours($ownerPlatform, $ownerUserId, $ownerPlanSlug = '') {
        $ownerPlanSlug = trim((string)$ownerPlanSlug);
        $sql = "
            SELECT COUNT(*)
            FROM api_logs_24 logs
            INNER JOIN api_keys api_keys_tbl ON BINARY api_keys_tbl.api_key = BINARY logs.api_key
            WHERE BINARY api_keys_tbl.owner_platform = BINARY ?
              AND api_keys_tbl.owner_user_id = ?
              AND logs.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ";
        $params = [$ownerPlatform, $ownerUserId];
        if ($ownerPlanSlug !== '') {
            $sql .= "
              AND BINARY api_keys_tbl.owner_plan_slug = BINARY ?
            ";
            $params[] = $ownerPlanSlug;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    private function incrementApiUsageDaily($apiKey) {
        $this->ensureApiUsageDailyTable();

        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $today = gmdate('Y-m-d');

            if ($driver === 'sqlite') {
                $stmt = $this->pdo->prepare("
                    INSERT INTO api_usage_daily (api_key, usage_date, request_count, created_at, updated_at)
                    VALUES (?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ON CONFLICT(api_key, usage_date)
                    DO UPDATE SET request_count = request_count + 1, updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$apiKey, $today]);
                return;
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO api_usage_daily (api_key, usage_date, request_count)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    request_count = request_count + 1,
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$apiKey, $today]);
        } catch (Throwable $e) {
            // Keep request logging alive even if rollup update fails.
        }
    }

    private function countOwnedApiRequestsForDate($ownerPlatform, $ownerUserId, $dateValue, $ownerPlanSlug = '') {
        $ownerPlanSlug = trim((string)$ownerPlanSlug);
        $sql = "
            SELECT COUNT(*)
            FROM api_logs_24 logs
            INNER JOIN api_keys api_keys_tbl ON BINARY api_keys_tbl.api_key = BINARY logs.api_key
            WHERE BINARY api_keys_tbl.owner_platform = BINARY ?
              AND api_keys_tbl.owner_user_id = ?
              AND DATE(logs.created_at) = ?
        ";
        $params = [$ownerPlatform, $ownerUserId, $dateValue];
        if ($ownerPlanSlug !== '') {
            $sql .= "
              AND BINARY api_keys_tbl.owner_plan_slug = BINARY ?
            ";
            $params[] = $ownerPlanSlug;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function generateApiKey($name, $allowedOriginId = null) {
        $apiKey = bin2hex(random_bytes(32)); // 64 character hex string
        $stmt = $this->pdo->prepare("INSERT INTO api_keys (api_key, name, is_active, allowed_origin_id) VALUES (?, ?, 1, ?)");
        $stmt->execute([$apiKey, $name, $allowedOriginId]);
        return $apiKey;
    }

    /**
     * جلب جميع مفاتيح API
     */
    public function getApiKeys() {
        $this->ensureApiOwnerColumns();
        return $this->pdo->query("
            SELECT
                k.*,
                o.origin as allowed_domain
            FROM api_keys k
            LEFT JOIN api_allowed_origins o ON k.allowed_origin_id = o.id
            ORDER BY k.created_at DESC
        ")->fetchAll();
    }

    /**
     * التحقق من صحة مفتاح API
     */
    public function validateApiKey($apiKey, $currentOrigin = null) {
        $this->ensureApiFilterColumns();

        $stmt = $this->pdo->prepare("
            SELECT k.*, o.origin as allowed_origin, o.is_active as origin_is_active, o.expires_at, o.request_limit, o.plan_type,
            (SELECT SUM(request_count) FROM api_keys WHERE allowed_origin_id = o.id) as total_origin_requests
            FROM api_keys k 
            LEFT JOIN api_allowed_origins o ON k.allowed_origin_id = o.id 
            WHERE k.api_key = ? AND k.is_active = 1
        ");
        $stmt->execute([$apiKey]);
        $keyData = $stmt->fetch();

        if (!$keyData) return false;

        // Origin details check
        if (!empty($keyData['allowed_origin_id'])) {
            if ((int)($keyData['origin_is_active'] ?? 1) !== 1) {
                return false; // Origin disabled
            }

            // Check expiry
            if (!empty($keyData['expires_at'])) {
                if (strtotime($keyData['expires_at']) < time()) {
                    return false; // Plan expired
                }
            }

            // Check request limit (if not 0 which means unlimited)
            if (!empty($keyData['request_limit']) && (int)$keyData['request_limit'] > 0) {
                if ((int)$keyData['total_origin_requests'] >= (int)$keyData['request_limit']) {
                    return false; // Limit reached
                }
            }
        }

        $apiSettings = $this->getApiSettings();
        $allowAllOrigins = (string)($apiSettings['allow_all_origins'] ?? '0') === '1';
        $isPublicKey = empty($keyData['allowed_origin_id']);

        $keyData['is_public_key'] = $isPublicKey ? 1 : 0;
        $keyData['dev_mode_active'] = $allowAllOrigins ? 1 : 0;

        if ($isPublicKey && !$allowAllOrigins) {
            return false;
        }

        if ($allowAllOrigins && $isPublicKey) {
            $keyData['effective_plan_type'] = 'premium';
            $keyData['plan_source'] = 'dev_mode_public_key';
        } else {
            $planType = trim((string)($keyData['plan_type'] ?? ''));
            $keyData['effective_plan_type'] = $planType !== '' ? $planType : 'free';
            $keyData['plan_source'] = $isPublicKey ? 'public_key_default' : 'allowed_origin';
        }

        $keyData['allowed_country_names'] = $this->decodeJsonStringList($keyData['allowed_country_names'] ?? []);
        $keyData['allowed_league_ids'] = $this->decodeJsonIntList($keyData['allowed_league_ids'] ?? []);
        $keyData['restrict_country_scope'] = $this->decodeBoolFlag($keyData['restrict_country_scope'] ?? 0) ? 1 : 0;
        $keyData['restrict_league_scope'] = $this->decodeBoolFlag($keyData['restrict_league_scope'] ?? 0) ? 1 : 0;

        // Restricted keys must always match their assigned origin.
        if (!$isPublicKey && !empty($keyData['allowed_origin'])) {
            if (empty($currentOrigin)) {
                return false; 
            }
            
            $allowedOrigin = rtrim(trim($keyData['allowed_origin']), '/');
            $requestedOrigin = rtrim(trim($currentOrigin), '/');
            
            if ($allowedOrigin !== $requestedOrigin) {
                return false;
            }
        }

        return $keyData;
    }

    /**
     * تحديث آخر استخدام لمفتاح API
     */
    public function updateApiKeyUsage($apiKey) {
        $stmt = $this->pdo->prepare("UPDATE api_keys SET last_used_at = NOW(), request_count = request_count + 1 WHERE api_key = ?");
        return $stmt->execute([$apiKey]);
    }

    /**
     * تسجيل طلب API في السجل
     */
    public function logApiRequest($apiKey, $endpoint) {
        $ip = $this->resolveApiLogIpAddress();
        $countryCode = $this->resolveApiLogCountryCode($ip);

        $stmt = $this->pdo->prepare("INSERT INTO api_logs_24 (api_key, endpoint, ip_address, country_code) VALUES (?, ?, ?, ?)");
        $logged = $stmt->execute([$apiKey, $endpoint, $ip, $countryCode]);

        if ($logged) {
            $this->incrementApiUsageDaily($apiKey);
        }

        return $logged;
    }

    public function findAllowedOriginByOrigin($origin) {
        $this->ensureApiSettingsTable();

        $normalizedOrigin = $this->normalizeAllowedOriginValue($origin);
        if ($normalizedOrigin === '') {
            return null;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM api_allowed_origins WHERE BINARY origin = BINARY ? LIMIT 1");
        $stmt->execute([$normalizedOrigin]);
        $originRow = $stmt->fetch();

        return $originRow ?: null;
    }

    public function provisionOwnedApiKey($payload) {
        $this->ensureApiSettingsTable();
        $this->ensureApiOwnerColumns();
        $this->ensureApiFilterColumns();

        $ownerPlatform = trim((string)($payload['owner_platform'] ?? 'bloge'));
        $ownerUserId = (int)($payload['owner_user_id'] ?? 0);
        $ownerEmail = strtolower(trim((string)($payload['owner_email'] ?? '')));
        $ownerName = trim((string)($payload['owner_name'] ?? ''));
        $ownerPlanSlug = trim((string)($payload['owner_plan_slug'] ?? ''));
        $ownerPlanName = trim((string)($payload['owner_plan_name'] ?? ''));
        $ownerResourceId = trim((string)($payload['owner_resource_id'] ?? ''));
        $existingKeyId = (int)($payload['existing_key_id'] ?? 0);
        $planType = trim((string)($payload['plan_type'] ?? 'free'));
        $subscriptionExpiresAt = $this->normalizeOptionalDateTimeValue($payload['subscription_expires_at'] ?? null);
        $keyName = trim((string)($payload['key_name'] ?? ''));
        $origin = $this->normalizeAllowedOriginValue($payload['origin'] ?? '');
        $allowedCountryNames = $this->decodeJsonStringList($payload['allowed_country_names'] ?? []);
        $allowedLeagueIds = $this->decodeJsonIntList($payload['allowed_league_ids'] ?? []);
        $restrictCountryScope = $this->decodeBoolFlag($payload['restrict_country_scope'] ?? false);
        $restrictLeagueScope = $this->decodeBoolFlag($payload['restrict_league_scope'] ?? false);

        if ($ownerPlatform === '' || $ownerUserId <= 0 || $ownerPlanSlug === '') {
            throw new InvalidArgumentException('Missing owner or plan information.');
        }

        if ($origin === '') {
            throw new InvalidArgumentException('A valid origin is required to provision API access.');
        }

        if ($keyName === '') {
            $keyName = $ownerPlanName !== '' ? $ownerPlanName : ('API Key ' . $ownerPlanSlug);
        }

        $existingPlanKey = null;
        if ($existingKeyId > 0) {
            $existingKeyStmt = $this->pdo->prepare("
                SELECT k.*, o.origin AS allowed_domain, o.is_active AS origin_is_active, o.plan_type, o.expires_at, o.request_limit
                FROM api_keys k
                LEFT JOIN api_allowed_origins o ON o.id = k.allowed_origin_id
                WHERE k.id = ?
                  AND BINARY k.owner_platform = BINARY ?
                  AND k.owner_user_id = ?
                LIMIT 1
            ");
            $existingKeyStmt->execute([$existingKeyId, $ownerPlatform, $ownerUserId]);
            $existingPlanKey = $existingKeyStmt->fetch();
        }

        if (!$existingPlanKey && $ownerResourceId !== '') {
            $resourceKeyStmt = $this->pdo->prepare("
                SELECT k.*, o.origin AS allowed_domain, o.is_active AS origin_is_active, o.plan_type, o.expires_at, o.request_limit
                FROM api_keys k
                LEFT JOIN api_allowed_origins o ON o.id = k.allowed_origin_id
                WHERE BINARY k.owner_platform = BINARY ?
                  AND k.owner_user_id = ?
                  AND BINARY k.owner_resource_id = BINARY ?
                ORDER BY k.is_active DESC, k.id DESC
                LIMIT 1
            ");
            $resourceKeyStmt->execute([$ownerPlatform, $ownerUserId, $ownerResourceId]);
            $existingPlanKey = $resourceKeyStmt->fetch();
        }

        if (!$existingPlanKey) {
            $planKeyStmt = $this->pdo->prepare("
                SELECT k.*, o.origin AS allowed_domain, o.is_active AS origin_is_active, o.plan_type, o.expires_at, o.request_limit
                FROM api_keys k
                LEFT JOIN api_allowed_origins o ON o.id = k.allowed_origin_id
                WHERE BINARY k.owner_platform = BINARY ?
                  AND k.owner_user_id = ?
                  AND k.owner_plan_slug = ?
                  AND (k.owner_resource_id IS NULL OR k.owner_resource_id = '')
                ORDER BY k.is_active DESC, k.id DESC
                LIMIT 1
            ");
            $planKeyStmt->execute([$ownerPlatform, $ownerUserId, $ownerPlanSlug]);
            $existingPlanKey = $planKeyStmt->fetch();
        }

        $originWasCreated = false;
        $originRow = $this->findAllowedOriginByOrigin($origin);
        if ($originRow) {
            $conflictStmt = $this->pdo->prepare("
                SELECT id
                FROM api_keys
                WHERE allowed_origin_id = ?
                  AND NOT (
                    BINARY owner_platform = BINARY ?
                    AND owner_user_id = ?
                  )
                LIMIT 1
            ");
            $conflictStmt->execute([(int)$originRow['id'], $ownerPlatform, $ownerUserId]);
            if ($conflictStmt->fetch()) {
                throw new RuntimeException('This origin is already linked to another account.');
            }

            if (($originRow['plan_type'] ?? '') !== $planType) {
                $this->updateAllowedOrigin((int)$originRow['id'], $origin, $planType);
                $originRow = $this->findAllowedOriginByOrigin($origin);
            }
        } else {
            $this->addAllowedOrigin($origin, $planType);
            $originWasCreated = true;
            $originRow = $this->findAllowedOriginByOrigin($origin);
        }

        if (!$originRow || empty($originRow['id'])) {
            throw new RuntimeException('Unable to provision the allowed origin.');
        }

        if ($existingPlanKey) {
            $previousOriginId = (int)($existingPlanKey['allowed_origin_id'] ?? 0);
            $existingKeyIsActive = (int)($existingPlanKey['is_active'] ?? 1) === 1 ? 1 : 0;

            if ($originWasCreated && $previousOriginId > 0 && $previousOriginId !== (int)$originRow['id']) {
                $syncOriginStateStmt = $this->pdo->prepare("
                    UPDATE api_allowed_origins
                    SET is_active = ?,
                        expires_at = ?,
                        request_limit = ?
                    WHERE id = ?
                ");
                $syncOriginStateStmt->execute([
                    (int)($existingPlanKey['origin_is_active'] ?? 1) === 1 ? 1 : 0,
                    $subscriptionExpiresAt ?? (!empty($existingPlanKey['expires_at']) ? $existingPlanKey['expires_at'] : null),
                    isset($existingPlanKey['request_limit']) ? (int)$existingPlanKey['request_limit'] : (int)($originRow['request_limit'] ?? 0),
                    (int)$originRow['id'],
                ]);

                $originRow = $this->findAllowedOriginByOrigin($origin);
            }

            if ($subscriptionExpiresAt !== null) {
                $currentOriginExpiresAt = $this->normalizeOptionalDateTimeValue($originRow['expires_at'] ?? null);
                if ($currentOriginExpiresAt !== $subscriptionExpiresAt) {
                    $syncExpiresStmt = $this->pdo->prepare("
                        UPDATE api_allowed_origins
                        SET expires_at = ?
                        WHERE id = ?
                    ");
                    $syncExpiresStmt->execute([
                        $subscriptionExpiresAt,
                        (int)$originRow['id'],
                    ]);

                    $originRow = $this->findAllowedOriginByOrigin($origin);
                }
            }

            $updateStmt = $this->pdo->prepare("
                UPDATE api_keys
                SET name = ?,
                    is_active = ?,
                    allowed_origin_id = ?,
                    owner_email = ?,
                    owner_name = ?,
                    owner_plan_name = ?,
                    owner_resource_id = ?,
                    allowed_country_names = ?,
                    allowed_league_ids = ?,
                    restrict_country_scope = ?,
                    restrict_league_scope = ?
                WHERE id = ?
            ");
            $updateStmt->execute([
                $keyName,
                $existingKeyIsActive,
                (int)$originRow['id'],
                $ownerEmail !== '' ? $ownerEmail : null,
                $ownerName !== '' ? $ownerName : null,
                $ownerPlanName !== '' ? $ownerPlanName : null,
                $ownerResourceId !== '' ? $ownerResourceId : (($existingPlanKey['owner_resource_id'] ?? '') !== '' ? $existingPlanKey['owner_resource_id'] : null),
                $this->encodeJsonStringList($allowedCountryNames),
                $this->encodeJsonIntList($allowedLeagueIds),
                $restrictCountryScope ? 1 : 0,
                $restrictLeagueScope ? 1 : 0,
                (int)$existingPlanKey['id']
            ]);

            if ($ownerResourceId === '') {
                $deactivateStmt = $this->pdo->prepare("
                    UPDATE api_keys
                    SET is_active = 0
                    WHERE BINARY owner_platform = BINARY ?
                      AND owner_user_id = ?
                      AND owner_plan_slug = ?
                      AND (owner_resource_id IS NULL OR owner_resource_id = '')
                      AND id <> ?
                ");
                $deactivateStmt->execute([
                    $ownerPlatform,
                    $ownerUserId,
                    $ownerPlanSlug,
                    (int)$existingPlanKey['id']
                ]);
            }

            if ($previousOriginId > 0 && $previousOriginId !== (int)$originRow['id']) {
                $originUsageStmt = $this->pdo->prepare("SELECT COUNT(*) FROM api_keys WHERE allowed_origin_id = ?");
                $originUsageStmt->execute([$previousOriginId]);
                if ((int)$originUsageStmt->fetchColumn() === 0) {
                    $this->deleteAllowedOrigin($previousOriginId);
                }
            }

            $resultStmt = $this->pdo->prepare("
                SELECT k.*, o.origin AS allowed_domain, o.plan_type, o.expires_at
                FROM api_keys k
                LEFT JOIN api_allowed_origins o ON o.id = k.allowed_origin_id
                WHERE k.id = ?
                LIMIT 1
            ");
            $resultStmt->execute([(int)$existingPlanKey['id']]);
            return $this->hydrateApiKeyFilterPayload($resultStmt->fetch());
        }

        if ($subscriptionExpiresAt !== null) {
            $currentOriginExpiresAt = $this->normalizeOptionalDateTimeValue($originRow['expires_at'] ?? null);
            if ($currentOriginExpiresAt !== $subscriptionExpiresAt) {
                $syncExpiresStmt = $this->pdo->prepare("
                    UPDATE api_allowed_origins
                    SET expires_at = ?
                    WHERE id = ?
                ");
                $syncExpiresStmt->execute([
                    $subscriptionExpiresAt,
                    (int)$originRow['id'],
                ]);

                $originRow = $this->findAllowedOriginByOrigin($origin);
            }
        }

        $apiKey = bin2hex(random_bytes(32));
        $insertStmt = $this->pdo->prepare("
            INSERT INTO api_keys (
                api_key,
                name,
                is_active,
                allowed_origin_id,
                owner_platform,
                owner_user_id,
                owner_email,
                owner_name,
                owner_plan_slug,
                owner_plan_name,
                owner_resource_id,
                allowed_country_names,
                allowed_league_ids,
                restrict_country_scope,
                restrict_league_scope
            ) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([
            $apiKey,
            $keyName,
            (int)$originRow['id'],
            $ownerPlatform,
            $ownerUserId,
            $ownerEmail !== '' ? $ownerEmail : null,
            $ownerName !== '' ? $ownerName : null,
            $ownerPlanSlug,
            $ownerPlanName !== '' ? $ownerPlanName : null,
            $ownerResourceId !== '' ? $ownerResourceId : null,
            $this->encodeJsonStringList($allowedCountryNames),
            $this->encodeJsonIntList($allowedLeagueIds),
            $restrictCountryScope ? 1 : 0,
            $restrictLeagueScope ? 1 : 0
        ]);

        $keyId = (int)$this->pdo->lastInsertId();
        $resultStmt = $this->pdo->prepare("
            SELECT k.*, o.origin AS allowed_domain, o.plan_type, o.expires_at
            FROM api_keys k
            LEFT JOIN api_allowed_origins o ON o.id = k.allowed_origin_id
            WHERE k.id = ?
            LIMIT 1
        ");
        $resultStmt->execute([$keyId]);
        return $this->hydrateApiKeyFilterPayload($resultStmt->fetch());
    }

    public function deleteOwnedApiKey($payload) {
        $this->ensureApiSettingsTable();
        $this->ensureApiOwnerColumns();
        $this->ensureApiFilterColumns();

        $ownerPlatform = trim((string)($payload['owner_platform'] ?? 'bloge'));
        $ownerUserId = (int)($payload['owner_user_id'] ?? 0);
        $ownerResourceId = trim((string)($payload['owner_resource_id'] ?? ''));
        $existingKeyId = (int)($payload['existing_key_id'] ?? 0);

        if ($ownerPlatform === '' || $ownerUserId <= 0) {
            throw new InvalidArgumentException('Missing owner information.');
        }

        $existingKey = null;
        if ($existingKeyId > 0) {
            $stmt = $this->pdo->prepare("
                SELECT * FROM api_keys
                WHERE id = ?
                  AND BINARY owner_platform = BINARY ?
                  AND owner_user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$existingKeyId, $ownerPlatform, $ownerUserId]);
            $existingKey = $stmt->fetch();
        }

        if (!$existingKey && $ownerResourceId !== '') {
            $stmt = $this->pdo->prepare("
                SELECT * FROM api_keys
                WHERE BINARY owner_platform = BINARY ?
                  AND owner_user_id = ?
                  AND BINARY owner_resource_id = BINARY ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$ownerPlatform, $ownerUserId, $ownerResourceId]);
            $existingKey = $stmt->fetch();
        }

        if (!$existingKey) {
            throw new RuntimeException('The selected API key was not found.');
        }

        $keyId = (int)($existingKey['id'] ?? 0);
        $originId = (int)($existingKey['allowed_origin_id'] ?? 0);

        $stmt = $this->pdo->prepare("
            DELETE FROM api_keys
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$keyId]);

        if ($originId > 0) {
            $originUsageStmt = $this->pdo->prepare("SELECT COUNT(*) FROM api_keys WHERE allowed_origin_id = ?");
            $originUsageStmt->execute([$originId]);
            if ((int)$originUsageStmt->fetchColumn() === 0) {
                $this->deleteAllowedOrigin($originId);
            }
        }

        return [
            'key_id' => $keyId,
            'status' => 'deleted',
        ];
    }

    public function getOwnedApiKeys($ownerPlatform, $ownerUserId, $ownerPlanSlug = '') {
        $this->ensureApiOwnerColumns();
        $this->ensureApiFilterColumns();

        $ownerPlanSlug = trim((string)$ownerPlanSlug);
        $sql = "
            SELECT k.*, o.origin AS allowed_domain, o.is_active AS origin_is_active, o.plan_type, o.expires_at, o.request_limit,
                   COALESCE(origin_usage.total_origin_requests, 0) AS total_origin_requests
            FROM api_keys k
            LEFT JOIN api_allowed_origins o ON o.id = k.allowed_origin_id
            LEFT JOIN (
                SELECT allowed_origin_id, SUM(request_count) AS total_origin_requests
                FROM api_keys
                GROUP BY allowed_origin_id
            ) origin_usage ON origin_usage.allowed_origin_id = o.id
            WHERE BINARY k.owner_platform = BINARY ?
              AND k.owner_user_id = ?
            ORDER BY k.created_at DESC
        ";
        $params = [$ownerPlatform, $ownerUserId];
        if ($ownerPlanSlug !== '') {
            $sql = str_replace('ORDER BY k.created_at DESC', "  AND BINARY k.owner_plan_slug = BINARY ?\n            ORDER BY k.created_at DESC", $sql);
            $params[] = $ownerPlanSlug;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row = $this->hydrateApiKeyFilterPayload($row);
        }
        unset($row);

        return $rows;
    }

    public function getOwnedAllowedOrigins($ownerPlatform, $ownerUserId, $ownerPlanSlug = '') {
        $this->ensureApiOwnerColumns();
        $this->ensureApiSettingsTable();

        $ownerPlanSlug = trim((string)$ownerPlanSlug);
        $sql = "
            SELECT
                o.*,
                COUNT(k.id) AS keys_count,
                COALESCE(SUM(k.request_count), 0) AS total_requests
            FROM api_allowed_origins o
            INNER JOIN api_keys k ON k.allowed_origin_id = o.id
            WHERE BINARY k.owner_platform = BINARY ?
              AND k.owner_user_id = ?
            GROUP BY o.id
            ORDER BY o.created_at DESC
        ";
        $params = [$ownerPlatform, $ownerUserId];
        if ($ownerPlanSlug !== '') {
            $sql = str_replace('GROUP BY o.id', "  AND BINARY k.owner_plan_slug = BINARY ?\n            GROUP BY o.id", $sql);
            $params[] = $ownerPlanSlug;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'hydrateOwnedAllowedOriginPayload'], $stmt->fetchAll());
    }

    public function getOwnedApiUsageSummary($ownerPlatform, $ownerUserId, $ownerPlanSlug = '') {
        $this->ensureApiOwnerColumns();

        $ownerPlanSlug = trim((string)$ownerPlanSlug);
        $sql = "
            SELECT
                COUNT(*) AS total_keys,
                SUM(
                    CASE
                        WHEN api_keys.is_active = 1
                         AND (api_keys.allowed_origin_id IS NULL OR (
                            COALESCE(o.is_active, 1) = 1
                            AND (o.expires_at IS NULL OR o.expires_at >= NOW())
                         ))
                        THEN 1
                        ELSE 0
                    END
                ) AS active_keys,
                COALESCE(SUM(request_count), 0) AS total_requests,
                MAX(last_used_at) AS last_used_at
            FROM api_keys
            LEFT JOIN api_allowed_origins o ON o.id = api_keys.allowed_origin_id
            WHERE BINARY owner_platform = BINARY ?
              AND owner_user_id = ?
        ";
        $params = [$ownerPlatform, $ownerUserId];
        if ($ownerPlanSlug !== '') {
            $sql .= "
              AND BINARY owner_plan_slug = BINARY ?
            ";
            $params[] = $ownerPlanSlug;
        }

        $summaryStmt = $this->pdo->prepare($sql);
        $summaryStmt->execute($params);
        $summary = $summaryStmt->fetch() ?: [];

        return [
            'total_keys' => (int)($summary['total_keys'] ?? 0),
            'active_keys' => (int)($summary['active_keys'] ?? 0),
            'total_requests' => (int)($summary['total_requests'] ?? 0),
            'requests_24h' => $this->sumOwnedApiRequestsLast24Hours($ownerPlatform, $ownerUserId, $ownerPlanSlug),
            'unique_visitors_24h' => $this->countOwnedUniqueApiVisitorsLast24Hours($ownerPlatform, $ownerUserId, $ownerPlanSlug),
            'last_used_at' => (string)($summary['last_used_at'] ?? '')
        ];
    }

    public function countOwnedUniqueApiVisitorsLast24Hours($ownerPlatform, $ownerUserId, $ownerPlanSlug = '') {
        $this->ensureApiOwnerColumns();

        $ownerPlanSlug = trim((string)$ownerPlanSlug);
        $sql = "
            SELECT COUNT(DISTINCT logs.ip_address) AS unique_visitors
            FROM api_logs_24 logs
            INNER JOIN api_keys api_keys_tbl ON BINARY api_keys_tbl.api_key = BINARY logs.api_key
            WHERE BINARY api_keys_tbl.owner_platform = BINARY ?
              AND api_keys_tbl.owner_user_id = ?
              AND logs.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
              AND logs.ip_address IS NOT NULL
              AND logs.ip_address <> ''
        ";
        $params = [$ownerPlatform, $ownerUserId];
        if ($ownerPlanSlug !== '') {
            $sql .= "
              AND BINARY api_keys_tbl.owner_plan_slug = BINARY ?
            ";
            $params[] = $ownerPlanSlug;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch() ?: [];
        return (int) ($row['unique_visitors'] ?? 0);
    }

    public function getOwnedCountryStats($ownerPlatform, $ownerUserId, $limit = 5, $ownerPlanSlug = '') {
        $this->ensureApiOwnerColumns();

        $ownerPlanSlug = trim((string)$ownerPlanSlug);
        $sql = "
            SELECT logs.country_code, COUNT(*) AS request_count
            FROM api_logs_24 logs
            INNER JOIN api_keys api_keys_tbl ON BINARY api_keys_tbl.api_key = BINARY logs.api_key
            WHERE BINARY api_keys_tbl.owner_platform = BINARY ?
              AND api_keys_tbl.owner_user_id = ?
              AND logs.country_code IS NOT NULL
              AND logs.country_code <> ''
            GROUP BY logs.country_code
            ORDER BY request_count DESC, logs.country_code ASC
            LIMIT ?
        ";
        if ($ownerPlanSlug !== '') {
            $sql = str_replace('GROUP BY logs.country_code', "  AND BINARY api_keys_tbl.owner_plan_slug = BINARY ?\n            GROUP BY logs.country_code", $sql);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $ownerPlatform, PDO::PARAM_STR);
        $stmt->bindValue(2, $ownerUserId, PDO::PARAM_INT);
        $nextBindIndex = 3;
        if ($ownerPlanSlug !== '') {
            $stmt->bindValue($nextBindIndex, $ownerPlanSlug, PDO::PARAM_STR);
            $nextBindIndex++;
        }
        $stmt->bindValue($nextBindIndex, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getOwnedEndpointStats($ownerPlatform, $ownerUserId, $limit = 5, $ownerPlanSlug = '') {
        $this->ensureApiOwnerColumns();

        $ownerPlanSlug = trim((string)$ownerPlanSlug);
        $sql = "
            SELECT logs.endpoint, COUNT(*) AS request_count
            FROM api_logs_24 logs
            INNER JOIN api_keys api_keys_tbl ON BINARY api_keys_tbl.api_key = BINARY logs.api_key
            WHERE BINARY api_keys_tbl.owner_platform = BINARY ?
              AND api_keys_tbl.owner_user_id = ?
              AND logs.endpoint IS NOT NULL
              AND logs.endpoint <> ''
            GROUP BY logs.endpoint
            ORDER BY request_count DESC, logs.endpoint ASC
            LIMIT ?
        ";
        if ($ownerPlanSlug !== '') {
            $sql = str_replace('GROUP BY logs.endpoint', "  AND BINARY api_keys_tbl.owner_plan_slug = BINARY ?\n            GROUP BY logs.endpoint", $sql);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $ownerPlatform, PDO::PARAM_STR);
        $stmt->bindValue(2, $ownerUserId, PDO::PARAM_INT);
        $nextBindIndex = 3;
        if ($ownerPlanSlug !== '') {
            $stmt->bindValue($nextBindIndex, $ownerPlanSlug, PDO::PARAM_STR);
            $nextBindIndex++;
        }
        $stmt->bindValue($nextBindIndex, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getOwnedRecentRequests($ownerPlatform, $ownerUserId, $limit = 20, $ownerPlanSlug = '') {
        $this->ensureApiOwnerColumns();

        $ownerPlanSlug = trim((string)$ownerPlanSlug);
        $sql = "
            SELECT
                logs.endpoint,
                logs.ip_address,
                logs.country_code,
                logs.created_at,
                api_keys_tbl.name AS key_name,
                api_keys_tbl.api_key,
                api_keys_tbl.owner_plan_slug
            FROM api_logs_24 logs
            INNER JOIN api_keys api_keys_tbl ON BINARY api_keys_tbl.api_key = BINARY logs.api_key
            WHERE BINARY api_keys_tbl.owner_platform = BINARY ?
              AND api_keys_tbl.owner_user_id = ?
            ORDER BY logs.created_at DESC
            LIMIT ?
        ";
        if ($ownerPlanSlug !== '') {
            $sql = str_replace('ORDER BY logs.created_at DESC', "  AND BINARY api_keys_tbl.owner_plan_slug = BINARY ?\n            ORDER BY logs.created_at DESC", $sql);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $ownerPlatform, PDO::PARAM_STR);
        $stmt->bindValue(2, $ownerUserId, PDO::PARAM_INT);
        $nextBindIndex = 3;
        if ($ownerPlanSlug !== '') {
            $stmt->bindValue($nextBindIndex, $ownerPlanSlug, PDO::PARAM_STR);
            $nextBindIndex++;
        }
        $stmt->bindValue($nextBindIndex, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getOwnedApiUsageTimeline($ownerPlatform, $ownerUserId, $hours = 24, $ownerPlanSlug = '') {
        $this->ensureApiOwnerColumns();

        $hours = max(1, min(72, (int)$hours));
        $ownerPlanSlug = trim((string)$ownerPlanSlug);

        $sql = "
            SELECT
                DATE_FORMAT(logs.created_at, '%Y-%m-%d %H:00:00') AS bucket_hour,
                COUNT(*) AS request_count
            FROM api_logs_24 logs
            INNER JOIN api_keys api_keys_tbl ON BINARY api_keys_tbl.api_key = BINARY logs.api_key
            WHERE BINARY api_keys_tbl.owner_platform = BINARY ?
              AND api_keys_tbl.owner_user_id = ?
              AND logs.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)
            GROUP BY bucket_hour
            ORDER BY bucket_hour ASC
        ";
        if ($ownerPlanSlug !== '') {
            $sql = str_replace('GROUP BY bucket_hour', "  AND BINARY api_keys_tbl.owner_plan_slug = BINARY ?\n            GROUP BY bucket_hour", $sql);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $ownerPlatform, PDO::PARAM_STR);
        $stmt->bindValue(2, $ownerUserId, PDO::PARAM_INT);
        $stmt->bindValue(3, $hours, PDO::PARAM_INT);
        if ($ownerPlanSlug !== '') {
            $stmt->bindValue(4, $ownerPlanSlug, PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $countsByBucket = [];
        foreach ($rows as $row) {
            $bucket = trim((string)($row['bucket_hour'] ?? ''));
            if ($bucket === '') {
                continue;
            }
            $countsByBucket[$bucket] = (int)($row['request_count'] ?? 0);
        }

        $timeline = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentHour = $now->setTime((int)$now->format('H'), 0, 0);
        $startHour = $currentHour->sub(new DateInterval('PT' . max(0, $hours - 1) . 'H'));

        for ($index = 0; $index < $hours; $index++) {
            $pointTime = $startHour->add(new DateInterval('PT' . $index . 'H'));
            $bucket = $pointTime->format('Y-m-d H:00:00');
            $timeline[] = [
                'label' => $pointTime->format('H:00'),
                'bucket' => $bucket,
                'requests' => (int)($countsByBucket[$bucket] ?? 0),
            ];
        }

        return $timeline;
    }

    private function getOwnedApiRequestHistoryDaily($ownerPlatform, $ownerUserId, $days, $ownerPlanSlug = '') {
        $this->ensureApiOwnerColumns();
        $this->ensureApiUsageDailyTable();

        $days = max(1, (int)$days);
        $ownerPlanSlug = trim((string)$ownerPlanSlug);
        $utc = new DateTimeZone('UTC');
        $today = new DateTimeImmutable('now', $utc);
        $todayDate = $today->format('Y-m-d');
        $startDate = $today->sub(new DateInterval('P' . max(0, $days - 1) . 'D'));

        $sql = "
            SELECT daily_rollup.usage_date AS bucket_date, SUM(daily_rollup.request_count) AS request_count
            FROM api_usage_daily daily_rollup
            INNER JOIN api_keys api_keys_tbl ON BINARY api_keys_tbl.api_key = BINARY daily_rollup.api_key
            WHERE BINARY api_keys_tbl.owner_platform = BINARY ?
              AND api_keys_tbl.owner_user_id = ?
              AND daily_rollup.usage_date >= ?
              AND daily_rollup.usage_date < UTC_DATE()
            GROUP BY daily_rollup.usage_date
            ORDER BY daily_rollup.usage_date ASC
        ";
        $params = [$ownerPlatform, $ownerUserId, $startDate->format('Y-m-d')];
        if ($ownerPlanSlug !== '') {
            $sql = str_replace('GROUP BY daily_rollup.usage_date', "  AND BINARY api_keys_tbl.owner_plan_slug = BINARY ?\n            GROUP BY daily_rollup.usage_date", $sql);
            $params[] = $ownerPlanSlug;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $countsByDate = [];
        foreach ($stmt->fetchAll() as $row) {
            $bucket = trim((string)($row['bucket_date'] ?? ''));
            if ($bucket === '') {
                continue;
            }
            $countsByDate[$bucket] = (int)($row['request_count'] ?? 0);
        }

        $todayCount = $this->countOwnedApiRequestsForDate($ownerPlatform, $ownerUserId, $todayDate, $ownerPlanSlug);
        $history = [];

        for ($index = 0; $index < $days; $index++) {
            $pointDate = $startDate->add(new DateInterval('P' . $index . 'D'));
            $bucket = $pointDate->format('Y-m-d');
            $history[] = [
                'label' => $days <= 7 ? $pointDate->format('D') : $pointDate->format('d M'),
                'bucket' => $bucket,
                'requests' => $bucket === $todayDate ? $todayCount : (int)($countsByDate[$bucket] ?? 0),
            ];
        }

        return $history;
    }

    private function getOwnedApiRequestHistoryMonthly($ownerPlatform, $ownerUserId, $months, $ownerPlanSlug = '') {
        $this->ensureApiOwnerColumns();
        $this->ensureApiUsageDailyTable();

        $months = max(1, (int)$months);
        $ownerPlanSlug = trim((string)$ownerPlanSlug);
        $utc = new DateTimeZone('UTC');
        $today = new DateTimeImmutable('now', $utc);
        $todayDate = $today->format('Y-m-d');
        $currentMonth = $today->modify('first day of this month');
        $startMonth = $currentMonth->sub(new DateInterval('P' . max(0, $months - 1) . 'M'));

        $sql = "
            SELECT DATE_FORMAT(daily_rollup.usage_date, '%Y-%m') AS bucket_month, SUM(daily_rollup.request_count) AS request_count
            FROM api_usage_daily daily_rollup
            INNER JOIN api_keys api_keys_tbl ON BINARY api_keys_tbl.api_key = BINARY daily_rollup.api_key
            WHERE BINARY api_keys_tbl.owner_platform = BINARY ?
              AND api_keys_tbl.owner_user_id = ?
              AND daily_rollup.usage_date >= ?
              AND daily_rollup.usage_date < UTC_DATE()
            GROUP BY DATE_FORMAT(daily_rollup.usage_date, '%Y-%m')
            ORDER BY bucket_month ASC
        ";
        $params = [$ownerPlatform, $ownerUserId, $startMonth->format('Y-m-d')];
        if ($ownerPlanSlug !== '') {
            $sql = str_replace("GROUP BY DATE_FORMAT(daily_rollup.usage_date, '%Y-%m')", "  AND BINARY api_keys_tbl.owner_plan_slug = BINARY ?\n            GROUP BY DATE_FORMAT(daily_rollup.usage_date, '%Y-%m')", $sql);
            $params[] = $ownerPlanSlug;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $countsByMonth = [];
        foreach ($stmt->fetchAll() as $row) {
            $bucket = trim((string)($row['bucket_month'] ?? ''));
            if ($bucket === '') {
                continue;
            }
            $countsByMonth[$bucket] = (int)($row['request_count'] ?? 0);
        }

        $currentMonthKey = $today->format('Y-m');
        $countsByMonth[$currentMonthKey] = (int)($countsByMonth[$currentMonthKey] ?? 0) + $this->countOwnedApiRequestsForDate($ownerPlatform, $ownerUserId, $todayDate, $ownerPlanSlug);

        $history = [];
        for ($index = 0; $index < $months; $index++) {
            $pointMonth = $startMonth->add(new DateInterval('P' . $index . 'M'));
            $bucket = $pointMonth->format('Y-m');
            $history[] = [
                'label' => $pointMonth->format('M'),
                'bucket' => $bucket,
                'requests' => (int)($countsByMonth[$bucket] ?? 0),
            ];
        }

        return $history;
    }

    public function getOwnedApiRequestHistory($ownerPlatform, $ownerUserId, $ownerPlanSlug = '') {
        return [
            '7d' => $this->getOwnedApiRequestHistoryDaily($ownerPlatform, $ownerUserId, 7, $ownerPlanSlug),
            '1m' => $this->getOwnedApiRequestHistoryDaily($ownerPlatform, $ownerUserId, 30, $ownerPlanSlug),
            '1y' => $this->getOwnedApiRequestHistoryMonthly($ownerPlatform, $ownerUserId, 12, $ownerPlanSlug),
        ];
    }

    public function getOwnedApiDashboard($ownerPlatform, $ownerUserId, $ownerPlanSlug = '') {
        return [
            'summary' => $this->getOwnedApiUsageSummary($ownerPlatform, $ownerUserId, $ownerPlanSlug),
            'keys' => $this->getOwnedApiKeys($ownerPlatform, $ownerUserId, $ownerPlanSlug),
            'origins' => $this->getOwnedAllowedOrigins($ownerPlatform, $ownerUserId, $ownerPlanSlug),
            'countries' => $this->getOwnedCountryStats($ownerPlatform, $ownerUserId, 6, $ownerPlanSlug),
            'endpoints' => $this->getOwnedEndpointStats($ownerPlatform, $ownerUserId, 6, $ownerPlanSlug),
            'timeline' => $this->getOwnedApiUsageTimeline($ownerPlatform, $ownerUserId, 24, $ownerPlanSlug),
            'history' => $this->getOwnedApiRequestHistory($ownerPlatform, $ownerUserId, $ownerPlanSlug),
            'recent_requests' => $this->getOwnedRecentRequests($ownerPlatform, $ownerUserId, 20, $ownerPlanSlug),
        ];
    }

    private function buildApiCountryCatalog(array $countries, array $leagues) {
        $activeCountries = array_values(array_filter($countries, function ($country) {
            return (int)($country['is_active'] ?? 0) === 1;
        }));

        if (!empty($activeCountries)) {
            return $activeCountries;
        }

        $allCountries = array_values(array_filter($countries, function ($country) {
            return trim((string)($country['name'] ?? '')) !== '';
        }));

        if (!empty($allCountries)) {
            return $allCountries;
        }

        $derivedCountries = [];
        foreach ($leagues as $league) {
            $countryName = trim((string)($league['country'] ?? ''));
            if ($countryName === '') {
                continue;
            }

            $countryKey = mb_strtolower($countryName, 'UTF-8');
            if (!isset($derivedCountries[$countryKey])) {
                $derivedCountries[$countryKey] = [
                    'id' => 0,
                    'name' => $countryName,
                    'name_ar' => '',
                    'name_en' => '',
                    'logo_url' => '',
                    'is_active' => 1,
                ];
            }
        }

        return array_values($derivedCountries);
    }

    public function getApiFiltersCatalog() {
        $allCountries = $this->getAllCountries();
        $leagues = array_values(array_filter($this->getAllLeagues(), function ($league) {
            $leagueActive = (int)($league['is_active'] ?? 0) === 1;
            $countryActive = !isset($league['country_active']) || $league['country_active'] === null || (int)$league['country_active'] === 1;
            return $leagueActive && $countryActive;
        }));
        $countries = $this->buildApiCountryCatalog($allCountries, $leagues);

        usort($countries, function ($a, $b) {
            return strcasecmp((string)($a['name_ar'] ?? $a['name'] ?? ''), (string)($b['name_ar'] ?? $b['name'] ?? ''));
        });

        usort($leagues, function ($a, $b) {
            $countryCompare = strcasecmp((string)($a['country'] ?? ''), (string)($b['country'] ?? ''));
            if ($countryCompare !== 0) {
                return $countryCompare;
            }

            return strcasecmp((string)($a['name_ar'] ?? $a['name'] ?? ''), (string)($b['name_ar'] ?? $b['name'] ?? ''));
        });

        return [
            'countries' => array_map(function ($country) {
                return [
                    'id' => (int)($country['id'] ?? 0),
                    'name' => (string)($country['name'] ?? ''),
                    'name_ar' => (string)($country['name_ar'] ?? ''),
                    'name_en' => (string)($country['name_en'] ?? ''),
                    'logo_url' => (string)($country['logo_url'] ?? ''),
                ];
            }, $countries),
            'leagues' => array_map(function ($league) {
                return [
                    'id' => (int)($league['id'] ?? 0),
                    'name' => (string)($league['name'] ?? ''),
                    'name_ar' => (string)($league['name_ar'] ?? ''),
                    'name_en' => (string)($league['name_en'] ?? ''),
                    'country' => (string)($league['country'] ?? ''),
                    'logo_url' => (string)($league['logo_url'] ?? ''),
                ];
            }, $leagues),
        ];
    }

    public function getApiCountryStats() {
        $stmt = $this->pdo->prepare("
            SELECT country_code, COUNT(*) as count 
            FROM api_logs_24 
            WHERE country_code IS NOT NULL 
            GROUP BY country_code 
            ORDER BY count DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * جلب إحصائيات استخدام API
     */
    public function getApiUsageHistory($period = '24h') {
        $sql = "";
        
        switch ($period) {
            case '24h':
                // Group by hour for last 24 hours from the "hot" table
                $sql = "SELECT 
                            DATE_FORMAT(MIN(created_at), '%H:00') as label,
                            COUNT(*) as count,
                            MIN(created_at) as sort_time
                        FROM api_logs_24
                        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')
                        ORDER BY sort_time ASC";
                break;
                
            case '7d':
                // Group by day for last 7 days from the summary table
                $sql = "SELECT 
                            DATE_FORMAT(MIN(log_date), '%W') as label, 
                            SUM(request_count) as count,
                            MIN(log_date) as sort_time
                        FROM api_logs 
                        WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
                        GROUP BY log_date 
                        ORDER BY sort_time ASC";
                break;
                
            case '1m':
                // Group by day for last 30 days from the summary table
                $sql = "SELECT 
                            DATE_FORMAT(MIN(log_date), '%d %b') as label, 
                            SUM(request_count) as count,
                            MIN(log_date) as sort_time
                        FROM api_logs 
                        WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
                        GROUP BY log_date 
                        ORDER BY sort_time ASC";
                break;
                
            case '6m':
                // Group by month for last 6 months from the summary table
                $sql = "SELECT 
                            DATE_FORMAT(MIN(log_date), '%M') as label, 
                            SUM(request_count) as count,
                            MIN(log_date) as sort_time
                        FROM api_logs 
                        WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
                        GROUP BY DATE_FORMAT(log_date, '%Y-%m') 
                        ORDER BY sort_time ASC";
                break;
                
            case '1y':
                // Group by month for last year from the summary table
                $sql = "SELECT 
                            DATE_FORMAT(MIN(log_date), '%M') as label, 
                            SUM(request_count) as count,
                            MIN(log_date) as sort_time
                        FROM api_logs 
                        WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR) 
                        GROUP BY DATE_FORMAT(log_date, '%Y-%m') 
                        ORDER BY sort_time ASC";
                break;
        }
        
        if ($sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        }
        return [];
    }

    /**
     * حذف مفتاح API
     */
    public function deleteApiKey($id) {
        $stmt = $this->pdo->prepare("DELETE FROM api_keys WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * تفعيل/تعطيل مفتاح API
     */
    public function toggleApiKey($id, $isActive) {
        $stmt = $this->pdo->prepare("UPDATE api_keys SET is_active = ? WHERE id = ?");
        return $stmt->execute([$isActive, $id]);
    }
    /**
     * League Management Methods
     */
    public function getAllLeagues($limit = null, $offset = 0, $search = null, $status = 'all') {
        $sql = "SELECT l.*, c.is_active as country_active 
                FROM leagues l 
                LEFT JOIN countries c ON l.country = c.name";
        $params = [];
        $where = [];

        if ($status === 'active') {
            $where[] = "l.is_active = 1";
        } elseif ($status === 'inactive') {
            $where[] = "l.is_active = 0";
        } elseif ($status === 'no_country') {
            $where[] = "(l.country IS NULL OR l.country = '')";
        }

        if ($search) {
            $where[] = "(l.name LIKE ? OR l.name_ar LIKE ? OR l.name_en LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY l.is_active DESC, l.name ASC";

        if ($limit !== null) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = (int)$limit;
            $params[] = (int)$offset;
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getLeaguesCount($search = null, $status = 'all') {
        $sql = "SELECT COUNT(*) FROM leagues l";
        $params = [];
        $where = [];

        if ($status === 'active') {
            $where[] = "l.is_active = 1";
        } elseif ($status === 'inactive') {
            $where[] = "l.is_active = 0";
        } elseif ($status === 'no_country') {
            $where[] = "(l.country IS NULL OR l.country = '')";
        }

        if ($search) {
            $where[] = "(l.name LIKE ? OR l.name_ar LIKE ? OR l.name_en LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function getLeaguesByCountryName($countryName) {
        $stmt = $this->pdo->prepare("SELECT * FROM leagues WHERE country = ? ORDER BY name ASC");
        $stmt->execute([$countryName]);
        return $stmt->fetchAll();
    }

    public function getActiveLeaguesWithActiveCountries() {
        // Join leagues and countries on name to ensure both are active
        // Assuming leagues.country stores the country name which matches countries.name
        $sql = "SELECT l.*, c.name_ar as country_ar, c.logo_url as country_logo 
                FROM leagues l 
                LEFT JOIN countries c ON l.country = c.name 
                WHERE l.is_active = 1 
                AND (c.is_active = 1 OR c.is_active IS NULL) 
                ORDER BY l.name ASC";
        
        return $this->pdo->query($sql)->fetchAll();
    }

    public function updateLeagueStatus($id, $status) {
        $sql = "UPDATE leagues SET is_active = ?";
        if ($status == 1) $sql .= ", activated_at = NOW()";
        $sql .= " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$status, $id]);
    }

    public function updateLeague($id, $nameEn = null, $nameAr = null, $isActive = 1, $logo = null, $country = null) {
        $sql = "UPDATE leagues SET name_en = ?, name_ar = ?, is_active = ?, logo_url = ?, country = ?";
        if ($isActive == 1) $sql .= ", activated_at = NOW()";
        $sql .= " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$nameEn, $nameAr, $isActive, $logo, $country, $id]);
    }

    public function addLeague($name, $nameEn = null, $nameAr = null, $isActive = 1, $logo = null, $country = null) {
        $activatedAt = ($isActive == 1) ? date('Y-m-d H:i:s') : null;
        $stmt = $this->pdo->prepare("INSERT INTO leagues (name, name_en, name_ar, is_active, activated_at, logo_url, country) VALUES (?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$name, $nameEn, $nameAr, $isActive, $activatedAt, $logo, $country]);
    }

    public function deleteLeague($id) {
        $stmt = $this->pdo->prepare("DELETE FROM leagues WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function isLeagueActive($name) {
        $stmt = $this->pdo->prepare("
            SELECT l.is_active as league_active, c.is_active as country_active 
            FROM leagues l 
            LEFT JOIN countries c ON l.country = c.name 
            WHERE l.name = ? OR l.name_ar = ? OR l.name_en = ?
        ");
        $stmt->execute([$name, $name, $name]);
        $result = $stmt->fetch();
        
        // If league doesn't exist, default to true and sync it
        if (!$result) {
            $this->syncLeague($name);
            return true;
        }
        
        // Check both league and country status (default to 1 if NULL)
        $leagueActive = ($result['league_active'] === null) ? 1 : $result['league_active'];
        $countryActive = ($result['country_active'] === null) ? 1 : $result['country_active'];
        
        return (bool)$leagueActive && (bool)$countryActive;
    }

    public function syncLeague($name, $logo = null, $country = null) {
        $name = trim($name);
        if (empty($name)) return;
        
        // Sync country first
        if ($country) {
            $this->syncCountry($country);
        }

        $stmt = $this->pdo->prepare("SELECT id, logo_url, country FROM leagues WHERE name = ? OR name_ar = ? OR name_en = ?");
        $stmt->execute([$name, $name, $name]);
        $league = $stmt->fetch();

        if ($league) {
            $updates = [];
            $params = [];
            
            // Only update if current value is empty to preserve manual changes
            if ($logo && empty($league['logo_url'])) {
                $updates[] = "logo_url = ?";
                $params[] = $logo;
            }
            
            if ($country && empty($league['country'])) {
                $updates[] = "country = ?";
                $params[] = $country;
            }
            
            if (!empty($updates)) {
                $sql = "UPDATE leagues SET " . implode(', ', $updates) . " WHERE id = ?";
                $params[] = $league['id'];
                
                $update = $this->pdo->prepare($sql);
                $update->execute($params);
            }
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO leagues (name, logo_url, country) VALUES (?, ?, ?)");
            $stmt->execute([$name, $logo, $country]);
        }
    }

    public function getAllCountries() {
        return $this->pdo->query("SELECT * FROM countries ORDER BY name ASC")->fetchAll();
    }

    public function updateCountryStatus($id, $status) {
        $sql = "UPDATE countries SET is_active = ?";
        if ($status == 1) $sql .= ", activated_at = NOW()";
        $sql .= " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$status, $id]);
    }

    public function updateCountry($id, $nameAr = null, $nameEn = null, $isActive = 1, $logo = null) {
        $sql = "UPDATE countries SET name_ar = ?, name_en = ?, is_active = ?, logo_url = ?";
        if ($isActive == 1) $sql .= ", activated_at = NOW()";
        $sql .= " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$nameAr, $nameEn, $isActive, $logo, $id]);
    }

    public function addCountry($name, $nameAr = null, $nameEn = null, $isActive = 1, $logo = null) {
        $activatedAt = ($isActive == 1) ? date('Y-m-d H:i:s') : null;
        $stmt = $this->pdo->prepare("INSERT INTO countries (name, name_ar, name_en, is_active, activated_at, logo_url) VALUES (?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$name, $nameAr, $nameEn, $isActive, $activatedAt, $logo]);
    }

    public function deleteCountry($id) {
        $stmt = $this->pdo->prepare("DELETE FROM countries WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function syncCountry($name) {
        $name = trim($name);
        if (empty($name)) return;
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO countries (name) VALUES (?)");
        $stmt->execute([$name]);
    }

    public function isCountryActive($name) {
        if (empty($name)) return true;
        $stmt = $this->pdo->prepare("SELECT is_active FROM countries WHERE name = ?");
        $stmt->execute([$name]);
        $result = $stmt->fetch(PDO::FETCH_COLUMN);
        return ($result === false) ? true : (bool)$result;
    }

    /**
     * News Management Methods
     */
    public function saveNews($newsData) {
        $stmt = $this->pdo->prepare("SELECT id FROM news WHERE url = ?");
        $stmt->execute([$newsData['url']]);
        $existing = $stmt->fetch();

        // Ensure date is in correct format
        $date = $newsData['date'];
        try {
            $dt = new DateTime($date);
            $date = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $date = date('Y-m-d H:i:s');
        }

        if ($existing) {
            // Update existing news (maybe only update if body is provided and currently empty)
            $sql = "UPDATE news SET title = ?, image = ?, date = ?, title_en = ?, body_en = ?";
            $params = [$newsData['title'], $newsData['image'], $date, $newsData['title_en'] ?? null, $newsData['body_en'] ?? null];
            
            if (!empty($newsData['body'])) {
                $sql .= ", body = ?";
                $params[] = $newsData['body'];
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $existing['id'];
            
            $update = $this->pdo->prepare($sql);
            $update->execute($params);
            return $existing['id'];
        } else {
            // Insert new
            $stmt = $this->pdo->prepare("INSERT INTO news (title, image, url, date, body, title_en, body_en) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $newsData['title'],
                $newsData['image'],
                $newsData['url'],
                $date,
                $newsData['body'] ?? null,
                $newsData['title_en'] ?? null,
                $newsData['body_en'] ?? null
            ]);
            return $this->pdo->lastInsertId();
        }
    }

    public function getNews($limit = 20) {
        $stmt = $this->pdo->prepare("SELECT * FROM news ORDER BY date DESC LIMIT ?");
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getNewsByUrl($url) {
        $stmt = $this->pdo->prepare("SELECT * FROM news WHERE url = ?");
        $stmt->execute([$url]);
        return $stmt->fetch();
    }

    public function getNewsById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM news WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function getNewsCount($search = null, $dateFilter = 'all') {
        $sql = "SELECT COUNT(*) FROM news";
        $where = [];
        $params = [];

        if ($search) {
            $where[] = "title LIKE ?";
            $params[] = "%$search%";
        }

        if ($dateFilter === 'today') {
            $where[] = "DATE(date) = CURDATE()";
        } elseif ($dateFilter === 'yesterday') {
            $where[] = "DATE(date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        } elseif ($dateFilter === 'translated') {
            $where[] = "title_en IS NOT NULL AND title_en != ''";
        } elseif ($dateFilter === 'missing') {
            $where[] = "(title_en IS NULL OR title_en = '')";
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function deleteNews($id) {
        $stmt = $this->pdo->prepare("DELETE FROM news WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function updateNewsById($id, $data) {
        // Ensure date is in correct format
        $date = $data['date'];
        try {
            $dt = new DateTime($date);
            $date = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $date = date('Y-m-d H:i:s');
        }

        $sql = "UPDATE news SET title = ?, image = ?, date = ?, body = ?, video = ?, url = ?, title_en = ?, body_en = ? WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            $data['title'],
            $data['image'],
            $date,
            $data['body'] ?? null,
            $data['video'] ?? null,
            $data['url'] ?? null,
            $data['title_en'] ?? null,
            $data['body_en'] ?? null,
            $id
        ]);
    }

    public function getAllNews($limit = null, $offset = 0, $search = null, $dateFilter = 'all') {
        $sql = "SELECT * FROM news";
        $where = [];
        $params = [];

        if ($search) {
            $where[] = "title LIKE ?";
            $params[] = "%$search%";
        }

        if ($dateFilter === 'today') {
            $where[] = "DATE(date) = CURDATE()";
        } elseif ($dateFilter === 'yesterday') {
            $where[] = "DATE(date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        } elseif ($dateFilter === 'translated') {
            $where[] = "title_en IS NOT NULL AND title_en != ''";
        } elseif ($dateFilter === 'missing') {
            $where[] = "(title_en IS NULL OR title_en = '')";
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY date DESC";

        if ($limit !== null) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = (int)$limit;
            $params[] = (int)$offset;
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Channels Management Methods
     */
    public function getAllChannels($limit = null, $offset = 0, $search = null, $status = 'all') {
        $sql = "SELECT * FROM channels";
        $params = [];
        $where = [];

        if ($status === 'active') {
            $where[] = "is_active = 1";
        } elseif ($status === 'inactive') {
            $where[] = "is_active = 0";
        } elseif ($status === 'with_stream') {
            $where[] = "stream_url IS NOT NULL AND stream_url != ''";
        }

        if ($search) {
            $where[] = "name LIKE ?";
            $params[] = "%$search%";
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY is_active DESC, name ASC";

        if ($limit !== null) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = (int)$limit;
            $params[] = (int)$offset;
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getChannelsCount($search = null, $status = 'all') {
        $sql = "SELECT COUNT(*) FROM channels";
        $params = [];
        $where = [];

        if ($status === 'active') {
            $where[] = "is_active = 1";
        } elseif ($status === 'inactive') {
            $where[] = "is_active = 0";
        } elseif ($status === 'with_stream') {
            $where[] = "stream_url IS NOT NULL AND stream_url != ''";
        }

        if ($search) {
            $where[] = "name LIKE ?";
            $params[] = "%$search%";
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function addChannel($name, $logo, $streamUrl = null) {
        $stmt = $this->pdo->prepare("INSERT INTO channels (name, logo, stream_url, is_active) VALUES (?, ?, ?, 1)");
        return $stmt->execute([$name, $logo, $streamUrl]);
    }

    public function updateChannel($id, $name, $logo, $streamUrl, $isActive) {
        $stmt = $this->pdo->prepare("UPDATE channels SET name = ?, logo = ?, stream_url = ?, is_active = ? WHERE id = ?");
        return $stmt->execute([$name, $logo, $streamUrl, $isActive, $id]);
    }

    public function updateChannelStatus($id, $status) {
        $stmt = $this->pdo->prepare("UPDATE channels SET is_active = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }

    public function deleteChannel($id) {
        $stmt = $this->pdo->prepare("DELETE FROM channels WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * API Global Settings
     */
    public function getApiSettings() {
        // Ensure table exists
        $this->ensureApiSettingsTable();
        
        $stmt = $this->pdo->query("SELECT * FROM api_settings");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        // Default settings ONLY for keys that NEVER existed in the database
        // We check if the key is missing in the $settings array.
        // If it's present but is an empty string, we keep it empty (meaning all blocked).
        $defaults = [
            'cors_enabled' => '1',
            'allow_all_origins' => '0',
            'allowed_methods' => 'GET, POST, OPTIONS',
            'allowed_headers' => 'Content-Type, X-API-Key',
            'api_key_required' => '1',
            'enable_aiscore_fallback' => '1',
            'enable_365scores_fallback' => '1',
            'enforce_forwarded_visitor_ip' => '1',
            'embed_player_signed_ttl' => '900',
            'embed_cast_signed_ttl' => '7200',
            'embed_domain_restriction_enabled' => '1'
        ];

        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $settings)) {
                $this->updateApiSetting($key, $value);
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    public function updateApiSetting($key, $value) {
        $this->ensureApiSettingsTable();
        // Handle MySQL vs Postgres differences for ON CONFLICT/ON DUPLICATE KEY
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $stmt = $this->pdo->prepare("INSERT INTO api_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value");
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO api_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        }
        
        return $stmt->execute([$key, $value]);
    }

    public function getAllowedOrigins() {
        $this->ensureApiSettingsTable();
        $this->ensureApiOwnerColumns();
        return $this->pdo->query("
            SELECT
                o.*,
                COUNT(k.id) AS keys_count,
                COALESCE(SUM(k.request_count), 0) AS total_requests,
                MAX(k.owner_platform) AS owner_platform,
                MAX(k.owner_user_id) AS owner_user_id,
                MAX(k.owner_email) AS owner_email,
                MAX(k.owner_name) AS owner_name,
                MAX(k.owner_plan_slug) AS owner_plan_slug,
                MAX(k.owner_plan_name) AS owner_plan_name
            FROM api_allowed_origins o
            LEFT JOIN api_keys k ON k.allowed_origin_id = o.id
            GROUP BY o.id
            ORDER BY o.created_at DESC
        ")->fetchAll();
    }

    private function ensureApiSettingsTable() {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_settings (
                id SERIAL PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_allowed_origins (
                id SERIAL PRIMARY KEY,
                origin VARCHAR(255) UNIQUE NOT NULL,
                is_active SMALLINT DEFAULT 1,
                plan_type VARCHAR(50) DEFAULT 'free',
                expires_at TIMESTAMP NULL,
                request_limit INT DEFAULT 4500,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            // Migration for existing table
            try { $this->pdo->exec("ALTER TABLE api_allowed_origins ADD COLUMN is_active SMALLINT DEFAULT 1"); } catch (Exception $e) {}
            try { $this->pdo->exec("ALTER TABLE api_allowed_origins ADD COLUMN plan_type VARCHAR(50) DEFAULT 'free'"); } catch (Exception $e) {}
            try { $this->pdo->exec("ALTER TABLE api_allowed_origins ADD COLUMN expires_at TIMESTAMP NULL"); } catch (Exception $e) {}
            try { $this->pdo->exec("ALTER TABLE api_allowed_origins ADD COLUMN request_limit INT DEFAULT 4500"); } catch (Exception $e) {}
        } else {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_allowed_origins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                origin VARCHAR(255) UNIQUE NOT NULL,
                is_active TINYINT DEFAULT 1,
                plan_type VARCHAR(50) DEFAULT 'free',
                expires_at DATETIME NULL,
                request_limit INT DEFAULT 4500,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Migration for existing table
            try { $this->pdo->exec("ALTER TABLE api_allowed_origins ADD COLUMN is_active TINYINT DEFAULT 1 AFTER origin"); } catch (Exception $e) {}
            try { $this->pdo->exec("ALTER TABLE api_allowed_origins ADD COLUMN plan_type VARCHAR(50) DEFAULT 'free' AFTER is_active"); } catch (Exception $e) {}
            try { $this->pdo->exec("ALTER TABLE api_allowed_origins ADD COLUMN expires_at DATETIME NULL AFTER plan_type"); } catch (Exception $e) {}
            try { $this->pdo->exec("ALTER TABLE api_allowed_origins ADD COLUMN request_limit INT DEFAULT 4500 AFTER expires_at"); } catch (Exception $e) {}
        }
    }

    public function addAllowedOrigin($origin, $planType = 'free') {
        $this->ensureApiSettingsTable();
        
        $expiresAt = null;
        $requestLimit = 0; // 0 means unlimited for non-free plans
        
        if ($planType === 'free') {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+48 hours'));
            $requestLimit = 4500;
        } else {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));
            $requestLimit = 0; 
        }

        $sql = "INSERT INTO api_allowed_origins (origin, is_active, plan_type, expires_at, request_limit) VALUES (?, 1, ?, ?, ?)";
        
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $sql .= " ON CONFLICT (origin) DO UPDATE SET is_active = 1, plan_type = EXCLUDED.plan_type, expires_at = EXCLUDED.expires_at, request_limit = EXCLUDED.request_limit";
        } else {
            $sql = "INSERT INTO api_allowed_origins (origin, is_active, plan_type, expires_at, request_limit) VALUES (?, 1, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE is_active = 1, plan_type = VALUES(plan_type), expires_at = VALUES(expires_at), request_limit = VALUES(request_limit)";
        }
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$origin, $planType, $expiresAt, $requestLimit]);
    }

    public function updateAllowedOrigin($id, $origin, $planType = null) {
        if ($planType) {
            $expiresAt = null;
            $requestLimit = 0;
            if ($planType === 'free') {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+48 hours'));
                $requestLimit = 4500;
            } else {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));
                $requestLimit = 0;
            }
            $stmt = $this->pdo->prepare("UPDATE api_allowed_origins SET origin = ?, plan_type = ?, expires_at = ?, request_limit = ? WHERE id = ?");
            $result = $stmt->execute([$origin, $planType, $expiresAt, $requestLimit, $id]);
            
            if ($result) {
                // Reset request counts when plan is changed/updated
                $this->pdo->prepare("UPDATE api_keys SET request_count = 0 WHERE allowed_origin_id = ?")->execute([$id]);
            }
            return $result;
        } else {
            $stmt = $this->pdo->prepare("UPDATE api_allowed_origins SET origin = ? WHERE id = ?");
            return $stmt->execute([$origin, $id]);
        }
    }

    public function renewAllowedOrigin($id) {
        $stmt = $this->pdo->prepare("SELECT plan_type FROM api_allowed_origins WHERE id = ?");
        $stmt->execute([$id]);
        $planType = $stmt->fetchColumn();
        
        if (!$planType) return false;

        $expiresAt = null;
        $requestLimit = 0;
        if ($planType === 'free') {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+48 hours'));
            $requestLimit = 4500;
        } else {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));
            $requestLimit = 0;
        }

        // Reset request count of all associated keys when renewing
        $this->pdo->prepare("UPDATE api_keys SET request_count = 0 WHERE allowed_origin_id = ?")->execute([$id]);

        $stmt = $this->pdo->prepare("UPDATE api_allowed_origins SET expires_at = ?, request_limit = ?, is_active = 1 WHERE id = ?");
        return $stmt->execute([$expiresAt, $requestLimit, $id]);
    }

    public function toggleAllowedOrigin($id, $status) {
        $stmt = $this->pdo->prepare("UPDATE api_allowed_origins SET is_active = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }

    public function deleteAllowedOrigin($id) {
        $stmt = $this->pdo->prepare("DELETE FROM api_allowed_origins WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * حفظ مباراة مع بيانات الترتيب
     * @param array $matchData بيانات المباراة
     * @param array|null $standingsData بيانات جدول الترتيب (سيتم تحويلها إلى JSON)
     * @return int معرف المباراة
     */
    public function saveMatchWithStandings($matchData, $standingsData = null) {
        // حفظ المباراة أولاً
        $matchId = $this->saveMatch($matchData);
        
        // إذا كانت هناك بيانات ترتيب، قم بحفظها
        if ($matchId && $standingsData !== null) {
            $this->updateMatchStandings($matchId, $standingsData);
        }
        
        return $matchId;
    }

    /**
     * تحديث بيانات الترتيب لمباراة موجودة
     * @param int $matchId معرف المباراة
     * @param array $standingsData بيانات جدول الترتيب
     * @return bool
     */
    public function updateMatchStandings($matchId, $standingsData) {
        try {
            // Only accept if more than 10 teams
            if (is_array($standingsData) && count($standingsData) <= 10) {
                // Check if we already have standings for this match
                $existing = $this->getMatchStandings($matchId);
                if ($existing && count($existing) > 10) {
                    return true; // Keep old data if it was better
                }
            }

            // تحويل البيانات إلى JSON
            $standingsJson = json_encode($standingsData, JSON_UNESCAPED_UNICODE);
            
            $stmt = $this->pdo->prepare("UPDATE matches SET standings_data = ? WHERE id = ?");
            $result = $stmt->execute([$standingsJson, $matchId]);

            // مزامنة مع جدول الدوريات أيضاً
            if ($result) {
                $match = $this->getMatchById($matchId);
                if ($match && !empty($match['league_name'])) {
                    $this->updateLeagueStandings($match['league_name'], $standingsData);
                }
            }

            return $result;
        } catch (PDOException $e) {
            error_log("Error updating match standings: " . $e->getMessage());
            return false;
        }
    }

    /**
     * تحديث جدول الترتيب للدوري
     */
    public function updateLeagueStandings($leagueName, $standingsData) {
        try {
            $standingsJson = json_encode($standingsData, JSON_UNESCAPED_UNICODE);
            $stmt = $this->pdo->prepare("UPDATE leagues SET standings_data = ?, standings_updated_at = NOW() WHERE name = ? OR name_ar = ? OR name_en = ?");
            return $stmt->execute([$standingsJson, $leagueName, $leagueName, $leagueName]);
        } catch (PDOException $e) {
            error_log("Error updating league standings: " . $e->getMessage());
            return false;
        }
    }

    /**
     * جلب جدول الترتيب للدوري
     */
    public function getLeagueStandings($leagueName) {
        $stmt = $this->pdo->prepare("SELECT standings_data FROM leagues WHERE name = ? OR name_ar = ? OR name_en = ?");
        $stmt->execute([$leagueName, $leagueName, $leagueName]);
        $result = $stmt->fetch();
        
        if ($result && !empty($result['standings_data'])) {
            return json_decode($result['standings_data'], true);
        }
        return null;
    }

    /**
     * جلب مباراة مع بيانات الترتيب
     * @param int $matchId معرف المباراة
     * @return array|false
     */
    public function getMatchWithStandings($matchId) {
        $match = $this->getMatchById($matchId);
        
        if ($match && !empty($match['standings_data'])) {
            // تحويل JSON إلى مصفوفة
            $match['standings'] = json_decode($match['standings_data'], true);
        } else {
            $match['standings'] = null;
        }
        
        return $match;
    }

    /**
     * جلب جميع مباريات يوم معين مع بيانات الترتيب
     * @param string|null $date التاريخ (Y-m-d)
     * @return array
     */
    public function getMatchesWithStandings($date = null) {
        $matches = $this->getMatches($date);
        
        // تحويل بيانات الترتيب من JSON إلى مصفوفة لكل مباراة
        foreach ($matches as &$match) {
            if (!empty($match['standings_data'])) {
                $match['standings'] = json_decode($match['standings_data'], true);
            } else {
                $match['standings'] = null;
            }
        }
        
        return $matches;
    }

    /**
     * جلب بيانات الترتيب لمباراة محددة
     * @param int $matchId معرف المباراة
     * @return array|null
     */
    public function getMatchStandings($matchId) {
        $stmt = $this->pdo->prepare("SELECT standings_data FROM matches WHERE id = ?");
        $stmt->execute([$matchId]);
        $result = $stmt->fetch();
        
        if ($result && !empty($result['standings_data'])) {
            return json_decode($result['standings_data'], true);
        }
        
        return null;
    }

    /**
     * جلب أحدث جداول الترتيب لكل دوري
     * @return array
     */
    public function getLatestLeaguesStandings() {
        $sql = "SELECT l.name as league_name, l.name_ar as league_name_ar, l.name_en as league_name_en, 
                       l.logo_url as league_logo, l.standings_data, l.standings_updated_at
                FROM leagues l
                WHERE l.standings_data IS NOT NULL AND l.standings_data != 'null' AND l.standings_data != '[]' AND l.standings_data != ''
                ORDER BY l.name_ar ASC";
        
        $standings = $this->pdo->query($sql)->fetchAll();
        
        $filteredStandings = [];
        foreach ($standings as &$s) {
            $s['standings'] = json_decode($s['standings_data'], true);
            // Filter: Only include if standings array has 10 or more teams
            if (is_array($s['standings']) && count($s['standings']) >= 10) {
                $filteredStandings[] = $s;
            }
        }
        
        return $filteredStandings;
    }
    /**
     * تحديث إحصائيات المباراة
     */
    public function updateMatchStatistics($matchId, $statisticsData) {
        try {
            $statisticsJson = json_encode($statisticsData, JSON_UNESCAPED_UNICODE);
            $stmt = $this->pdo->prepare("UPDATE matches SET statistics_data = ? WHERE id = ?");
            return $stmt->execute([$statisticsJson, $matchId]);
        } catch (PDOException $e) {
            error_log("Error updating match statistics: " . $e->getMessage());
            return false;
        }
    }
    /**
     * تحديث نتائج المباريات السابقة
     */
    public function updateMatchPreviousMatches($matchId, $previousMatchesData) {
        try {
            $json = json_encode($previousMatchesData, JSON_UNESCAPED_UNICODE);
            $stmt = $this->pdo->prepare("UPDATE matches SET previous_matches_data = ? WHERE id = ?");
            return $stmt->execute([$json, $matchId]);
        } catch (PDOException $e) {
            error_log("Error updating match previous matches: " . $e->getMessage());
            return false;
        }
    }

    /**
     * تحديث ملعب المباراة
     */
    public function updateMatchStadium($matchId, $stadiumName) {
        $stmt = $this->pdo->prepare("UPDATE matches SET stadium_name = ? WHERE id = ?");
        return $stmt->execute([$stadiumName, $matchId]);
    }

    public function saveLineups($matchId, $lineups) {
        $homeLineup = json_encode($lineups['home'], JSON_UNESCAPED_UNICODE);
        $awayLineup = json_encode($lineups['away'], JSON_UNESCAPED_UNICODE);
        return $this->updateMatchLineup($matchId, $homeLineup, $awayLineup);
    }

    public function saveStandings($matchId, $standings) {
        return $this->updateMatchStandings($matchId, $standings);
    }
    public function getMatchesMissingDetails($limit = 10) {
        $sql = "SELECT id, match_url FROM matches 
                WHERE (statistics_data IS NULL OR statistics_data = '' OR statistics_data = '[]') 
                AND match_url IS NOT NULL 
                AND (status = 'Finished' OR status = 'Live')
                ORDER BY match_date DESC LIMIT ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function updateMatchDetails($matchId, $details) {
        $sql = "UPDATE matches SET 
                channel = ?, 
                channel_logo = ?, 
                stadium_name = ?, ";
        
        $params = [
            $details['channel'] ?? null,
            $details['channel_logo'] ?? null,
            $details['stadium'] ?? null
        ];

        // Add referee if it exists in DB (we'll check or just try)
        if (isset($details['referee'])) {
            $refereeValue = $this->prepareRefereeValueForStorage($details['referee']);

            if ($refereeValue !== null) {
                $sql .= "referee = ?, ";
                $params[] = $refereeValue;
            }
        }

        $sql .= "league_name = ?";
        $params[] = $details['league'] ?? null;

        if (isset($details['live_url'])) {
            $sql .= ", live_url = ?";
            $params[] = $details['live_url'];
        }

        if (!empty($details['channels'])) {
            $sql .= ", channels_data = ?";
            $params[] = json_encode($details['channels'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($details['details_time'])) {
            $sql .= ", details_match_time = ?";
            $params[] = $details['details_time'];

            $currentMatch = $this->getMatchById($matchId);
            $effectiveMatchDate = $details['details_date'] ?? ($currentMatch['match_date'] ?? null);
            if (!empty($details['details_date'])) {
                $sql .= ", match_date = ?";
                $params[] = $details['details_date'];
            }
            $utcStartTime = $this->buildUtcStartTime($effectiveMatchDate, $details['details_time']);
            if ($utcStartTime !== null) {
                $sql .= ", start_time = ?";
                $params[] = $utcStartTime;
            }
        }

        $sql .= " WHERE id = ?";
        $params[] = $matchId;

        $stmt = $this->pdo->prepare($sql);
        $res = $stmt->execute($params);
        
        // Sync with channels table
        if ($res && (isset($details['channel']) || isset($details['live_url']) || isset($details['channels']))) {
            $match = $this->getMatchById($matchId);
            if ($match) {
                $stream = !empty($match['live_iframe']) ? $match['live_iframe'] : $match['live_url'];
                if (!empty($stream)) {
                    // Sync primary channel
                    if (!empty($match['channel'])) {
                        $this->syncChannelStream($match['channel'], $stream, $match['channel_logo']);
                    }
                    
                    // Sync multiple channels if available (from YssScore typically)
                    if (!empty($details['channels']) && is_array($details['channels'])) {
                        foreach ($details['channels'] as $ch) {
                            $chName = is_array($ch) ? ($ch['name'] ?? null) : $ch;
                            if ($chName && $chName !== $match['channel']) {
                                $this->syncChannelStream($chName, $stream, is_array($ch) ? ($ch['logo'] ?? null) : null);
                            }
                        }
                    }
                }
            }
        }
        
        return $res;
    }
    /**
     * Players Management Methods
     */
    public function getAllPlayers($teamId = null, $limit = 50, $offset = 0, $search = null, $filterType = 'all') {
        $sql = "SELECT p.*, t.name as team_name, t.name_ar as team_name_ar, t.name_en as team_name_en 
                FROM players p 
                LEFT JOIN teams t ON p.team_id = t.id";
        $params = [];
        $where = [];

        if ($teamId) {
            $where[] = "p.team_id = ?";
            $params[] = $teamId;
        }

        if ($search) {
            $where[] = "(p.name LIKE ? OR p.name_ar LIKE ? OR p.name_en LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Advanced Filters
        $defaultImageUrl = 'https://cdn.sportfeeds.io/sdl/images/person/head/medium/default.png';
        $officialPrefix = 'https://cdn.sportfeeds.io/sdl/images/%';

        if ($filterType === 'default_logo') {
            $where[] = "p.image_url = ?";
            $params[] = $defaultImageUrl;
        } elseif ($filterType === 'external_logo') {
            $where[] = "p.image_url NOT LIKE ? AND p.image_url != ? AND p.image_url IS NOT NULL AND p.image_url != ''";
            $params[] = $officialPrefix;
            $params[] = $defaultImageUrl;
        } elseif ($filterType === 'both') {
            $where[] = "p.name_ar IS NOT NULL AND p.name_ar != '' AND p.name_en IS NOT NULL AND p.name_en != ''";
        } elseif ($filterType === 'ar') {
            $where[] = "p.name_ar IS NOT NULL AND p.name_ar != ''";
        } elseif ($filterType === 'en') {
            $where[] = "p.name_en IS NOT NULL AND p.name_en != ''";
        } elseif ($filterType === 'missing') {
            $where[] = "(p.name_ar IS NULL OR p.name_ar = '' OR p.name_en IS NULL OR p.name_en = '')";
        } elseif ($filterType === 'coach') {
            $where[] = "p.position = 'Coach'";
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY p.name ASC LIMIT ? OFFSET ?";
        $params[] = (int)$limit;
        $params[] = (int)$offset;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getPlayers($teamId = null, $limit = 50, $offset = 0, $search = null) {
        $sql = "SELECT p.*, t.name as team_name, t.logo_url as team_logo 
                FROM players p 
                LEFT JOIN teams t ON p.team_id = t.id";
        
        $params = [];
        $where = [];

        if ($teamId) {
            $where[] = "p.team_id = ?";
            $params[] = $teamId;
        }

        if ($search) {
            $where[] = "(p.name LIKE ? OR p.name_ar LIKE ? OR p.name_en LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY p.name ASC LIMIT ? OFFSET ?";
        $params[] = (int)$limit;
        $params[] = (int)$offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getPlayerById($id) {
        $stmt = $this->pdo->prepare("SELECT p.*, t.name as team_name FROM players p LEFT JOIN teams t ON p.team_id = t.id WHERE p.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function addPlayer($name, $nameAr = null, $nameEn = null, $imageUrl = null, $teamId = null, $position = null, $number = null) {
        $stmt = $this->pdo->prepare("INSERT INTO players (name, name_ar, name_en, image_url, team_id, position, number) VALUES (?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$name, $nameAr, $nameEn, $imageUrl, $teamId, $position, $number]);
    }

    public function updatePlayer($id, $name, $nameAr = null, $nameEn = null, $imageUrl = null, $teamId = null, $position = null, $number = null) {
        $stmt = $this->pdo->prepare("UPDATE players SET name = ?, name_ar = ?, name_en = ?, image_url = ?, team_id = ?, position = ?, number = ? WHERE id = ?");
        return $stmt->execute([$name, $nameAr, $nameEn, $imageUrl, $teamId, $position, $number, $id]);
    }

    public function deletePlayer($id) {
        $stmt = $this->pdo->prepare("DELETE FROM players WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    public function getPlayersCount($teamId = null, $search = null, $filterType = 'all') {
        $sql = "SELECT COUNT(*) FROM players p";
        $params = [];
        $where = [];

        if ($teamId) {
            $where[] = "p.team_id = ?";
            $params[] = $teamId;
        }

        if ($search) {
            $where[] = "(p.name LIKE ? OR p.name_ar LIKE ? OR p.name_en LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Advanced Filters
        $defaultImageUrl = 'https://cdn.sportfeeds.io/sdl/images/person/head/medium/default.png';
        $officialPrefix = 'https://cdn.sportfeeds.io/sdl/images/%';

        if ($filterType === 'default_logo') {
            $where[] = "p.image_url = ?";
            $params[] = $defaultImageUrl;
        } elseif ($filterType === 'external_logo') {
            $where[] = "p.image_url NOT LIKE ? AND p.image_url != ? AND p.image_url IS NOT NULL AND p.image_url != ''";
            $params[] = $officialPrefix;
            $params[] = $defaultImageUrl;
        } elseif ($filterType === 'both') {
            $where[] = "p.name_ar IS NOT NULL AND p.name_ar != '' AND p.name_en IS NOT NULL AND p.name_en != ''";
        } elseif ($filterType === 'ar') {
            $where[] = "p.name_ar IS NOT NULL AND p.name_ar != ''";
        } elseif ($filterType === 'en') {
            $where[] = "p.name_en IS NOT NULL AND p.name_en != ''";
        } elseif ($filterType === 'missing') {
            $where[] = "(p.name_ar IS NULL OR p.name_ar = '' OR p.name_en IS NULL OR p.name_en = '')";
        } elseif ($filterType === 'coach') {
            $where[] = "p.position = 'Coach'";
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
    public function syncPlayer($name, $teamId, $number = null, $position = null, $image = null) {
        // Normalize name: remove extra spaces and invisible characters
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if (empty($name)) return null;

        $player = null;
        
        // 1. Try to find by name and team
        if ($teamId) {
            $stmt = $this->pdo->prepare("SELECT * FROM players WHERE (TRIM(name) = ? OR TRIM(name_ar) = ? OR TRIM(name_en) = ?) AND team_id = ?");
            $stmt->execute([$name, $name, $name, $teamId]);
            $player = $stmt->fetch();
        }

        // 2. If not found in team, try to find globally to prevent duplicates (especially for coaches)
        if (!$player) {
            $stmt = $this->pdo->prepare("SELECT * FROM players WHERE (TRIM(name) = ? OR TRIM(name_ar) = ? OR TRIM(name_en) = ?)");
            $stmt->execute([$name, $name, $name]);
            $globalPlayers = $stmt->fetchAll();
            
            foreach ($globalPlayers as $gp) {
                // If it's a coach, we reuse the record even if it's in another team (update team)
                if ($position === 'Coach' && $gp['position'] === 'Coach') {
                    $player = $gp;
                    if ($teamId && $gp['team_id'] != $teamId) {
                        $this->pdo->prepare("UPDATE players SET team_id = ? WHERE id = ?")->execute([$teamId, $gp['id']]);
                        $player['team_id'] = $teamId;
                    }
                    break;
                }
                
                // If the player has no team, claim them
                if (empty($gp['team_id']) || $gp['team_id'] == 0) {
                    $player = $gp;
                    if ($teamId) {
                        $this->pdo->prepare("UPDATE players SET team_id = ? WHERE id = ?")->execute([$teamId, $gp['id']]);
                        $player['team_id'] = $teamId;
                    }
                    break;
                }
            }
        }

        if ($player) {
            // Update info if provided
            $updates = [];
            $params = [];
            
            if ($number) { $updates[] = "number = ?"; $params[] = $number; }
            
            // Ensure position is a string (handle array case seen in screenshot)
            if ($position) {
                if (is_array($position)) $position = implode(', ', $position);
                $updates[] = "position = ?"; 
                $params[] = $position; 
            }
            
            // Only update image if current is empty and new is provided
            if ($image && empty($player['image_url'])) { 
                $updates[] = "image_url = ?"; 
                $params[] = $image; 
                $player['image_url'] = $image;
            }
            
            if (!empty($updates)) {
                $sql = "UPDATE players SET " . implode(', ', $updates) . " WHERE id = ?";
                $params[] = $player['id'];
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            }
            return $player;
        } else {
            // Insert
            if (is_array($position)) $position = implode(', ', $position);
            
            $stmt = $this->pdo->prepare("INSERT INTO players (name, team_id, number, position, image_url) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $teamId, $number, $position, $image]);
            $id = $this->pdo->lastInsertId();
            return [
                'id' => $id,
                'name' => $name,
                'team_id' => $teamId,
                'number' => $number,
                'position' => $position,
                'image_url' => $image
            ];
        }
    }
    public function addScrapeLog($type, $message) {
        $stmt = $this->pdo->prepare("INSERT INTO scrape_logs (type, message) VALUES (?, ?)");
        return $stmt->execute([$type, $message]);
    }

    public function getLatestScrapeLogs($limit = 50) {
        $stmt = $this->pdo->prepare("SELECT * FROM scrape_logs ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_reverse($stmt->fetchAll());
    }

    public function clearScrapeLogs() {
        return $this->pdo->exec("TRUNCATE TABLE scrape_logs");
    }

    /**
     * Save FIFA Rankings to database
     */
    public function saveFifaRankings($rankings) {
        try {
            // Clear existing rankings
            $this->pdo->exec("TRUNCATE TABLE fifa_rankings");
            
            // Insert new rankings
            $stmt = $this->pdo->prepare(
                "INSERT INTO fifa_rankings (ranking, country_name, country_name_en, country_name_ar, country_code, points, previous_points, rank_change, flag_url, confederation) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $count = 0;
            foreach ($rankings as $ranking) {
                $stmt->execute([
                    $ranking['rank'],
                    $ranking['country_name'],
                    $ranking['country_name_en'] ?? $ranking['country_name'],
                    $ranking['country_name_ar'] ?? null,
                    $ranking['country_code'],
                    $ranking['points'],
                    $ranking['previous_points'],
                    $ranking['rank_change'],
                    $ranking['flag_url'],
                    $ranking['confederation']
                ]);
                $count++;
            }
            
            return $count;
        } catch (PDOException $e) {
            error_log("Error saving FIFA rankings: " . $e->getMessage());
            return false;
        }
    }



    /**
     * Get active scraper source
     */
    public function getActiveScraperSource() {
        // Create table if it doesn't exist (safety check)
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS scraper_source_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_key VARCHAR(50) NOT NULL UNIQUE,
            source_name VARCHAR(50) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $stmt = $this->pdo->query("SELECT source_key FROM scraper_source_settings WHERE is_active = 1 LIMIT 1");
        $result = $stmt->fetch(PDO::FETCH_COLUMN);
        return $result ?: false; // Return false if no source is active
    }

    /**
     * Synchronize a channel stream URL with the channels table
     */
    public function syncChannelStream($channelName, $streamUrl, $channelLogo = null) {
        if (empty($channelName) || empty($streamUrl)) return false;
        
        try {
            // Find existing channel by name
            $stmt = $this->pdo->prepare("SELECT id, logo, stream_url FROM channels WHERE name = ?");
            $stmt->execute([$channelName]);
            $channel = $stmt->fetch();
            
            if ($channel) {
                // Update stream and logo (if logo is missing)
                $updates = ["stream_url = ?"];
                $params = [$streamUrl];
                
                if ($channelLogo && empty($channel['logo'])) {
                    $updates[] = "logo = ?";
                    $params[] = $channelLogo;
                }
                
                $sql = "UPDATE channels SET " . implode(', ', $updates) . " WHERE id = ?";
                $params[] = $channel['id'];
                
                $stmt = $this->pdo->prepare($sql);
                return $stmt->execute($params);
            } else {
                // Create new channel
                $stmt = $this->pdo->prepare("INSERT INTO channels (name, logo, stream_url, is_active) VALUES (?, ?, ?, 1)");
                return $stmt->execute([$channelName, $channelLogo, $streamUrl]);
            }
        } catch (PDOException $e) {
            error_log("Error syncing channel stream: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get FIFA Rankings from database
     */
    public function getFifaRankings($limit = null) {
        $sql = "SELECT * FROM fifa_rankings ORDER BY ranking ASC";
        if ($limit) {
            $sql .= " LIMIT ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }
        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * Scraper Sources Management
     */
    public function ensureScraperSourcesTable() {
        $sql = "CREATE TABLE IF NOT EXISTS scraper_sources (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            base_url VARCHAR(255) NOT NULL,
            matches_path VARCHAR(255) DEFAULT '/matches-today/',
            container_selector VARCHAR(255) DEFAULT '//div[contains(@class, \'AY_Match\')]',
            teams_selector VARCHAR(255) DEFAULT './/div[@class=\'TM_Name\']',
            link_selector VARCHAR(255) DEFAULT './/a[contains(@href, \'/matches/\')]',
            live_link_selector VARCHAR(255) DEFAULT './a',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        $this->pdo->exec($sql);
        
        // Check if column exists (migration)
        $stmt = $this->pdo->query("SHOW COLUMNS FROM scraper_sources LIKE 'live_link_selector'");
        if (!$stmt->fetch()) {
            $this->pdo->exec("ALTER TABLE scraper_sources ADD COLUMN live_link_selector VARCHAR(255) DEFAULT './a' AFTER link_selector");
        }

        $this->ensureDefaultScraperSource([
            'name' => 'Yalla Shoot MOV',
            'base_url' => 'https://yalla-shoot.mov',
            'matches_path' => '/',
            'container_selector' => "//div[contains(@class, 'AY_Match')]",
            'teams_selector' => ".//div[contains(@class, 'TM_Name')]",
            'link_selector' => ".//a[contains(@href, '?m=')]",
            'live_link_selector' => ".//a[contains(@href, '?m=')]",
            'is_active' => 1
        ]);

        $this->ensureDefaultScraperSource([
            'name' => 'TotalSportekX',
            'base_url' => 'https://totalsportekx.top',
            'matches_path' => '/',
            'container_selector' => "//a[contains(@href, '?m=') or contains(@href, 'smartagro.mov')]",
            'teams_selector' => ".//*[contains(@class, 'team-name')]",
            'link_selector' => ".//a[contains(@href, '?m=') or contains(@href, 'smartagro.mov')]",
            'live_link_selector' => ".//a[contains(@href, '?m=') or contains(@href, 'smartagro.mov')]",
            'is_active' => 1
        ]);

        $this->ensureDefaultScraperSource([
            'name' => 'Kora Simo',
            'base_url' => 'https://www.korasimo.com',
            'matches_path' => '/',
            'container_selector' => "//a[contains(@class, 'card') and contains(@href, '/match/')]",
            'teams_selector' => ".//div[contains(@class, 'team')]//b",
            'link_selector' => "self::a[@href]",
            'live_link_selector' => "self::a[@href]",
            'is_active' => 1
        ]);
        
        return true;
    }

    private function ensureDefaultScraperSource(array $source) {
        $stmt = $this->pdo->prepare("SELECT id FROM scraper_sources WHERE base_url = ? LIMIT 1");
        $stmt->execute([$source['base_url']]);

        if ($stmt->fetch()) {
            return;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO scraper_sources (name, base_url, matches_path, container_selector, teams_selector, link_selector, live_link_selector, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $insert->execute([
            $source['name'],
            $source['base_url'],
            $source['matches_path'],
            $source['container_selector'],
            $source['teams_selector'],
            $source['link_selector'],
            $source['live_link_selector'],
            (int)($source['is_active'] ?? 1)
        ]);
    }

    public function getAllScraperSources() {
        $this->ensureScraperSourcesTable();
        return $this->pdo->query("SELECT * FROM scraper_sources ORDER BY id ASC")->fetchAll();
    }

    public function getActiveScraperSources() {
        $this->ensureScraperSourcesTable();
        return $this->pdo->query("SELECT * FROM scraper_sources WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
    }

    public function saveScraperSource($data) {
        $this->ensureScraperSourcesTable();
        if (isset($data['id']) && !empty($data['id'])) {
            $stmt = $this->pdo->prepare("UPDATE scraper_sources SET name = ?, base_url = ?, matches_path = ?, container_selector = ?, teams_selector = ?, link_selector = ?, live_link_selector = ?, is_active = ? WHERE id = ?");
            return $stmt->execute([
                $data['name'], $data['base_url'], $data['matches_path'], 
                $data['container_selector'], $data['teams_selector'], 
                $data['link_selector'], $data['live_link_selector'], 
                $data['is_active'], $data['id']
            ]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO scraper_sources (name, base_url, matches_path, container_selector, teams_selector, link_selector, live_link_selector, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            return $stmt->execute([
                $data['name'], $data['base_url'], $data['matches_path'], 
                $data['container_selector'], $data['teams_selector'], 
                $data['link_selector'], $data['live_link_selector'], 
                $data['is_active']
            ]);
        }
    }

    public function deleteScraperSource($id) {
        $stmt = $this->pdo->prepare("DELETE FROM scraper_sources WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
?>

