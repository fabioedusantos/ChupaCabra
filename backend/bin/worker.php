<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
\ChupaCabra\Bootstrap::init();
while(true) {
    try {
        $redis=\ChupaCabra\Bootstrap::redis();
        if($redis->get('scrape:paused')==='1'){sleep(5);continue;}
        $item=$redis->brpop(['scrape:queue'],5);
        if(!$item)continue;
        $id=(int)($item[1]??0);
        if($id>0)(new \ChupaCabra\Jobs(\ChupaCabra\Bootstrap::db(),$redis))->work($id);
    } catch(\Throwable $e) { error_log('Worker: '.$e->getMessage());sleep(5); }
}
