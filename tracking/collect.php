<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $c = configuration();
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!in_array($origin, $c['origins'], true)) { http_response_code(403); echo '{"error":"Origem não autorizada"}'; exit; }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST, OPTIONS'); echo '{"error":"Use POST para enviar o contato."}'; exit; }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($raw) > 16384) { http_response_code(413); echo '{"error":"Os dados enviados excedem o limite permitido."}'; exit; }
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('JSON inválido');
    $db = database($c);
    // IP não é persistido: somente HMAC com janela de um minuto.
    $now = time(); $key = hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . ':' . intdiv($now, 60), $c['secret']);
    $q = $db->prepare('INSERT INTO rate_limits(key,count,expires) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=count+1'); $q->execute([$key, $now + 120]);
    $q = $db->prepare('SELECT count FROM rate_limits WHERE key=?'); $q->execute([$key]);
    if ((int)$q->fetchColumn() > 120) { http_response_code(429); header('Retry-After: 60'); echo '{"error":"Aguarde antes de tentar novamente"}'; exit; }
    $db->prepare('DELETE FROM rate_limits WHERE expires<?')->execute([$now]);
    echo json_encode(collect($db, $c, $input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException | JsonException $ex) {
    http_response_code(422); echo json_encode(['error' => $ex->getMessage()]);
} catch (Throwable $ex) {
    http_response_code(503); echo '{"error":"Serviço indisponível. Tente novamente."}';
    error_log('ECRM tracking: erro interno no coletor');
}
