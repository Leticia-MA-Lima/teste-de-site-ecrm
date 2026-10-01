<?php
declare(strict_types=1);

function configuration(): array {
    $path = getenv('ECRM_TRACKING_CONFIG') ?: '/etc/ecrm-tracking/config.php';
    if (!is_file($path)) throw new RuntimeException('Configuração ausente');
    $c = require $path;
    if (strlen($c['secret'] ?? '') < 32 || str_contains($c['secret'], 'SUBSTITUA')) throw new RuntimeException('Segredo inválido');
    return $c;
}

function database(array $c): PDO {
    $db = new PDO('sqlite:' . $c['database'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS visitors (id TEXT PRIMARY KEY, site TEXT NOT NULL, score INTEGER NOT NULL DEFAULT 0, crm_id TEXT, created INTEGER NOT NULL, updated INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS events (id TEXT PRIMARY KEY, visitor TEXT NOT NULL, kind TEXT NOT NULL, target TEXT NOT NULL, dedup TEXT UNIQUE NOT NULL, points INTEGER NOT NULL, created INTEGER NOT NULL)');
    $db->exec('CREATE INDEX IF NOT EXISTS events_visitor ON events(visitor, created)');
    $db->exec("CREATE TABLE IF NOT EXISTS outbox (visitor TEXT PRIMARY KEY, payload TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, next_try INTEGER NOT NULL DEFAULT 0, error TEXT, updated INTEGER NOT NULL)");
    $db->exec('CREATE TABLE IF NOT EXISTS rate_limits (key TEXT PRIMARY KEY, count INTEGER NOT NULL, expires INTEGER NOT NULL)');
    return $db;
}

function uuid(string $v): bool {
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $v) === 1;
}

function contact(array $input): array {
    $result = [];
    foreach (['nome' => 160, 'empresa' => 200, 'email' => 254, 'whatsapp' => 32, 'segmento' => 100, 'organizacao' => 2000] as $key => $max) {
        $v = $input[$key] ?? '';
        if (!is_string($v) || strlen($v) > $max) throw new InvalidArgumentException('Campo inválido: ' . $key);
        $result[$key] = trim($v);
    }
    if ($result['nome'] === '' || !filter_var($result['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Informe nome e e-mail válido.');
    $result['whatsapp'] = preg_replace('/\D/', '', $result['whatsapp']);
    if ($result['whatsapp'] !== '' && (strlen($result['whatsapp']) < 10 || strlen($result['whatsapp']) > 15)) throw new InvalidArgumentException('Telefone inválido.');
    return $result;
}

function collect(PDO $db, array $c, array $e): array {
    $visitor = $e['visitor'] ?? ''; $id = $e['id'] ?? ''; $session = $e['session'] ?? '';
    $site = $e['site'] ?? ''; $kind = $e['kind'] ?? ''; $target = $e['target'] ?? '';
    if (!is_string($visitor) || !uuid($visitor) || !is_string($id) || !uuid($id) || !is_string($session) || !uuid($session)) throw new InvalidArgumentException('Identificador inválido.');
    if (!is_string($site) || !isset($c['sites'][$site]) || !is_string($kind) || !isset($c['points'][$kind]) || !is_string($target)) throw new InvalidArgumentException('Evento inválido.');
    if ($kind === 'visit') $target = 'page'; // uma visita pontuada por sessão
    if ($kind === 'click' && !in_array($target, ['contact', 'schedule', 'whatsapp', 'navigation', 'cta'], true)) throw new InvalidArgumentException('Clique inválido.');
    if (in_array($kind, ['form_start', 'form_submit'], true) && !in_array($target, ['contact', 'whatsapp'], true)) throw new InvalidArgumentException('Formulário inválido.');
    if ($kind === 'field_filled' && !preg_match('/^(contact|whatsapp):(nome|empresa|email|whatsapp|segmento|organizacao)$/', $target)) throw new InvalidArgumentException('Campo inválido.');
    $lead = $kind === 'form_submit' ? contact(is_array($e['contact'] ?? null) ? $e['contact'] : []) : null;
    if (!empty($e['website'])) throw new InvalidArgumentException('Envio inválido.');
    // Pontos de formulário contam uma vez por visitante; visitas/cliques uma vez por sessão/alvo.
    $scope = in_array($kind, ['form_start', 'field_filled', 'form_submit'], true) ? 'lifetime' : $session;
    $dedup = hash('sha256', "$visitor|$site|$scope|$kind|$target");
    $now = time();
    $db->beginTransaction();
    try {
        $q = $db->prepare('INSERT OR IGNORE INTO visitors(id,site,created,updated) VALUES(?,?,?,?)'); $q->execute([$visitor, $site, $now, $now]);
        $q = $db->prepare('SELECT site,score FROM visitors WHERE id=?'); $q->execute([$visitor]); $v = $q->fetch(PDO::FETCH_ASSOC);
        if ($v['site'] !== $site) throw new InvalidArgumentException('Visitante pertence a outro site.');
        $q = $db->prepare('SELECT visitor FROM events WHERE id=?'); $q->execute([$id]); $owner = $q->fetchColumn();
        if ($owner !== false && $owner !== $visitor) throw new InvalidArgumentException('Evento pertence a outro visitante.');
        $q = $db->prepare('SELECT COUNT(*) FROM events WHERE visitor=? AND created>=?'); $q->execute([$visitor, $now - 86400]);
        if ((int)$q->fetchColumn() >= 100) throw new InvalidArgumentException('Limite diário atingido.');
        $q = $db->prepare('INSERT OR IGNORE INTO events(id,visitor,kind,target,dedup,points,created) VALUES(?,?,?,?,?,?,?)');
        $q->execute([$id, $visitor, $kind, $target, $dedup, (int)$c['points'][$kind], $now]);
        $inserted = $q->rowCount() > 0;
        if ($inserted) {
            $q = $db->prepare('UPDATE visitors SET score=score+?,updated=? WHERE id=?'); $q->execute([(int)$c['points'][$kind], $now, $visitor]);
            if ($lead === null) {
                $q = $db->prepare("UPDATE outbox SET state='pending',updated=? WHERE visitor=? AND state IN ('synced','dry_run')"); $q->execute([$now, $visitor]);
            }
            if ($lead !== null) {
                $payload = ['contact' => $lead, 'form' => $target];
                // Não sobrescrever um envio que já está sendo processado.
                $q = $db->prepare("INSERT INTO outbox(visitor,payload,updated) VALUES(?,?,?) ON CONFLICT(visitor) DO UPDATE SET payload=excluded.payload,state='pending',attempts=0,next_try=0,error=NULL,updated=excluded.updated WHERE outbox.state IN ('synced','dry_run')");
                $q->execute([$visitor, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $now]);
            }
        }
        $q = $db->prepare('SELECT score FROM visitors WHERE id=?'); $q->execute([$visitor]); $score = (int)$q->fetchColumn();
        $q = $db->prepare('SELECT state FROM outbox WHERE visitor=?'); $q->execute([$visitor]); $state = $q->fetchColumn();
        $db->commit();
        return ['accepted' => true, 'duplicate' => !$inserted, 'score' => $score, 'qualified' => $state !== false, 'crm_state' => $state ?: null];
    } catch (Throwable $ex) { if ($db->inTransaction()) $db->rollBack(); throw $ex; }
}

class CrmResponseUncertain extends RuntimeException {}

function crm(array $c, string $operation, array $params): array {
    $ch = curl_init($c['crm_url']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['operation' => $operation] + $params, JSON_THROW_ON_ERROR), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'corebos-authorization: ' . $c['crm_token']], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    // Timeout após envio pode significar criação concluída: não repetir automaticamente.
    if ($raw === false || $code >= 500) throw new CrmResponseUncertain('Resposta incerta da API');
    $body = json_decode($raw, true);
    if ($code === 200 && (!is_array($body) || (!empty($body['success']) && !is_array($body['result'] ?? null)))) throw new CrmResponseUncertain('Resposta inválida após a operação');
    if ($code !== 200 || !is_array($body) || empty($body['success'])) {
        $error = is_array($body) ? ($body['error'] ?? []) : [];
        $message = substr(str_replace($c['crm_token'], '[credencial]', (string)($error['message'] ?? 'Resposta inválida')), 0, 250);
        throw new RuntimeException('API recusou a operação (' . ($error['code'] ?? $code) . '): ' . $message);
    }
    return $body['result'];
}

function writeAndConfirm(array $c, string $operation, array $params, array $element, string $email): array {
    try { return crm($c, $operation, $params); }
    catch (CrmResponseUncertain $ex) {
        // Alguns CRMs gravam, mas falham ao serializar o registro completo.
        // Consulta projetada evita o campo problemático e confirma a mesma escrita.
        $fields = array_unique(array_merge(['id'], array_keys($element)));
        foreach ($fields as $field) if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) throw $ex;
        $records = crm($c, 'query', ['query' => 'select ' . implode(',', $fields) . " from Leads where email='$email';"]);
        if (count($records) !== 1) throw $ex;
        foreach ($element as $field => $value) {
            if (!array_key_exists($field, $records[0]) || (string)$records[0][$field] !== (string)$value) throw $ex;
        }
        if (empty($records[0]['id'])) throw $ex;
        return $records[0];
    }
}

function syncOne(PDO $db, array $c): bool {
    // Um único worker por banco, inclusive entre cron e execução manual.
    $lock = fopen($c['database'] . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return false;
    try {
        // Processamento interrompido exige conciliação para evitar criação duplicada.
        $q = $db->prepare("UPDATE outbox SET state='review',error='Worker interrompido; conciliar no CRM' WHERE state='processing' AND updated<?"); $q->execute([time() - 120]);
        $q = $db->prepare("SELECT o.*,v.site,v.score,v.crm_id FROM outbox o JOIN visitors v ON v.id=o.visitor WHERE o.state='pending' AND o.next_try<=? ORDER BY o.updated LIMIT 1"); $q->execute([time()]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $q = $db->prepare("UPDATE outbox SET state='processing',attempts=attempts+1,updated=? WHERE visitor=?"); $q->execute([time(), $row['visitor']]);
        $operation = 'query';
        try {
            $p = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR); $lead = $p['contact'];
            if ($c['dry_run']) { $state = 'dry_run'; $crmId = null; }
            else {
                // Escape da linguagem de consulta do CRM, não interpolação SQL local.
                $email = str_replace(['\\', "'"], ['\\\\', "\\'"], $lead['email']);
                $matches = crm($c, 'query', ['query' => "select id,description from Leads where email='$email';"]);
                if (count($matches) > 1) throw new RuntimeException('Mais de um lead com o mesmo e-mail; conciliar');
                $crmId = $matches[0]['id'] ?? null;
                if ($row['crm_id'] && !$crmId) throw new RuntimeException('Cadastro anterior não localizado pelo e-mail; conciliar identidade ou acesso');
                $name = preg_split('/\s+/', $lead['nome']); $last = array_pop($name);
                $marker = '[ECRM tracking ' . $row['visitor'] . ']';
                $block = $marker . "\nContato pelo site. Pontuação: {$row['score']}. Segmento: {$lead['segmento']}\n{$lead['organizacao']}\n[/ECRM tracking]";
                $previous = (string)($matches[0]['description'] ?? '');
                $previous = preg_replace('/' . preg_quote($marker, '/') . '.*?\[\/ECRM tracking\]/s', '', $previous);
                $element = ['lastname' => $last, 'firstname' => implode(' ', $name), 'email' => $lead['email'], 'cf_1396' => $c['sites'][$row['site']], 'cf_1397' => $p['form'] === 'whatsapp' ? 'Bolha WhatsApp' : 'Formulário de contato', 'description' => trim($previous) . (trim($previous) ? "\n\n" : '') . $block];
                if ($lead['empresa'] !== '') $element['company'] = $lead['empresa'];
                if ($lead['whatsapp'] !== '') $element['mobile'] = $lead['whatsapp'];
                if ($c['score_field']) $element[$c['score_field']] = $row['score'];
                if ($crmId) { $element['id'] = $crmId; $operation = 'revise'; }
                else { $element['assigned_user_id'] = $c['assigned_user_id']; $element['leadstatus'] = $c['leadstatus']; $element['rating'] = $c['rating']; $element['leadsource'] = $p['form'] === 'whatsapp' ? 'Formulário WhatsApp' : $c['leadsource']; $operation = 'create'; }
                $params = ['element' => json_encode($element, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
                if ($operation === 'create') $params['elementType'] = 'Leads';
                $result = writeAndConfirm($c, $operation, $params, $element, $email);
                $crmId = $result['id'] ?? $crmId;
                if (!$crmId) throw new RuntimeException('Resposta sem identificador');
                $state = 'synced';
            }
            $q = $db->prepare('UPDATE visitors SET crm_id=COALESCE(?,crm_id) WHERE id=?'); $q->execute([$crmId, $row['visitor']]);
            $q = $db->prepare("UPDATE outbox SET state=CASE WHEN (SELECT score FROM visitors WHERE id=outbox.visitor)<>? THEN 'pending' ELSE ? END,error=NULL,updated=? WHERE visitor=?"); $q->execute([$row['score'], $state, time(), $row['visitor']]);
        } catch (Throwable $ex) {
            // Consultas podem ser repetidas; criação com resposta incerta exige conciliação.
            $retry = $operation === 'query' && $row['attempts'] < 4 && !str_contains($ex->getMessage(), 'Mais de um lead') && !str_contains($ex->getMessage(), 'Cadastro anterior');
            $q = $db->prepare('UPDATE outbox SET state=?,next_try=?,error=?,updated=? WHERE visitor=?');
            $q->execute([$retry ? 'pending' : 'review', time() + min(3600, 60 * (2 ** (int)$row['attempts'])), $operation . ': ' . $ex->getMessage(), time(), $row['visitor']]);
        }
        return true;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
