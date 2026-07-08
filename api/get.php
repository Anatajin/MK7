<?php

/**
 * API Endpoint - Get Matches
 * Returns match data in JSON format
 */

// ØªØ¶Ù…ÙŠÙ† Ø¥Ø¹Ø¯Ø§Ø¯Ø§Øª CORS
require_once __DIR__ . '/cors.php';

require_once '../config.php';
require_once '../includes/ApiResponseCache.php';
require_once '../includes/Database.php';
require_once '../includes/EmbedAccess.php';

/**
 * Ù…Ù†Ø¹ Ø§Ù„ÙˆØµÙˆÙ„ Ø§Ù„Ù…Ø¨Ø§Ø´Ø± Ù…Ù† Ø§Ù„Ù…ØªØµÙØ­ Ù„Ù…Ù„Ù get.php
 * ÙŠØ³Ù…Ø­ ÙÙ‚Ø· Ù„Ù„Ø·Ù„Ø¨Ø§Øª Ø§Ù„Ø¨Ø±Ù…Ø¬ÙŠØ© Ø§Ù„ØªÙŠ ØªØ­ØªÙˆÙŠ Ø¹Ù„Ù‰ X-API-Key Ø£Ùˆ Ø·Ù„Ø¨Ø§Øª CORS Ø§Ù„Ù…ØµØ±Ø­ Ø¨Ù‡Ø§
 */
$isApiRequest = !empty($_SERVER['HTTP_X_API_KEY']) || 
                !empty($_GET['api_key']) || 
                !empty($_SERVER['HTTP_ORIGIN']) ||
                strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'Mozilla') === false;

if (!$isApiRequest && php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Direct access to this resource is prohibited.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ØªØ­Ø³ÙŠÙ† Ø±ÙˆØ§Ø¨Ø· ØµÙˆØ± Ø§Ù„Ù„Ø§Ø¹Ø¨ÙŠÙ†
 */
function getPlayerImages($imageUrl) {
    $image = '';
    $backupImage = '';
    
    if (!empty($imageUrl)) {
        // Ø¥Ø°Ø§ ÙƒØ§Ù† Ø§Ù„Ø±Ø§Ø¨Ø· ÙŠØ­ØªÙˆÙŠ Ø¹Ù„Ù‰ "small" Ù†Ø³ØªØ¨Ø¯Ù„Ù‡ Ø¨Ù€ "medium"
        if (strpos($imageUrl, '/small/') !== false) {
            $image = str_replace('/small/', '/medium/', $imageUrl);
            $backupImage = $imageUrl; // Ø§Ù„ØµÙˆØ±Ø© Ø§Ù„ØµØºÙŠØ±Ø© ÙƒÙ†Ø³Ø®Ø© Ø§Ø­ØªÙŠØ§Ø·ÙŠØ©
        } 
        // Ø¥Ø°Ø§ ÙƒØ§Ù† Ø§Ù„Ø±Ø§Ø¨Ø· ÙŠØ­ØªÙˆÙŠ Ø¹Ù„Ù‰ "tiny" Ù†Ø³ØªØ¨Ø¯Ù„Ù‡ Ø¨Ù€ "medium"
        else if (strpos($imageUrl, '/tiny/') !== false) {
            $image = str_replace('/tiny/', '/medium/', $imageUrl);
            $backupImage = str_replace('/tiny/', '/small/', $imageUrl);
        }
        // Ø¥Ø°Ø§ ÙƒØ§Ù† Ø§Ù„Ø±Ø§Ø¨Ø· Ø¹Ø§Ø¯ÙŠ
        else {
            $image = $imageUrl;
            $backupImage = '';
        }
    }
    
    return [
        'image' => $image,
        'backupImage' => $backupImage
    ];
}

function getApiAppTimezone() {
    return defined('APP_TIMEZONE') ? APP_TIMEZONE : date_default_timezone_get();
}

function getApiStorageTimezone() {
    return defined('MATCH_STORAGE_TIMEZONE') ? MATCH_STORAGE_TIMEZONE : 'UTC';
}

function normalizeClientIpCandidate($candidate) {
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
        $parts = explode(',', $candidate);
        foreach ($parts as $part) {
            $normalized = normalizeClientIpCandidate($part);
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

function isPublicIpAddress($ip) {
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function getRequestIpCandidates() {
    $candidates = [
        $_SERVER['HTTP_X_VISITOR_IP'] ?? null,
        $_SERVER['HTTP_X_END_USER_IP'] ?? null,
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

    $publicIps = [];
    $fallbackIps = [];
    $seen = [];

    foreach ($candidates as $candidate) {
        $normalized = normalizeClientIpCandidate($candidate);
        if ($normalized === null || filter_var($normalized, FILTER_VALIDATE_IP) === false) {
            continue;
        }

        if (isset($seen[$normalized])) {
            continue;
        }

        $seen[$normalized] = true;

        if (isPublicIpAddress($normalized)) {
            $publicIps[] = $normalized;
        } else {
            $fallbackIps[] = $normalized;
        }
    }

    return array_merge($publicIps, $fallbackIps);
}

function getForwardedVisitorIpCandidates() {
    $candidates = [
        $_SERVER['HTTP_X_VISITOR_IP'] ?? null,
        $_SERVER['HTTP_X_END_USER_IP'] ?? null,
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_TRUE_CLIENT_IP'] ?? null,
        $_SERVER['HTTP_X_CLIENT_IP'] ?? null,
        $_SERVER['HTTP_CLIENT_IP'] ?? null,
        $_SERVER['HTTP_FASTLY_CLIENT_IP'] ?? null,
        $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'] ?? null,
        $_SERVER['HTTP_FORWARDED'] ?? null,
        $_SERVER['HTTP_X_REAL_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['HTTP_X_FORWARDED'] ?? null
    ];

    $publicIps = [];
    $fallbackIps = [];
    $seen = [];

    foreach ($candidates as $candidate) {
        $normalized = normalizeClientIpCandidate($candidate);
        if ($normalized === null || filter_var($normalized, FILTER_VALIDATE_IP) === false) {
            continue;
        }

        if (isset($seen[$normalized])) {
            continue;
        }

        $seen[$normalized] = true;

        if (isPublicIpAddress($normalized)) {
            $publicIps[] = $normalized;
        } else {
            $fallbackIps[] = $normalized;
        }
    }

    return array_merge($publicIps, $fallbackIps);
}

function resolveForwardedVisitorIp() {
    $candidates = getForwardedVisitorIpCandidates();
    return $candidates[0] ?? null;
}

function isDirectBrowserLikeRequest() {
    $userAgent = strtolower(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
    $isBrowserLike = $userAgent !== '' && (
        strpos($userAgent, 'mozilla/') !== false ||
        strpos($userAgent, 'chrome/') !== false ||
        strpos($userAgent, 'safari/') !== false ||
        strpos($userAgent, 'firefox/') !== false ||
        strpos($userAgent, 'edg/') !== false
    );

    $hasBrowserFetchHints = !empty($_SERVER['HTTP_SEC_FETCH_MODE']) || !empty($_SERVER['HTTP_SEC_FETCH_SITE']);
    $hasBrowserLocale = !empty($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    $requestIp = resolveRequestIp();

    return ($hasBrowserFetchHints || ($isBrowserLike && $hasBrowserLocale)) && isPublicIpAddress($requestIp);
}

function shouldEnforceForwardedVisitorIdentity(array $apiSettings) {
    $strictMode = (string)($apiSettings['enforce_forwarded_visitor_ip'] ?? '1') === '1';
    if (!$strictMode) {
        return false;
    }

    if (isDirectBrowserLikeRequest()) {
        return false;
    }

    return true;
}

function resolveRequestIp() {
    $candidates = getRequestIpCandidates();
    return $candidates[0] ?? null;
}

function getBlockedIpsPath() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . '.monitoring' . DIRECTORY_SEPARATOR . 'blocked_ips.json';
}

function readBlockedIpMap() {
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $path = getBlockedIpsPath();
    if (!is_file($path)) {
        $cache = [];
        return $cache;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        $cache = [];
        return $cache;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $cache = [];
        return $cache;
    }

    $normalized = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $ip = trim((string)($item['ip'] ?? ''));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            continue;
        }

        $normalized[$ip] = [
            'ip' => $ip,
            'blocked_at' => trim((string)($item['blocked_at'] ?? '')) ?: null,
        ];
    }

    $cache = $normalized;
    return $cache;
}

function getBlockedRequestIp() {
    $blockedIps = readBlockedIpMap();
    if (empty($blockedIps)) {
        return null;
    }

    foreach (getRequestIpCandidates() as $candidate) {
        if (isset($blockedIps[$candidate])) {
            return $candidate;
        }
    }

    return null;
}

function resolveTimezoneFromIp($ip, $defaultTimezone) {
    static $cache = [];

    $ip = normalizeClientIpCandidate($ip);
    if ($ip === null || isset($cache[$ip])) {
        return $cache[$ip] ?? $defaultTimezone;
    }

    if (!isPublicIpAddress($ip)) {
        $cache[$ip] = $defaultTimezone;
        return $cache[$ip];
    }

    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents(
            "http://ip-api.com/json/{$ip}?fields=status,message,timezone",
            false,
            $context
        );

        if ($response !== false) {
            $data = json_decode($response, true);
            $timezone = trim((string)($data['timezone'] ?? ''));

            if (($data['status'] ?? null) === 'success' && $timezone !== '') {
                $cache[$ip] = (new DateTimeZone($timezone))->getName();
                return $cache[$ip];
            }
        }
    } catch (Exception $e) {
        // Fall through to the default timezone.
    }

    $cache[$ip] = $defaultTimezone;
    return $cache[$ip];
}

function resolveTimezoneFromCountryCode($countryCode, $defaultTimezone) {
    static $cache = [];

    $countryCode = strtoupper(trim((string)$countryCode));
    if ($countryCode === '') {
        return $defaultTimezone;
    }

    if (isset($cache[$countryCode])) {
        return $cache[$countryCode];
    }

    $preferredTimezones = [
        'AE' => 'Asia/Dubai',
        'AU' => 'Australia/Sydney',
        'BH' => 'Asia/Bahrain',
        'BR' => 'America/Sao_Paulo',
        'CA' => 'America/Toronto',
        'DZ' => 'Africa/Algiers',
        'EG' => 'Africa/Cairo',
        'ES' => 'Europe/Madrid',
        'FR' => 'Europe/Paris',
        'GB' => 'Europe/London',
        'ID' => 'Asia/Jakarta',
        'IQ' => 'Asia/Baghdad',
        'JO' => 'Asia/Amman',
        'KW' => 'Asia/Kuwait',
        'LB' => 'Asia/Beirut',
        'LY' => 'Africa/Tripoli',
        'MA' => 'Africa/Casablanca',
        'MX' => 'America/Mexico_City',
        'OM' => 'Asia/Muscat',
        'PS' => 'Asia/Gaza',
        'PT' => 'Europe/Lisbon',
        'QA' => 'Asia/Qatar',
        'RU' => 'Europe/Moscow',
        'SA' => 'Asia/Riyadh',
        'SY' => 'Asia/Damascus',
        'TN' => 'Africa/Tunis',
        'TR' => 'Europe/Istanbul',
        'US' => 'America/New_York',
        'YE' => 'Asia/Aden'
    ];

    if (isset($preferredTimezones[$countryCode])) {
        $cache[$countryCode] = $preferredTimezones[$countryCode];
        return $cache[$countryCode];
    }

    try {
        $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $countryCode);
        if (!empty($identifiers)) {
            $cache[$countryCode] = $identifiers[0];
            return $cache[$countryCode];
        }
    } catch (Exception $e) {
        // Fall back to the default timezone.
    }

    $cache[$countryCode] = $defaultTimezone;
    return $cache[$countryCode];
}

function shouldUseIpTimezoneFallback() {
    $candidates = [
        $_GET['auto_timezone'] ?? null,
        $_GET['timezone_mode'] ?? null,
        $_SERVER['HTTP_X_AUTO_TIMEZONE'] ?? null
    ];

    foreach ($candidates as $candidate) {
        $candidate = strtolower(trim((string)$candidate));
        if ($candidate === '') {
            continue;
        }

        if (in_array($candidate, ['1', 'true', 'yes', 'ip', 'auto'], true)) {
            return true;
        }

        if (in_array($candidate, ['0', 'false', 'no', 'off', 'utc'], true)) {
            return false;
        }
    }

    foreach ([
        'HTTP_X_VISITOR_IP',
        'HTTP_X_END_USER_IP',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_X_CLIENT_IP',
        'HTTP_CLIENT_IP',
        'HTTP_FASTLY_CLIENT_IP',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED'
    ] as $serverKey) {
        if (!empty($_SERVER[$serverKey])) {
            return true;
        }
    }

    if (isDirectBrowserLikeRequest()) {
        return true;
    }

    return false;
}

function resolveRequestTimezone() {
    $defaultTimezone = getApiAppTimezone();
    $candidates = [
        $_GET['timezone'] ?? null,
        $_GET['tz'] ?? null,
        $_SERVER['HTTP_X_TIMEZONE'] ?? null,
        $_SERVER['HTTP_TIMEZONE'] ?? null
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }

        try {
            return (new DateTimeZone($candidate))->getName();
        } catch (Exception $e) {
            continue;
        }
    }

    $countryCandidates = [
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

    foreach ($countryCandidates as $countryCandidate) {
        $countryCandidate = strtoupper(trim((string)$countryCandidate));
        if (!preg_match('/^[A-Z]{2}$/', $countryCandidate)) {
            continue;
        }

        return resolveTimezoneFromCountryCode($countryCandidate, $defaultTimezone);
    }

    if (shouldUseIpTimezoneFallback()) {
        return resolveTimezoneFromIp(resolveRequestIp(), $defaultTimezone);
    }

    return $defaultTimezone;
}

function todayInTimezone($timezone) {
    return (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
}

function normalizeApiClockString($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
        return null;
    }

    $hour = (int)$matches[1];
    $minute = (int)$matches[2];

    if ($hour > 23 || $minute > 59) {
        return null;
    }

    return sprintf('%02d:%02d', $hour, $minute);
}

function deriveUtcStartTimeFromMatch(array $match, string $appTimezone, string $storageTimezone) {
    if (!empty($match['start_time'])) {
        try {
            return (new DateTimeImmutable($match['start_time'], new DateTimeZone($storageTimezone)))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            // Fall back to legacy fields.
        }
    }

    $scheduledTime = normalizeApiClockString($match['details_match_time'] ?? null);
    if ($scheduledTime === null) {
        $scheduledTime = normalizeApiClockString($match['match_time'] ?? null);
    }

    if ($scheduledTime === null || empty($match['match_date'])) {
        return null;
    }

    try {
        $localStart = new DateTimeImmutable($match['match_date'] . ' ' . $scheduledTime . ':00', new DateTimeZone($appTimezone));
        return $localStart
            ->setTimezone(new DateTimeZone($storageTimezone))
            ->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function isApiLiveStatus($status): bool {
    return in_array((string) $status, ['Live', 'مباشر'], true);
}

function normalizeApiLiveDisplayTime(array $match) {
    $rawMatchTime = trim((string) ($match['match_time'] ?? ''));
    if ($rawMatchTime === '') {
        return $match['match_time'] ?? null;
    }

    if (!isApiLiveStatus($match['status'] ?? $match['match_status'] ?? '')) {
        return $match['match_time'] ?? null;
    }

    if (preg_match('/^\d{1,2}:\d{2}$/', $rawMatchTime) === 1) {
        return '0';
    }

    return $match['match_time'] ?? null;
}

function transformMatchForTimezone(array $match, string $requestTimezone, string $appTimezone, string $storageTimezone) {
    $utcStartTime = deriveUtcStartTimeFromMatch($match, $appTimezone, $storageTimezone);
    $scheduledClock = normalizeApiClockString($match['match_time'] ?? null);

    $match['request_timezone'] = $requestTimezone;
    $match['source_timezone'] = $appTimezone;

    if ($utcStartTime === null) {
        return $match;
    }

    $utcDateTime = new DateTimeImmutable($utcStartTime, new DateTimeZone('UTC'));
    $localized = $utcDateTime->setTimezone(new DateTimeZone($requestTimezone));

    $match['start_time_utc'] = $utcDateTime->format('Y-m-d H:i:s');
    $match['start_time'] = $localized->format('Y-m-d H:i:s');
    $match['match_date'] = $localized->format('Y-m-d');
    $match['details_match_time'] = $localized->format('H:i');

    if ($scheduledClock !== null) {
        $match['match_time'] = $localized->format('H:i');
    }

    $match['match_time'] = normalizeApiLiveDisplayTime($match);

    return $match;
}

function buildCandidateSourceDates(string $requestedDate, string $requestTimezone, string $appTimezone) {
    try {
        $requestZone = new DateTimeZone($requestTimezone);
        $appZone = new DateTimeZone($appTimezone);
        $startLocal = new DateTimeImmutable($requestedDate . ' 00:00:00', $requestZone);
        $endLocal = $startLocal->modify('+1 day')->modify('-1 second');
        $startApp = $startLocal->setTimezone($appZone);
        $endApp = $endLocal->setTimezone($appZone);
    } catch (Exception $e) {
        return [$requestedDate];
    }

    $dates = [];
    $cursor = $startApp->setTime(0, 0, 0);
    $lastDate = $endApp->format('Y-m-d');

    while (true) {
        $dates[] = $cursor->format('Y-m-d');
        if ($cursor->format('Y-m-d') === $lastDate) {
            break;
        }
        $cursor = $cursor->modify('+1 day');
    }

    return array_values(array_unique($dates));
}

function sortMatchesForApi(array &$matches) {
    usort($matches, function ($a, $b) {
        $aLive = in_array($a['status'] ?? '', ['Live', 'Ù…Ø¨Ø§Ø´Ø±'], true) ? 0 : 1;
        $bLive = in_array($b['status'] ?? '', ['Live', 'Ù…Ø¨Ø§Ø´Ø±'], true) ? 0 : 1;
        if ($aLive !== $bLive) {
            return $aLive <=> $bLive;
        }

        $aStart = $a['start_time_utc'] ?? '';
        $bStart = $b['start_time_utc'] ?? '';
        if ($aStart !== '' || $bStart !== '') {
            return strcmp($aStart, $bStart);
        }

        return strcmp((string)($a['match_time'] ?? ''), (string)($b['match_time'] ?? ''));
    });
}

function hasMeaningfulApiMatchValue($value): bool
{
    if ($value === null) {
        return false;
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed !== '' && $trimmed !== '[]' && $trimmed !== '{}';
    }

    return true;
}

function calculateApiMatchCompletenessScore(array $match): int
{
    $score = 0;
    $fields = [
        'external_id', 'match_url', 'start_time_utc', 'details_match_time', 'channel', 'channel_logo',
        'channels_data', 'stadium_name', 'referee', 'match_summary', 'lineup', 'lineup_home',
        'lineup_away', 'standings_data', 'statistics_data', 'previous_matches_data', 'events',
        'live_url', 'live_iframe'
    ];

    foreach ($fields as $field) {
        if (hasMeaningfulApiMatchValue($match[$field] ?? null)) {
            $score += 10;
        }
    }

    if (($match['status'] ?? '') === 'Live') {
        $score += 8;
    } elseif (($match['status'] ?? '') === 'Finished') {
        $score += 6;
    }

    return $score;
}

function buildApiMatchIdentity(array $match): string
{
    $externalId = trim((string)($match['external_id'] ?? ''));
    if ($externalId !== '') {
        return 'external:' . $externalId;
    }

    $matchUrl = trim((string)($match['match_url'] ?? ''));
    if ($matchUrl !== '') {
        return 'url:' . strtolower($matchUrl);
    }

    $home = trim(mb_strtolower((string)($match['home_team_en'] ?? $match['home_team_ar'] ?? $match['home_team'] ?? ''), 'UTF-8'));
    $away = trim(mb_strtolower((string)($match['away_team_en'] ?? $match['away_team_ar'] ?? $match['away_team'] ?? ''), 'UTF-8'));
    $league = trim(mb_strtolower((string)($match['league_name_en'] ?? $match['league_name_ar'] ?? $match['league_name'] ?? ''), 'UTF-8'));
    $date = (string)($match['match_date'] ?? '');
    $start = (string)($match['start_time_utc'] ?? $match['start_time'] ?? '');
    $details = (string)($match['details_match_time'] ?? '');

    return 'teams:' . implode('|', [$home, $away, $league, $date, $start, $details]);
}

function mergeApiDuplicateMatch(array $preferred, array $candidate): array
{
    $merged = $preferred;
    $fields = [
        'external_id', 'match_url', 'detail_url', 'start_time_utc', 'start_time', 'details_match_time',
        'channels_data', 'match_summary', 'lineup', 'lineup_home', 'lineup_away',
        'standings_data', 'statistics_data', 'previous_matches_data', 'stadium_name',
        'referee', 'channel', 'channel_logo', 'commentator', 'events'
    ];

    if (($preferred['status'] ?? '') !== 'Finished') {
        $fields[] = 'live_url';
        $fields[] = 'live_iframe';
    }

    foreach ($fields as $field) {
        if (
            !hasMeaningfulApiMatchValue($merged[$field] ?? null) &&
            hasMeaningfulApiMatchValue($candidate[$field] ?? null)
        ) {
            $merged[$field] = $candidate[$field];
        }
    }

    return $merged;
}

function deduplicateMatchesForApi(array $matches): array
{
    $deduped = [];

    foreach ($matches as $match) {
        $identity = buildApiMatchIdentity($match);

        if (!isset($deduped[$identity])) {
            $deduped[$identity] = $match;
            continue;
        }

        $current = $deduped[$identity];
        $currentUpdated = strtotime((string)($current['updated_at'] ?? '')) ?: 0;
        $incomingUpdated = strtotime((string)($match['updated_at'] ?? '')) ?: 0;
        $useIncoming = false;

        if ($incomingUpdated > $currentUpdated) {
            $useIncoming = true;
        } elseif ($incomingUpdated === $currentUpdated) {
            $useIncoming = calculateApiMatchCompletenessScore($match) > calculateApiMatchCompletenessScore($current);
        }

        $preferred = $useIncoming ? $match : $current;
        $secondary = $useIncoming ? $current : $match;
        $deduped[$identity] = mergeApiDuplicateMatch($preferred, $secondary);
    }

    return array_values($deduped);
}

function buildApiCacheContext(string $action, string $planType, string $requestTimezone, array $params = []): array
{
    $scopeToken = trim((string)($GLOBALS['sport_api_cache_scope_token'] ?? ''));
    if ($scopeToken !== '') {
        $params['scope'] = $scopeToken;
    }

    ksort($params);

    return [
        'schema' => 'sport-api-response-v1',
        'action' => $action,
        'plan_type' => $planType,
        'timezone' => $requestTimezone,
        'params' => $params
    ];
}

function isSuccessfulApiPayload(array $payload): bool
{
    $status = $payload['status'] ?? null;
    return $status === 'success' || $status === true;
}

function probeApiCache(ApiResponseCache $cache, ?array $context): ?array
{
    if ($context === null || !$cache->isEnabled()) {
        header('X-API-Server-Cache: BYPASS');
        header('X-Accel-Expires: 0');
        return null;
    }

    $probe = $cache->probe($context);
    $state = strtoupper((string)($probe['state'] ?? 'BYPASS'));
    header('X-API-Server-Cache: ' . $state);

    if (($probe['state'] ?? null) === 'hit' || ($probe['state'] ?? null) === 'stale') {
        $entry = $probe['entry'] ?? [];
        $ttlRemaining = max(0, (int)(($entry['expires_at'] ?? 0) - time()));
        header('X-API-Server-Cache-TTL: ' . $ttlRemaining);
        header('X-Accel-Expires: ' . max(1, $ttlRemaining));
        http_response_code((int)($entry['status_code'] ?? 200));
        echo (string)($entry['body'] ?? '');
        exit;
    }

    return $probe;
}

function getApiCacheDateDistance(?string $date, string $requestToday): ?int
{
    if (!$date) {
        return null;
    }

    try {
        $requested = new DateTimeImmutable($date);
        $today = new DateTimeImmutable($requestToday);
        return (int)$today->diff($requested)->format('%r%a');
    } catch (Exception $e) {
        return null;
    }
}

function getApiCacheTtl(array $context, array $payload): int
{
    $action = $context['action'] ?? '';
    $params = $context['params'] ?? [];

    switch ($action) {
        case 'matches':
            $distance = getApiCacheDateDistance($params['date'] ?? null, $params['request_today'] ?? '');
            $matches = $payload['data'] ?? [];
            $hasLive = false;
            $hasTodayScheduled = false;

            foreach ($matches as $match) {
                $status = strtolower(trim((string)($match['status'] ?? $match['match_status'] ?? '')));
                if (in_array($status, ['live', 'مباشر'], true)) {
                    $hasLive = true;
                    break;
                }

                if (($match['match_date'] ?? null) === ($params['date'] ?? null)) {
                    $hasTodayScheduled = true;
                }
            }

            if ($distance === 0) {
                return $hasLive ? 10 : ($hasTodayScheduled ? 25 : 45);
            }

            if ($distance !== null && abs($distance) === 1) {
                return 90;
            }

            if ($distance !== null && abs($distance) <= 7) {
                return 300;
            }

            return 900;

        case 'match':
            $match = $payload['data'] ?? [];
            $status = strtolower(trim((string)($match['status'] ?? $match['match_status'] ?? '')));
            $distance = getApiCacheDateDistance($match['match_date'] ?? ($params['date'] ?? null), $params['request_today'] ?? '');

            if (in_array($status, ['live', 'مباشر'], true)) {
                return 10;
            }

            if ($distance === 0) {
                return 30;
            }

            if ($distance !== null && abs($distance) <= 2) {
                return 180;
            }

            return !empty($params['include_standings']) ? 300 : 600;

        case 'news':
            $limit = (int)($params['limit'] ?? 20);
            return $limit <= 5 ? 120 : 300;

        case 'news_details':
            return 600;

        case 'leagues_standings':
            return 300;

        case 'standings':
            return 240;

        case 'stats':
            return 60;

        case 'teams':
        case 'players':
            return 1800;

        case 'leagues':
        case 'countries':
            return 3600;

        default:
            return 0;
    }
}
function sendApiJsonResponse(array $payload, int $statusCode = 200, ?ApiResponseCache $cache = null, ?array $context = null, ?array $probe = null): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        if ($cache && $probe) {
            $cache->release($probe);
        }

        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to encode JSON response.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (
        $statusCode === 200 &&
        $cache !== null &&
        $context !== null &&
        $probe !== null &&
        isSuccessfulApiPayload($payload)
    ) {
        $ttl = getApiCacheTtl($context, $payload);
        header('X-API-Server-Cache-TTL: ' . max(0, $ttl));

        if ($ttl > 0) {
            header('X-Accel-Expires: ' . $ttl);
            $cache->store($probe, $json, $statusCode, $ttl, [
                'action' => $context['action'] ?? null,
                'ttl' => $ttl
            ]);
        } else {
            $cache->release($probe);
            header('X-API-Server-Cache: BYPASS');
            header('X-Accel-Expires: 0');
        }
    } elseif ($cache !== null && $probe !== null) {
        $cache->release($probe);
        header('X-Accel-Expires: 0');
    } else {
        header('X-Accel-Expires: 0');
    }

    http_response_code($statusCode);
    echo $json;
    exit;
}

function releaseApiCacheProbe(?ApiResponseCache $cache, ?array &$probe): void
{
    if ($cache !== null && $probe !== null) {
        $cache->release($probe);
    }

    $probe = null;
}

function sendApiErrorResponse(string $message, int $statusCode = 400, ?ApiResponseCache $cache = null, ?array &$probe = null): void
{
    releaseApiCacheProbe($cache, $probe);
    header('X-Accel-Expires: 0');
    http_response_code($statusCode);
    echo json_encode([
        'status' => 'error',
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function getMatchesForRequestedDate(Database $db, string $requestedDate, string $requestTimezone, string $appTimezone, string $storageTimezone) {
    $matchesById = [];

    foreach (buildCandidateSourceDates($requestedDate, $requestTimezone, $appTimezone) as $sourceDate) {
        foreach ($db->getMatches($sourceDate) as $match) {
            $matchesById[$match['id']] = $match;
        }
    }

    $filtered = [];

    foreach ($matchesById as $match) {
        $transformed = transformMatchForTimezone($match, $requestTimezone, $appTimezone, $storageTimezone);

        if (($transformed['match_date'] ?? null) === $requestedDate) {
            $filtered[] = $transformed;
            continue;
        }

        if (empty($transformed['start_time_utc']) && ($match['match_date'] ?? null) === $requestedDate) {
            $filtered[] = $transformed;
        }
    }

    $filtered = deduplicateMatchesForApi($filtered);
    sortMatchesForApi($filtered);
    return $filtered;
}

$db = new Database($pdo);
$apiResponseCache = new ApiResponseCache();
$cacheProbe = null;
$requestTimezone = resolveRequestTimezone();
$appTimezone = getApiAppTimezone();
$storageTimezone = getApiStorageTimezone();
$requestToday = todayInTimezone($requestTimezone);

// Ù…Ù†Ø¹ Ø§Ù„ØªØ®Ø²ÙŠÙ† Ø§Ù„Ù…Ø¤Ù‚Øª (Cache) Ù„Ø¶Ù…Ø§Ù† Ø¹Ø¯Ù… ØªØ®Ø²ÙŠÙ† Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ø­Ø³Ø§Ø³Ø© Ø£Ùˆ Ø§Ù„Ø§Ø³ØªØ¬Ø§Ø¨Ø§Øª
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

$blockedRequestIp = getBlockedRequestIp();
if ($blockedRequestIp !== null) {
    sendApiErrorResponse(
        'This IP address is blocked from accessing the API.',
        403,
        $apiResponseCache,
        $cacheProbe
    );
}

// Ø§Ù„ØªØ­Ù‚Ù‚ Ù…Ù† Ù…ÙØªØ§Ø­ API
$apiSettings = $db->getApiSettings();
$apiKeyRequired = ($apiSettings['api_key_required'] ?? '1') == '1';

$apiKey = isset($_SERVER['HTTP_X_API_KEY']) ? htmlspecialchars(trim($_SERVER['HTTP_X_API_KEY'])) : (isset($_GET['api_key']) ? htmlspecialchars(trim($_GET['api_key'])) : null);

if ($apiKeyRequired && empty($apiKey)) {
    sendApiErrorResponse(
        'API key is required. Please provide X-API-Key header or api_key parameter.',
        401,
        $apiResponseCache,
        $cacheProbe
    );
}

// Ù…Ø¹Ø§Ù„Ø¬Ø© Ø§Ù„Ø·Ù„Ø¨
$action = isset($_GET['action']) ? htmlspecialchars(trim($_GET['action'])) : 'matches';
$origin = isset($_SERVER['HTTP_ORIGIN']) ? htmlspecialchars(trim($_SERVER['HTTP_ORIGIN'])) : null;
$embedAllowedOrigin = null;
$validKey = [];
$apiCoverageFilters = [
    'restrict_leagues' => false,
    'selected_league_ids' => [],
    'selected_league_name_to_id' => [],
    'selected_country_names' => [],
];

if ($apiKey) {
    $validKey = $db->validateApiKey($apiKey, $origin);
    if (!$validKey) {
        sendApiErrorResponse('Invalid or inactive API key.', 403, $apiResponseCache, $cacheProbe);
    }

    $isPublicDevKey = !empty($validKey['is_public_key']) && !empty($validKey['dev_mode_active']);
    $shouldRequireForwardedVisitorIp = shouldEnforceForwardedVisitorIdentity($apiSettings) && !$isPublicDevKey;

    if ($shouldRequireForwardedVisitorIp) {
        $forwardedVisitorIp = resolveForwardedVisitorIp();
        if ($forwardedVisitorIp === null) {
            sendApiErrorResponse(
                'This API key requires the real visitor IP to be forwarded. Please send X-Visitor-IP, X-End-User-IP, or X-Forwarded-For.',
                403,
                $apiResponseCache,
                $cacheProbe
            );
        }
    }

    // ØªØ­Ø¯ÙŠØ« Ø§Ø³ØªØ®Ø¯Ø§Ù… Ø§Ù„Ù…ÙØªØ§Ø­
    $db->updateApiKeyUsage($apiKey);
    $db->logApiRequest($apiKey, $action);
    $embedAllowedOrigin = embedAccessResolveApiAllowedOrigin($origin, $validKey);
    $apiCoverageFilters = buildApiCoverageFilters($validKey, $db);
}

$GLOBALS['sport_api_cache_scope_token'] = buildApiCoverageScopeToken($validKey, $embedAllowedOrigin, $apiCoverageFilters);
// Filter and sanitize Date
$dateRequested = isset($_GET['date']) ? htmlspecialchars(trim($_GET['date'])) : $requestToday;
$date = $dateRequested;

// Basic date validation Y-m-d
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = $requestToday;
}

/**
 * Ø¯Ø§Ù„Ø© Ù„ØªØµÙÙŠØ© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª Ø¨Ù†Ø§Ø¡Ù‹ Ø¹Ù„Ù‰ Ù†ÙˆØ¹ Ø§Ù„Ø®Ø·Ø©
 */
function stripRestrictedData(&$data, $planType) {
    if ($planType === 'premium') return;

    // Ø§Ù„Ø¹Ù†Ø§ØµØ± Ø§Ù„ØªÙØµÙŠÙ„ÙŠØ© (Ø§Ù„Ø£Ø­Ø¯Ø§Ø«ØŒ Ø§Ù„ØªØ´ÙƒÙŠÙ„Ø§ØªØŒ Ø¥Ù„Ø®)
    $detailFields = [
        'events', 'lineup_home', 'lineup_away', 'summary', 'match_summary', 
        'summary_data', 'standings_data', 'previous_matches_data', 'previous_meetings',
        'stats', 'statistics', 'match_statistics', 'statistics_data', 
        'detail_url', 'standings'
    ];
    
    // Ø±ÙˆØ§Ø¨Ø· Ø§Ù„Ø¨Ø« Ø§Ù„Ù…Ø¨Ø§Ø´Ø±
    $streamingFields = ['live_url', 'live_iframe'];

    if ($planType === 'free') {
        // Ø§Ù„Ø®Ø·Ø© Ø§Ù„Ù…Ø¬Ø§Ù†ÙŠØ©: Ø­Ø¬Ø¨ ÙƒÙ„ Ø´ÙŠØ¡
        foreach (array_merge($detailFields, $streamingFields) as $field) {
            unset($data[$field]);
        }
        if (isset($data['match_details'])) {
            unset($data['match_details']['live_url']);
            unset($data['match_details']['streaming']);
        }
    } elseif ($planType === 'professional') {
        // Ø§Ù„Ø®Ø·Ø© Ø§Ù„Ø§Ø­ØªØ±Ø§ÙÙŠØ©: Ø­Ø¬Ø¨ Ø§Ù„Ø¨Ø« Ø§Ù„Ù…Ø¨Ø§Ø´Ø± ÙÙ‚Ø·ØŒ ÙˆØ§Ù„Ø³Ù…Ø§Ø­ Ø¨Ø§Ù„Ø¹Ù†Ø§ØµØ± Ø§Ù„Ø£Ø®Ø±Ù‰
        foreach ($streamingFields as $field) {
            unset($data[$field]);
        }
        if (isset($data['match_details'])) {
            unset($data['match_details']['live_url']);
        }
    }
}

function secureApiStreamValue($value, $allowedOrigin, $matchId = null) {
    if (!is_string($value)) {
        return $value;
    }

    $value = trim($value);
    if ($value === '' || $allowedOrigin === null) {
        return $value;
    }

    $value = embedAccessInjectMatchIdIntoProtectedValue($value, $matchId);

    if (stripos($value, '<iframe') !== false) {
        return embedAccessSignIframeHtml($value, $allowedOrigin);
    }

    if (embedAccessIsProtectedLocalUrl($value)) {
        return embedAccessSignLocalUrl($value, $allowedOrigin);
    }

    return $value;
}

function secureApiStreamPayload(&$data, $allowedOrigin) {
    if (!is_array($data) || $allowedOrigin === null) {
        return;
    }

    $matchId = (int)($data['id'] ?? $data['match_id'] ?? 0);

    foreach (['live_iframe', 'live_url'] as $field) {
        if (isset($data[$field])) {
            $data[$field] = secureApiStreamValue($data[$field], $allowedOrigin, $matchId);
        }
    }

    if (isset($data['match_details']) && is_array($data['match_details'])) {
        $detailMatchId = (int)($data['match_details']['id'] ?? $data['match_details']['match_id'] ?? $matchId);
        foreach (['live_iframe', 'live_url'] as $field) {
            if (isset($data['match_details'][$field])) {
                $data['match_details'][$field] = secureApiStreamValue($data['match_details'][$field], $allowedOrigin, $detailMatchId);
            }
        }
    }
}

function normalizeApiCoverageValue($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/u', ' ', $value);

    return mb_strtolower($value, 'UTF-8');
}

function buildApiCoverageFilters(array $validKey, Database $db) {
    $restrictLeagues = !empty($validKey['restrict_league_scope']);
    $selectedLeagueIds = [];
    if ($restrictLeagues) {
        foreach ((array)($validKey['allowed_league_ids'] ?? []) as $leagueId) {
            $leagueId = (int)$leagueId;
            if ($leagueId > 0) {
                $selectedLeagueIds[$leagueId] = true;
            }
        }
    }

    $selectedLeagueNameToId = [];
    $selectedCountryNames = [];

    if ($restrictLeagues) {
        foreach ($db->getAllLeagues() as $league) {
            $leagueId = (int)($league['id'] ?? 0);
            if ($leagueId <= 0 || !isset($selectedLeagueIds[$leagueId])) {
                continue;
            }

            foreach (apiCoverageExtractLeagueNames($league) as $nameKey) {
                if ($nameKey !== '') {
                    $selectedLeagueNameToId[$nameKey] = $leagueId;
                }
            }

            $countryName = normalizeApiCoverageValue($league['country'] ?? '');
            if ($countryName !== '') {
                $selectedCountryNames[$countryName] = true;
            }
        }
    }

    return [
        'restrict_leagues' => $restrictLeagues,
        'selected_league_ids' => $selectedLeagueIds,
        'selected_league_name_to_id' => $selectedLeagueNameToId,
        'selected_country_names' => $selectedCountryNames,
    ];
}

function apiCoverageHasRestrictions(array $filters): bool {
    return !empty($filters['restrict_leagues']);
}

function apiCoverageExtractLeagueNames(array $row): array {
    $names = [];

    foreach (['league_name', 'name', 'league_name_ar', 'name_ar', 'league_name_en', 'name_en'] as $field) {
        $value = normalizeApiCoverageValue($row[$field] ?? '');
        if ($value !== '') {
            $names[$value] = true;
        }
    }

    return array_keys($names);
}

function apiCoverageExtractExplicitLeagueId(array $row): ?int {
    foreach (['league_id', 'leagueId'] as $field) {
        $leagueId = (int)($row[$field] ?? 0);
        if ($leagueId > 0) {
            return $leagueId;
        }
    }

    $looksLikeLeagueRow = array_key_exists('name', $row)
        || array_key_exists('name_ar', $row)
        || array_key_exists('name_en', $row)
        || array_key_exists('logo_url', $row)
        || array_key_exists('standings_updated_at', $row);

    if ($looksLikeLeagueRow) {
        $rowId = (int)($row['id'] ?? 0);
        if ($rowId > 0) {
            return $rowId;
        }
    }

    return null;
}

function apiCoverageResolveSelectedLeagueId(array $row, array $filters): ?int {
    $leagueId = apiCoverageExtractExplicitLeagueId($row);
    if ($leagueId !== null && isset($filters['selected_league_ids'][$leagueId])) {
        return $leagueId;
    }

    foreach (apiCoverageExtractLeagueNames($row) as $leagueName) {
        if (isset($filters['selected_league_name_to_id'][$leagueName])) {
            return (int)$filters['selected_league_name_to_id'][$leagueName];
        }
    }

    return null;
}

function apiCoverageLeagueSelected(array $row, array $filters): bool {
    if (!apiCoverageHasRestrictions($filters)) {
        return true;
    }

    if (empty($filters['selected_league_ids']) && empty($filters['selected_league_name_to_id'])) {
        return false;
    }

    return apiCoverageResolveSelectedLeagueId($row, $filters) !== null;
}

function apiCoverageMatchAllowed(array $match, array $filters): bool {
    return apiCoverageLeagueSelected($match, $filters);
}

function apiCoverageLeagueAllowed(array $league, array $filters): bool {
    return apiCoverageLeagueSelected($league, $filters);
}

function apiCoverageCountryAllowed(array $country, array $filters): bool {
    if (!apiCoverageHasRestrictions($filters)) {
        return true;
    }

    if (empty($filters['selected_country_names'])) {
        return true;
    }

    foreach (['country', 'name', 'name_ar', 'name_en'] as $field) {
        $countryName = normalizeApiCoverageValue($country[$field] ?? '');
        if ($countryName !== '' && !empty($filters['selected_country_names'][$countryName])) {
            return true;
        }
    }

    return false;
}

function buildApiCoverageScopeToken(array $validKey, $allowedOrigin, array $filters): string {
    $leagueIds = array_map('intval', array_keys($filters['selected_league_ids'] ?? []));
    sort($leagueIds);

    return sha1(json_encode([
        'plan' => (string)($validKey['effective_plan_type'] ?? $validKey['plan_type'] ?? 'free'),
        'origin' => (string)$allowedOrigin,
        'restrict_leagues' => !empty($filters['restrict_leagues']),
        'leagues' => $leagueIds,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// Plan-based restrictions
$planType = trim((string)($validKey['effective_plan_type'] ?? ($validKey['plan_type'] ?? 'free')));
if ($planType === '') {
    $planType = 'free';
}

if ($planType === 'free') {
    // Free plan: Only matches, match details, and leagues are allowed
    if ($action !== 'matches' && $action !== 'match' && $action !== 'leagues') {
        sendApiErrorResponse('This action is not available in the Free plan.', 403, $apiResponseCache, $cacheProbe);
    }
    // Free plan: Force date to today only
    $date = $requestToday;
} elseif ($planType === 'professional') {
    // Professional plan: Allows all actions (news, leagues, etc.)
    // but streaming is still restricted in stripRestrictedData()
}
// Premium plan: All actions allowed

try {
    switch ($action) {
        case 'matches':
            $cacheContext = buildApiCacheContext('matches', $planType, $requestTimezone, [
                'date' => $date,
                'request_today' => $requestToday
            ]);
            $cacheProbe = probeApiCache($apiResponseCache, $cacheContext);
            // Ø¬Ù„Ø¨ Ø§Ù„Ù…Ø¨Ø§Ø±ÙŠØ§Øª
            $matches = getMatchesForRequestedDate($db, $date, $requestTimezone, $appTimezone, $storageTimezone);
            $matches = array_values(array_filter($matches, function ($match) use ($apiCoverageFilters) {
                return apiCoverageMatchAllowed($match, $apiCoverageFilters);
            }));
            
            // ØªØ·Ø¨ÙŠÙ‚ Ø§Ù„Ù‚ÙŠÙˆØ¯ Ø¹Ù„Ù‰ Ø§Ù„Ù‚Ø§Ø¦Ù…Ø©
            foreach ($matches as &$m) {
                stripRestrictedData($m, $planType);
                secureApiStreamPayload($m, $embedAllowedOrigin);
            }

            sendApiJsonResponse([
                'status' => 'success',
                'date' => $date,
                'timezone' => $requestTimezone,
                'storage_timezone' => 'UTC',
                'count' => count($matches),
                'data' => $matches
            ], 200, $apiResponseCache, $cacheContext, $cacheProbe);
            
        case 'match':
            // Ø¬Ù„Ø¨ Ù…Ø¨Ø§Ø±Ø§Ø© ÙˆØ§Ø­Ø¯Ø©
            $matchId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
            $includeStandings = isset($_GET['include_standings']) && $_GET['include_standings'] === 'true';
            
            if (!$matchId) {
                sendApiErrorResponse('Match ID is required', 400, $apiResponseCache, $cacheProbe);
            }

            $cacheContext = buildApiCacheContext('match', $planType, $requestTimezone, [
                'id' => $matchId,
                'include_standings' => $includeStandings ? 1 : 0,
                'request_today' => $requestToday
            ]);
            $cacheProbe = probeApiCache($apiResponseCache, $cacheContext);
            
            // Ø¬Ù„Ø¨ Ø§Ù„Ù…Ø¨Ø§Ø±Ø§Ø© Ù…Ø¹ Ø£Ùˆ Ø¨Ø¯ÙˆÙ† Ø§Ù„ØªØ±ØªÙŠØ¨
            if ($includeStandings) {
                $match = $db->getMatchWithStandings($matchId);
            } else {
                $match = $db->getMatchById($matchId);
            }
            
            if (!$match) {
                sendApiErrorResponse('Match not found', 404, $apiResponseCache, $cacheProbe);
            }

            $match = transformMatchForTimezone($match, $requestTimezone, $appTimezone, $storageTimezone);

            if (!apiCoverageMatchAllowed($match, $apiCoverageFilters)) {
                sendApiErrorResponse('This match is not available for the current API key.', 403, $apiResponseCache, $cacheProbe);
            }

            // Free plan: Restriction to today's matches only
            if ($planType === 'free' && $match['match_date'] !== $requestToday) {
                sendApiErrorResponse('Free plan is limited to today\'s matches only.', 403, $apiResponseCache, $cacheProbe);
            }
            
            // Ù…Ø¹Ø§Ù„Ø¬Ø© Ø§Ù„Ø£Ø­Ø¯Ø§Ø« Ø­ÙŠØ« Ø£Ù†Ù‡Ø§ ØªØ£ØªÙŠ Ø§Ù„Ø¢Ù† ÙƒÙ€ JSON ÙÙŠ Ù†ÙØ³ Ø¬Ø¯ÙˆÙ„ Ø§Ù„Ù…Ø¨Ø§Ø±ÙŠØ§Øª
            if (!empty($match['events']) && is_string($match['events'])) {
                $decodedEvents = json_decode($match['events'], true);
                $match['events'] = is_array($decodedEvents) ? $decodedEvents : [];
            } else {
                $match['events'] = [];
            }

            // Ø¥Ø«Ø±Ø§Ø¡ Ø§Ù„ØªØ´ÙƒÙŠÙ„Ø© Ø¨Ø§Ù„ØªØ±Ø¬Ù…Ø§Øª
            if (isset($match['lineup_home']) || isset($match['lineup_away'])) {
                
                // Ø¯Ø§Ù„Ø© Ù„Ø¥Ø¶Ø§ÙØ© Ø§Ù„ØªØ±Ø¬Ù…Ø§Øª ÙˆØ§Ù„ØµÙˆØ± Ø§Ù„Ù…Ø­Ø³Ù†Ø© Ù„Ù„ØªØ´ÙƒÙŠÙ„Ø©
                if (!function_exists('enrichLineupWithTranslations')) {
                    function enrichLineupWithTranslations($lineup, $pdo) {
                        if (empty($lineup)) return $lineup;
                        
                        $lineupData = json_decode($lineup, true);
                        if (!$lineupData) return $lineup;
                        
                        // Ø¬Ù…Ø¹ ÙƒÙ„ Ø£Ø³Ù…Ø§Ø¡ Ø§Ù„Ù„Ø§Ø¹Ø¨ÙŠÙ† Ù…Ù† Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù‚ÙˆØ§Ø¦Ù…
                        $allNames = [];
                        
                        // Ù…Ù† Ø§Ù„ØªØ´ÙƒÙŠÙ„Ø© Ø§Ù„Ø£Ø³Ø§Ø³ÙŠØ©
                        if (isset($lineupData['starting']) && is_array($lineupData['starting'])) {
                            foreach ($lineupData['starting'] as $player) {
                                if (isset($player['name'])) {
                                    $allNames[] = $player['name'];
                                }
                            }
                        }
                        
                        // Ù…Ù† Ø§Ù„Ø¨Ø¯Ù„Ø§Ø¡
                        if (isset($lineupData['substitutes']) && is_array($lineupData['substitutes'])) {
                            foreach ($lineupData['substitutes'] as $player) {
                                if (isset($player['name'])) {
                                    $allNames[] = $player['name'];
                                }
                            }
                        }
                        
                        // Ù…Ù† Ø§Ù„Ù…ØµØ§Ø¨ÙŠÙ†/Ø§Ù„ØºØ§Ø¦Ø¨ÙŠÙ†
                        $missingKeys = ['missing', 'missing_players', 'injured', 'unavailable'];
                        foreach ($missingKeys as $mKey) {
                            if (isset($lineupData[$mKey]) && is_array($lineupData[$mKey])) {
                                foreach ($lineupData[$mKey] as $player) {
                                    if (isset($player['name'])) {
                                        $allNames[] = $player['name'];
                                    } elseif (isset($player['player_name'])) {
                                        $allNames[] = $player['player_name'];
                                    }
                                }
                            }
                        }
                        
                        if (empty($allNames)) return $lineup;
                        
                        // Ø¬Ù„Ø¨ Ø¬Ù…ÙŠØ¹ Ø§Ù„ØªØ±Ø¬Ù…Ø§Øª ÙˆØ§Ù„ØµÙˆØ± Ø¯ÙØ¹Ø© ÙˆØ§Ø­Ø¯Ø©
                        // Ø§Ù„Ø¨Ø­Ø« ÙÙŠ name, name_ar, name_en
                        $placeholders = str_repeat('?,', count($allNames) - 1) . '?';
                        $stmt = $pdo->prepare("
                            SELECT name, name_ar, name_en, image_url 
                            FROM players 
                            WHERE LOWER(TRIM(name)) IN ($placeholders)
                               OR LOWER(TRIM(name_ar)) IN ($placeholders)
                               OR LOWER(TRIM(name_en)) IN ($placeholders)
                        ");
                        // ØªÙ…Ø±ÙŠØ± Ø§Ù„Ø£Ø³Ù…Ø§Ø¡ 3 Ù…Ø±Ø§Øª (Ù„ÙƒÙ„ Ø­Ù‚Ù„)
                        $lowerNames = array_map(function($n) { return strtolower(trim($n)); }, $allNames);
                        $stmt->execute(array_merge($lowerNames, $lowerNames, $lowerNames));
                        
                        $translations = [];
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            // ÙÙ‡Ø±Ø³Ø© Ø¨Ø¬Ù…ÙŠØ¹ Ø§Ù„Ø£Ø´ÙƒØ§Ù„ Ø§Ù„Ù…Ù…ÙƒÙ†Ø© Ù„Ù„Ø§Ø³Ù…
                            $keys = [
                                strtolower(trim($row['name'])),
                                strtolower(trim($row['name_ar'])),
                                strtolower(trim($row['name_en']))
                            ];
                            
                            // ØªØ­Ø³ÙŠÙ† Ø±ÙˆØ§Ø¨Ø· Ø§Ù„ØµÙˆØ±
                            $images = getPlayerImages($row['image_url']);
                            
                            $playerData = [
                                'name_ar' => $row['name_ar'] ?: $row['name'],
                                'name_en' => $row['name_en'] ?: $row['name'],
                                'image' => $images['image'],
                                'backupImage' => $images['backupImage']
                            ];
                            
                            // Ø­ÙØ¸ Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª Ø¨Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù…ÙØ§ØªÙŠØ­ Ø§Ù„Ù…Ù…ÙƒÙ†Ø©
                            foreach ($keys as $key) {
                                if (!empty($key)) {
                                    $translations[$key] = $playerData;
                                }
                            }
                        }
                        
                        // Ø¯Ø§Ù„Ø© Ù„Ø¥Ø«Ø±Ø§Ø¡ Ù‚Ø§Ø¦Ù…Ø© Ø§Ù„Ù„Ø§Ø¹Ø¨ÙŠÙ†
                        $enrichPlayers = function(&$players) use ($translations) {
                            if (!is_array($players)) return;
                            
                            foreach ($players as &$player) {
                                $playerName = $player['name'] ?? $player['player_name'] ?? null;
                                
                                if ($playerName) {
                                    $key = strtolower(trim($playerName));
                                    if (isset($translations[$key])) {
                                        // Ø¥Ø¶Ø§ÙØ© Ø§Ù„ØªØ±Ø¬Ù…Ø§Øª
                                        $player['name_ar'] = $translations[$key]['name_ar'];
                                        $player['name_en'] = $translations[$key]['name_en'];
                                        
                                        // Ø¥Ø¶Ø§ÙØ© Ø§Ù„ØµÙˆØ± Ø§Ù„Ù…Ø­Ø³Ù†Ø© (ÙÙ‚Ø· Ø¥Ø°Ø§ Ù„Ù… ØªÙƒÙ† Ù…ÙˆØ¬ÙˆØ¯Ø© Ø£Ùˆ ÙƒØ§Ù†Øª ÙØ§Ø±ØºØ©)
                                        if (empty($player['image']) && !empty($translations[$key]['image'])) {
                                            $player['image'] = $translations[$key]['image'];
                                            $player['backupImage'] = $translations[$key]['backupImage'];
                                        } else if (!empty($player['image'])) {
                                            // ØªØ­Ø³ÙŠÙ† Ø§Ù„ØµÙˆØ±Ø© Ø§Ù„Ù…ÙˆØ¬ÙˆØ¯Ø©
                                            $images = getPlayerImages($player['image']);
                                            $player['image'] = $images['image'];
                                            $player['backupImage'] = $images['backupImage'];
                                        }
                                    }
                                }
                            }
                        };
                        
                        // ØªØ·Ø¨ÙŠÙ‚ Ø§Ù„ØªØ±Ø¬Ù…Ø§Øª ÙˆØ§Ù„ØµÙˆØ± Ø¹Ù„Ù‰ Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù‚ÙˆØ§Ø¦Ù…
                        if (isset($lineupData['starting'])) {
                            $enrichPlayers($lineupData['starting']);
                        }
                        if (isset($lineupData['substitutes'])) {
                            $enrichPlayers($lineupData['substitutes']);
                        }
                        // Ù…Ø¹Ø§Ù„Ø¬Ø© Ø¬Ù…ÙŠØ¹ Ø£Ù†ÙˆØ§Ø¹ Ø§Ù„Ù„Ø§Ø¹Ø¨ÙŠÙ† Ø§Ù„ØºØ§Ø¦Ø¨ÙŠÙ†
                        $missingKeys = ['missing', 'missing_players', 'injured', 'unavailable', 'injuries', 'suspensions', 'suspended', 'absent'];
                        foreach ($missingKeys as $mKey) {
                            if (isset($lineupData[$mKey])) {
                                $enrichPlayers($lineupData[$mKey]);
                            }
                        }
                        
                        return json_encode($lineupData, JSON_UNESCAPED_UNICODE);
                    }
                }
                
                // ØªØ·Ø¨ÙŠÙ‚ Ø§Ù„Ø¥Ø«Ø±Ø§Ø¡ Ø¹Ù„Ù‰ Ø§Ù„ØªØ´ÙƒÙŠÙ„ØªÙŠÙ†
                if (isset($match['lineup_home'])) {
                    $match['lineup_home'] = enrichLineupWithTranslations($match['lineup_home'], $pdo);
                }
                if (isset($match['lineup_away'])) {
                    $match['lineup_away'] = enrichLineupWithTranslations($match['lineup_away'], $pdo);
                }

                // Ø¥Ø«Ø±Ø§Ø¡ Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„Ù…Ø¯Ø±Ø¨ (Home & Away)
                foreach (['lineup_home', 'lineup_away'] as $lKey) {
                    if (isset($match[$lKey])) {
                        $lineupData = json_decode($match[$lKey], true);
                        if ($lineupData && isset($lineupData['coach'])) {
                            $coachName = trim($lineupData['coach']);
                            
                            // Ø§Ù„Ø¨Ø­Ø« Ø¹Ù† Ø§Ù„Ù…Ø¯Ø±Ø¨ ÙÙŠ Ù‚Ø§Ø¹Ø¯Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª
                            $stmt = $pdo->prepare("
                                SELECT name_ar, name_en, image_url 
                                FROM players 
                                WHERE (LOWER(TRIM(name)) = LOWER(?) OR LOWER(TRIM(name_ar)) = LOWER(?) OR LOWER(TRIM(name_en)) = LOWER(?))
                                AND position = 'Coach'
                                LIMIT 1
                            ");
                            $stmt->execute([$coachName, $coachName, $coachName]);
                            $coachData = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($coachData) {
                                $lineupData['coach_ar'] = $coachData['name_ar'] ?: $lineupData['coach'];
                                $lineupData['coach_en'] = $coachData['name_en'] ?: $lineupData['coach'];
                                
                                $coachImg = $coachData['image_url'] ?: ($lineupData['coach_image'] ?? '');
                                if ($coachImg) {
                                    $imgInfo = getPlayerImages($coachImg);
                                    $lineupData['coach_image'] = $imgInfo['image'];
                                    $lineupData['coach_backupImage'] = $imgInfo['backupImage'];
                                } else {
                                    $lineupData['coach_image'] = 'https://cdn.sportfeeds.io/sdl/images/person/head/medium/default.png';
                                    $lineupData['coach_backupImage'] = '';
                                }
                            } else {
                                // Ø¥Ø°Ø§ Ù„Ù… ÙŠÙˆØ¬Ø¯ ÙÙŠ Ø§Ù„Ù‚Ø§Ø¹Ø¯Ø©ØŒ Ù†Ø¶Ø¹ Ù‚ÙŠÙ… Ø§ÙØªØ±Ø§Ø¶ÙŠØ©
                                $lineupData['coach_ar'] = $lineupData['coach_ar'] ?? $lineupData['coach'];
                                $lineupData['coach_en'] = $lineupData['coach_en'] ?? $lineupData['coach'];
                                
                                $coachImg = $lineupData['coach_image'] ?? '';
                                if ($coachImg) {
                                    $imgInfo = getPlayerImages($coachImg);
                                    $lineupData['coach_image'] = $imgInfo['image'];
                                    $lineupData['coach_backupImage'] = $imgInfo['backupImage'];
                                } else {
                                    $lineupData['coach_image'] = 'https://cdn.sportfeeds.io/sdl/images/person/head/medium/default.png';
                                    $lineupData['coach_backupImage'] = '';
                                }
                            }
                            
                            $match[$lKey] = json_encode($lineupData, JSON_UNESCAPED_UNICODE);
                        }
                    }
                }
            }

            // Ø¥Ø«Ø±Ø§Ø¡ Ø£Ø­Ø¯Ø§Ø« Ø§Ù„Ù…Ø¨Ø§Ø±Ø§Ø© Ø¨Ø§Ù„ØªØ±Ø¬Ù…Ø§Øª ÙˆØ§Ù„ØµÙˆØ±
            if (isset($match['events']) && is_array($match['events'])) {
                
                // Ø¬Ù…Ø¹ Ø£Ø³Ù…Ø§Ø¡ Ø§Ù„Ù„Ø§Ø¹Ø¨ÙŠÙ† Ù…Ù† Ø§Ù„Ø£Ø­Ø¯Ø§Ø«
                $eventPlayerNames = [];
                foreach ($match['events'] as $event) {
                    if (isset($event['player_name'])) {
                        $eventPlayerNames[] = $event['player_name'];
                    }
                    if (isset($event['player_name_secondary'])) {
                        $eventPlayerNames[] = $event['player_name_secondary'];
                    }
                }
                
                // Ø¬Ù„Ø¨ Ø§Ù„ØªØ±Ø¬Ù…Ø§Øª ÙˆØ§Ù„ØµÙˆØ±
                $eventTranslations = [];
                if (!empty($eventPlayerNames)) {
                    $placeholders = str_repeat('LOWER(TRIM(?)),', count($eventPlayerNames) - 1) . 'LOWER(TRIM(?))';
                    $stmt = $pdo->prepare("
                        SELECT name, name_ar, name_en, image_url 
                        FROM players 
                        WHERE LOWER(TRIM(name)) IN ($placeholders)
                    ");
                    $stmt->execute($eventPlayerNames);
                    
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $key = strtolower(trim($row['name']));
                        $images = getPlayerImages($row['image_url']);
                        
                        $eventTranslations[$key] = [
                            'name_ar' => $row['name_ar'] ?: $row['name'],
                            'name_en' => $row['name_en'] ?: $row['name'],
                            'image' => $images['image'],
                            'backupImage' => $images['backupImage']
                        ];
                    }
                }
                
                // ØªØ·Ø¨ÙŠÙ‚ Ø§Ù„ØªØ±Ø¬Ù…Ø§Øª Ø¹Ù„Ù‰ Ø§Ù„Ø£Ø­Ø¯Ø§Ø«
                foreach ($match['events'] as &$event) {
                    // Ø§Ù„Ù„Ø§Ø¹Ø¨ Ø§Ù„Ø£Ø³Ø§Ø³ÙŠ
                    if (isset($event['player_name'])) {
                        $key = strtolower(trim($event['player_name']));
                        if (isset($eventTranslations[$key])) {
                            $event['player_name_ar'] = $eventTranslations[$key]['name_ar'];
                            $event['player_name_en'] = $eventTranslations[$key]['name_en'];
                        }
                    }
                    
                    // Ø§Ù„Ù„Ø§Ø¹Ø¨ Ø§Ù„Ø«Ø§Ù†ÙˆÙŠ (ÙÙŠ Ø­Ø§Ù„Ø© Ø§Ù„ØªØ¨Ø¯ÙŠÙ„)
                    if (isset($event['player_name_secondary'])) {
                        $key = strtolower(trim($event['player_name_secondary']));
                        if (isset($eventTranslations[$key])) {
                            $event['player_name_secondary_ar'] = $eventTranslations[$key]['name_ar'];
                            $event['player_name_secondary_en'] = $eventTranslations[$key]['name_en'];
                        }
                    }
                }
            }
            
            // Ù…Ø¹Ø§Ù„Ø¬Ø© Ø§Ù„Ù…ÙˆØ§Ø¬Ù‡Ø§Øª Ø§Ù„Ø³Ø§Ø¨Ù‚Ø© (H2H)
            if (!empty($match['previous_matches_data'])) {
                $match['previous_meetings'] = json_decode($match['previous_matches_data'], true);
            } else {
                $match['previous_meetings'] = null;
            }

            // ØªØ¬Ù…ÙŠØ¹ ØªÙØ§ØµÙŠÙ„ Ø§Ù„Ù…Ø¨Ø§Ø±Ø§Ø© ÙÙŠ Ù…ØµÙÙˆÙØ© ÙˆØ§Ø­Ø¯Ø© ÙƒÙ…Ø§ Ø·Ù„Ø¨ Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù…
            $refereeValue = $match['referee'] ?? null;
            if (is_string($refereeValue)) {
                $decodedReferee = json_decode($refereeValue, true);
                if (json_last_error() === JSON_ERROR_NONE && $decodedReferee !== null) {
                    $refereeValue = $decodedReferee;
                }
            }

            $match['match_details'] = [
                'league_id'     => $match['league_id'] ?? null,
                'league_name'   => $match['league_name'],
                'league_logo'   => $match['league_logo'] ?? null,
                'stadium'       => $match['stadium_name'] ?? null,
                'channel'       => $match['channel'] ?? $match['channels_data'] ?? null,
                'channel_logo'  => $match['channel_logo'] ?? null,
                'referee'       => $refereeValue,
                'match_time'    => $match['match_time'] ?? null,
                'details_match_time'    => $match['details_match_time'] ?? null,
                'match_date'    => $match['match_date'] ?? null,
                'start_time_utc' => $match['start_time_utc'] ?? $match['start_time'] ?? null,
                'timezone'      => $requestTimezone
            ];

            // handling streaming URL based on plan
            if ($planType !== 'free') {
                $match['match_details']['live_url'] = $match['live_url'] ?? null;
            }

            // ØªØ·Ø¨ÙŠÙ‚ Ø§Ù„Ù‚ÙŠÙˆØ¯ Ø§Ù„Ù†Ù‡Ø§Ø¦ÙŠØ©
            stripRestrictedData($match, $planType);
            
            // ØªÙ†Ø¸ÙŠÙ Ø§Ù„Ø­Ù‚ÙˆÙ„ Ø§Ù„Ù‚Ø¯ÙŠÙ…Ø© Ù…Ù† Ù…ØµÙÙˆÙØ© Ø§Ù„Ù…Ø¨Ø§Ø±Ø§Ø© Ø§Ù„Ø£Ø³Ø§Ø³ÙŠØ©
            unset(
                $match['league_name'], $match['league_logo'], $match['stadium_name'], 
                $match['channel'], $match['referee'], $match['match_time'], 
                $match['match_date'], $match['league_name_ar'], $match['league_name_en'],
                $match['league_country'], $match['details_match_time'], $match['channels_data'],
                $match['live_url'], $match['channel_logo'], $match['previous_matches_data']
            );

            secureApiStreamPayload($match, $embedAllowedOrigin);

            sendApiJsonResponse([
                'status' => 'success',
                'timezone' => $requestTimezone,
                'storage_timezone' => 'UTC',
                'data' => $match
            ], 200, $apiResponseCache, $cacheContext, $cacheProbe);
            
        case 'teams':
            // Ø¬Ù„Ø¨ Ø¬Ù…ÙŠØ¹ Ø§Ù„ÙØ±Ù‚
            $cacheContext = buildApiCacheContext('teams', $planType, 'global');
            $cacheProbe = probeApiCache($apiResponseCache, $cacheContext);
            $teams = $db->getAllTeams();
            
            sendApiJsonResponse([
                'status' => 'success',
                'count' => count($teams),
                'data' => $teams
            ], 200, $apiResponseCache, $cacheContext, $cacheProbe);
            
        case 'stats':
            // Ø¥Ø­ØµØ§Ø¦ÙŠØ§Øª
            $cacheContext = buildApiCacheContext('stats', $planType, 'global');
            $cacheProbe = probeApiCache($apiResponseCache, $cacheContext);
            $stats = $db->getStats();
            $todayStats = $db->getTodayStats();
            
            sendApiJsonResponse([
                'status' => 'success',
                'data' => [
                    'overall' => $stats,
                    'today' => $todayStats
                ]
            ], 200, $apiResponseCache, $cacheContext, $cacheProbe);
            
        case 'news':
            // Ø¬Ù„Ø¨ Ø§Ù„Ø£Ø®Ø¨Ø§Ø± Ù…Ù† Ù‚Ø§Ø¹Ø¯Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª
            $limit = isset($_GET['limit']) ? filter_var($_GET['limit'], FILTER_VALIDATE_INT, ['options' => ['default' => 20, 'min_range' => 1, 'max_range' => 100]]) : 20;
            $cacheContext = buildApiCacheContext('news', $planType, 'global', [
                'limit' => $limit
            ]);
            $cacheProbe = probeApiCache($apiResponseCache, $cacheContext);
            $news = $db->getNews($limit);
            
            // Ø¥Ø²Ø§Ù„Ø© Ø§Ù„Ø±Ø§Ø¨Ø· Ù…Ù† Ø§Ù„Ø£Ø®Ø¨Ø§Ø±
            $news = array_map(function($item) {
                unset($item['url']);
                return $item;
            }, $news);
            
            sendApiJsonResponse([
                'status' => 'success',
                'count' => count($news),
                'data' => $news
            ], 200, $apiResponseCache, $cacheContext, $cacheProbe);
            
        case 'standings':
            // Ø¬Ù„Ø¨ Ø¨ÙŠØ§Ù†Ø§Øª Ø§Ù„ØªØ±ØªÙŠØ¨ Ù„Ù…Ø¨Ø§Ø±Ø§Ø© Ù…Ø­Ø¯Ø¯Ø©
            $matchId = isset($_GET['match_id']) ? filter_var($_GET['match_id'], FILTER_VALIDATE_INT) : null;
            if (!$matchId) {
                sendApiErrorResponse('Match ID is required', 400, $apiResponseCache, $cacheProbe);
            }

            $cacheContext = buildApiCacheContext('standings', $planType, 'global', [
                'match_id' => $matchId
            ]);
            $cacheProbe = probeApiCache($apiResponseCache, $cacheContext);
            
            $matchForStandings = $db->getMatchById($matchId);
            if (!$matchForStandings) {
                sendApiErrorResponse('Match not found', 404, $apiResponseCache, $cacheProbe);
            }

            if (!apiCoverageMatchAllowed($matchForStandings, $apiCoverageFilters)) {
                sendApiErrorResponse('This standings data is not available for the current API key.', 403, $apiResponseCache, $cacheProbe);
            }

            $standings = $db->getMatchStandings($matchId);
            
            sendApiJsonResponse([
                'status' => 'success',
                'match_id' => $matchId,
                'data' => $standings ?: []
            ], 200, $apiResponseCache, $cacheContext, $cacheProbe);
            
        case 'news_details':
            // Ø¬Ù„Ø¨ ØªÙØ§ØµÙŠÙ„ Ø§Ù„Ø®Ø¨Ø±
            $newsId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
            $newsUrl = isset($_GET['url']) ? filter_var($_GET['url'], FILTER_SANITIZE_URL) : null;
            
            if (!$newsId && !$newsUrl) {
                sendApiErrorResponse('News ID or URL is required', 400, $apiResponseCache, $cacheProbe);
            }

            $cacheContext = buildApiCacheContext('news_details', $planType, 'global', [
                'id' => $newsId ?: 0,
                'url' => $newsUrl ?: ''
            ]);
            $cacheProbe = probeApiCache($apiResponseCache, $cacheContext);
            
            $article = null;
            if ($newsId) {
                $article = $db->getNewsById($newsId);
            } elseif ($newsUrl) {
                $article = $db->getNewsByUrl($newsUrl);
            }
            
            if ((!$article || empty($article['body'])) && $newsUrl) {
                // Ø¥Ø°Ø§ Ù„Ù… ÙŠÙˆØ¬Ø¯ Ø£Ùˆ ÙŠÙ†Ù‚ØµÙ‡ Ø§Ù„Ù…Ø­ØªÙˆÙ‰ØŒ Ù†Ù‚ÙˆÙ… Ø¨Ø¬Ù„Ø¨Ù‡ Ù…Ù† Ø§Ù„Ù…ØµØ¯Ø± ÙˆØ­ÙØ¸Ù‡
                require_once '../scraper_news.php';
                $scraped = getNewsDetails($newsUrl);
                if ($scraped) {
                    if ($article) {
                        $article = array_merge($article, $scraped);
                    } else {
                        $article = $scraped;
                        $article['url'] = $newsUrl;
                    }
                    $db->saveNews($article);
                    // Re-fetch to get the ID if it was just created
                    if (!$newsId) {
                        $article = $db->getNewsByUrl($newsUrl);
                    }
                }
            }
            
            if (!$article) {
                sendApiErrorResponse('News not found', 404, $apiResponseCache, $cacheProbe);
            }
            
            // Ø¥Ø²Ø§Ù„Ø© Ø§Ù„Ø±Ø§Ø¨Ø· Ù…Ù† Ø§Ù„ØªÙØ§ØµÙŠÙ„
            unset($article['url']);
            
            sendApiJsonResponse([
                'status' => 'success',
                'data' => $article
            ], 200, $apiResponseCache, $cacheContext, $cacheProbe);
            
        case 'players':
            // Ø¬Ù„Ø¨ Ø§Ù„Ù„Ø§Ø¹Ø¨ÙŠÙ†
            $teamId = isset($_GET['team_id']) ? filter_var($_GET['team_id'], FILTER_VALIDATE_INT) : null;
            $limit = isset($_GET['limit']) ? filter_var($_GET['limit'], FILTER_VALIDATE_INT, ['options' => ['default' => 50, 'min_range' => 1, 'max_range' => 100]]) : 50;
            $offset = isset($_GET['offset']) ? filter_var($_GET['offset'], FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]) : 0;
            $search = isset($_GET['search']) ? htmlspecialchars(trim($_GET['search'])) : null;
            $cacheContext = buildApiCacheContext('players', $planType, 'global', [
                'team_id' => $teamId ?: 0,
                'limit' => $limit,
                'offset' => $offset,
                'search' => $search ?: ''
            ]);
            $cacheProbe = probeApiCache($apiResponseCache, $cacheContext);
            
            $players = $db->getPlayers($teamId, $limit, $offset, $search);
            $count = $db->getPlayersCount($teamId, $search);
            
            sendApiJsonResponse([
                'status' => 'success',
                'count' => count($players),
                'total' => $count,
                'data' => $players
            ], 200, $apiResponseCache, $cacheContext, $cacheProbe);
            
        case 'leagues':
            $cacheContext = buildApiCacheContext('leagues', $planType, 'global');
            $cacheProbe = probeApiCache($apiResponseCache, $cacheContext);
            // Ø¬Ù„Ø¨ Ø¬Ù…ÙŠØ¹ Ø§Ù„Ø¯ÙˆØ±ÙŠØ§Øª
            $leagues = $db->getAllLeagues();
            
            // ØªØµÙÙŠØ© Ø§Ù„Ø¯ÙˆØ±ÙŠØ§Øª Ø§Ù„Ù†Ø´Ø·Ø© ÙÙ‚Ø·
            $leagues = array_values(array_filter($leagues, function($league) {
                return (int)$league['is_active'] === 1;
            }));
            $leagues = array_values(array_filter($leagues, function ($league) use ($apiCoverageFilters) {
                return apiCoverageLeagueAllowed($league, $apiCoverageFilters);
            }));
            
            sendApiJsonResponse([
                'status' => 'success',
                'count' => count($leagues),
                'data' => $leagues
            ], 200, $apiResponseCache, $cacheContext, $cacheProbe);
            
        case 'countries':
            $cacheContext = buildApiCacheContext('countries', $planType, 'global');
            $cacheProbe = probeApiCache($apiResponseCache, $cacheContext);
            // Ø¬Ù„Ø¨ Ø¬Ù…ÙŠØ¹ Ø§Ù„Ø¨Ù„Ø¯Ø§Ù†
            $countries = $db->getAllCountries();
            
            // ØªØµÙÙŠØ© Ø§Ù„Ø¯ÙˆÙ„ Ø§Ù„Ù†Ø´Ø·Ø© ÙÙ‚Ø·
            $countries = array_values(array_filter($countries, function($country) {
                return (int)$country['is_active'] === 1;
            }));
            $countries = array_values(array_filter($countries, function ($country) use ($apiCoverageFilters) {
                return apiCoverageCountryAllowed($country, $apiCoverageFilters);
            }));
            
            sendApiJsonResponse([
                'status' => 'success',
                'count' => count($countries),
                'data' => $countries
            ], 200, $apiResponseCache, $cacheContext, $cacheProbe);

        case 'leagues_standings':
            $cacheContext = buildApiCacheContext('leagues_standings', $planType, 'global');
            $cacheProbe = probeApiCache($apiResponseCache, $cacheContext);
            // Ø¬Ù„Ø¨ Ø¬Ù…ÙŠØ¹ Ø¬Ø¯Ø§ÙˆÙ„ Ø§Ù„ØªØ±ØªÙŠØ¨ Ø§Ù„Ù…ØªØ§Ø­Ø© Ù„Ù„Ø¯ÙˆØ±ÙŠØ§Øª
            $standings = $db->getLatestLeaguesStandings();
            $standings = array_values(array_filter($standings, function ($leagueStanding) use ($apiCoverageFilters) {
                return apiCoverageLeagueAllowed($leagueStanding, $apiCoverageFilters);
            }));
            
            sendApiJsonResponse([
                'status' => 'success',
                'count' => count($standings),
                'data' => $standings
            ], 200, $apiResponseCache, $cacheContext, $cacheProbe);
            
        default:
            sendApiErrorResponse(
                'Invalid action. Available actions: matches, match, teams, leagues, stats, standings, news, news_details',
                400,
                $apiResponseCache,
                $cacheProbe
            );
    }
    
} catch (Exception $e) {
    releaseApiCacheProbe($apiResponseCache, $cacheProbe);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

