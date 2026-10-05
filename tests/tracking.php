<?php
declare(strict_types=1);
require __DIR__ . '/../tracking/lib.php';
$path = sys_get_temp_dir() . '/ecrm-test-' . bin2hex(random_bytes(8)) . '.sqlite';
$c = ['database' => $path, 'sites' => ['test' => 'Teste'], 'points' => ['visit' => 1, 'click' => 2, 'form_start' => 3, 'field_filled' => 1, 'form_submit' => 20], 'dry_run' => true];
$db = database($c);
$visitor = '11111111-1111-4111-8111-111111111111';
$session = '22222222-2222-4222-8222-222222222222';
$counter = 0;
function event(string $kind, string $target, array $extra = []): array {
    global $visitor, $session, $counter;
    $id = sprintf('33333333-3333-4333-8333-%012d', ++$counter);
    return ['id' => $id, 'visitor' => $visitor, 'session' => $session, 'site' => 'test', 'kind' => $kind, 'target' => $target] + $extra;
}
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "OK: $label\n"; }
try {
    check(!syncOne($db, ['dry_run' => false]), 'Integração CRM desativada por padrão');
    $r = collect($db, $c, event('visit', 'page')); check($r['score'] === 1 && !$r['qualified'], 'Visita anônima');
    $r = collect($db, $c, event('visit', 'outra-pagina')); check($r['score'] === 1 && $r['duplicate'], 'Visita repetida na mesma sessão');
    $r = collect($db, $c, event('click', 'schedule')); check($r['score'] === 3 && !$r['qualified'], 'Agendamento clicado não qualifica');
    collect($db, $c, event('form_start', 'contact'));
    collect($db, $c, event('field_filled', 'contact:nome'));
    $r = collect($db, $c, event('field_filled', 'contact:nome')); check($r['score'] === 7, 'Campo soma uma vez');
    try { collect($db, $c, event('form_submit', 'contact', ['contact' => ['nome' => 'Teste', 'email' => 'inválido']])); throw new RuntimeException('Validação ausente'); }
    catch (InvalidArgumentException $ex) { check(true, 'Rejeita e-mail inválido'); }
    check((int)$db->query('SELECT COUNT(*) FROM outbox')->fetchColumn() === 0, 'Contato inválido não enfileirado');
    $submission = event('form_submit', 'contact', ['contact' => ['nome' => 'Teste Técnico', 'email' => 'test@example.invalid']]);
    $r = collect($db, $c, $submission); check($r['score'] === 27 && $r['qualified'], 'Envio válido qualifica e enfileira');
    $r = collect($db, $c, $submission); check($r['score'] === 27 && $r['duplicate'], 'Retry idempotente');
    check(syncOne($db, $c), 'Worker consome fila');
    check($db->query('SELECT state FROM outbox')->fetchColumn() === 'dry_run', 'Simulação não chama CRM');
    check(!syncOne($db, $c), 'Fila consumida não reprocessa');
    $other = event('click', 'contact'); $other['visitor'] = '44444444-4444-4444-8444-444444444444'; $other['id'] = $submission['id'];
    try { collect($db, $c, $other); throw new RuntimeException('Colisão aceita'); }
    catch (InvalidArgumentException $ex) { check(true, 'Evento não pode migrar de visitante'); }
    $db->exec("UPDATE outbox SET state='synced'");
    collect($db, $c, event('click', 'whatsapp'));
    check($db->query('SELECT state FROM outbox')->fetchColumn() === 'pending', 'Pontuação posterior solicita atualização');
    $db->exec("UPDATE outbox SET state='processing',updated=0");
    syncOne($db, $c);
    check($db->query('SELECT state FROM outbox')->fetchColumn() === 'review', 'Worker interrompido exige conciliação');
} finally {
    $db = null;
    foreach ([$path, $path . '-wal', $path . '-shm', $path . '.lock'] as $file) if (is_file($file)) unlink($file);
}
