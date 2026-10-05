<?php
declare(strict_types=1);
require __DIR__.'/../analytics/server/lib.php';
$dir=sys_get_temp_dir().'/analytics-http-'.bin2hex(random_bytes(6));mkdir($dir,0700);
file_put_contents($dir.'/index.php', <<<'PHP'
<?php
$file=__DIR__.'/count';$n=is_file($file)?(int)file_get_contents($file):0;file_put_contents($file,(string)++$n);
header('Content-Type: application/json');
if(isset($_GET['forbidden'])){http_response_code(403);echo '{"error":"no"}';}
elseif(isset($_GET['invalid']))echo '<html>not json</html>';
elseif($n<3){http_response_code(429);echo '{"error":"quota"}';}
else echo '{"rows":[]}';
PHP);
$socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,'-t',$dir],[0=>['pipe','r'],1=>['file',$dir.'/stdout','a'],2=>['file',$dir.'/stderr','a']],$pipes);
try{
    for($i=0;$i<40;$i++){ $ready=@stream_socket_client('tcp://'.$address,$errno,$error,.1);if($ready){fclose($ready);break;}usleep(50000); }
    $r=googleHttp('http://'.$address,[],'{}');
    if($r!==['rows'=>[]]||(int)file_get_contents($dir.'/count')!==3)throw new RuntimeException('Retry 429 inválido');
    echo "OK: quota 429 usa retry limitado e recupera.\n";
    file_put_contents($dir.'/count','0');
    try{googleHttp('http://'.$address.'/?forbidden=1',[],'{}');throw new LogicException('403 aceito');}catch(RuntimeException $e){if($e->getCode()!==403)throw $e;}
    if((int)file_get_contents($dir.'/count')!==1)throw new RuntimeException('403 repetido');
    echo "OK: erro de permissão 403 não é repetido.\n";
    try{googleHttp('http://'.$address.'/?invalid=1',[],'{}');throw new LogicException('HTML aceito');}catch(JsonException $e){echo "OK: resposta HTML não é tratada como consulta vazia.\n";}
}finally{
    foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
    proc_terminate($process);proc_close($process);
    foreach(glob($dir.'/*')as$file)unlink($file);rmdir($dir);
}
