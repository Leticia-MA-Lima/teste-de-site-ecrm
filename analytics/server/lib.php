<?php
declare(strict_types=1);

function analyticsConfig(): array {
    $path = getenv('ECRM_ANALYTICS_CONFIG') ?: '/etc/ecrm360-analytics/config.php';
    if (!is_file($path)) throw new RuntimeException('Configuração indisponível');
    $c = require $path;
    if (!is_array($c) || !ctype_digit((string)($c['property_id'] ?? ''))) throw new RuntimeException('Propriedade inválida');
    return $c;
}
function readCache(string $path): ?array {
    if (!is_file($path)) return null;
    try { $d = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); return is_array($d) ? $d : null; }
    catch (Throwable $e) { return null; }
}
function atomicCache(string $path, array $data, int $mode = 0640): void {
    $temp = tempnam(dirname($path), '.analytics-');
    if ($temp === false) throw new RuntimeException('Cache indisponível');
    try {
        chmod($temp, $mode);
        if (file_put_contents($temp, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !rename($temp, $path)) throw new RuntimeException('Falha ao salvar cache');
    } finally { if (is_file($temp)) unlink($temp); }
}
function googleHttp(string $url, array $headers, string $body): array {
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
        $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw !== false && $status >= 200 && $status < 300) {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($value) || isset($value['error'])) throw new RuntimeException('Resposta externa inválida');
            return $value;
        }
        if ($attempt === 2 || ($status >= 400 && $status < 500 && $status !== 429)) throw new RuntimeException('Consulta externa falhou', (int)$status);
        usleep((2 ** $attempt) * 300000 + random_int(0, 200000));
    }
    throw new RuntimeException('Consulta externa falhou');
}
function googleToken(array $c): string {
    $cached = readCache($c['token_cache']);
    if (is_string($cached['access_token'] ?? null) && ($cached['expires_at'] ?? 0) > time() + 120) return $cached['access_token'];
    $key = readCache($c['credential']);
    if (!$key || !isset($key['client_email'], $key['private_key'])) throw new RuntimeException('Credencial indisponível');
    $b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $input = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode(['iss' => $key['client_email'], 'scope' => 'https://www.googleapis.com/auth/analytics.readonly', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign($input, $sig, $key['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Autenticação indisponível');
    $r = googleHttp('https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $input . '.' . $b64($sig)]));
    if (!is_string($r['access_token'] ?? null) || !isset($r['expires_in'])) throw new RuntimeException('Autenticação inválida');
    atomicCache($c['token_cache'], ['access_token' => $r['access_token'], 'expires_at' => $now + (int)$r['expires_in']], 0600);
    return $r['access_token'];
}
function reportQuery(array $dims, array $metrics, array $extra = []): array {
    $q = ['metrics' => array_map(fn($n) => ['name' => $n], $metrics), 'limit' => 1000, 'returnPropertyQuota' => true];
    if ($dims) $q['dimensions'] = array_map(fn($n) => ['name' => $n], $dims);
    return array_replace($q, $extra);
}
function reportRows(array $r): array {
    return array_map(fn($row) => ['d' => array_column($row['dimensionValues'] ?? [], 'value'), 'm' => array_column($row['metricValues'] ?? [], 'value')], $r['rows'] ?? []);
}
function collectRealtime(callable $query, array $c): array {
    $total = reportRows($query('runRealtimeReport', reportQuery([], ['activeUsers'])));
    $five = reportRows($query('runRealtimeReport', reportQuery([], ['activeUsers'], ['minuteRanges' => [['startMinutesAgo' => 4, 'endMinutesAgo' => 0]]])));
    $events = reportRows($query('runRealtimeReport', reportQuery(['eventName'], ['eventCount'])));
    $counts = ['whatsapp_click' => 0, 'generate_lead' => 0];
    foreach ($events as $r) if (array_key_exists($r['d'][0], $counts)) $counts[$r['d'][0]] = (int)$r['m'][0];
    $pages = reportRows($query('runRealtimeReport', reportQuery(['unifiedScreenName'], ['screenPageViews'])));
    $cities = reportRows($query('runRealtimeReport', reportQuery(['countryId', 'cityId', 'city'], ['activeUsers'], ['dimensionFilter' => ['filter' => ['fieldName' => 'countryId', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'BR']]]])));
    $minutes = array_fill(0, 30, 0);
    foreach (reportRows($query('runRealtimeReport', reportQuery(['minutesAgo'], ['eventCount']))) as $r) {
        $m = (int)$r['d'][0]; if ($m >= 0 && $m < 30) $minutes[$m] = (int)$r['m'][0];
    }
    return ['active_30m' => (int)($total[0]['m'][0] ?? 0), 'active_5m' => (int)($five[0]['m'][0] ?? 0), 'events_30m' => $counts,
        'pages_30m' => array_map(fn($r) => ['title' => $r['d'][0], 'lp' => $c['page_titles'][$r['d'][0]] ?? 'Outras páginas', 'views' => (int)$r['m'][0]], $pages),
        'cities_br' => array_map(fn($r) => ['city_id' => $r['d'][1], 'city' => $r['d'][2], 'active_users' => (int)$r['m'][0]], $cities),
        'activity_30m' => array_map(fn($m) => ['minutes_ago' => $m, 'events' => $minutes[$m]], range(29, 0))];
}
function collectDaily(callable $query, array $c, int $now): array {
    $base = ['dateRanges' => [['startDate' => 'today', 'endDate' => 'today']]];
    $pages = reportRows($query('runReport', reportQuery(['hostName'], ['screenPageViews'], $base)));
    $dims = ['sessionSource', 'sessionMedium', 'sessionCampaignName']; $metrics = ['sessions', 'userEngagementDuration'];
    $compat = $query('checkCompatibility', ['dimensions' => array_map(fn($n) => ['name' => $n], $dims), 'metrics' => array_map(fn($n) => ['name' => $n], $metrics)]);
    foreach (array_merge($compat['dimensionCompatibilities'] ?? [], $compat['metricCompatibilities'] ?? []) as $entry) if (($entry['compatibility'] ?? '') !== 'COMPATIBLE') throw new RuntimeException('Consulta diária incompatível');
    $campaigns = reportRows($query('runReport', reportQuery($dims, $metrics, $base)));
    return ['status' => 'ok', 'generated_at' => gmdate('c', $now), 'date' => (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($c['timezone']))->format('Y-m-d'),
        'pages' => array_map(fn($r) => ['lp' => $c['hosts'][$r['d'][0]] ?? 'Outras páginas', 'views' => (int)$r['m'][0]], $pages),
        'campaigns' => array_map(fn($r) => ['source' => $r['d'][0], 'medium' => $r['d'][1], 'campaign' => $r['d'][2], 'sessions' => (int)$r['m'][0], 'engagement_seconds' => (float)$r['m'][1]], $campaigns)];
}
function dashboardCache(?array $data, int $now, int $stale): array {
    if (!$data || !isset($data['generated_at']) || ($stamp = strtotime($data['generated_at'])) === false) return ['status' => 'unavailable', 'generated_at' => null, 'active_30m' => null, 'active_5m' => null];
    $data['status'] = $now - $stamp > $stale ? 'stale' : 'ok';
    if (!empty($data['daily']['generated_at']) && $now - strtotime($data['daily']['generated_at']) > 1800) $data['daily']['status'] = 'stale';
    return $data;
}
