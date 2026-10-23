<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
\ChupaCabra\Bootstrap::init();
$ok=true;
$checks=[
    'PHP 8.5.12'=>PHP_VERSION==='8.5.12',
    'Extensões'=>extension_loaded('pdo_mysql') && extension_loaded('dom') && extension_loaded('curl') && extension_loaded('mbstring'),
    'Usuários configurados'=>(bool)(getenv('AUTH_USERS_B64')?:''),
    'Cookie Secure'=>(getenv('APP_ENV')!=='production'||getenv('COOKIE_SECURE')==='1'),
];
foreach($checks as $label=>$pass){printf('%s %s\n',$pass?'OK':'VERIFICAR',$label);if(!$pass)$ok=false;}
try {$db=\ChupaCabra\Bootstrap::db();$db->query('SELECT 1');echo "OK MariaDB\n";}
catch(\Throwable $e) {$ok=false;echo "FALHA MariaDB\n";}
try {$redis=\ChupaCabra\Bootstrap::redis();$redis->ping();echo "OK Redis\n";}
catch(\Throwable $e) {$ok=false;echo "FALHA Redis\n";}
if(isset($db)) {
    foreach(['saved','data_confianca','data_tauste','url_search','scrape_jobs'] as $table) {
        try {printf('%s: %d registros\n',$table,(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());}
        catch(\Throwable $e) {$ok=false;printf('FALHA: tabela %s ausente\n',$table);}
    }
}
exit($ok?0:1);
