<?php
declare(strict_types=1);
namespace ChupaCabra;

use ChupaCabra\Scraper\ScraperFactory;
use PDO;

/** Gerencia apenas URLs/categorias: adicionar um novo mercado exige um raspador próprio. */
final class Sources
{
    public function __construct(private PDO $db) {}

    public function markets(): array
    {
        // A presença no catálogo ou na lista mantém o supermercado visível,
        // mesmo depois que suas fontes forem desabilitadas. Dados históricos não somem.
        $rows=$this->db->query("SELECT s.alias,s.name,
             (SELECT COUNT(*) FROM url_search u WHERE u.supermercado=s.alias) AS sources_count,
             (SELECT COUNT(*) FROM url_search u WHERE u.supermercado=s.alias AND u.enabled=1) AS active_sources,
             (SELECT COUNT(*) FROM saved i WHERE i.supermercado=s.alias) AS saved_items,
             (SELECT COUNT(*) FROM purchase_history_items h WHERE h.supermercado=s.alias) AS historic_items
             FROM supermercados s ORDER BY s.name")->fetchAll();
        foreach ($rows as &$row) {
            $market=(string)$row['alias'];
            // As tabelas de catálogos são implementadas por raspador, não derivadas de input.
            $row['has_products']=in_array($market,['confianca','tauste'],true)
                ? (int)$this->db->query('SELECT EXISTS(SELECT 1 FROM data_'.$market.' LIMIT 1)')->fetchColumn() : 0;
            $row['visible']=((int)$row['active_sources']>0 || (int)$row['has_products']>0
                || (int)$row['saved_items']>0 || (int)$row['historic_items']>0);
        }
        unset($row);
        return $rows;
    }

    public function save(array $data, ?string $uid=null): array
    {
        // Mesma trava usada pelo worker: não alterar URL/categoria no meio da raspagem.
        $lockName=$uid===null?null:'chupacabra:source:'.sha1($uid);
        if($lockName!==null) {
            $st=$this->db->prepare('SELECT GET_LOCK(?,0)');
            $st->execute([$lockName]);
            if((int)$st->fetchColumn()!==1) throw new ApiException('Esta fonte está ocupada. Aguarde antes de editar.',409);
        }
        try {
            $this->db->beginTransaction();
            $result=$this->write($data,$uid);
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        } finally {
            if($lockName!==null) {
                $st=$this->db->prepare('SELECT RELEASE_LOCK(?)');$st->execute([$lockName]);
            }
        }
    }

    private function write(array $data, ?string $uid): array
    {
        $market=trim((string)($data['supermercado']??''));
        $category=trim((string)($data['categoria']??''));
        $url=trim((string)($data['url']??''));
        $enabled=$data['enabled']??true;
        if(!is_bool($enabled))throw new ApiException('Informe se a fonte está ativa ou inativa.');
        if($category==='' || mb_strlen($category)>100)throw new ApiException('Informe uma categoria de até 100 caracteres.');
        if($url==='' || strlen($url)>2048 || !filter_var($url,FILTER_VALIDATE_URL))throw new ApiException('Informe uma URL HTTPS válida de até 2048 caracteres.');
        $parts=parse_url($url);
        if(!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new ApiException('Use uma URL HTTPS sem credenciais, porta personalizada ou fragmento.');
        }
        // ScraperFactory valida supermercado implementado, host permitido e allowlist de .env.
        ScraperFactory::for($market,$url);
        $changedConfiguration=false;
        if($uid!==null){
            $this->requireUid($uid);
            $st=$this->db->prepare('SELECT supermercado,categoria,url,enabled FROM url_search WHERE uid=?');
            $st->execute([$uid]);$original=$st->fetch(PDO::FETCH_ASSOC);
            if(!$original)throw new ApiException('Fonte não encontrada.',404);
            if($original['supermercado']!==$market)throw new ApiException('Para trocar de supermercado, crie uma nova fonte.');
            $changedConfiguration=($original['categoria']!==$category || $original['url']!==$url);
            if($changedConfiguration || !$enabled){
                $running=$this->db->prepare("SELECT 1 FROM scrape_jobs WHERE source_uid=? AND status='running' LIMIT 1");
                $running->execute([$uid]);
                if($running->fetchColumn())throw new ApiException('Esta fonte está em execução. Aguarde a conclusão antes de alterar a URL, a categoria ou desativá-la.',409);
            }
        }
        $dupe=$this->db->prepare('SELECT uid FROM url_search WHERE supermercado=? AND (LOWER(categoria)=LOWER(?) OR url=?) AND (? IS NULL OR uid<>?) LIMIT 1');
        $dupe->execute([$market,$category,$url,$uid,$uid]);
        if($dupe->fetchColumn())throw new ApiException('Já existe uma fonte com essa categoria ou URL nesse supermercado.',409);
        if($uid===null){
            $bytes=random_bytes(16);
            $bytes[6]=chr((ord($bytes[6]) & 0x0f)|0x40);
            $bytes[8]=chr((ord($bytes[8]) & 0x3f)|0x80);
            $hex=bin2hex($bytes);
            $uid=sprintf('%s-%s-%s-%s-%s',substr($hex,0,8),substr($hex,8,4),substr($hex,12,4),substr($hex,16,4),substr($hex,20));
            $st=$this->db->prepare('INSERT INTO url_search(uid,supermercado,categoria,url,enabled) VALUES (?,?,?,?,?)');
            $st->execute([$uid,$market,$category,$url,$enabled?1:0]);
        } else {
            $st=$this->db->prepare('UPDATE url_search SET categoria=?,url=?,enabled=? WHERE uid=?');
            $st->execute([$category,$url,$enabled?1:0,$uid]);
        }
        // Não apagar trabalhos em execução. Pendências de fontes desativadas deixam
        // de ser processadas e continuam auditáveis como substituídas.
        if(!$enabled || $changedConfiguration) $this->supersedeWaiting($uid,$enabled);
        $st=$this->db->prepare('SELECT uid,supermercado,categoria,url,enabled FROM url_search WHERE uid=?');
        $st->execute([$uid]);return $st->fetch();
    }

    public function delete(string $uid): void
    {
        $this->requireUid($uid);
        $st=$this->db->prepare('SELECT 1 FROM scrape_jobs WHERE source_uid=? LIMIT 1');
        $st->execute([$uid]);
        if($st->fetchColumn())throw new ApiException('Esta fonte possui histórico de importações. Desative-a para preservar a auditoria.',409);
        $st=$this->db->prepare('DELETE FROM url_search WHERE uid=?');
        $st->execute([$uid]);
        if(!$st->rowCount())throw new ApiException('Fonte não encontrada.',404);
    }

    private function supersedeWaiting(string $uid,bool $enabled): void
    {
        $message=$enabled?'URL ou categoria alterada no painel; solicitação anterior substituída':'Fonte desativada no painel';
        $st=$this->db->prepare("UPDATE scrape_jobs SET status='superseded',finished_at=NOW(),
            error_message=? WHERE source_uid=? AND status IN ('pending','queued','retry')");
        $st->execute([$message,$uid]);
        // Os IDs no Redis permanecem inofensivos: Jobs::work ignora IDs não-queued.
    }

    private function requireUid(string $uid): void
    {
        if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$uid))throw new ApiException('Identificador de fonte inválido.');
    }
}
