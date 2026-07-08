<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/AdminMonitor.php';

$ip = trim((string)($_GET['ip'] ?? ''));
$errorMessage = null;
$mapUrl = null;
$locationLabel = null;

if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
    $errorMessage = 'عنوان IP غير صالح.';
} else {
    try {
        $monitor = new AdminMonitor($pdo);
        $result = $monitor->lookupIpLocation($ip);

        if (!($result['success'] ?? false) || empty($result['data']['map_url'])) {
            $errorMessage = $result['message'] ?? 'تعذر تحديد موقع هذا الـ IP.';
        } else {
            $data = $result['data'];
            $mapUrl = (string)$data['map_url'];

            $parts = array_filter([
                trim((string)($data['city'] ?? '')),
                trim((string)($data['region'] ?? '')),
                trim((string)($data['country'] ?? '')),
            ]);
            $locationLabel = !empty($parts) ? implode('، ', $parts) : $ip;
            header('Location: ' . $mapUrl, true, 302);
            exit;
        }
    } catch (Throwable $e) {
        $errorMessage = 'حدث خطأ أثناء فتح الخريطة.';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>موقع الـ IP</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fbff;
            color: #0f172a;
            font-family: Outfit, Arial, sans-serif;
            padding: 24px;
        }
        .wrap {
            width: min(520px, 100%);
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 22px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
            padding: 28px;
            text-align: center;
        }
        h1 {
            margin: 0 0 10px;
            font-size: 1.35rem;
        }
        p {
            margin: 0;
            color: #64748b;
            line-height: 1.9;
        }
        .actions {
            margin-top: 22px;
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 18px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
        }
        .btn-primary {
            background: #2563eb;
            color: #fff;
        }
        .btn-secondary {
            background: #eef4ff;
            color: #2563eb;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>تعذر فتح الخريطة مباشرة</h1>
        <p>
            <?= htmlspecialchars($errorMessage ?? ('الموقع: ' . ($locationLabel ?: $ip)), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="actions">
            <?php if ($mapUrl): ?>
                <a class="btn btn-primary" href="<?= htmlspecialchars($mapUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">فتح الخريطة</a>
            <?php endif; ?>
            <a class="btn btn-secondary" href="monitor.php">العودة للمراقبة</a>
        </div>
    </div>
</body>
</html>
