<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
\ChupaCabra\Bootstrap::init();
$db=\ChupaCabra\Bootstrap::db();
$schema=file_get_contents(dirname(__DIR__).'/docker/production/database/schema.sql');
if($schema===false) {fwrite(STDERR,"Schema não encontrado.\n");exit(1);}
// DDL de schema: cada statement é independente; nenhum corpo SQL com ; embutido.
$schema=(string)preg_replace('/^--.*$/m','',$schema);
foreach(explode(';',$schema) as $sql) {
    $sql=trim($sql);
    if($sql!=='')$db->exec($sql);
}
$check=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saved'")->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('observacao',$check,true)) { $db->exec('ALTER TABLE saved ADD COLUMN observacao VARCHAR(500) NULL');echo "Coluna saved.observacao criada.\n"; }
if(!in_array('imgUrl',$check,true)) { $db->exec('ALTER TABLE saved ADD COLUMN imgUrl TEXT NULL');echo "Coluna saved.imgUrl criada.\n"; }
$jobColumns=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='scrape_jobs'")->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('batch_key',$jobColumns,true)) { $db->exec('ALTER TABLE scrape_jobs ADD COLUMN batch_key VARCHAR(64) NULL, ADD INDEX idx_batch (batch_key,id)');echo "Coluna scrape_jobs.batch_key criada.\n"; }
// A partir desta versão, tarefas antigas podem ser finalizadas sem nova raspagem.
$jobStatusType=$db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='scrape_jobs' AND COLUMN_NAME='status'")->fetchColumn();
if($jobStatusType!==false && !str_contains((string)$jobStatusType,"'superseded'")) {
    $db->exec("ALTER TABLE scrape_jobs MODIFY COLUMN status ENUM('pending','queued','running','retry','success','failed','superseded') NOT NULL DEFAULT 'pending'");
    echo "Status superseded habilitado em scrape_jobs.\n";
}
$jobIndices=$db->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='scrape_jobs'")->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('idx_source_status',$jobIndices,true)) {
    $db->exec('ALTER TABLE scrape_jobs ADD INDEX idx_source_status (source_uid,status,id)');
    echo "Índice de consolidação de fontes criado.\n";
}
$check=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='url_search'")->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('enabled',$check,true)) { $db->exec('ALTER TABLE url_search ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1');echo "Coluna url_search.enabled criada.\n"; }
// A tabela legacy possui id sem auto_increment; a nova interface insere itens sem id explícito.
$extra=$db->query("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saved' AND COLUMN_NAME='id'")->fetchColumn();
if(!str_contains((string)$extra,'auto_increment'))$db->exec('ALTER TABLE saved MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT');
echo "Migração concluída.\n";
