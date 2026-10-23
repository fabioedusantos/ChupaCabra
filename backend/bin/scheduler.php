<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
\ChupaCabra\Bootstrap::init();
while(true) {
    try { (new \ChupaCabra\Jobs(\ChupaCabra\Bootstrap::db(),\ChupaCabra\Bootstrap::redis()))->tick(); }
    catch(\Throwable $e) { error_log('Scheduler: '.$e->getMessage()); }
    sleep(30);
}
