<?php
http_response_code(404); // Sending 404 to trick scanners that the page doesn't exist at all.
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
// Combine host and uri to show the exact link typed
$full_url = $host . ($uri === '/' ? '' : $uri);
$display_url = htmlspecialchars($full_url, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>This site can’t be reached</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            font-size: 14px;
            color: #202124;
            background-color: #ffffff;
            margin: 0;
            display: flex;
            justify-content: center;
            padding-top: 12vh;
        }
        .container {
            display: flex;
            max-width: 650px;
            padding: 20px;
            align-items: flex-start;
            gap: 20px;
        }
        .icon {
            flex-shrink: 0;
            margin-right: 35px;
            width: 55px;
            height: 55px;
        }
        .icon svg {
            width: 170%;
            height: 170%;
            transform: translate(-10%, -10%);
        }
        /* Style fixes for light theme */
        .icon svg .color1, .icon svg path[fill^="rgb(24,"], .icon svg path[fill^="rgb(25,"], .icon svg path[fill^="rgb(26,"], .icon svg path[fill^="rgb(28,"] {
            fill: #70757a !important;
        }
        .icon svg path[stroke^="rgb(24,"], .icon svg path[stroke^="rgb(26,"] {
            stroke: #70757a !important;
            fill: none !important;
        }
        .icon svg .color2, .icon svg path[fill="rgb(255,255,255)"] {
            fill: #ffffff !important;
        }
        .icon svg path[fill-opacity="0"] {
            fill: none !important;
        }

        .content {
            flex-grow: 1;
        }
        h1 {
            font-size: 1.6em;
            font-weight: 500;
            margin-top: 0;
            margin-bottom: 20px;
            color: #202124;
        }
        p {
            margin-top: 0;
            margin-bottom: 20px;
            color: #202124;
            line-height: 1.5;
        }
        .suggestion-title {
            font-weight: 600;
            margin-bottom: 12px;
            color: #202124;
        }
        .try-list {
            list-style: none;
            padding-left: 0;
            margin-top: 0;
            margin-bottom: 30px;
        }
        .try-list li {
            position: relative;
            padding-left: 20px;
            margin-bottom: 8px;
            color: #202124;
        }
        .try-list li::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #1a73e8;
            font-weight: bold;
            font-size: 14px;
        }
        .error-code {
            font-size: 0.9em;
            color: #5f6368;
            margin-bottom: 30px;
            font-weight: 500;
        }
        .details h2 {
            font-size: 1em;
            font-weight: bold;
            margin-bottom: 12px;
            color: #202124;
        }
        .details p {
            color: #202124;
            margin-bottom: 0;
        }
        a {
            color: #1a73e8;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        .search-box {
            display: flex;
            align-items: center;
            border: 1px solid #dfe1e5;
            border-radius: 24px;
            padding: 8px 16px;
            margin-top: 35px;
            max-width: 100%;
            background: #fff;
            box-shadow: none;
            transition: box-shadow 0.2s;
        }
        .search-box:hover {
            box-shadow: 0 1px 6px rgba(32,33,36,.28);
            border-color: rgba(223,225,229,0);
        }
        .g-logo {
            width: 20px;
            height: 20px;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .search-input {
            flex-grow: 1;
            border: none;
            outline: none;
            font-size: 16px;
            color: #202124;
            background: transparent;
            font-family: inherit;
        }
        .search-icon {
            width: 20px;
            height: 20px;
            fill: #9aa0a6;
            flex-shrink: 0;
        }

    </style>
</head>
<body>
<div class="container">
    <div class="icon">
        <?php include __DIR__ . '/alien_error.svg'; ?>
    </div>
    <div class="content">
        <h1>This site can’t be reached</h1>
        <p>Check if there is a typo in <strong><?php echo $display_url; ?></strong>.</p>
        <p class="suggestion-title">Try:</p>
        <ul class="try-list">
            <li><a href="#">Running Windows Network Diagnostics</a></li>
            <li>Changing DNS over HTTPS settings</li>
        </ul>
        <div class="error-code">DNS_PROBE_FINISHED_NXDOMAIN</div>
        
        <div class="details">
            <h2>Check your DNS over HTTPS settings</h2>
            <p>Go to Opera &gt; Preferences... &gt; System &gt; Use DNS-over-HTTPS instead of the system's DNS settings and check your DNS-over-HTTPS provider.</p>
        </div>

        <div class="search-box">
            <svg class="g-logo" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
            <input type="text" class="search-input" value="<?php echo $display_url; ?>" readonly>
            <svg class="search-icon" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
        </div>
    </div>
</div>
</body>
</html>
