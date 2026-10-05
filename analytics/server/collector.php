<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
try {
    $c = analyticsConfig();
    $lock = fopen($c['cache'] . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
    $old = readCache($c['cache']); $now = time();
    $token = googleToken($c);
    $query = function (string $method, array $q) use ($c, $token): array {
        if (!in_array($method, ['runRealtimeReport', 'runReport', 'checkCompatibility'], true)) throw new RuntimeException('Método inválido');
        $all = []; $offset = 0;
        do {
            if ($method === 'runReport') $q['offset'] = $offset;
            $r = googleHttp('https://analyticsdata.googleapis.com/v1beta/properties/' . $c['property_id'] . ':' . $method, ['Content-Type: application/json', 'Authorization: Bearer ' . $token], json_encode($q, JSON_THROW_ON_ERROR));
            if ($method !== 'checkCompatibility' && array_column($r['metricHeaders'] ?? [], 'name') !== array_column($q['metrics'], 'name')) throw new RuntimeException('Contrato externo inválido');
            if ($method === 'checkCompatibility' && count($r['dimensionCompatibilities'] ?? []) + count($r['metricCompatibilities'] ?? []) !== count($q['dimensions']) + count($q['metrics'])) throw new RuntimeException('Compatibilidade não confirmada');
            $all = array_merge($all, $r['rows'] ?? []); $offset = count($all);
            if ($method === 'runRealtimeReport' && ($r['rowCount'] ?? 0) > count($all)) throw new RuntimeException('Relatório em tempo real truncado');
        } while ($method === 'runReport' && $offset < ($r['rowCount'] ?? 0) && count($r['rows'] ?? []) > 0);
        if ($method !== 'checkCompatibility') $r['rows'] = $all;
        return $r;
    };
    $out = collectRealtime($query, $c) + ['status' => 'ok', 'source' => 'Google Analytics 4', 'generated_at' => gmdate('c', $now), 'timezone' => $c['timezone'], 'window_minutes' => 30];
    $out['daily'] = $old['daily'] ?? ['status' => 'pending', 'generated_at' => null];
    $today = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($c['timezone']))->format('Y-m-d');
    if (($out['daily']['date'] ?? '') !== $today || $now - (strtotime($out['daily']['generated_at'] ?? '') ?: 0) >= $c['daily_interval']) {
        try { $out['daily'] = collectDaily($query, $c, $now); }
        catch (Throwable $e) { $out['daily']['status'] = empty($out['daily']['generated_at']) ? 'unavailable' : 'stale'; error_log('analytics source=ga4_daily status=failed code=' . (int)$e->getCode()); }
    }
    atomicCache($c['cache'], $out);
    echo "Analytics: cache atualizado.\n";
} catch (Throwable $e) {
    error_log('analytics source=ga4 status=failed code=' . (int)$e->getCode()); exit(1);
} finally { if (isset($lock) && is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
