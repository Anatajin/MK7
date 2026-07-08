<?php

if (!defined('SPORT_EMBED_SIGNING_SECRET')) {
    define(
        'SPORT_EMBED_SIGNING_SECRET',
        'sport-embed-access-v1::7b1ef1d7c4e44b7b8a921f67cdd4b8d394c6e0aa2f34461c8e76f06e90c11f7a'
    );
}

function embedAccessBase64UrlEncode($value) {
    return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
}

function embedAccessBase64UrlDecode($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

function embedAccessNormalizeOrigin($origin) {
    $origin = trim((string)$origin);
    if ($origin === '') {
        return null;
    }

    if (!preg_match('#^https?://#i', $origin)) {
        if (preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $origin)) {
            $origin = 'https://' . $origin;
        } else {
            return null;
        }
    }

    $parts = parse_url($origin);
    if (!$parts || empty($parts['host'])) {
        return null;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
    $host = strtolower((string)$parts['host']);
    $port = isset($parts['port']) ? (int)$parts['port'] : null;

    $normalized = $scheme . '://' . $host;
    if ($port !== null) {
        $isDefault = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        if (!$isDefault) {
            $normalized .= ':' . $port;
        }
    }

    return $normalized;
}

function embedAccessOriginFromUrl($url) {
    return embedAccessNormalizeOrigin((string)$url);
}

function embedAccessGetInternalOrigins() {
    static $origins = null;

    if ($origins !== null) {
        return $origins;
    }

    $defaults = [
        'https://dashboard.hayachoout.space',
        'https://hayachoout.space',
        'https://www.hayachoout.space',
        'https://204.168.181.228',
        'http://204.168.181.228',
    ];

    $configured = trim((string)(getenv('SPORT_EMBED_INTERNAL_ORIGINS') ?: ''));
    $candidates = $defaults;
    if ($configured !== '') {
        $candidates = array_merge($candidates, preg_split('/[\s,]+/', $configured) ?: []);
    }

    $normalized = [];
    foreach ($candidates as $candidate) {
        $origin = embedAccessNormalizeOrigin($candidate);
        if ($origin !== null) {
            $normalized[$origin] = true;
        }
    }

    $origins = array_keys($normalized);
    return $origins;
}

function embedAccessIsInternalOrigin($origin) {
    $origin = embedAccessNormalizeOrigin($origin);
    if ($origin === null) {
        return false;
    }

    return in_array($origin, embedAccessGetInternalOrigins(), true);
}

function embedAccessResolveIncomingOrigin() {
    $originHeader = embedAccessNormalizeOrigin($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($originHeader !== null) {
        return $originHeader;
    }

    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer !== '') {
        return embedAccessOriginFromUrl($referer);
    }

    return null;
}

function embedAccessCanonicalizeParams(array $params) {
    unset($params['sig']);

    foreach ($params as $key => $value) {
        if (is_bool($value)) {
            $params[$key] = $value ? '1' : '0';
        } elseif ($value === null) {
            $params[$key] = '';
        } elseif (!is_scalar($value)) {
            unset($params[$key]);
        } else {
            $params[$key] = (string)$value;
        }
    }

    ksort($params, SORT_STRING);

    return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function embedAccessGenerateSignature(array $params) {
    return hash_hmac('sha256', embedAccessCanonicalizeParams($params), SPORT_EMBED_SIGNING_SECRET);
}

function embedAccessGetRuntimeSettings() {
    static $settings = null;

    if ($settings !== null) {
        return $settings;
    }

    $settings = [
        'embed_player_signed_ttl' => 900,
        'embed_cast_signed_ttl' => 7200,
        'embed_domain_restriction_enabled' => true,
    ];

    try {
        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            $configPath = dirname(__DIR__) . '/config.php';
            if (is_file($configPath)) {
                require_once $configPath;
                if (isset($pdo) && $pdo instanceof PDO) {
                    $GLOBALS['pdo'] = $pdo;
                }
            }
        }

        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo instanceof PDO) {
            $stmt = $pdo->prepare(
                "SELECT setting_key, setting_value FROM api_settings WHERE setting_key IN (?, ?, ?)"
            );
            $stmt->execute([
                'embed_player_signed_ttl',
                'embed_cast_signed_ttl',
                'embed_domain_restriction_enabled',
            ]);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = (string)($row['setting_key'] ?? '');
                $value = (string)($row['setting_value'] ?? '');
                if ($key === 'embed_player_signed_ttl') {
                    $settings[$key] = max(60, min(43200, (int)$value));
                } elseif ($key === 'embed_cast_signed_ttl') {
                    $settings[$key] = max(120, min(43200, (int)$value));
                } elseif ($key === 'embed_domain_restriction_enabled') {
                    $settings[$key] = $value !== '0';
                }
            }
        }
    } catch (Throwable $e) {
        // Keep safe defaults if settings lookup fails.
    }

    return $settings;
}

function embedAccessGetPlayerSignedTtl() {
    $settings = embedAccessGetRuntimeSettings();
    return max(60, (int)($settings['embed_player_signed_ttl'] ?? 900));
}

function embedAccessGetCastSignedTtl() {
    $settings = embedAccessGetRuntimeSettings();
    return max(120, (int)($settings['embed_cast_signed_ttl'] ?? 7200));
}

function embedAccessIsDomainRestrictionEnabled() {
    $settings = embedAccessGetRuntimeSettings();
    return (bool)($settings['embed_domain_restriction_enabled'] ?? true);
}

function embedAccessBuildSignedQuery(array $params, $allowedOrigin, $ttl = null) {
    $allowedOrigin = embedAccessNormalizeOrigin($allowedOrigin);
    if ($allowedOrigin === null) {
        return $params;
    }

    $params['eo'] = embedAccessBase64UrlEncode($allowedOrigin);
    $effectiveTtl = $ttl === null ? embedAccessGetPlayerSignedTtl() : max(60, (int)$ttl);
    $params['exp'] = (string)(time() + $effectiveTtl);
    unset($params['sig']);
    $params['sig'] = embedAccessGenerateSignature($params);

    return $params;
}

function embedAccessBuildCastSignedQuery(array $params, $ttl = null) {
    $params['cast'] = '1';
    $effectiveTtl = $ttl === null ? embedAccessGetCastSignedTtl() : max(120, (int)$ttl);
    $params['exp'] = (string)(time() + $effectiveTtl);
    unset($params['eo'], $params['sig']);
    $params['sig'] = embedAccessGenerateSignature($params);

    return $params;
}

function embedAccessBuildUrlFromParts(array $parts, array $queryParams) {
    $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $user = $parts['user'] ?? '';
    $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
    $auth = $user !== '' ? $user . $pass . '@' : '';
    $path = $parts['path'] ?? '';
    $query = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return $scheme . $auth . $host . $port . $path . ($query !== '' ? '?' . $query : '') . $fragment;
}

function embedAccessAddQueryParamToUrl($url, $key, $value) {
    $url = trim((string)$url);
    $key = trim((string)$key);

    if ($url === '' || $key === '' || !preg_match('#^https?://#i', $url)) {
        return $url;
    }

    $parts = parse_url($url);
    if (!$parts) {
        return $url;
    }

    $queryParams = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $queryParams);
    }

    $queryParams[$key] = (string)$value;
    return embedAccessBuildUrlFromParts($parts, $queryParams);
}

function embedAccessIsProtectedLocalUrl($url) {
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return false;
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return false;
    }

    $path = strtolower((string)($parts['path'] ?? ''));
    if (!in_array(basename($path), ['frame_player.php', 'stream_player.php', 'stream_proxy.php', 'cast_media.php'], true)) {
        return false;
    }

    $origin = embedAccessNormalizeOrigin(($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    return $origin !== null && embedAccessIsInternalOrigin($origin);
}

function embedAccessInjectMatchIdIntoProtectedValue($value, $matchId) {
    $matchId = (int)$matchId;
    if ($matchId <= 0 || !is_string($value)) {
        return $value;
    }

    $value = trim($value);
    if ($value === '') {
        return $value;
    }

    $injectIfStreamPlayer = function ($url) use ($matchId) {
        $url = trim((string)$url);
        if ($url === '' || !embedAccessIsProtectedLocalUrl($url)) {
            return $url;
        }

        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
        if (basename($path) !== 'stream_player.php') {
            return $url;
        }

        return embedAccessAddQueryParamToUrl($url, 'match_id', $matchId);
    };

    if (stripos($value, '<iframe') === false) {
        return $injectIfStreamPlayer($value);
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML('<?xml encoding="utf-8" ?>' . $value, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
        return $value;
    }

    $iframe = $dom->getElementsByTagName('iframe')->item(0);
    if (!$iframe) {
        return $value;
    }

    $src = trim((string)$iframe->getAttribute('src'));
    if ($src === '') {
        return $value;
    }

    $updatedSrc = $injectIfStreamPlayer($src);
    if ($updatedSrc === $src) {
        return $value;
    }

    $iframe->setAttribute('src', $updatedSrc);
    $updated = $dom->saveHTML($iframe);
    return $updated ?: $value;
}

function embedAccessSignLocalUrl($url, $allowedOrigin, $ttl = null) {
    $url = trim((string)$url);
    if ($url === '' || !embedAccessIsProtectedLocalUrl($url)) {
        return $url;
    }

    $allowedOrigin = embedAccessNormalizeOrigin($allowedOrigin);
    if ($allowedOrigin === null) {
        return $url;
    }

    $parts = parse_url($url);
    if (!$parts) {
        return $url;
    }

    $queryParams = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $queryParams);
    }

    $queryParams = embedAccessBuildSignedQuery($queryParams, $allowedOrigin, $ttl);
    return embedAccessBuildUrlFromParts($parts, $queryParams);
}

function embedAccessSignCastLocalUrl($url, $ttl = null) {
    $url = trim((string)$url);
    if ($url === '' || !embedAccessIsProtectedLocalUrl($url)) {
        return $url;
    }

    $parts = parse_url($url);
    if (!$parts) {
        return $url;
    }

    $queryParams = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $queryParams);
    }

    $queryParams = embedAccessBuildCastSignedQuery($queryParams, $ttl);
    return embedAccessBuildUrlFromParts($parts, $queryParams);
}

function embedAccessSignIframeHtml($html, $allowedOrigin, $ttl = null) {
    $html = trim((string)$html);
    if ($html === '' || stripos($html, '<iframe') === false) {
        return $html;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
        return $html;
    }

    $iframe = $dom->getElementsByTagName('iframe')->item(0);
    if (!$iframe) {
        return $html;
    }

    $src = trim((string)$iframe->getAttribute('src'));
    if ($src === '') {
        return $html;
    }

    $signedSrc = embedAccessSignLocalUrl($src, $allowedOrigin, $ttl);
    if ($signedSrc === $src) {
        return $html;
    }

    $iframe->setAttribute('src', $signedSrc);
    $updated = $dom->saveHTML($iframe);
    return $updated ?: $html;
}

function embedAccessResolveApiAllowedOrigin($requestOrigin, $validKey = null) {
    $origin = embedAccessNormalizeOrigin($requestOrigin);
    if ($origin !== null) {
        return $origin;
    }

    if (is_array($validKey)) {
        $candidate = $validKey['allowed_origin'] ?? $validKey['allowed_domain'] ?? null;
        $origin = embedAccessNormalizeOrigin($candidate);
        if ($origin !== null) {
            return $origin;
        }
    }

    return null;
}

function embedAccessVerifyRequest(array $query, $incomingOrigin, $allowUnsignedInternal = true) {
    $incomingOrigin = embedAccessNormalizeOrigin($incomingOrigin);
    $domainRestrictionEnabled = embedAccessIsDomainRestrictionEnabled();

    if ($allowUnsignedInternal && $incomingOrigin !== null && embedAccessIsInternalOrigin($incomingOrigin) && empty($query['sig'])) {
        return [
            'ok' => true,
            'allowed_origin' => $incomingOrigin,
            'mode' => 'internal_unsigned',
            'expires_at' => null,
        ];
    }

    if (!$domainRestrictionEnabled && empty($query['sig'])) {
        return [
            'ok' => true,
            'allowed_origin' => null,
            'incoming_origin' => $incomingOrigin,
            'mode' => 'public_unsigned',
            'expires_at' => null,
        ];
    }

    $signature = trim((string)($query['sig'] ?? ''));
    $expiresAt = (int)($query['exp'] ?? 0);
    $isCastRequest = (string)($query['cast'] ?? '0') === '1';

    if ($isCastRequest) {
        if ($signature === '' || $expiresAt <= 0) {
            return [
                'ok' => false,
                'reason' => 'missing_signature',
            ];
        }

        if ($expiresAt < time()) {
            return [
                'ok' => false,
                'reason' => 'expired',
            ];
        }

        $check = $query;
        unset($check['sig']);
        $expectedSignature = embedAccessGenerateSignature($check);
        if (!hash_equals($expectedSignature, $signature)) {
            return [
                'ok' => false,
                'reason' => 'invalid_signature',
            ];
        }

        return [
            'ok' => true,
            'allowed_origin' => null,
            'incoming_origin' => $incomingOrigin,
            'mode' => 'cast_signed',
            'expires_at' => $expiresAt,
        ];
    }
    $encodedOrigin = trim((string)($query['eo'] ?? ''));

    if ($signature === '' || $encodedOrigin === '' || $expiresAt <= 0) {
        return [
            'ok' => false,
            'reason' => 'missing_signature',
        ];
    }

    if ($expiresAt < time()) {
        return [
            'ok' => false,
            'reason' => 'expired',
        ];
    }

    $decodedOrigin = embedAccessBase64UrlDecode($encodedOrigin);
    $allowedOrigin = embedAccessNormalizeOrigin($decodedOrigin);
    if ($allowedOrigin === null) {
        return [
            'ok' => false,
            'reason' => 'invalid_origin',
        ];
    }

    $check = $query;
    unset($check['sig']);
    $expectedSignature = embedAccessGenerateSignature($check);
    if (!hash_equals($expectedSignature, $signature)) {
        return [
            'ok' => false,
            'reason' => 'invalid_signature',
        ];
    }

    if (!$domainRestrictionEnabled) {
        return [
            'ok' => true,
            'allowed_origin' => $allowedOrigin,
            'incoming_origin' => $incomingOrigin,
            'mode' => 'signed_unrestricted',
            'expires_at' => $expiresAt,
        ];
    }

    if ($incomingOrigin === null) {
        return [
            'ok' => false,
            'reason' => 'missing_origin',
        ];
    }

    if ($incomingOrigin !== $allowedOrigin && !embedAccessIsInternalOrigin($incomingOrigin)) {
        return [
            'ok' => false,
            'reason' => 'origin_mismatch',
        ];
    }

    return [
        'ok' => true,
        'allowed_origin' => $allowedOrigin,
        'incoming_origin' => $incomingOrigin,
        'mode' => 'signed',
        'expires_at' => $expiresAt,
    ];
}

function embedAccessBuildFrameAncestorsDirective($allowedOrigin = null) {
    if (!embedAccessIsDomainRestrictionEnabled()) {
        return '*';
    }

    $origins = embedAccessGetInternalOrigins();
    $allowedOrigin = embedAccessNormalizeOrigin($allowedOrigin);
    if ($allowedOrigin !== null) {
        $origins[] = $allowedOrigin;
    }

    $tokens = ["'self'"];
    foreach (array_values(array_unique($origins)) as $origin) {
        $tokens[] = $origin;
    }

    return implode(' ', $tokens);
}
