<?php
require_once __DIR__ . '/ThreeSixFiveScraper.php';

/**
 * Kooora-Sport Scraper
 * Scrapes match streams and info from kooora-sport.com
 */

class NYallaShootScraper {
    private $baseUrl = 'https://www.kooora-sport.com';
    private $db;
    private $sources = [];
    private $threeSixFiveScraper;
    private $currentLiveMatchId = null;
    private $yallaShootMatchesApiBase = 'https://ws.kora-api.space/api/matches/';
    private $yallaShootMatchApiBase = 'https://ws.kora-api.top/api/matche/';
    private $yallaShootStreamingBase = 'https://xyzyalla-shootx-space.smartagro.mov/';
    private $yallaShootFrameUrls = [
        'https://vsys.kora-top.zip/frame.php',
        'https://ar.kora-top.zip/frame.php',
        'https://yalla.kora-top.zip/frame.php',
        'https://live.kora-top.zip/frame.php',
        'https://vip.kora-top.zip/frame.php'
    ];

    public function __construct($db = null) {
        $this->db = $db;
        if ($this->db) {
            $this->sources = $this->db->getActiveScraperSources();
        }
        
        // If no sources in DB, use default hardcoded for compatibility
        if (empty($this->sources)) {
            $this->sources = [[
                'name' => 'Kooora-Sport',
                'base_url' => 'https://www.kooora-sport.com',
                'matches_path' => '/matches-today/',
                'container_selector' => "//div[contains(@class, 'AY_Match')]",
                'teams_selector' => ".//div[@class='TM_Name']",
                'link_selector' => ".//a[contains(@href, '/matches/')]"
            ]];
        }
        $this->threeSixFiveScraper = new ThreeSixFiveScraper($db);
    }

    private function isThreeSixFiveFallbackEnabled() {
        if (!$this->db || !method_exists($this->db, 'getApiSettings')) {
            return true;
        }

        try {
            $settings = $this->db->getApiSettings();
            if (!is_array($settings)) {
                return true;
            }

            return (string)($settings['enable_365scores_fallback'] ?? '1') === '1';
        } catch (Throwable $e) {
            return true;
        }
    }

    private function getKoraApiSourceProfile($source) {
        $baseUrl = trim((string)($source['base_url'] ?? ''));
        if ($baseUrl === '') {
            return null;
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!$host) {
            return null;
        }

        $profiles = [
            'yalla-shoot.mov' => [
                'name' => 'Yalla Shoot MOV',
                'streaming_base' => 'https://xyzyalla-shootx-space.smartagro.mov/',
                'lang' => 'ar'
            ],
            'totalsportekx.top' => [
                'name' => 'TotalSportekX',
                'streaming_base' => 'https://xyztotalsportekx-top.smartagro.mov/',
                'lang' => 'en'
            ],
        ];

        foreach ($profiles as $needle => $profile) {
            if (stripos($host, $needle) !== false) {
                return $profile;
            }
        }

        return null;
    }

    private function fetchJsonContent($url, $referer = null) {
        $raw = $this->fetchUrlContent($url, $referer);
        if (!$raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function fetchUrlResponse($url, $referer = null) {
        $headers = [];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        if ($referer) {
            curl_setopt($ch, CURLOPT_REFERER, $referer);
        } else {
            curl_setopt($ch, CURLOPT_REFERER, $this->baseUrl . "/");
        }

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$headers) {
            $length = strlen($line);
            $trimmed = trim($line);

            if ($trimmed === '') {
                return $length;
            }

            if (stripos($trimmed, 'HTTP/') === 0) {
                $headers = [];
                return $length;
            }

            $pos = strpos($trimmed, ':');
            if ($pos === false) {
                return $length;
            }

            $name = strtolower(trim(substr($trimmed, 0, $pos)));
            $value = trim(substr($trimmed, $pos + 1));

            if (isset($headers[$name])) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }

            return $length;
        });

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
        curl_close($ch);

        if ($body === false || $body === null) {
            return null;
        }

        return [
            'body' => (string)$body,
            'status' => $status,
            'headers' => $headers,
            'effective_url' => $effectiveUrl
        ];
    }

    private function fetchUrlPostResponse($url, array $postFields, $referer = null, array $extraHeaders = []) {
        $headers = [];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');

        $normalizedHeaders = [];
        if ($referer) {
            curl_setopt($ch, CURLOPT_REFERER, $referer);
            $parts = parse_url($referer);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $origin = $parts['scheme'] . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $origin .= ':' . $parts['port'];
                }
                $normalizedHeaders[] = 'Origin: ' . $origin;
            }
        } else {
            curl_setopt($ch, CURLOPT_REFERER, $this->baseUrl . "/");
        }

        foreach ($extraHeaders as $headerLine) {
            $headerLine = trim((string)$headerLine);
            if ($headerLine !== '') {
                $normalizedHeaders[] = $headerLine;
            }
        }

        if (!empty($normalizedHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $normalizedHeaders);
        }

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$headers) {
            $length = strlen($line);
            $trimmed = trim($line);

            if ($trimmed === '') {
                return $length;
            }

            if (stripos($trimmed, 'HTTP/') === 0) {
                $headers = [];
                return $length;
            }

            $pos = strpos($trimmed, ':');
            if ($pos === false) {
                return $length;
            }

            $name = strtolower(trim(substr($trimmed, 0, $pos)));
            $value = trim(substr($trimmed, $pos + 1));

            if (isset($headers[$name])) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }

            return $length;
        });

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
        curl_close($ch);

        if ($body === false || $body === null) {
            return null;
        }

        return [
            'body' => (string)$body,
            'status' => $status,
            'headers' => $headers,
            'effective_url' => $effectiveUrl
        ];
    }

    private function resolveUrlAgainstBase($url, $baseUrl) {
        $url = trim((string)$url);
        $baseUrl = trim((string)$baseUrl);

        if ($url === '') {
            return null;
        }

        if (strpos($url, '//') === 0) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $baseParts = parse_url($baseUrl);
        if (!$baseParts || empty($baseParts['host'])) {
            return $url;
        }

        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

        if (strpos($url, '/') === 0) {
            return $scheme . '://' . $host . $port . $url;
        }

        $path = $baseParts['path'] ?? '/';
        $path = preg_replace('#/[^/]*$#', '/', $path);

        return $scheme . '://' . $host . $port . $path . $url;
    }

    private function extractRedirectTargetFromHtml($html, $baseUrl) {
        $html = (string)$html;
        if ($html === '') {
            return null;
        }

        $patterns = [
            '/<meta[^>]+http-equiv=["\']refresh["\'][^>]+content=["\'][^"\']*url=([^"\']+)["\']/i',
            '/window\.location\.replace\((["\'])(.*?)\1\)/i',
            '/window\.location\.href\s*=\s*(["\'])(.*?)\1/i',
            '/location\.href\s*=\s*(["\'])(.*?)\1/i',
            '/location\.replace\((["\'])(.*?)\1\)/i',
            '/window\.open\((["\'])(.*?)\1/i',
            '/<iframe[^>]+src=["\']([^"\']+)["\']/i'
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $html, $matches)) {
                continue;
            }

            $candidate = trim((string)($matches[count($matches) - 1] ?? ''));
            if ($candidate === '' || stripos($candidate, 'javascript:') === 0 || stripos($candidate, 'about:') === 0) {
                continue;
            }

            if (stripos($candidate, 'ad-frame.html') !== false) {
                continue;
            }

            $candidate = $this->normalizeExtractedUrlCandidate($candidate, $baseUrl);
            if ($candidate) {
                return $candidate;
            }
        }

        if (preg_match_all('/https?:\\\\\/\\\\\/[^"\']+/i', $html, $matches)) {
            foreach ($matches[0] as $candidate) {
                $candidate = $this->normalizeExtractedUrlCandidate($candidate, $baseUrl);
                if ($candidate && preg_match('~(href\.li/\?|/channels/|frame\.php|embed/|/live/|/watch/|sportzsonline|dynamicsecular|score808)~i', $candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function responseAllowsEmbedding(array $headers) {
        $allowedHosts = [
            'hayachoout.space',
            'www.hayachoout.space',
            'dashboard.hayachoout.space',
            'api.hayachoout.space'
        ];

        $xFrameOptions = strtolower(trim((string)($headers['x-frame-options'] ?? '')));
        if ($xFrameOptions !== '') {
            if (strpos($xFrameOptions, 'deny') !== false || strpos($xFrameOptions, 'sameorigin') !== false) {
                return false;
            }

            if (strpos($xFrameOptions, 'allow-from') !== false) {
                foreach ($allowedHosts as $host) {
                    if (strpos($xFrameOptions, strtolower($host)) !== false) {
                        return true;
                    }
                }
                return false;
            }
        }

        $csp = strtolower(trim((string)($headers['content-security-policy'] ?? '')));
        if ($csp !== '' && preg_match('/frame-ancestors\s+([^;]+)/i', $csp, $matches)) {
            $frameAncestors = strtolower(trim((string)($matches[1] ?? '')));
            if ($frameAncestors === '' || strpos($frameAncestors, '*') !== false) {
                return true;
            }

            if (strpos($frameAncestors, "'none'") !== false) {
                return false;
            }

            if (strpos($frameAncestors, "'self'") !== false) {
                return false;
            }

            foreach ($allowedHosts as $host) {
                if (strpos($frameAncestors, strtolower($host)) !== false) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function canonicalHost($value) {
        $value = trim(strtolower((string)$value));
        if ($value === '') {
            return '';
        }

        $host = preg_match('#^https?://#i', $value)
            ? (string)(parse_url($value, PHP_URL_HOST) ?: '')
            : $value;

        $host = preg_replace('/:\d+$/', '', strtolower(trim($host)));
        return preg_replace('/^www\./', '', $host);
    }

    private function isCurrentSourceHostUrl($url) {
        $urlHost = $this->canonicalHost($url);
        $sourceHost = $this->canonicalHost($this->baseUrl);

        return $urlHost !== '' && $sourceHost !== '' && $urlHost === $sourceHost;
    }

    private function isKoraSimoSource() {
        $sourceHost = $this->canonicalHost($this->baseUrl);
        return $sourceHost === 'korasimo.com';
    }

    private function isLocalPlayerWrapperUrl($url) {
        $url = trim((string)$url);
        return stripos($url, '/stream_player.php?') !== false || stripos($url, '/frame_player.php?') !== false;
    }

    private function hasUnresolvedUrlTemplate($url) {
        $url = trim((string)$url);
        if ($url === '') {
            return false;
        }

        return strpos($url, '${') !== false
            || strpos($url, '{{') !== false
            || strpos($url, '}}') !== false
            || stripos($url, 'window.location') !== false;
    }

    private function isGenericKoraSimoPlayerUrl($url) {
        if (!$this->isKoraSimoSource()) {
            return false;
        }

        $url = trim((string)$url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }

        $host = $this->canonicalHost($url);
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));

        return ($host === 'soccertvhd.live' || substr($host, -strlen('.soccertvhd.live')) === '.soccertvhd.live')
            && preg_match('~/splayer/(?:live)?\d+\.php$~i', $path) === 1;
    }

    private function shouldRejectSourceHostedFrameUrl($url) {
        $url = trim((string)$url);
        if ($this->hasUnresolvedUrlTemplate($url) || $this->isGenericKoraSimoPlayerUrl($url)) {
            return true;
        }

        if ($url === '' || $this->isLocalPlayerWrapperUrl($url)) {
            return false;
        }

        if (!preg_match('#^https?://#i', $url)) {
            return true;
        }

        if (!$this->isCurrentSourceHostUrl($url)) {
            return false;
        }

        return !preg_match('~\.m3u8($|[?#])~i', $url);
    }

    private function resolveEmbeddableChannelUrl($url, $referer = null, $depth = 0) {
        $url = trim((string)$url);
        if ($url === '' || $depth > 4) {
            return null;
        }

        $response = $this->fetchUrlResponse($url, $referer);
        if (!$response || (int)($response['status'] ?? 0) >= 400) {
            return null;
        }

        $effectiveUrl = trim((string)($response['effective_url'] ?? $url));
        $body = (string)($response['body'] ?? '');
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];

        $redirectTarget = $this->extractRedirectTargetFromHtml($body, $effectiveUrl);
        if ($redirectTarget && strcasecmp($redirectTarget, $effectiveUrl) !== 0) {
            $resolved = $this->resolveEmbeddableChannelUrl($redirectTarget, $effectiveUrl, $depth + 1);
            if ($resolved) {
                return $resolved;
            }
        }

        if ($this->isLikelyNonPlayerContentPage($effectiveUrl, $body)) {
            return null;
        }

        if (!$this->responseAllowsEmbedding($headers)) {
            return null;
        }

        return $effectiveUrl;
    }

    private function buildIframeHtmlFromUrl($src) {
        $src = trim((string)$src);
        if ($src === '') {
            return null;
        }

        if ($this->shouldRejectSourceHostedFrameUrl($src)) {
            return null;
        }

        if (stripos($src, '/stream_player.php?') !== false || stripos($src, '/frame_player.php?') !== false) {
            return $this->buildRawIframeHtml($src);
        }

        return $this->buildLocalFrameWrapperIframeHtml($src, [
            'title' => 'Live Stream',
            'no_sandbox' => $this->shouldUseRelaxedFrameSandbox($src),
        ]);
    }

    private function extractLiveAnchorCandidatesFromXpath($xpath, $baseUrl) {
        $baseUrl = trim((string)$baseUrl);
        if (!$xpath || $baseUrl === '') {
            return [];
        }

        $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $basePath = strtolower(rtrim((string)(parse_url($baseUrl, PHP_URL_PATH) ?: ''), '/'));
        $baseQuery = trim((string)(parse_url($baseUrl, PHP_URL_QUERY) ?: ''));
        $candidates = [];
        $seen = [];

        $selectorGroups = [
            "//a[contains(@class, 'btn-live') and @href]",
            "//div[contains(@class, 'premium-watch-box')]//a[@href]",
            "//a[@target='_blank' and @href]",
            "//a[contains(@href, 'live') or contains(@href, 'watch') or contains(@href, 'stream') or contains(@href, 'player')]",
            "//a[@href]"
        ];

        foreach ($selectorGroups as $selector) {
            $nodes = $xpath->query($selector);
            if (!$nodes || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                $href = trim((string)$node->getAttribute('href'));
                if ($href === '' || $href === '#' || stripos($href, 'javascript:') === 0 || stripos($href, 'mailto:') === 0) {
                    continue;
                }

                $candidate = $this->resolveUrlAgainstBase($href, $baseUrl);
                if (!$candidate || !preg_match('#^https?://#i', $candidate)) {
                    continue;
                }

                $host = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?: ''));
                $path = strtolower((string)(parse_url($candidate, PHP_URL_PATH) ?: ''));
                $query = trim((string)(parse_url($candidate, PHP_URL_QUERY) ?: ''));
                $className = strtolower((string)$node->getAttribute('class'));
                $label = mb_strtolower(trim((string)$node->textContent), 'UTF-8');
                $hrefSignals = strtolower($href . ' ' . $candidate);
                $isSamePage = $host === $baseHost
                    && rtrim($path, '/') === $basePath
                    && $query === $baseQuery;
                $isHomepageLike = ($path === '' || $path === '/') && $query === '';

                $isPreferredAnchor = strpos($className, 'btn-live') !== false
                    || strpos($className, 'watch') !== false
                    || strpos($label, 'شاهد') !== false
                    || strpos($label, 'مباشر') !== false
                    || strpos($label, 'watch') !== false
                    || strpos($label, 'live') !== false;

                $isPreferredAnchor = $isPreferredAnchor
                    || strpos($className, 'server') !== false
                    || strpos($className, 'player') !== false
                    || strpos($className, 'channel') !== false
                    || strpos($label, 'server') !== false
                    || strpos($label, 'stream') !== false
                    || strpos($hrefSignals, 'live') !== false
                    || strpos($hrefSignals, 'watch') !== false
                    || strpos($hrefSignals, 'stream') !== false
                    || strpos($hrefSignals, 'player') !== false
                    || strpos($hrefSignals, 'embed') !== false
                    || strpos($hrefSignals, 'channel') !== false
                    || strpos($hrefSignals, 'frame.php') !== false
                    || strpos($hrefSignals, 'm3u8') !== false;

                if ($isSamePage || $isHomepageLike) {
                    continue;
                }

                if ($host !== '' && $baseHost !== '' && $host === $baseHost && !$isPreferredAnchor) {
                    continue;
                }

                $candidateKey = strtolower($candidate);
                if (isset($seen[$candidateKey])) {
                    continue;
                }

                $seen[$candidateKey] = true;
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private function hasDirectPlayerMarkup($html) {
        $html = (string)$html;
        if ($html === '') {
            return false;
        }

        foreach ([
            '<iframe',
            '.m3u8',
            'id="yalla-ajax-server"',
            "id='yalla-ajax-server'",
            'class="video-serv',
            "class='video-serv",
            'btn-server',
            'data-server',
            'data-channel',
            'server-button',
            'frame.php?',
            'streams.center/embed',
            'albaplayer',
            'simosports',
            'smartagro',
            'kora-top',
            'jwplayer',
            'player_api',
            'source src='
        ] as $needle) {
            if (stripos($html, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isLikelyNonPlayerContentPage($url, $html) {
        $url = trim((string)$url);
        if ($url === '') {
            return false;
        }

        if ($this->hasDirectPlayerMarkup($html)) {
            return false;
        }

        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
        $query = trim((string)(parse_url($url, PHP_URL_QUERY) ?: ''));
        $html = (string)$html;

        if (($path === '' || $path === '/') && $query === '') {
            return true;
        }

        if (preg_match('#^/(matches?|news|article|category|tag|author|page)(/|$)#i', $path)) {
            return true;
        }

        if (
            strpos($host, 'nkoora.online') !== false
            || stripos($html, 'wp-content/plugins/AlbaSportApi') !== false
            || stripos($html, 'wp-theme-AlbaYallaShoot') !== false
            || stripos($html, 'single-alba-matches') !== false
        ) {
            return true;
        }

        return false;
    }

    private function decodeUrlSafeBase64($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return null;
        }

        return trim((string)$decoded);
    }

    private function decodeHexUrlCandidate($value) {
        $value = preg_replace('/\s+/', '', trim((string)$value));
        if ($value === '' || !preg_match('/^[0-9a-f]+$/i', $value) || (strlen($value) % 2) !== 0) {
            return null;
        }

        $decoded = @hex2bin($value);
        if ($decoded === false) {
            return null;
        }

        return trim((string)$decoded);
    }

    private function unwrapRedirectServiceUrl($url) {
        $url = trim((string)$url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host !== 'href.li' && $host !== 'www.href.li') {
            return $url;
        }

        $query = trim((string)($parts['query'] ?? ''));
        if ($query !== '' && preg_match('#^https?://#i', $query)) {
            return $query;
        }

        return $url;
    }

    private function normalizeExtractedUrlCandidate($candidate, $baseUrl = null) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            return null;
        }

        $candidate = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $candidate = str_replace(['\\/', '\\u002F', '\\x2f'], ['/', '/', '/'], $candidate);
        $candidate = preg_replace('/^"(.*)"$/s', '$1', $candidate);
        $candidate = preg_replace("/^'(.*)'$/s", '$1', $candidate);
        $candidate = trim((string)stripslashes($candidate));

        if ($this->hasUnresolvedUrlTemplate($candidate)) {
            return null;
        }

        if (preg_match('#^https?://#i', $candidate)) {
            return $this->unwrapRedirectServiceUrl($candidate);
        }

        if ($baseUrl) {
            $resolved = $this->resolveUrlAgainstBase($candidate, $baseUrl);
            if ($resolved && preg_match('#^https?://#i', $resolved)) {
                return $this->unwrapRedirectServiceUrl($resolved);
            }
        }

        return null;
    }

    private function extractEncodedRedirectFromUrl($url, $baseUrl = null) {
        $url = trim((string)$url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        $query = trim((string)($parts['query'] ?? ''));
        if ($query === '') {
            return null;
        }

        parse_str($query, $params);
        if (empty($params) || !is_array($params)) {
            return null;
        }

        foreach (['token', 'url', 'src', 'link', 'target', 'redirect', 'r'] as $key) {
            if (empty($params[$key]) || !is_scalar($params[$key])) {
                continue;
            }

            $rawValue = trim((string)$params[$key]);
            if ($rawValue === '') {
                continue;
            }

            $candidates = [
                $rawValue,
                $this->decodeHexUrlCandidate($rawValue),
                $this->decodeUrlSafeBase64($rawValue),
            ];

            foreach ($candidates as $candidate) {
                $candidate = $this->normalizeExtractedUrlCandidate($candidate, $baseUrl ?: $url);
                if ($candidate && preg_match('#^https?://#i', $candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function shouldUseRelaxedFrameSandbox($url) {
        $host = strtolower((string)(parse_url((string)$url, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return false;
        }

        foreach (['dynamicsecular.net', 'wgstream.sx'] as $suffix) {
            if ($host === $suffix || substr($host, -strlen('.' . $suffix)) === '.' . $suffix) {
                return true;
            }
        }

        return false;
    }

    private function decodePotentialUrlValue($value, $baseUrl = null) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $candidates = array_filter([
            $this->decodeUrlSafeBase64($value),
            $this->decodeHexUrlCandidate($value),
            rawurldecode($value),
            $value,
        ], function ($candidate) {
            return trim((string)$candidate) !== '';
        });
        $candidates = array_values(array_unique(array_map(function ($candidate) {
            return trim((string)$candidate);
        }, $candidates)));

        foreach ($candidates as $candidate) {
            $variants = array_filter([
                $this->normalizeExtractedUrlCandidate($candidate, null),
                $baseUrl ? $this->normalizeExtractedUrlCandidate($candidate, $baseUrl) : null,
            ]);

            foreach ($variants as $variant) {
                if (
                    $variant
                    && preg_match('#^https?://#i', $variant)
                    && !preg_match('~(?:^|/)(?:sw\.js|worker\.js)(?:$|[?#])~i', $variant)
                    && !preg_match('~\.(?:js|css|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|map)(?:$|[?#])~i', $variant)
                ) {
                    return $variant;
                }
            }
        }

        return null;
    }

    private function extractUrlFromJavascriptExpression($expression, $baseUrl = null) {
        $expression = trim((string)$expression);
        if ($expression === '') {
            return null;
        }

        if (preg_match('/^(?:window\.)?atob\(\s*(["\'])(.*?)\1\s*\)$/is', $expression, $matches)) {
            return $this->decodePotentialUrlValue($this->decodeUrlSafeBase64($matches[2] ?? ''), $baseUrl);
        }

        if (preg_match('/^decodeURIComponent\(\s*(["\'])(.*?)\1\s*\)$/is', $expression, $matches)) {
            return $this->decodePotentialUrlValue(rawurldecode((string)($matches[2] ?? '')), $baseUrl);
        }

        if (preg_match('/^(["\'])(.*?)\1$/s', $expression, $matches)) {
            return $this->decodePotentialUrlValue($matches[2] ?? '', $baseUrl);
        }

        return $this->decodePotentialUrlValue($expression, $baseUrl);
    }

    private function extractAlbaPlayerStreamUrlFromHtml($html, $baseUrl = null) {
        $html = (string)$html;
        if ($html === '' || stripos($html, 'AlbaPlayerControl') === false) {
            return null;
        }

        if (preg_match_all('/AlbaPlayerControl\(\s*(.*?)\s*,\s*(["\'])(hls|mp4)\2/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $candidate = $this->extractUrlFromJavascriptExpression($match[1] ?? '', $baseUrl);
                if ($candidate) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function extractPlayerAdapterStreamUrl($html, $baseUrl = null) {
        $extractors = [
            'extractSkoIntentStreamUrlFromHtml',
            'extractAlbaPlayerStreamUrlFromHtml',
        ];

        foreach ($extractors as $extractor) {
            $candidate = $this->{$extractor}($html, $baseUrl);
            if ($candidate) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractSkoIntentStreamUrlFromHtml($html, $baseUrl = null) {
        $html = html_entity_decode((string)$html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($html === '' || stripos($html, 'intent://') === false) {
            return null;
        }

        if (!preg_match_all('/\b(?:href|data-[a-z0-9_-]+)\s*=\s*(["\'])(intent:\/\/play\?[^"\']+)\1/i', $html, $matches)) {
            return null;
        }

        foreach (($matches[2] ?? []) as $intentUrl) {
            $intentUrl = html_entity_decode(trim((string)$intentUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $queryStart = strpos($intentUrl, '?');
            if ($queryStart === false) {
                continue;
            }

            $fragmentStart = strpos($intentUrl, '#', $queryStart);
            $query = $fragmentStart === false
                ? substr($intentUrl, $queryStart + 1)
                : substr($intentUrl, $queryStart + 1, $fragmentStart - $queryStart - 1);

            parse_str($query, $params);
            $urlsValue = trim((string)($params['urls'] ?? ''));
            if ($urlsValue === '') {
                continue;
            }

            foreach (explode('|', $urlsValue) as $candidate) {
                $candidate = rawurldecode(trim((string)$candidate));
                $candidate = $this->normalizeExtractedUrlCandidate($candidate, $baseUrl);
                if ($candidate && preg_match('~\.m3u8($|[?#])~i', $candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function extractStreamUrlFromHtml($html, $baseUrl = null) {
        $html = (string)$html;
        if ($html === '') {
            return null;
        }

        $adapterCandidate = $this->extractPlayerAdapterStreamUrl($html, $baseUrl);
        if ($adapterCandidate) {
            return $adapterCandidate;
        }

        $patterns = [
            '/token\s*:\s*"([^"]+)"/i',
            "/token\\s*:\\s*'([^']+)'/i",
            '/source\s*:\s*"([^"]+)"/i',
            "/source\\s*:\\s*'([^']+)'/i",
            '/file\s*:\s*"([^"]+)"/i',
            "/file\\s*:\\s*'([^']+)'/i",
            '/src\s*=\s*"([^"]+\.m3u8[^"]*)"/i',
            "/src\\s*=\\s*'([^']+\\.m3u8[^']*)'/i",
            '/(https?:\/\/[^"\']+\.m3u8[^"\']*)/i'
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $html, $matches)) {
                continue;
            }

            $candidate = trim((string)($matches[1] ?? ''));
            if ($candidate === '') {
                continue;
            }

            if (stripos($pattern, 'token') !== false) {
                $candidate = $this->decodeUrlSafeBase64($candidate) ?: $candidate;
            }

            $candidate = $this->decodePotentialUrlValue($candidate, $baseUrl);
            if ($candidate) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractPlayableEmbedUrlFromHtml($html, $baseUrl = null) {
        $html = (string)$html;
        if ($html === '') {
            return null;
        }

        $candidates = [];
        if (preg_match_all('/<(?:iframe|frame)[^>]+src\s*=\s*(["\'])(.*?)\1/i', $html, $matches)) {
            foreach (($matches[2] ?? []) as $rawCandidate) {
                $candidate = $this->normalizeExtractedUrlCandidate($rawCandidate, $baseUrl);
                if (!$candidate || !preg_match('#^https?://#i', $candidate)) {
                    continue;
                }

                if ($this->shouldRejectSourceHostedFrameUrl($candidate)) {
                    continue;
                }

                $score = 0;
                if (stripos($candidate, '/embed/hls.php') !== false) {
                    $score += 120;
                }
                if (stripos($candidate, '/embed/') !== false) {
                    $score += 60;
                }
                if (preg_match('~(?:frame\.php|player|watch|live|stream|hls\.php)~i', $candidate)) {
                    $score += 30;
                }

                if ($baseUrl) {
                    $candidateHost = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?: ''));
                    $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
                    if ($candidateHost !== '' && $candidateHost === $baseHost) {
                        $score += 20;
                    }
                }

                $candidates[] = [
                    'url' => $candidate,
                    'score' => $score,
                ];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function ($left, $right) {
            return ($right['score'] <=> $left['score']);
        });

        return $candidates[0]['url'] ?? null;
    }

    private function extractPlayableServerTargetFromHtml($html, $baseUrl = null) {
        $html = html_entity_decode((string)$html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($html === '' || stripos($html, 'servers') === false) {
            return null;
        }

        if (!preg_match_all('/\b(?:const|let|var)\s+servers\s*=\s*(\[[\s\S]*?\])\s*;/i', $html, $matches)) {
            return null;
        }

        $fallbackFrameUrl = null;
        foreach (($matches[1] ?? []) as $rawServersJson) {
            $servers = json_decode((string)$rawServersJson, true);
            if (!is_array($servers)) {
                continue;
            }

            foreach ($servers as $server) {
                if (!is_array($server)) {
                    continue;
                }

                $url = $this->normalizeExtractedUrlCandidate($server['url'] ?? '', $baseUrl);
                if (!$url || !preg_match('#^https?://#i', $url)) {
                    continue;
                }

                $type = strtolower(trim((string)($server['type'] ?? '')));
                if ($type === 'hls' || preg_match('~\.m3u8($|[?#])~i', $url)) {
                    return [
                        'type' => 'hls',
                        'url' => $url,
                        'referer' => $baseUrl,
                    ];
                }

                if ($fallbackFrameUrl === null && in_array($type, ['iframe', 'frame', 'embed', 'player'], true)) {
                    $fallbackFrameUrl = $url;
                }
            }
        }

        if ($fallbackFrameUrl !== null) {
            return [
                'type' => 'frame',
                'url' => $fallbackFrameUrl,
                'referer' => $baseUrl,
            ];
        }

        return null;
    }

    private function extractStreamsCenterDecryptedUrlFromHtml($html, $baseUrl = null) {
        $html = (string)$html;
        if ($html === '') {
            return null;
        }

        if (!preg_match('/fetch\((["\'])decrypt\.php\1/i', $html)) {
            return null;
        }

        if (!preg_match('/input\s*:\s*(["\'])(.*?)\1/is', $html, $matches)) {
            return null;
        }

        $inputValue = trim((string)($matches[2] ?? ''));
        if ($inputValue === '') {
            return null;
        }

        $decryptUrl = $this->resolveUrlAgainstBase('decrypt.php', $baseUrl ?: '');
        if (!$decryptUrl || !preg_match('#^https?://#i', $decryptUrl)) {
            return null;
        }

        $response = $this->fetchUrlPostResponse($decryptUrl, ['input' => $inputValue], $baseUrl ?: $decryptUrl, [
            'X-Requested-With: XMLHttpRequest',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        ]);

        if (!$response || (int)($response['status'] ?? 0) >= 400) {
            return null;
        }

        $candidate = trim((string)($response['body'] ?? ''));
        if ($candidate === '' || stripos($candidate, 'error:') === 0) {
            return null;
        }

        $candidate = $this->normalizeExtractedUrlCandidate($candidate, $decryptUrl);
        if ($candidate && preg_match('#^https?://#i', $candidate)) {
            return $candidate;
        }

        return null;
    }

    private function getLocalStreamPlayerBaseUrl() {
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

    private function buildLocalStreamPlayerUrl($streamUrl, array $meta = []) {
        $streamUrl = trim((string)$streamUrl);
        if ($streamUrl === '' || !preg_match('#^https?://#i', $streamUrl)) {
            return null;
        }

        $params = [
            'stream' => rtrim(strtr(base64_encode($streamUrl), '+/', '-_'), '='),
        ];

        if (!empty($meta['title'])) {
            $params['title'] = trim((string)$meta['title']);
        }

        if (!empty($meta['referer']) && preg_match('#^https?://#i', (string)$meta['referer'])) {
            $params['ref'] = rtrim(strtr(base64_encode(trim((string)$meta['referer'])), '+/', '-_'), '=');
        }

        $resolvedMatchId = (int)($meta['match_id'] ?? $this->currentLiveMatchId ?? 0);
        if ($resolvedMatchId > 0) {
            $params['match_id'] = $resolvedMatchId;
        }

        return $this->getLocalStreamPlayerBaseUrl() . '/stream_player.php?' . http_build_query($params);
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

        if (!empty($meta['no_sandbox'])) {
            $params['nosandbox'] = '1';
        }

        return $this->getLocalStreamPlayerBaseUrl() . '/frame_player.php?' . http_build_query($params);
    }

    private function buildRawIframeHtml($src, array $options = []) {
        $src = trim((string)$src);
        if ($src === '') {
            return null;
        }

        $allow = trim((string)($options['allow'] ?? 'autoplay; encrypted-media; picture-in-picture; fullscreen'));
        $height = trim((string)($options['height'] ?? '500px'));
        $width = trim((string)($options['width'] ?? '100%'));
        $name = trim((string)($options['name'] ?? 'search_iframe'));
        $sandbox = trim((string)($options['sandbox'] ?? 'allow-forms allow-same-origin allow-scripts allow-popups allow-presentation'));

        return '<iframe allow="' . htmlspecialchars($allow, ENT_QUOTES, 'UTF-8') . '" class="cf" frameborder="0" height="' . htmlspecialchars($height, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" rel="nofollow" sandbox="' . htmlspecialchars($sandbox, ENT_QUOTES, 'UTF-8') . '" scrolling="no" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" width="' . htmlspecialchars($width, ENT_QUOTES, 'UTF-8') . '"></iframe>';
    }

    private function buildLocalFrameWrapperIframeHtml($frameUrl, array $meta = []) {
        $playerUrl = $this->buildLocalFramePlayerUrl($frameUrl, $meta);
        if (!$playerUrl) {
            return null;
        }

        return $this->buildRawIframeHtml($playerUrl, $meta);
    }

    private function buildHlsPlayerIframeHtml($streamUrl, array $meta = []) {
        $playerUrl = $this->buildLocalStreamPlayerUrl($streamUrl, $meta);
        if (!$playerUrl) {
            return null;
        }

        return $this->buildRawIframeHtml($playerUrl, $meta);
    }

    private function resolvePlayableChannelTarget($url, $referer = null, $depth = 0) {
        $url = trim((string)$url);
        if ($url === '' || $depth > 5 || $this->hasUnresolvedUrlTemplate($url)) {
            return null;
        }

        $preResolvedUrl = $this->extractEncodedRedirectFromUrl($url, $referer ?: $url);
        if ($preResolvedUrl && strcasecmp($preResolvedUrl, $url) !== 0) {
            $resolved = $this->resolvePlayableChannelTarget($preResolvedUrl, $referer ?: $url, $depth + 1);
            if ($resolved) {
                return $resolved;
            }
        }

        $response = $this->fetchUrlResponse($url, $referer);
        if (!$response || (int)($response['status'] ?? 0) >= 400) {
            return null;
        }

        $effectiveUrl = $this->normalizeExtractedUrlCandidate((string)($response['effective_url'] ?? $url), $url)
            ?: trim((string)($response['effective_url'] ?? $url));
        $body = (string)($response['body'] ?? '');
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];

        if (preg_match('~\.m3u8($|[?\#])~i', $effectiveUrl)) {
            return [
                'type' => 'hls',
                'url' => $effectiveUrl,
                'referer' => $referer ?: $url,
            ];
        }

        $redirectFromEffectiveUrl = $this->extractEncodedRedirectFromUrl($effectiveUrl, $referer ?: $effectiveUrl);
        if ($redirectFromEffectiveUrl && strcasecmp($redirectFromEffectiveUrl, $effectiveUrl) !== 0) {
            $resolved = $this->resolvePlayableChannelTarget($redirectFromEffectiveUrl, $effectiveUrl, $depth + 1);
            if ($resolved) {
                return $resolved;
            }
        }

        $decryptedStreamUrl = $this->extractStreamsCenterDecryptedUrlFromHtml($body, $effectiveUrl);
        if ($decryptedStreamUrl && preg_match('#^https?://#i', $decryptedStreamUrl)) {
            if (preg_match('~\.m3u8($|[?\#])~i', $decryptedStreamUrl)) {
                return [
                    'type' => 'hls',
                    'url' => $decryptedStreamUrl,
                    'referer' => $effectiveUrl,
                ];
            }

            $nestedResolved = $this->resolvePlayableChannelTarget($decryptedStreamUrl, $effectiveUrl, $depth + 1);
            if ($nestedResolved) {
                return $nestedResolved;
            }
        }

        // Prefer extracting the real stream first, especially for smartagro/kora-top chains.
        $streamUrl = $this->extractStreamUrlFromHtml($body, $effectiveUrl);
        if ($streamUrl && preg_match('#^https?://#i', $streamUrl)) {
            if (preg_match('~\.m3u8($|[?\#])~i', $streamUrl)) {
                return [
                    'type' => 'hls',
                    'url' => $streamUrl,
                    'referer' => $effectiveUrl,
                ];
            }

            $nestedResolved = $this->resolvePlayableChannelTarget($streamUrl, $effectiveUrl, $depth + 1);
            if ($nestedResolved) {
                return $nestedResolved;
            }

            return [
                'type' => 'frame',
                'url' => $streamUrl,
            ];
        }

        $serverTarget = $this->extractPlayableServerTargetFromHtml($body, $effectiveUrl);
        if ($serverTarget && !empty($serverTarget['url'])) {
            if (($serverTarget['type'] ?? '') === 'hls') {
                return $serverTarget;
            }

            $nestedResolved = $this->resolvePlayableChannelTarget($serverTarget['url'], $effectiveUrl, $depth + 1);
            if ($nestedResolved) {
                return $nestedResolved;
            }

            return $serverTarget;
        }

        $embedUrl = $this->extractPlayableEmbedUrlFromHtml($body, $effectiveUrl);
        if ($embedUrl && strcasecmp($embedUrl, $effectiveUrl) !== 0) {
            $nestedResolved = $this->resolvePlayableChannelTarget($embedUrl, $effectiveUrl, $depth + 1);
            if ($nestedResolved) {
                return $nestedResolved;
            }

            return [
                'type' => 'frame',
                'url' => $embedUrl,
            ];
        }

        $redirectTarget = $this->extractRedirectTargetFromHtml($body, $effectiveUrl);
        if ($redirectTarget && strcasecmp($redirectTarget, $effectiveUrl) !== 0) {
            $resolved = $this->resolvePlayableChannelTarget($redirectTarget, $effectiveUrl, $depth + 1);
            if ($resolved) {
                return $resolved;
            }

            $embeddableRedirect = $this->resolveEmbeddableChannelUrl($redirectTarget, $effectiveUrl, $depth + 1);
            if ($embeddableRedirect) {
                return [
                    'type' => 'frame',
                    'url' => $embeddableRedirect,
                ];
            }
        }

        if ($this->shouldRejectSourceHostedFrameUrl($effectiveUrl) || $this->isLikelyNonPlayerContentPage($effectiveUrl, $body)) {
            return null;
        }

        if (!$this->responseAllowsEmbedding($headers)) {
            return null;
        }

        return [
            'type' => 'frame',
            'url' => $effectiveUrl,
        ];
    }

    private function extractDynamicLiveCandidates($liveUrl) {
        $liveUrl = trim((string)$liveUrl);
        if ($liveUrl === '' || !function_exists('shell_exec')) {
            return [];
        }

        $disabled = ',' . str_replace(' ', '', (string)ini_get('disable_functions')) . ',';
        if (strpos($disabled, ',shell_exec,') !== false) {
            return [];
        }

        $scriptPath = __DIR__ . '/../extract_live_candidates.js';
        if (!is_file($scriptPath)) {
            return [];
        }

        $nodeBinary = $this->resolveNodeBinaryForPhp();
        if ($nodeBinary === null) {
            return [];
        }

        $command = $nodeBinary . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($liveUrl) . ' 2>&1';
        $output = @shell_exec($command);
        $output = trim((string)$output);
        if ($output === '') {
            return [];
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            return [];
        }

        $candidates = [];
        foreach ($decoded as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '' || !preg_match('#^https?://#i', $candidate)) {
                continue;
            }

            if (!$this->isPromisingDynamicLiveCandidate($candidate)) {
                continue;
            }
            $candidates[] = $candidate;
        }

        $candidates = array_values(array_unique($candidates));
        usort($candidates, function ($left, $right) {
            return $this->scoreDynamicLiveCandidate($right) <=> $this->scoreDynamicLiveCandidate($left);
        });

        return $candidates;
    }

    private function resolveNodeBinaryForPhp() {
        static $resolved = false;
        static $binary = null;

        if ($resolved) {
            return $binary;
        }

        $resolved = true;
        $candidates = array_filter([
            trim((string)(getenv('SPORT_NODE_BIN') ?: '')),
            trim((string)(getenv('NODE_BINARY') ?: '')),
            '/usr/bin/node',
            '/usr/local/bin/node',
            '/bin/node',
            'node',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === 'node') {
                $binary = 'node';
                return $binary;
            }

            if (is_file($candidate) && is_executable($candidate)) {
                $binary = escapeshellarg($candidate);
                return $binary;
            }
        }

        return null;
    }

    private function isPromisingDynamicLiveCandidate($candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '' || strpos($candidate, '${') !== false) {
            return false;
        }

        if ($this->isGenericKoraSimoPlayerUrl($candidate)) {
            return false;
        }

        $host = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?: ''));
        $path = strtolower((string)(parse_url($candidate, PHP_URL_PATH) ?: ''));
        $query = (string)(parse_url($candidate, PHP_URL_QUERY) ?: '');

        if (in_array($host, ['twitter.com', 'www.twitter.com', 'wa.me', 't.me', 'telegram.me', 'sports-now.top'], true)) {
            return false;
        }

        if (strpos($path, '/intent/tweet') !== false || strpos($path, '/share/url') !== false || strpos($path, '/article/') !== false) {
            return false;
        }

        if (strpos($path, 'frame.php') !== false && trim($query) === '') {
            return false;
        }

        return $this->scoreDynamicLiveCandidate($candidate) > 0;
    }

    private function scoreDynamicLiveCandidate($candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            return -100;
        }

        $host = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?: ''));
        $path = strtolower((string)(parse_url($candidate, PHP_URL_PATH) ?: ''));
        $query = strtolower((string)(parse_url($candidate, PHP_URL_QUERY) ?: ''));
        $score = 0;

        if (preg_match('~\.m3u8($|[?#])~i', $candidate)) {
            $score += 100;
        }

        if (strpos($path, '/watch/') !== false) {
            $score += 55;
        }

        if (strpos($path, '/embed/') !== false) {
            $score += 45;
        }

        if (strpos($path, '/channels/') !== false || strpos($path, 'hls.php') !== false || strpos($path, 'decrypt.php') !== false) {
            $score += 35;
        }

        if (strpos($path, 'frame.php') !== false && $query !== '') {
            $score += 30;
        }

        if (strpos($path, '/3d/football') !== false) {
            $score += 6;
        }

        if (strpos($query, 'ch=') !== false) {
            $score += 12;
        }

        if (strpos($query, 'token=') !== false) {
            $score += 8;
        }

        if (preg_match('~(kora-top\.zip|kora-plus\.dad|sportszonline|streams\.center|score808|dynamicsecular|thesports01\.com)~i', $host . $path)) {
            $score += 10;
        }

        if (preg_match('~(twitter\.com|wa\.me|t\.me|sports-now\.top)~i', $host)) {
            $score -= 100;
        }

        if (strpos($path, '/intent/tweet') !== false || strpos($path, '/share/url') !== false || strpos($path, '/article/') !== false) {
            $score -= 80;
        }

        if (strpos($candidate, '${') !== false) {
            $score -= 100;
        }

        if ($this->isGenericKoraSimoPlayerUrl($candidate)) {
            $score -= 100;
        }

        return $score;
    }

    private function isResolvedHlsTargetPlayable(array $resolvedTarget) {
        $type = strtolower(trim((string)($resolvedTarget['type'] ?? '')));
        $url = trim((string)($resolvedTarget['url'] ?? ''));
        if ($type !== 'hls' || $url === '') {
            return true;
        }

        $response = $this->fetchUrlResponse($url, $resolvedTarget['referer'] ?? null);
        if (!$response) {
            return false;
        }

        $status = (int)($response['status'] ?? 0);
        if ($status >= 400) {
            return false;
        }

        $body = ltrim((string)($response['body'] ?? ''));
        $contentType = strtolower(trim((string)(($response['headers'] ?? [])['content-type'] ?? '')));

        return strpos($body, '#EXTM3U') === 0
            || strpos($contentType, 'application/vnd.apple.mpegurl') !== false
            || strpos($contentType, 'application/x-mpegurl') !== false;
    }

    private function scoreKoraApiChannel(array $channel) {
        $score = 0;
        $name = trim((string)($channel['server_name'] ?? $channel['server_name_en'] ?? ''));
        $normalizedName = strtolower($this->normalizeYallaShootTeamName($name));
        $type = strtolower(trim((string)($channel['type'] ?? '')));

        if ((string)($channel['edge'] ?? '') === '0') {
            $score += 14;
        }

        if (!empty($channel['mobile_link'])) {
            $score += 6;
        }

        if ($name !== '') {
            $score += 4;
        }

        if ($type === 'frame' || $type === 'landscape') {
            $score += 2;
        }

        if (preg_match('/\b(fhd|full hd|1080|4k|uhd)\b/i', $normalizedName)) {
            $score += 8;
        } elseif (preg_match('/\b(hd|720)\b/i', $normalizedName)) {
            $score += 5;
        }

        if (preg_match('/\b(bein|sky|ssc|dazn|espn|eurosport|canal|on sport|abu dhabi|ad sports|bein sport|beinsports)\b/i', $normalizedName)) {
            $score += 40;
        }

        if (preg_match('/(بي ان|بي إن|اون سبورت|أون سبورت|ابو ظبي|أبو ظبي|دازن|يوروسبورت)/u', $name)) {
            $score += 40;
        }

        if (preg_match('/\b(live|server|stream)\s*\d*\b/i', $normalizedName)) {
            $score -= 24;
        }

        if (preg_match('/\b(channel|link)\s*\d*\b/i', $normalizedName)) {
            $score -= 12;
        }

        if (preg_match('/^(live|server|stream)\b/i', $normalizedName)) {
            $score -= 18;
        }

        return $score;
    }

    private function findYallaShootMatchCandidate($homeTeam, $awayTeam, $matchDate) {
        $date = trim((string)$matchDate);
        if ($date === '') {
            return null;
        }

        $apiUrl = $this->yallaShootMatchesApiBase . rawurlencode($date) . '/1?t=' . time();
        $payload = $this->fetchJsonContent($apiUrl, 'https://yalla-shoot.mov/');
        if (!is_array($payload) || empty($payload['matches']) || !is_array($payload['matches'])) {
            return null;
        }

        $bestMatch = null;
        $bestScore = 0;

        foreach ($payload['matches'] as $match) {
            $homeCandidates = array_filter([
                trim((string)($match['home'] ?? '')),
                trim((string)($match['home_en'] ?? ''))
            ]);

            $awayCandidates = array_filter([
                trim((string)($match['away'] ?? '')),
                trim((string)($match['away_en'] ?? ''))
            ]);

            $score = $this->calculateGameMatchScore($homeTeam, $awayTeam, $homeCandidates, $awayCandidates);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $match;
            }
        }

        return $bestScore >= 68 ? $bestMatch : null;
    }

    private function normalizeYallaShootTeamName($name) {
        $name = trim((string)$name);
        if ($name === '') {
            return '';
        }

        $name = mb_strtolower($name, 'UTF-8');
        $name = strtr($name, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ٱ' => 'ا',
            'ى' => 'ي',
            'ة' => 'ه',
            'ؤ' => 'و',
            'ئ' => 'ي',
        ]);

        $name = preg_replace('/[^\p{Arabic}\p{Latin}\p{N}\s]/u', ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name);

        return trim($name);
    }

    private function deepNormalizeTeamName($name) {
        $name = $this->normalizeYallaShootTeamName($this->cleanTeamName($name));
        if ($name === '') {
            return '';
        }

        $name = strtr($name, [
            'غ' => 'ج',
            'خ' => 'ج',
            'ذ' => 'ز',
            'ظ' => 'ز',
            'ص' => 'س',
            'ض' => 'س',
            'ط' => 'ت',
            'ث' => 'ت',
            'ق' => 'ك',
            'ح' => 'ه',
        ]);

        $name = preg_replace('/(.)\\1+/u', '$1', $name);
        $name = preg_replace('/\\s+/u', ' ', $name);

        return trim($name);
    }

    private function wordLevelSimilarity($name1, $name2) {
        $words1 = preg_split('/[\s\-_\.]+/u', $name1);
        $words2 = preg_split('/[\s\-_\.]+/u', $name2);

        $words1 = array_values(array_filter($words1, static function ($word) {
            return mb_strlen($word, 'UTF-8') >= 4;
        }));
        $words2 = array_values(array_filter($words2, static function ($word) {
            return mb_strlen($word, 'UTF-8') >= 4;
        }));

        if (empty($words1) || empty($words2)) {
            return 0;
        }

        $matchedWords = 0;
        $bestSingleWordScore = 0;

        foreach ($words1 as $word1) {
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

                similar_text($word1, $word2, $wordPercent);
                if ($wordPercent > 70) {
                    $bestMatch = max($bestMatch, $wordPercent);
                }
            }

            if ($bestMatch >= 70) {
                $matchedWords++;
            }

            $bestSingleWordScore = max($bestSingleWordScore, $bestMatch);
        }

        if ($matchedWords === 0) {
            return $bestSingleWordScore > 70 ? $bestSingleWordScore : 0;
        }

        if ($matchedWords >= 2) {
            return 90;
        }

        return max(72, min($bestSingleWordScore, 82));
    }

    private function calculateSingleNameSimilarity($name1, $name2) {
        $normal1 = $this->normalizeYallaShootTeamName($this->cleanTeamName($name1));
        $normal2 = $this->normalizeYallaShootTeamName($this->cleanTeamName($name2));

        if ($normal1 === '' || $normal2 === '') {
            return 0;
        }

        if ($normal1 === $normal2) {
            return 100;
        }

        if (mb_strpos($normal1, $normal2) !== false || mb_strpos($normal2, $normal1) !== false) {
            return 95;
        }

        $aliases1 = $this->getTeamAliases($name1);
        foreach ($aliases1 as $alias) {
            $aliasNorm = $this->normalizeYallaShootTeamName($alias);
            if ($aliasNorm !== '' && ($aliasNorm === $normal2 || mb_strpos($normal2, $aliasNorm) !== false || mb_strpos($aliasNorm, $normal2) !== false)) {
                return 97;
            }
        }

        $aliases2 = $this->getTeamAliases($name2);
        foreach ($aliases2 as $alias) {
            $aliasNorm = $this->normalizeYallaShootTeamName($alias);
            if ($aliasNorm !== '' && ($aliasNorm === $normal1 || mb_strpos($normal1, $aliasNorm) !== false || mb_strpos($aliasNorm, $normal1) !== false)) {
                return 97;
            }
        }

        $wordScore = $this->wordLevelSimilarity($normal1, $normal2);

        similar_text($normal1, $normal2, $percent);

        $deep1 = $this->deepNormalizeTeamName($name1);
        $deep2 = $this->deepNormalizeTeamName($name2);
        $deepWordScore = 0;
        $deepPercent = 0;

        if ($deep1 !== '' && $deep2 !== '') {
            if ($deep1 === $deep2) {
                return 98;
            }

            if (mb_strpos($deep1, $deep2) !== false || mb_strpos($deep2, $deep1) !== false) {
                return 93;
            }

            $deepWordScore = $this->wordLevelSimilarity($deep1, $deep2);
            similar_text($deep1, $deep2, $deepPercent);

            if (mb_strlen($deep1, 'UTF-8') > 4 && mb_strlen($deep2, 'UTF-8') > 4) {
                $lev = levenshtein($deep1, $deep2);
                $maxLen = max(mb_strlen($deep1, 'UTF-8'), mb_strlen($deep2, 'UTF-8'));
                if ($maxLen > 0) {
                    $levPercent = max(0, 100 - (($lev / $maxLen) * 100));
                    $deepPercent = max($deepPercent, $levPercent);
                }
            }
        }

        return max($percent, $wordScore, $deepPercent, $deepWordScore);
    }

    private function calculateTeamCandidateSetScore($expectedTeam, $actualTeam) {
        $expectedCandidates = $this->extractTeamCandidateStrings($expectedTeam);
        $actualCandidates = $this->extractTeamCandidateStrings($actualTeam);
        $bestScore = 0;

        foreach ($expectedCandidates as $expectedCandidate) {
            foreach ($actualCandidates as $actualCandidate) {
                $bestScore = max($bestScore, $this->calculateSingleNameSimilarity($expectedCandidate, $actualCandidate));
            }
        }

        return $bestScore;
    }

    private function calculateGameMatchScore($homeExpected, $awayExpected, $homeActual, $awayActual) {
        $directHome = $this->calculateTeamCandidateSetScore($homeExpected, $homeActual);
        $directAway = $this->calculateTeamCandidateSetScore($awayExpected, $awayActual);
        $swappedHome = $this->calculateTeamCandidateSetScore($homeExpected, $awayActual);
        $swappedAway = $this->calculateTeamCandidateSetScore($awayExpected, $homeActual);

        $directScore = ($directHome + $directAway) / 2;
        $swappedScore = ($swappedHome + $swappedAway) / 2;

        $bestCombinedScore = max($directScore, $swappedScore);
        $bestMinScore = max(min($directHome, $directAway), min($swappedHome, $swappedAway));
        $bestOneTeamScore = max($directHome, $directAway, $swappedHome, $swappedAway);

        if ($bestCombinedScore >= 70 && $bestMinScore >= 55) {
            return $bestCombinedScore;
        }

        if ($bestCombinedScore >= 64 && $bestMinScore >= 62) {
            return $bestCombinedScore;
        }

        if ($bestOneTeamScore >= 95 && $bestCombinedScore >= 52) {
            return $bestCombinedScore;
        }

        return 0;
    }

    private function isYallaShootTeamNameMatch($expected, $actual) {
        return $this->calculateTeamCandidateSetScore($expected, $actual) >= 70;
    }

    private function isYallaShootTeamPairMatch($homeTeam, $awayTeam, array $homeCandidates, array $awayCandidates) {
        return $this->calculateGameMatchScore($homeTeam, $awayTeam, $homeCandidates, $awayCandidates) >= 68;
    }

    private function buildKoraApiLiveUrl($matchId, array $profile) {
        $streamingBase = rtrim((string)($profile['streaming_base'] ?? $this->yallaShootStreamingBase), '/');
        $lang = trim((string)($profile['lang'] ?? 'ar'));

        return $streamingBase . '/?m=' . rawurlencode((string)$matchId) . '&lang=' . rawurlencode($lang);
    }

    private function buildKoraApiMatchApiUrl($matchId, $lang = 'ar') {
        return $this->yallaShootMatchApiBase . rawurlencode((string)$matchId) . '/' . rawurlencode($lang) . '?t=' . time();
    }

    private function getPreferredYallaShootFrameBase() {
        return $this->yallaShootFrameUrls[0];
    }

    private function generateYallaShootVisitorToken() {
        if (function_exists('random_bytes')) {
            try {
                $data = random_bytes(16);
                $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
                $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

                return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
            } catch (Throwable $e) {
                // Fall back to a deterministic-enough token.
            }
        }

        return uniqid('ysm-', true);
    }

    private function buildYallaShootIframeFromChannel(array $channel) {
        $type = strtolower(trim((string)($channel['type'] ?? '')));
        $edge = (string)($channel['edge'] ?? '');
        $channelLink = trim((string)($channel['link'] ?? ''));
        $mobileLink = trim((string)($channel['mobile_link'] ?? ''));
        $channelCode = trim((string)($channel['ch'] ?? ''));
        $playerSlot = trim((string)($channel['p'] ?? ''));

        $candidateUrls = [];

        if ($mobileLink !== '') {
            $candidateUrls[] = $mobileLink;
        }

        if ($channelLink !== '') {
            $candidateUrls[] = $channelLink;
        }

        if (($type === 'landscape' || $type === 'frame') && $channelCode !== '') {
            $fallbackSlot = $playerSlot !== '' ? $playerSlot : '12';
            $candidateUrls[] = $this->getPreferredYallaShootFrameBase()
                . '?ch=' . rawurlencode($channelCode)
                . '&p=' . rawurlencode($fallbackSlot)
                . '&token=' . rawurlencode($this->generateYallaShootVisitorToken())
                . '&kt=' . time();
        }

        foreach (array_values(array_unique(array_filter($candidateUrls))) as $candidateUrl) {
            $resolvedTarget = $this->resolvePlayableChannelTarget($candidateUrl, $this->baseUrl . '/');
            if (!$resolvedTarget) {
                continue;
            }

            if (($resolvedTarget['type'] ?? '') === 'hls') {
                if (!$this->isResolvedHlsTargetPlayable($resolvedTarget)) {
                    continue;
                }

                $iframe = $this->buildHlsPlayerIframeHtml($resolvedTarget['url'], [
                    'title' => trim((string)($channel['server_name'] ?? $channel['server_name_en'] ?? 'Live Stream')),
                    'referer' => $resolvedTarget['referer'] ?? null,
                ]);
                if ($iframe) {
                    return $iframe;
                }
            }

            if (!empty($resolvedTarget['url'])) {
                return $this->buildIframeHtmlFromUrl($resolvedTarget['url']);
            }
        }

        return null;
    }

    private function buildKoraApiIframeFromLiveUrl($liveUrl) {
        $candidates = $this->extractDynamicLiveCandidates($liveUrl);
        if (empty($candidates)) {
            return null;
        }

        foreach ($candidates as $candidateUrl) {
            $resolvedTarget = $this->resolvePlayableChannelTarget($candidateUrl, $liveUrl);
            if (!$resolvedTarget) {
                continue;
            }

            if (($resolvedTarget['type'] ?? '') === 'hls') {
                $iframe = $this->buildHlsPlayerIframeHtml($resolvedTarget['url'], [
                    'title' => 'Live Stream',
                    'referer' => $resolvedTarget['referer'] ?? null,
                ]);
                if ($iframe) {
                    return $iframe;
                }
            }

            if (!empty($resolvedTarget['url'])) {
                return $this->buildIframeHtmlFromUrl($resolvedTarget['url']);
            }
        }

        return null;
    }

    private function buildKoraApiIframeFromMatchId($matchId, array $profile) {
        $lang = trim((string)($profile['lang'] ?? 'ar'));
        $matchApiUrl = $this->buildKoraApiMatchApiUrl($matchId, $lang);
        $payload = $this->fetchJsonContent($matchApiUrl, $this->buildKoraApiLiveUrl($matchId, $profile));

        if (!is_array($payload) || empty($payload['channels']) || !is_array($payload['channels'])) {
            return null;
        }

        $channels = $payload['channels'];
        usort($channels, function ($a, $b) {
            return $this->scoreKoraApiChannel($b) <=> $this->scoreKoraApiChannel($a);
        });

        foreach ($channels as $channel) {
            $iframe = $this->buildYallaShootIframeFromChannel($channel);
            if ($iframe) {
                return $iframe;
            }
        }

        return null;
    }
    
    /**
     * Search for a match on kooora-sport.com and get its detail URL
     */
    public function findMatchDetailUrl($homeTeam, $awayTeam, $matchDate) {
        foreach ($this->sources as $source) {
            $this->baseUrl = $source['base_url'];
            $todayUrl = $this->baseUrl . $source['matches_path'];
            
            $html = $this->fetchUrlContent($todayUrl);
            if (!$html) continue;
            
            // Parse HTML to find match
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            $xpath = new DOMXPath($dom);
            
            // Find all match containers
            $matchDivs = $xpath->query($source['container_selector']);
            
            foreach ($matchDivs as $matchDiv) {
                // Get team names from this match div
                $teamNames = $xpath->query($source['teams_selector'], $matchDiv);
                
                if ($teamNames->length >= 2) {
                    $team1 = trim($teamNames->item(0)->textContent);
                    $team2 = trim($teamNames->item(1)->textContent);
                    
                    // Use the new SMART MATCH algorithm
                    if ($this->isGameMatch($homeTeam, $awayTeam, $team1, $team2)) {
                        
                        // Find the link within this match div
                        $link = $xpath->query($source['link_selector'], $matchDiv)->item(0);
                        
                        if ($link) {
                            $href = $link->getAttribute('href');
                            
                            // Make sure it's a full URL
                            if (strpos($href, 'http') === false) {
                                $href = $this->baseUrl . (strpos($href, '/') === 0 ? '' : '/') . $href;
                            }
                            
                            return $href;
                        }
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Clean team name for better matching (Smart Normalization)
     */
    private function cleanTeamName($name) {
        if (empty($name)) return '';
        
        // Convert to lowercase if English
        $name = mb_strtolower($name, 'UTF-8');
        
        // Normalize Arabic characters
        $arabicNorm = [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي',
            'ؤ' => 'و', 'ئ' => 'ي',
            'گ' => 'ك', 'پ' => 'ب', 'چ' => 'ج', 'ژ' => 'ز', 'ڤ' => 'ف'
        ];
        $name = strtr($name, $arabicNorm);
        
        // Remove common prefixes/suffixes and noise words
        $noiseWords = [
            'نادي', 'فريق', 'منتخب', 'تحت', 'للشباب', 'الشباب', 'الاول', 'الاحتياطي', 
            'الرديف', 'سيدات', 'السيدات', 'دولي', 'ودي', 'وديه', 'بث', 'مباشر',
            'fc', 'sc', 'u23', 'u21', 'u19', 'u17', 'u15', 'youth', 'women', 'club'
        ];
        
        // Remove "ال" prefix from words only if it's followed by letters
        $name = preg_replace('/\bال(?=\p{L})/u', '', $name);
        
        foreach ($noiseWords as $word) {
            $cleaned = preg_replace('/\b' . preg_quote($word, '/') . '\b/u', '', $name);
            if (trim((string)$cleaned) !== '') {
                $name = $cleaned;
            }
        }
        
        // Remove punctuated symbols and numbers
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
        
        // Clean double spaces
        $name = preg_replace('/\s+/', ' ', $name);
        
        return trim($name);
    }
    
    /**
     * Get predefined aliases for teams that have completely different names
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

        $supplementalAliases = [
            'strasbourg' => ['rc strasbourg', 'strasburg', 'strasbur', 'strasbourg alsace', 'ستراسبورغ', 'ستراسبور', 'ستراسبورج'],
            'ستراسبورغ' => ['ستراسبور', 'ستراسبورج', 'strasbourg', 'rc strasbourg'],
            'nice' => ['ogc nice', 'نيس'],
            'نيس' => ['nice', 'ogc nice'],
            'bayer leverkusen' => ['leverkusen', 'bayer 04', 'bayer 04 leverkusen', 'باير ليفركوزن', 'ليفركوزن'],
            'leverkusen' => ['bayer leverkusen', 'bayer 04', 'باير ليفركوزن', 'ليفركوزن'],
            'باير ليفركوزن' => ['ليفركوزن', 'bayer leverkusen', 'bayer 04', 'leverkusen'],
            'ليفركوزن' => ['باير ليفركوزن', 'bayer leverkusen', 'bayer 04', 'leverkusen'],
            'wolfsburg' => ['vfl wolfsburg', 'فولفسبورغ', 'فولفسبورج'],
            'vfl wolfsburg' => ['wolfsburg', 'فولفسبورغ', 'فولفسبورج'],
            'فولفسبورغ' => ['فولفسبورج', 'wolfsburg', 'vfl wolfsburg'],
            'فولفسبورج' => ['فولفسبورغ', 'wolfsburg', 'vfl wolfsburg'],
        ];
        $aliases = array_merge($aliases, $supplementalAliases);

        $clean = $this->cleanTeamName($name);
        
        // Check if any alias key is contained in the clean name or vice versa
        foreach ($aliases as $key => $list) {
            $cleanKey = $this->cleanTeamName($key);
            if ($clean === $cleanKey || (mb_strlen($clean) > 3 && mb_strpos($cleanKey, $clean) !== false)) {
                return $list;
            }
        }
        
        return [];
    }

    private function extractTeamCandidateStrings($team) {
        $candidates = [];

        $append = function ($value) use (&$candidates) {
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

        $seen = [];
        $unique = [];
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

    private function getPreferredTeamCandidate($team) {
        $candidates = $this->extractTeamCandidateStrings($team);
        return $candidates[0] ?? '';
    }

    private function teamCandidateSetsMatch($expectedTeam, $actualTeam) {
        $expectedCandidates = $this->extractTeamCandidateStrings($expectedTeam);
        $actualCandidates = $this->extractTeamCandidateStrings($actualTeam);

        foreach ($expectedCandidates as $expectedCandidate) {
            foreach ($actualCandidates as $actualCandidate) {
                if ($this->isMatch($expectedCandidate, $actualCandidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function strongTeamCandidateSetsMatch($expectedTeam, $actualTeam) {
        $expectedCandidates = $this->extractTeamCandidateStrings($expectedTeam);
        $actualCandidates = $this->extractTeamCandidateStrings($actualTeam);

        foreach ($expectedCandidates as $expectedCandidate) {
            foreach ($actualCandidates as $actualCandidate) {
                if ($this->isStrongMatch($expectedCandidate, $actualCandidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check similarity between two team names
     */
    private function isMatch($teamA, $teamB) {
        return $this->calculateSingleNameSimilarity($teamA, $teamB) >= 70;
    }

    /**
     * SMART MATCH: Tries to find the best match for a game
     */
    private function isGameMatch($h1, $a1, $h2, $a2) {
        return $this->calculateGameMatchScore($h1, $a1, $h2, $a2) >= 68;
    }
    
    /**
     * Check if a match is "Strong" enough to justify a one-team fallback
     */
    private function isStrongMatch($name1, $name2) {
        return $this->calculateSingleNameSimilarity($name1, $name2) >= 85;
    }
    
    /**
     * Scrape match summary from detail page
     */
    public function scrapeSummary($detailUrl) {
        if (!$detailUrl) return null;
        
        $html = $this->fetchUrlContent($detailUrl);
        if (!$html) return null;
        
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        
        $summary = '';
        $contentDivs = $xpath->query("//div[contains(@class, 'entry')]//p");
        if ($contentDivs->length > 0) {
            $paragraphs = []; $count = 0;
            foreach ($contentDivs as $node) {
                $text = trim($node->textContent);
                if (!empty($text) && strlen($text) > 20 && $count < 3) {
                    $paragraphs[] = $text; $count++;
                }
            }
            if (!empty($paragraphs)) $summary = implode("\n\n", $paragraphs);
        }
        
        if (empty($summary)) {
            $articleContent = $xpath->query("//article//p");
            if ($articleContent->length > 0) {
                $paragraphs = []; $count = 0;
                foreach ($articleContent as $node) {
                    $text = trim($node->textContent);
                    if (!empty($text) && strlen($text) > 20 && $count < 3) {
                        $paragraphs[] = $text; $count++;
                    }
                }
                if (!empty($paragraphs)) $summary = implode("\n\n", $paragraphs);
            }
        }
        
        return $summary;
    }
    
    /**
     * Get match summary by searching and scraping
     */
    public function getMatchSummary($homeTeam, $awayTeam, $matchDate) {
        $detailUrl = $this->findMatchDetailUrl($homeTeam, $awayTeam, $matchDate);
        $summary = $detailUrl ? $this->scrapeSummary($detailUrl) : null;
        
        return [
            'success' => !empty($summary),
            'detail_url' => $detailUrl,
            'summary' => $summary,
            'error' => empty($summary) ? 'Summary not found' : null
        ];
    }

    /**
     * Find live URL for a match from kooora-sport.com matches page
     */
    public function findMatchLiveUrl($homeTeam, $awayTeam, $matchDate) {
        foreach ($this->sources as $source) {
            $candidate = $this->findMatchLiveCandidateFromSource($source, $homeTeam, $awayTeam, $matchDate);
            if (!empty($candidate['live_url'])) {
                return $candidate['live_url'];
            }
        }
        return null;
    }

    /**
     * Get live iframe from live page
     */
    public function getLiveIframe($liveUrl) {
        if (!$liveUrl) return null;
        
        $html = $this->fetchUrlContent($liveUrl);
        if (!$html) return null;
        
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        
        $directStreamUrl = $this->extractStreamUrlFromHtml($html, $liveUrl);
        if ($directStreamUrl && preg_match('~\.m3u8($|[?#])~i', $directStreamUrl)) {
            $iframe = $this->buildHlsPlayerIframeHtml($directStreamUrl, [
                'title' => 'Live Stream',
                'referer' => $liveUrl,
            ]);
            if ($iframe) {
                return $iframe;
            }
        }

        $targetIframe = $this->findTargetIframeInXpath($xpath);
        if ($targetIframe) return $targetIframe;

        $liveAnchors = $this->extractLiveAnchorCandidatesFromXpath($xpath, $liveUrl);
        foreach ($liveAnchors as $candidateUrl) {
            $resolvedTarget = $this->resolvePlayableChannelTarget($candidateUrl, $liveUrl);
            if (!$resolvedTarget) {
                continue;
            }

            if (($resolvedTarget['type'] ?? '') === 'hls') {
                $iframe = $this->buildHlsPlayerIframeHtml($resolvedTarget['url'], [
                    'title' => 'Live Stream',
                    'referer' => $resolvedTarget['referer'] ?? null,
                ]);
                if ($iframe) {
                    return $iframe;
                }
            }

            if (!empty($resolvedTarget['url'])) {
                return $this->buildIframeHtmlFromUrl($resolvedTarget['url']);
            }
        }

        $dynamicCandidates = $this->extractDynamicLiveCandidates($liveUrl);
        foreach ($dynamicCandidates as $candidateUrl) {
            $resolvedTarget = $this->resolvePlayableChannelTarget($candidateUrl, $liveUrl);
            if (!$resolvedTarget) {
                continue;
            }

            if (($resolvedTarget['type'] ?? '') === 'hls') {
                $iframe = $this->buildHlsPlayerIframeHtml($resolvedTarget['url'], [
                    'title' => 'Live Stream',
                    'referer' => $resolvedTarget['referer'] ?? null,
                ]);
                if ($iframe) {
                    return $iframe;
                }
            }

            if (!empty($resolvedTarget['url'])) {
                return $this->buildIframeHtmlFromUrl($resolvedTarget['url']);
            }
        }

        $allIframes = $xpath->query("//iframe");
        foreach ($allIframes as $parentIframe) {
            $parentSrc = $parentIframe->getAttribute('src');
            if ($this->shouldRejectSourceHostedFrameUrl($parentSrc)) {
                continue;
            }

            $parentHtml = $this->fetchUrlContent($parentSrc, $liveUrl);
            
            if ($parentHtml) {
                $parentDom = new DOMDocument();
                @$parentDom->loadHTML('<?xml encoding="utf-8" ?>' . $parentHtml);
                $parentXpath = new DOMXPath($parentDom);
                $nestedTarget = $this->findTargetIframeInXpath($parentXpath);
                if ($nestedTarget) return $nestedTarget;
            }
        }
        
        foreach ($allIframes as $iframe) {
            $builtIframe = $this->buildIframeHtml($iframe);
            if ($builtIframe) {
                return $builtIframe;
            }
        }

        return null;
    }

    private function findTargetIframeInXpath($xpath) {
        $allIframes = $xpath->query("//iframe");
        foreach ($allIframes as $item) {
            $allow = $item->getAttribute('allow');
            if (strpos($allow, 'encrypted-media') !== false) {
                $iframe = $this->buildIframeHtml($item);
                if ($iframe) {
                    return $iframe;
                }
            }
        }

        foreach ($allIframes as $item) {
            $src = $item->getAttribute('src');
            if (strpos($src, 'yalllashoot') !== false || strpos($src, 'pl.yalllashoot') !== false || strpos($src, 'panda-live') !== false) {
                $iframe = $this->buildIframeHtml($item);
                if ($iframe) {
                    return $iframe;
                }
            }
        }
        
        $streamingDomains = ['embedme.top', 'streams.center', 'stream', 'player', 'embed', 'albaplayer'];
        foreach ($allIframes as $item) {
            $src = $item->getAttribute('src');
            foreach ($streamingDomains as $domain) {
                if (strpos($src, $domain) !== false) {
                    $iframe = $this->buildIframeHtml($item);
                    if ($iframe) {
                        return $iframe;
                    }
                }
            }
        }
        return null;
    }

    private function fetchUrlContent($url, $referer = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
        else curl_setopt($ch, CURLOPT_REFERER, $this->baseUrl . "/");
        
        $html = curl_exec($ch);
        curl_close($ch);
        return $html;
    }

    private function buildIframeHtml($iframe) {
        $src = $iframe->getAttribute('src');
        $name = $iframe->getAttribute('name') ?: 'search_iframe';
        $height = $iframe->getAttribute('height') ?: '500px';
        $width = $iframe->getAttribute('width') ?: '100%';
        $allow = $iframe->getAttribute('allow');
        $allowValue = $allow ?: 'autoplay; encrypted-media; picture-in-picture; fullscreen';

        if (!$src || !preg_match('#^https?://#i', $src)) {
            return null;
        }

        if (!$this->isLocalPlayerWrapperUrl($src)) {
            $resolvedTarget = $this->resolvePlayableChannelTarget($src, $src);
            if ($resolvedTarget) {
                if (($resolvedTarget['type'] ?? '') === 'hls') {
                    $hlsIframe = $this->buildHlsPlayerIframeHtml($resolvedTarget['url'], [
                        'title' => 'Live Stream',
                        'referer' => $resolvedTarget['referer'] ?? $src,
                    ]);
                    if ($hlsIframe) {
                        return $hlsIframe;
                    }
                }

                if (!empty($resolvedTarget['url'])) {
                    return $this->buildIframeHtmlFromUrl($resolvedTarget['url']);
                }
            }
        }

        if ($this->shouldRejectSourceHostedFrameUrl($src)) {
            return null;
        }

        return $this->buildLocalFrameWrapperIframeHtml($src, [
            'title' => 'Live Stream',
            'allow' => $allowValue,
            'height' => $height,
            'width' => $width,
            'name' => $name,
        ]);
    }

    public function getMatchLive($homeTeam, $awayTeam, $matchDate, $matchId = null) {
        $previousMatchId = $this->currentLiveMatchId;
        $this->currentLiveMatchId = (int)$matchId > 0 ? (int)$matchId : null;
        $attemptedSources = [];

        foreach ($this->sources as $source) {
            $candidate = $this->findMatchLiveCandidateFromSource($source, $homeTeam, $awayTeam, $matchDate);
            $attemptedSources[] = [
                'source' => $candidate['source'] ?? ($source['name'] ?? $source['base_url'] ?? 'custom-source'),
                'status' => $candidate['success'] ? 'success' : 'failed',
                'reason' => $candidate['error'] ?? null
            ];

            if (!empty($candidate['success'])) {
                $candidate['attempted_sources'] = $attemptedSources;
                $this->currentLiveMatchId = $previousMatchId;
                return $candidate;
            }
        }

        // Fallback to 365Scores after all enabled live sources fail.
        if ($this->threeSixFiveScraper && $this->isThreeSixFiveFallbackEnabled()) {
            $threeSixFiveUrl = $this->threeSixFiveScraper->findMatchLiveUrl(
                $homeTeam,
                $awayTeam,
                $matchDate
            );
            if ($threeSixFiveUrl) {
                $threeSixFiveIframe = $this->threeSixFiveScraper->getLiveIframe($threeSixFiveUrl);
                  if ($threeSixFiveIframe) {
                      $threeSixFiveStreamUrl = $this->extractIframeSrcFromHtml($threeSixFiveIframe) ?: $threeSixFiveUrl;
                      $this->currentLiveMatchId = $previousMatchId;
                      return [
                          'success'    => true,
                          'live_url'    => $threeSixFiveStreamUrl,
                        'live_iframe' => $threeSixFiveIframe,
                        'source'      => '365scores.com',
                        'error'       => null,
                        'attempted_sources' => $attemptedSources
                    ];
                }
            }
        } elseif ($this->threeSixFiveScraper) {
            $attemptedSources[] = [
                'source' => '365scores.com',
                'status' => 'disabled',
                'reason' => '365Scores fallback disabled'
            ];
          }
          
          $this->currentLiveMatchId = $previousMatchId;
          return [
              'success'     => false,
              'live_url'     => null,
            'live_iframe'  => null,
            'source'       => null,
            'error'        => 'Live iframe not found',
            'attempted_sources' => $attemptedSources
          ];
    }

    private function extractIframeSrcFromHtml($iframeHtml) {
        if (!is_string($iframeHtml) || stripos($iframeHtml, '<iframe') === false) {
            return null;
        }

        if (!preg_match('/<iframe\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/i', $iframeHtml, $matches)) {
            return null;
        }

        $src = html_entity_decode(trim((string)($matches[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($src === '' || !preg_match('#^https?://#i', $src)) {
            return null;
        }

        return $src;
    }

    private function findMatchLiveCandidateFromSource($source, $homeTeam, $awayTeam, $matchDate) {
        $sourceName = $source['name'] ?? $source['base_url'] ?? 'custom-source';
        $this->baseUrl = $source['base_url'] ?? $this->baseUrl;

        $koraApiProfile = $this->getKoraApiSourceProfile($source);
        if ($koraApiProfile !== null) {
            $matchedGame = $this->findYallaShootMatchCandidate($homeTeam, $awayTeam, $matchDate);

            if (!$matchedGame) {
                return [
                    'success' => false,
                    'live_url' => null,
                    'live_iframe' => null,
                    'source' => $sourceName,
                    'error' => 'لم تتم مطابقة المباراة داخل هذا المصدر.'
                ];
            }

            $matchId = trim((string)($matchedGame['id'] ?? ''));
            if ($matchId === '') {
                return [
                    'success' => false,
                    'live_url' => null,
                    'live_iframe' => null,
                    'source' => $sourceName,
                    'error' => 'تمت مطابقة المباراة لكن معرف المباراة غير متوفر في المصدر.'
                ];
            }

            $hasChannels = (string)($matchedGame['has_channels'] ?? '0') === '1';
            $isActive = (int)($matchedGame['active'] ?? 0) === 1;
            $liveUrl = $this->buildKoraApiLiveUrl($matchId, $koraApiProfile);

            if (!$hasChannels || !$isActive) {
                $liveIframe = $this->buildKoraApiIframeFromLiveUrl($liveUrl);
                if (!$liveIframe) {
                    $liveIframe = $this->buildKoraApiIframeFromMatchId($matchId, $koraApiProfile);
                }
                if ($liveIframe) {
                    return [
                        'success' => true,
                        'live_url' => $liveUrl,
                        'live_iframe' => $liveIframe,
                        'source' => $sourceName,
                        'error' => null
                    ];
                }
            }

            if (!$hasChannels || !$isActive) {
                return [
                    'success' => false,
                    'live_url' => $liveUrl,
                    'live_iframe' => null,
                    'source' => $sourceName,
                    'error' => 'تمت مطابقة المباراة داخل هذا المصدر لكن روابط البث غير متاحة بعد.'
                ];
            }

            $liveIframe = $this->buildKoraApiIframeFromLiveUrl($liveUrl);
            if (!$liveIframe) {
                $liveIframe = $this->buildKoraApiIframeFromMatchId($matchId, $koraApiProfile);
            }
            if ($liveIframe) {
                return [
                    'success' => true,
                    'live_url' => $liveUrl,
                    'live_iframe' => $liveIframe,
                    'source' => $sourceName,
                    'error' => null
                ];
            }

            return [
                'success' => false,
                'live_url' => $liveUrl,
                'live_iframe' => null,
                'source' => $sourceName,
                'error' => 'تمت مطابقة المباراة داخل هذا المصدر لكن تعذر بناء الـ iframe من بيانات القنوات.'
            ];
        }

        $this->baseUrl = $source['base_url'];
        $todayUrl = $this->baseUrl . ($source['matches_path'] ?? '/matches-today/');

        $html = $this->fetchUrlContent($todayUrl);
        if (!$html) {
            return [
                'success' => false,
                'live_url' => null,
                'live_iframe' => null,
                'source' => $sourceName,
                'error' => 'تعذر تحميل صفحة المباريات من المصدر.'
            ];
        }

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        $matchDivs = $xpath->query($source['container_selector']);

        $rejectedSlugUrl = null;

        foreach ($matchDivs as $matchDiv) {
            $teamNames = $xpath->query($source['teams_selector'], $matchDiv);
            if ($teamNames->length < 2) {
                continue;
            }

            $team1 = trim($teamNames->item(0)->textContent);
            $team2 = trim($teamNames->item(1)->textContent);

            if (!$this->isGameMatch($homeTeam, $awayTeam, $team1, $team2)) {
                continue;
            }

            $liveUrl = $this->extractLiveUrlFromMatchDiv($xpath, $matchDiv, $source);
            if (!$liveUrl) {
                return [
                    'success' => false,
                    'live_url' => null,
                    'live_iframe' => null,
                    'source' => $sourceName,
                    'error' => 'تمت مطابقة المباراة لكن لم يتم العثور على رابط البث داخل المصدر.'
                ];
            }

            if (!$this->doesMatchUrlPairMatchTeams($liveUrl, $homeTeam, $awayTeam)) {
                $rejectedSlugUrl = $liveUrl;
                continue;
            }

            $liveIframe = $this->getLiveIframe($liveUrl);
            if ($liveIframe) {
                return [
                    'success' => true,
                    'live_url' => $liveUrl,
                    'live_iframe' => $liveIframe,
                    'source' => $sourceName,
                    'error' => null
                ];
            }

            return [
                'success' => false,
                'live_url' => $liveUrl,
                'live_iframe' => null,
                'source' => $sourceName,
                'error' => 'تم العثور على رابط البث لكن تعذر استخراج الـ iframe من هذا المصدر.'
            ];
        }

        if ($rejectedSlugUrl) {
            return [
                'success' => false,
                'live_url' => $rejectedSlugUrl,
                'live_iframe' => null,
                'source' => $sourceName,
                'error' => 'Rejected live URL because its slug does not match this match.'
            ];
        }

        return [
            'success' => false,
            'live_url' => null,
            'live_iframe' => null,
            'source' => $sourceName,
            'error' => 'لم تتم مطابقة المباراة داخل هذا المصدر.'
        ];
    }

    private function extractLiveUrlFromMatchDiv($xpath, $matchDiv, $source) {
        $selectorCandidates = array_filter([
            trim((string)($source['live_link_selector'] ?? '')),
            trim((string)($source['link_selector'] ?? '')),
            './/a[@href]',
            './a[@href]',
        ]);

        foreach ($selectorCandidates as $selector) {
            $link = $xpath->query($selector, $matchDiv)->item(0);
            if (!$link) {
                continue;
            }

            $href = trim((string)$link->getAttribute('href'));
            if ($href === '' || stripos($href, 'javascript:') === 0 || $href === '#') {
                continue;
            }

            return $this->makeAbsoluteUrl($href);
        }

        return null;
    }

    private function doesMatchUrlPairMatchTeams($url, $homeTeam, $awayTeam) {
        $url = trim((string)$url);
        if ($url === '') {
            return true;
        }

        $path = rawurldecode((string)(parse_url($url, PHP_URL_PATH) ?: ''));
        $slug = trim((string)basename($path));
        if ($slug === '' || stripos($slug, '-vs-') === false) {
            return true;
        }

        $slug = preg_replace('/-\d+$/', '', $slug);
        $parts = preg_split('/-vs-/i', $slug, 2);
        if (!is_array($parts) || count($parts) !== 2) {
            return true;
        }

        $urlHome = trim((string)preg_replace('/[-_]+/u', ' ', $parts[0]));
        $urlAway = trim((string)preg_replace('/[-_]+/u', ' ', $parts[1]));
        if ($urlHome === '' || $urlAway === '') {
            return true;
        }

        return (
            $this->strongTeamCandidateSetsMatch($homeTeam, $urlHome)
            && $this->strongTeamCandidateSetsMatch($awayTeam, $urlAway)
        ) || (
            $this->strongTeamCandidateSetsMatch($homeTeam, $urlAway)
            && $this->strongTeamCandidateSetsMatch($awayTeam, $urlHome)
        );
    }

    private function makeAbsoluteUrl($href) {
        if (strpos($href, 'http://') === 0 || strpos($href, 'https://') === 0) {
            return $href;
        }

        return $this->baseUrl . (strpos($href, '/') === 0 ? '' : '/') . $href;
    }

    public function scrapeLineup($detailUrl) {
        if (!$detailUrl) return null;
        $html = $this->fetchUrlContent($detailUrl);
        if (!$html) return null;
        
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        
        $lineup = [
            'home' => ['starting' => [], 'substitutes' => []],
            'away' => ['starting' => [], 'substitutes' => []]
        ];
        
        $extractPlayers = function($container) use ($xpath) {
            $players = [];
            $playerNodes = $xpath->query(".//div[contains(@class, 'aspi-player-lnp')]", $container);
            foreach ($playerNodes as $node) {
                $number = ''; $name = ''; $position = '';
                $numNode = $xpath->query(".//span", $node)->item(0);
                if ($numNode) $number = trim($numNode->textContent);
                $nameNode = $xpath->query(".//div[contains(@class, 'player_area')]//b", $node)->item(0);
                if ($nameNode) $name = trim($nameNode->textContent);
                $posNode = $xpath->query(".//div[contains(@class, 'player_area')]", $node)->item(0);
                if ($posNode) {
                    $fullText = $posNode->textContent;
                    $position = trim(str_replace($name, '', $fullText));
                }
                if ($name) $players[] = ['number' => $number, 'name' => $name, 'position' => $position];
            }
            return $players;
        };
        
        $homeContainer = $xpath->query("//div[contains(@class, 'f_sq')]")->item(0);
        if ($homeContainer) {
            $basic = $xpath->query(".//div[contains(@class, 'aspi_lineup') and contains(@class, 'basic')]", $homeContainer)->item(0);
            if ($basic) $lineup['home']['starting'] = $extractPlayers($basic);
            $subs = $xpath->query(".//div[contains(@class, 'aspi_lineup') and not(contains(@class, 'basic'))]", $homeContainer)->item(0);
            if ($subs) $lineup['home']['substitutes'] = $extractPlayers($subs);
        }
        
        $awayContainer = $xpath->query("//div[contains(@class, 's_sq')]")->item(0);
        if ($awayContainer) {
            $basic = $xpath->query(".//div[contains(@class, 'aspi_lineup') and contains(@class, 'basic')]", $awayContainer)->item(0);
            if ($basic) $lineup['away']['starting'] = $extractPlayers($basic);
            $subs = $xpath->query(".//div[contains(@class, 'aspi_lineup') and not(contains(@class, 'basic'))]", $awayContainer)->item(0);
            if ($subs) $lineup['away']['substitutes'] = $extractPlayers($subs);
        }
        
        return $lineup;
    }
    
    public function getMatchLineup($homeTeam, $awayTeam, $matchDate) {
         $detailUrl = $this->findMatchDetailUrl($homeTeam, $awayTeam, $matchDate);
         if (!$detailUrl) return ['success' => false, 'error' => 'Match not found'];
         $lineup = $this->scrapeLineup($detailUrl);
         return [
             'success' => !empty($lineup['home']['starting']) || !empty($lineup['away']['starting']),
             'lineup'  => $lineup
         ];
    }
}
