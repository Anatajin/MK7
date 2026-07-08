<?php
require_once 'init.php';
require_once '../config.php';
require_once '../includes/AdminMonitor.php';
$format = strtolower(trim((string)($_GET['format'] ?? 'json')));
$windowMinutes = isset($_GET['window']) ? (int)$_GET['window'] : 60;
$monitor = new AdminMonitor($pdo);
$data = $monitor->getSnapshot($windowMinutes);
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sport-monitor-' . gmdate('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['section', 'key', 'value', 'extra']);
    $system = $data['system'] ?? []; $api = $data['api'] ?? []; $cache = $data['cache'] ?? []; $security = $data['security'] ?? [];
    foreach ([['summary','generated_at',$data['generated_at'] ?? '',''],['summary','window_minutes',$data['window_minutes'] ?? '',''],['summary','hostname',$system['hostname'] ?? '',''],['summary','load_1m',$system['load_average']['one'] ?? '',''],['summary','memory_used_percent',$system['memory']['used_percent'] ?? '',''],['summary','disk_used_percent',$system['disk']['used_percent'] ?? '',''],['summary','total_requests',$api['total_requests'] ?? '',''],['summary','rate_limited_429',$api['rate_limited'] ?? '',''],['summary','server_errors_5xx',$api['server_errors'] ?? '',''],['summary','avg_request_time_ms',$api['avg_request_time_ms'] ?? '',''],['summary','cache_hit_ratio',$cache['hit_ratio'] ?? '',''],['summary','attack_signals_total',$security['total_signals'] ?? '',''],['summary','attack_signals_probes',$security['probes'] ?? '',''],['summary','attack_signals_blocked',$security['blocked'] ?? '',''],['summary','attack_signals_unique_ips',$security['unique_ips'] ?? '','']] as $row) fputcsv($out, $row);
    foreach (($data['services'] ?? []) as $service) fputcsv($out, ['service', $service['label'] ?? '', $service['status'] ?? '', $service['service'] ?? '']);
    foreach (($api['series'] ?? []) as $point) fputcsv($out, ['traffic', $point['label'] ?? '', $point['requests'] ?? 0, '429=' . ($point['rate_limited'] ?? 0)]);
    foreach (($security['series'] ?? []) as $point) fputcsv($out, ['security', $point['label'] ?? '', $point['total'] ?? 0, 'probes=' . ($point['probes'] ?? 0) . ';blocked=' . ($point['blocked'] ?? 0) . ';429=' . ($point['rate_limited'] ?? 0)]);
    foreach (($data['top_keys'] ?? []) as $key) fputcsv($out, ['top_key', $key['name'] ?? '', $key['requests_24h'] ?? 0, $key['masked_key'] ?? '']);
    fclose($out);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="sport-monitor-' . gmdate('Ymd-His') . '.json"');
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
