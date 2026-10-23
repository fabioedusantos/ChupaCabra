<?php
declare(strict_types=1);
namespace ChupaCabra;

use PDO;
use Predis\Client;
use ChupaCabra\Scraper\ScraperFactory;

/** Uma linha no banco por trabalho; Redis transporta somente IDs. */
final class Jobs
{
    public function __construct(private PDO $db,private Client $redis) {}

    public function sources(): array
    {
        return $this->db->query('SELECT u.uid,u.url,u.categoria,u.supermercado,u.enabled,s.name supermercadoName FROM url_search u JOIN supermercados s ON s.alias=u.supermercado ORDER BY u.supermercado,u.categoria')->fetchAll();
    }

    public function queuePaused(): bool { return $this->redis->get('scrape:paused') === '1'; }
    public function pause(bool $value): void { $this->redis->set('scrape:paused',$value?'1':'0'); }

    public function manualMarket(string $market): array
    {
        if(!in_array($market,['confianca','tauste'],true)) throw new ApiException('Supermercado inválido.');
        $st=$this->db->prepare('SELECT uid,supermercado,categoria,url FROM url_search WHERE supermercado=? AND enabled=1');
        $st->execute([$market]);$ids=[];$batch='manual:'.bin2hex(random_bytes(12));
        foreach($st->fetchAll() as $row) {
            ScraperFactory::for($market,$row['url']);
            $ids[]=$this->insertJob($row,'manual',null,$batch);
        }
        if(!$ids)throw new ApiException('Nenhuma fonte ativa para o supermercado.',404);
        return $ids;
    }

    /** Uma execução manual única para todas as fontes ativas, agrupada no histórico. */
    public function manualAll(): array
    {
        $sources=$this->db->query('SELECT uid,supermercado,categoria,url FROM url_search WHERE enabled=1 ORDER BY supermercado,categoria')->fetchAll();
        if(!$sources)throw new ApiException('Nenhuma fonte ativa para importar.',404);

        // Validar todas as fontes antes de gravar qualquer trabalho.
        foreach($sources as $source) {
            ScraperFactory::for((string)$source['supermercado'],(string)$source['url']);
        }
        $batch='manual-all:'.bin2hex(random_bytes(12));
        $ids=[];
        try {
            $this->db->beginTransaction();
            foreach($sources as $source) $ids[]=$this->insertJob($source,'manual',null,$batch);
            $this->db->commit();
        } catch(\Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
        return $ids;
    }

    public function recent(): array
    {
        $jobs=$this->db->query('SELECT j.id,j.source_uid,j.batch_key,j.supermercado,j.categoria,j.tipo,j.status,j.attempts,j.max_attempts,j.created_at,j.started_at,j.finished_at,j.next_attempt_at,j.products_count,j.error_message FROM scrape_jobs j ORDER BY j.id DESC LIMIT 150')->fetchAll();
        $attempts=$this->db->query('SELECT a.job_id,a.attempt_number,a.started_at,a.finished_at,a.status,a.products_count,a.error_message FROM scrape_attempts a ORDER BY a.id DESC LIMIT 300')->fetchAll();
        return ['jobs'=>$jobs,'attempts'=>$attempts,'paused'=>$this->queuePaused()];
    }

    public function manual(string $source): int
    {
        $st=$this->db->prepare('SELECT uid,supermercado,categoria,url FROM url_search WHERE uid=? AND enabled=1');
        $st->execute([$source]);$row=$st->fetch();
        if(!$row)throw new ApiException('Fonte não encontrada ou inativa.',404);
        ScraperFactory::for($row['supermercado'],(string)($row['url']??''));
        return $this->insertJob($row,'manual',null,'manual:'.bin2hex(random_bytes(12)));
    }

    public function retry(int $id): int
    {
        $st=$this->db->prepare('SELECT source_uid FROM scrape_jobs WHERE id=? AND status IN (\'failed\',\'retry\',\'success\',\'superseded\')');
        $st->execute([$id]);$uid=$st->fetchColumn();
        if(!$uid)throw new ApiException('Aguarde a execução atual terminar antes de reprocessar.',409);
        $this->db->prepare("UPDATE scrape_jobs SET status='superseded',finished_at=NOW(),error_message='Substituída por reprocessamento manual' WHERE id=? AND status='retry'")->execute([$id]);
        return $this->manual((string)$uid);
    }

    private function insertJob(array $source,string $type,?string $key,?string $batch): int
    {
        $sql='INSERT INTO scrape_jobs (source_uid,supermercado,categoria,tipo,schedule_key,batch_key) VALUES (?,?,?,?,?,?)';
        $st=$this->db->prepare($sql);
        $st->execute([$source['uid'],$source['supermercado'],$source['categoria'],$type,$key,$batch]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Mantém apenas o pedido mais recente por fonte, inclusive entre dias e lotes manuais.
     * Os IDs já presentes no Redis tornam-se inofensivos; work() valida o estado no DB.
     */
    private function consolidateBacklog(): void
    {
        // IDs enfileirados que serão descartados, para limpar também a fila física.
        $queued=$this->db->query("SELECT DISTINCT older.id FROM scrape_jobs older
            JOIN scrape_jobs newer ON newer.source_uid=older.source_uid AND newer.id>older.id
                AND newer.status IN ('pending','queued','retry')
            WHERE older.status='queued'")->fetchAll(PDO::FETCH_COLUMN);
        $this->db->exec("UPDATE scrape_jobs older
            JOIN scrape_jobs newer ON newer.source_uid=older.source_uid AND newer.id>older.id
                AND newer.status IN ('pending','queued','retry')
            SET older.status='superseded',older.finished_at=NOW(),
                older.error_message='Substituída por solicitação mais recente da mesma fonte'
            WHERE older.status IN ('pending','queued','retry')");
        $this->removeSupersededFromQueue($queued);
    }

    /** Remove apenas entradas já encerradas; uma falha de Redis não reativa o trabalho. */
    private function removeSupersededFromQueue(array $ids): void
    {
        $st=$this->db->prepare('SELECT status FROM scrape_jobs WHERE id=?');
        foreach($ids as $id) {
            $st->execute([$id]);
            if($st->fetchColumn()!=='superseded')continue;
            try { $this->redis->lrem('scrape:queue',0,(string)$id); }
            catch(\Throwable $e) { error_log('Limpeza da fila Redis: '.$e->getMessage()); }
        }
    }

    public function tick(): void
    {
        // Evita agendamento repetido mesmo com múltiplos contêineres.
        $leader=$this->db->query("SELECT GET_LOCK('chupacabra_scheduler', 0)")->fetchColumn();
        if((int)$leader!==1)return;
        try {
            $now=new \DateTimeImmutable('now',new \DateTimeZone('America/Sao_Paulo'));
            if($now->format('H:i')>='09:00') {
                foreach($this->sources() as $source) {
                    if(!(int)$source['enabled'])continue;
                    $key=$now->format('Y-m-d').':'.$source['uid'];
                    $st=$this->db->prepare('INSERT IGNORE INTO scrape_jobs (source_uid,supermercado,categoria,tipo,schedule_key,batch_key) VALUES (?,?,?,\'daily\',?,?)');
                    $st->execute([$source['uid'],$source['supermercado'],$source['categoria'],$key,'daily:'.$now->format('Y-m-d')]);
                }
            }
            // Uma fonte pode acumular vários dias na pausa: só a solicitação mais nova fica.
            $this->consolidateBacklog();
            // Se worker morreu, o heartbeat vence e o trabalho entra em nova tentativa.
            $this->db->exec("UPDATE scrape_attempts a JOIN scrape_jobs j ON j.id=a.job_id SET a.status='failed',a.finished_at=NOW(),a.error_message='Worker interrompido ou sem heartbeat' WHERE a.status='running' AND j.status='running' AND j.heartbeat_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
            $this->db->exec("UPDATE scrape_jobs SET status=IF(attempts>=max_attempts,'failed','retry'),next_attempt_at=DATE_ADD(NOW(), INTERVAL 1 HOUR),error_message='Worker interrompido ou sem heartbeat' WHERE status='running' AND heartbeat_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
            // Reparação de fila perdida: NÃO reenfileirar só por ter ficado 15 min
            // aguardando. Durante pausa/fila longa o ID ainda existe no Redis.
            $oldQueued=$this->db->query("SELECT id FROM scrape_jobs WHERE status='queued'
                AND next_attempt_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchAll(PDO::FETCH_COLUMN);
            if($oldQueued) {
                $present=array_fill_keys($this->redis->lrange('scrape:queue',0,-1),true);
                $recover=$this->db->prepare("UPDATE scrape_jobs SET status='pending' WHERE id=? AND status='queued'");
                foreach($oldQueued as $queuedId) {
                    if(isset($present[(string)$queuedId]))continue;
                    $recover->execute([$queuedId]);
                }
            }
            $st=$this->db->query("SELECT j.id FROM scrape_jobs j
                WHERE j.status IN ('pending','retry') AND j.next_attempt_at<=NOW()
                  AND NOT EXISTS (SELECT 1 FROM scrape_jobs running
                                  WHERE running.source_uid=j.source_uid AND running.status='running')
                ORDER BY j.id ASC LIMIT 100");
            foreach($st->fetchAll() as $row) {
                $id=(int)$row['id'];
                $update=$this->db->prepare("UPDATE scrape_jobs SET status='queued',next_attempt_at=NOW() WHERE id=? AND status IN ('pending','retry')");
                $update->execute([$id]);
                if($update->rowCount()!==1)continue;
                try { $this->redis->lpush('scrape:queue',[(string)$id]); }
                catch(\Throwable $e) { $recover=$this->db->prepare("UPDATE scrape_jobs SET status='pending' WHERE id=? AND status='queued'");$recover->execute([$id]);throw $e; }
            }
        } finally { $this->db->query("SELECT RELEASE_LOCK('chupacabra_scheduler')"); }
    }

    public function work(int $id): void
    {
        // Reserva a fonte antes de abrir a URL. Impede raspagens paralelas da mesma
        // origem, inclusive quando há mais de um worker e IDs antigos no Redis.
        $source=$this->db->prepare("SELECT source_uid FROM scrape_jobs WHERE id=? AND status='queued'");
        $source->execute([$id]);
        $sourceUid=$source->fetchColumn();
        if(!$sourceUid)return; // ID obsoleto no Redis: não faz chamada HTTP.
        $lockName='chupacabra:source:'.sha1((string)$sourceUid);
        $lock=$this->db->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$lockName]);
        if((int)$lock->fetchColumn()!==1) {
            // Outro worker possui a fonte, possivelmente até este mesmo ID
            // (a lista antiga pode conter cópias). NÃO trocar o status aqui:
            // isso impediria o dono do lock de iniciar seu próprio trabalho.
            // Caso este ID tenha sido retirado do Redis, o scheduler o repara.
            return;
        }
        try {
            // Pode ter surgido um pedido novo depois da última verificação do scheduler.
            $newer=$this->db->prepare("SELECT 1 FROM scrape_jobs
                WHERE source_uid=? AND id>? AND status IN ('pending','queued','running','retry','success') LIMIT 1");
            $newer->execute([$sourceUid,$id]);
            if($newer->fetchColumn()) {
                $skip=$this->db->prepare("UPDATE scrape_jobs SET status='superseded',finished_at=NOW(),
                    error_message='Substituída por solicitação mais recente da mesma fonte'
                    WHERE id=? AND status='queued'");
                $skip->execute([$id]);
                return;
            }
            $this->executeJob($id);
        } finally {
            $release=$this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    /** Executa somente após a reserva exclusiva da fonte. */
    private function executeJob(int $id): void
    {
        $start=$this->db->prepare("UPDATE scrape_jobs SET status='running',attempts=attempts+1,started_at=NOW(),heartbeat_at=NOW(),error_message=NULL WHERE id=? AND status='queued'");
        $start->execute([$id]);
        if($start->rowCount()!==1)return;
        // Limpa cópias do mesmo ID eventualmente acumuladas antes da correção.
        try { $this->redis->lrem('scrape:queue',0,(string)$id); }
        catch(\Throwable $e) { error_log('Limpeza de IDs duplicados: '.$e->getMessage()); }
        $st=$this->db->prepare('SELECT j.*,u.url,u.enabled FROM scrape_jobs j LEFT JOIN url_search u ON u.uid=j.source_uid WHERE j.id=?');$st->execute([$id]);$job=$st->fetch();
        $attempt=(int)$job['attempts'];
        $st=$this->db->prepare("INSERT INTO scrape_attempts(job_id,attempt_number) VALUES (?,?)");$st->execute([$id,$attempt]);
        $attemptId=(int)$this->db->lastInsertId();
        try {
            if(!$job['url'] || !(int)$job['enabled'])throw new \RuntimeException('Fonte removida ou desabilitada.');
            $scraper=ScraperFactory::for($job['supermercado'],$job['url']);
            $heartbeat=function() use($id) {$st=$this->db->prepare('UPDATE scrape_jobs SET heartbeat_at=NOW() WHERE id=?');$st->execute([$id]);};
            $products=$scraper->scrape($job['url'],$job['categoria'],$heartbeat);
            if(count($products)===0)throw new \RuntimeException('Extração retornou zero produtos; dados anteriores foram preservados.');
            $this->saveProducts($job['supermercado'],$job['categoria'],$products);
            $st=$this->db->prepare("UPDATE scrape_jobs SET status='success',finished_at=NOW(),heartbeat_at=NOW(),products_count=?,error_message=NULL WHERE id=?");$st->execute([count($products),$id]);
            $st=$this->db->prepare("UPDATE scrape_attempts SET status='success',finished_at=NOW(),products_count=? WHERE id=?");$st->execute([count($products),$attemptId]);
            // A fonte acaba de ser atualizada. Pedidos criados durante a raspagem
            // já estão satisfeitos; não faz sentido raspá-la outra vez.
            try {
                $pending=$this->db->prepare("SELECT id FROM scrape_jobs WHERE source_uid=? AND id<>? AND status='queued'");
                $pending->execute([$job['source_uid'],$id]);
                $queuedIds=$pending->fetchAll(PDO::FETCH_COLUMN);
                $st=$this->db->prepare("UPDATE scrape_jobs SET status='superseded',finished_at=NOW(),
                    error_message=? WHERE source_uid=? AND id<>? AND status IN ('pending','queued','retry')");
                $st->execute(['Atendida pela importação concluída #'.$id,$job['source_uid'],$id]);
                $this->removeSupersededFromQueue($queuedIds);
            } catch(\Throwable $cleanupError) {
                // O catálogo já foi gravado: falha na limpeza nunca deve gerar
                // tentativa extra nem converter uma importação bem-sucedida em erro.
                error_log('Limpeza de duplicatas após #'.$id.': '.$cleanupError->getMessage());
            }
        } catch(\Throwable $e) {
            $msg=mb_substr($e->getMessage(),0,1800);
            $finished=$attempt >= (int)$job['max_attempts'];
            // Cálculo independente de mudanças de dia / horário de verão.
            $clock=new \DateTimeImmutable('now',new \DateTimeZone('America/Sao_Paulo'));
            $next=$clock->setTime((int)$clock->format('H'),0)->modify('+1 hour');
            $st=$this->db->prepare('UPDATE scrape_jobs SET status=?,finished_at=NOW(),heartbeat_at=NOW(),next_attempt_at=?,error_message=? WHERE id=?');
            $st->execute([$finished?'failed':'retry',$next->format('Y-m-d H:i:s'),$msg,$id]);
            $st=$this->db->prepare("UPDATE scrape_attempts SET status='failed',finished_at=NOW(),error_message=? WHERE id=?");$st->execute([$msg,$attemptId]);
            error_log('Scraping #'.$id.' tentativa '.$attempt.' falhou: '.$msg);
        }
    }

    private function saveProducts(string $market,string $category,array $products): void
    {
        if(!in_array($market,['confianca','tauste'],true))throw new \RuntimeException('Supermercado desconhecido.');
        $lockName='chupacabra:'.sha1($market.'|'.$category);
        $st=$this->db->prepare('SELECT GET_LOCK(?,30)');$st->execute([$lockName]);
        if((int)$st->fetchColumn()!==1)throw new \RuntimeException('Falha ao obter bloqueio exclusivo da categoria.');
        try {
            $this->db->beginTransaction();
            $table='data_'.$market;
            $st=$this->db->prepare("DELETE FROM $table WHERE categoria=?");$st->execute([$category]);
            $insert=$this->db->prepare("INSERT INTO $table(titulo,preco,categoria,imgUrl) VALUES(?,?,?,?)");
            foreach($products as $item) {
                $insert->execute([mb_substr((string)$item['titulo'],0,254),$item['preco'],$category,$item['imgUrl']]);
            }
            $this->db->commit();
        } catch(\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack();throw $e; }
        finally {$st=$this->db->prepare('SELECT RELEASE_LOCK(?)');$st->execute([$lockName]);}
    }
}
