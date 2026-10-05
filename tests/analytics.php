<?php
declare(strict_types=1);
require __DIR__ . '/../analytics/server/lib.php';
function verify(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "OK: $label\n"; }
function result(array $dims, array $metrics): array { return ['dimensionValues' => array_map(fn($v) => ['value' => (string)$v], $dims), 'metricValues' => array_map(fn($v) => ['value' => (string)$v], $metrics)]; }
$calls=[];
$query=function($method,$q)use(&$calls){
    $calls[]=[$method,$q]; $dims=array_column($q['dimensions']??[],'name');
    if (!$dims) return ['rows'=>[result([],isset($q['minuteRanges'])?[2]:[5])]];
    $rows=match($dims[0]){
        'eventName'=>[result(['whatsapp_click'],[2]),result(['generate_lead'],[1]),result(['click'],[99])],
        'unifiedScreenName'=>[result(['Conheça'],[7]),result(['Desconhecida'],[3])],
        'countryId'=>[result(['BR','1','São Paulo'],[4]),result(['BR','2','Campinas'],[4])],
        'minutesAgo'=>[result(['00'],[2]),result(['29'],[6])], default=>[]};
    return ['rows'=>$rows];
};
$c=['page_titles'=>['Conheça'=>'Geral']];$d=collectRealtime($query,$c);
verify($d['active_30m']===5 && $d['active_5m']===2,'Totais distintos vêm de consultas sem dimensões, não da soma das cidades');
verify($d['events_30m']===['whatsapp_click'=>2,'generate_lead'=>1],'Clique e sucesso de formulário são métricas distintas');
verify($d['pages_30m'][1]['lp']==='Outras páginas','Página desconhecida não é descartada');
verify(count($d['activity_30m'])===30 && $d['activity_30m'][0]['events']===6 && $d['activity_30m'][29]['events']===2 && $d['activity_30m'][1]['events']===0,'Minutos em ordem cronológica, lacunas preenchidas');
verify($calls[1][1]['minuteRanges'][0]['startMinutesAgo']===4,'Janela de cinco minutos inclusiva');
verify($calls[4][1]['dimensionFilter']['filter']['stringFilter']['value']==='BR','Filtro de cidades restrito ao Brasil');
$zero=collectRealtime(fn()=>['rows'=>[]],$c);verify($zero['active_30m']===0,'Consulta válida vazia confirma zero');
try{collectRealtime(fn()=>throw new RuntimeException('Falha'),$c);verify(false,'Falha não deve virar zero');}catch(RuntimeException $e){verify(true,'Falha de consulta não se transforma em zero');}
verify(dashboardCache(null,time(),180)['active_30m']===null,'Sem cache representa indisponibilidade, não zero');
$dailyQuery=function($method,$q){
    if($method==='checkCompatibility') return ['dimensionCompatibilities'=>[['compatibility'=>'COMPATIBLE']],'metricCompatibilities'=>[['compatibility'=>'COMPATIBLE']]];
    return ['rows'=>[array_column($q['dimensions'],'name')[0]==='hostName'?result(['conheca.ecrm360.com.br'],[9]):result(['instagram','paid_social','(not set)'],[4,120])]];
};
$daily=collectDaily($dailyQuery,['hosts'=>['conheca.ecrm360.com.br'=>'Geral'],'timezone'=>'America/Sao_Paulo'],strtotime('2026-10-06T01:00:00Z'));
verify($daily['date']==='2026-10-05' && $daily['pages'][0]['lp']==='Geral','Resumo diário respeita fuso de São Paulo e domínio da LP');
verify($daily['campaigns'][0]['engagement_seconds']===120.0 && $daily['campaigns'][0]['campaign']==='(not set)','Engajamento é duração total, sem inventar campanha ou média');
try{collectDaily(fn($m,$q)=>$m==='checkCompatibility'?['metricCompatibilities'=>[['compatibility'=>'INCOMPATIBLE']]]:['rows'=>[]],['timezone'=>'America/Sao_Paulo','hosts'=>[]],time());verify(false,'Incompatibilidade ignorada');}catch(RuntimeException $e){verify(true,'Consulta diária incompatível é recusada');}
$old=['generated_at'=>gmdate('c',time()-181),'active_30m'=>5];verify(dashboardCache($old,time(),180)['status']==='stale','Cache preservado marcado como atrasado');
$path=sys_get_temp_dir().'/analytics-test-'.bin2hex(random_bytes(6)).'.json';
try{atomicCache($path,$old);verify(readCache($path)===$old,'Escrita atômica preserva o contrato');}finally{if(is_file($path))unlink($path);}
require __DIR__.'/../tracking/lib.php';
$db=new PDO('sqlite::memory:');verify(syncOne($db,['dry_run'=>false])===false,'Integração CRM desativada por padrão antes de acessar a fila ou a rede');
