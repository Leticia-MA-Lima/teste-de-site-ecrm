<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
$c = configuration(); $db = database($c);
$limit = time() - (int)$c['retention_days'] * 86400;
$db->beginTransaction();
foreach (['events' => 'created', 'outbox' => 'updated', 'visitors' => 'updated'] as $table => $column) {
    $q = $db->prepare("DELETE FROM $table WHERE $column<?"); $q->execute([$limit]);
}
$db->commit();
$n = 0;
while ($n < 50 && syncOne($db, $c)) $n++;
echo "Itens processados: $n\n";
