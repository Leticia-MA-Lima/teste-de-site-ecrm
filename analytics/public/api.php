<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
// Toda a rota deve ser protegida pelo Apache; negar acesso sem sessão HTTP autenticada.
if (empty($_SERVER['REMOTE_USER'])) { http_response_code(403); echo '{"status":"forbidden"}'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
try {
    require '/opt/ecrm360-analytics/lib.php';
    $c = analyticsConfig(); $d = dashboardCache(readCache($c['cache']), time(), (int)$c['stale_seconds']);
    if ($d['status'] === 'unavailable') http_response_code(503);
    echo json_encode($d, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) { http_response_code(503); echo '{"status":"unavailable"}'; }
