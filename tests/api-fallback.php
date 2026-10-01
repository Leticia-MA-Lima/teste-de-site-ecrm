<?php
declare(strict_types=1);
require __DIR__ . '/../tracking/lib.php';
$dir = sys_get_temp_dir() . '/ecrm-api-test-' . bin2hex(random_bytes(8)); mkdir($dir, 0700);
$fixture = <<<'PHP'
<?php
$p=json_decode(file_get_contents('php://input'),true);
$file=__DIR__.'/record.json';
if(in_array($p['operation'],['create','revise'],true)){
    $record=json_decode($p['element'],true);$record['id']='10x99999';file_put_contents($file,json_encode($record));
    header('Content-Type: text/html');echo '<b>Erro ao serializar registro já gravado</b>';exit;
}
$record=json_decode(file_get_contents($file),true);
if(isset($_GET['mismatch']))$record['description']='Conteúdo de outro envio';
header('Content-Type: application/json');echo json_encode(['success'=>true,'result'=>[$record]]);
PHP;
file_put_contents($dir . '/index.php', $fixture);
$socket = stream_socket_server('tcp://127.0.0.1:0'); $address = stream_socket_get_name($socket, false); fclose($socket);
$process = proc_open([PHP_BINARY, '-S', $address, '-t', $dir], [0 => ['pipe', 'r'], 1 => ['file', $dir . '/stdout', 'a'], 2 => ['file', $dir . '/stderr', 'a']], $pipes);
try {
    for ($i = 0; $i < 40; $i++) { $ready = @stream_socket_client('tcp://' . $address, $errno, $error, .1); if ($ready) { fclose($ready); break; } usleep(50000); }
    $c = ['crm_url' => 'http://' . $address, 'crm_token' => 'FAKE-TEST'];
    $element = ['lastname' => 'Teste', 'email' => 'teste@example.invalid', 'description' => '[ECRM tracking identificador] Pontuação: 20'];
    $params = ['elementType' => 'Leads', 'element' => json_encode($element)];
    $result = writeAndConfirm($c, 'create', $params, $element, $element['email']);
    if ($result['id'] !== '10x99999') throw new RuntimeException('Criação não confirmada');
    echo "OK: resposta HTML após criação é conciliada por consulta exata.\n";
    $element['id'] = $result['id']; $element['description'] = '[ECRM tracking identificador] Pontuação: 22';
    $result = writeAndConfirm($c, 'revise', ['element' => json_encode($element)], $element, $element['email']);
    if ($result['description'] !== $element['description']) throw new RuntimeException('Atualização não confirmada');
    echo "OK: atualização confirmada sem repetir a escrita.\n";
    $c['crm_url'] .= '/?mismatch=1'; $rejected = false;
    try { writeAndConfirm($c, 'create', $params, $element, $element['email']); } catch (CrmResponseUncertain $ex) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Registro diferente foi aceito');
    echo "OK: consulta com conteúdo diferente não confirma a escrita.\n";
} finally {
    proc_terminate($process); proc_close($process);
    foreach (glob($dir . '/*') as $file) unlink($file); rmdir($dir);
}
