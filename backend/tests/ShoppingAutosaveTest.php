<?php
declare(strict_types=1);

/** Execução independente de MariaDB: php backend/tests/ShoppingAutosaveTest.php */
namespace ChupaCabra {
    if (!function_exists('ChupaCabra\mb_strlen')) {
        function mb_strlen(string $text): int { return \function_exists('mb_strlen') ? \mb_strlen($text) : strlen($text); }
    }
}
namespace ChupaCabra\Tests {
    use ChupaCabra\ApiException;
    use ChupaCabra\Shopping;
    use PDO;
    use PDOStatement;
    use RuntimeException;

    require dirname(__DIR__).'/src/ApiException.php';
    require dirname(__DIR__).'/src/Shopping.php';

    final class FakeStatement extends PDOStatement
    {
        public function __construct(private FakeDb $db, private string $sql, private array $rows = []) {}
        public function execute(?array $params = null): bool
        {
            $params ??= [];
            if (str_starts_with($this->sql,'SELECT id FROM saved WHERE supermercado=')) {
                $this->rows=array_values(array_filter($this->db->rows,
                    fn($item)=>$item['supermercado']===$params[0]&&$item['titulo']===$params[1]));
            } elseif (str_starts_with($this->sql,'DELETE FROM saved WHERE id=')) {
                unset($this->db->rows[$params[0]]);
            } elseif (str_starts_with($this->sql,'UPDATE saved SET titulo=')) {
                $id=(int)$params[8];
                if (!isset($this->db->rows[$id])) throw new RuntimeException('Item ausente no UPDATE');
                [$title,$category,$qty,$price,$market,$note,$replace,$image]=$params;
                $r=&$this->db->rows[$id];
                foreach (['titulo'=>$title,'categoria'=>$category,'qtd'=>$qty,'preco'=>$price,
                    'supermercado'=>$market,'observacao'=>$note] as $key=>$value) $r[$key]=$value;
                if ($replace) $r['imgUrl']=$image;
                unset($r);
            } elseif (str_starts_with($this->sql,'INSERT INTO saved (')) {
                $id=$this->db->nextId++;
                $this->db->lastId=$id;
                $this->db->rows[$id]=array_combine(
                    ['titulo','categoria','qtd','preco','supermercado','observacao','imgUrl'],$params
                )+['id'=>$id,'oculto'=>0,'dataHora'=>'2026-11-06 15:00:00'];
            } else throw new RuntimeException('SQL inesperado: '.$this->sql);
            return true;
        }
        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            return $mode===PDO::FETCH_COLUMN ? array_column($this->rows,'id') : $this->rows;
        }
        public function fetchColumn(int $column = 0): mixed { return $this->rows[0]['id']??false; }
    }
    final class FakeDb extends PDO
    {
        public array $rows=[];
        public int $nextId=1;
        public int $lastId=0;
        private ?array $snapshot=null;
        public function __construct() {}
        public function beginTransaction(): bool { $this->snapshot=$this->rows;return true; }
        public function commit(): bool { $this->snapshot=null;return true; }
        public function rollBack(): bool { if($this->snapshot!==null)$this->rows=$this->snapshot;$this->snapshot=null;return true; }
        public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            if ($query==='SELECT id FROM saved FOR UPDATE') return new FakeStatement($this,$query,array_values($this->rows));
            if (str_starts_with($query,'SELECT s.id,')) return new FakeStatement($this,$query,array_values($this->rows));
            throw new RuntimeException('SELECT inesperado: '.$query);
        }
        public function prepare(string $query, array $options=[]): PDOStatement|false { return new FakeStatement($this,$query); }
    }
    function ok(bool $value,string $message): void { if(!$value)throw new RuntimeException($message); }
    function reject(callable $operation,int $code): void
    {
        try{$operation();}catch(ApiException $e){ok($e->httpStatus===$code,'Status incorreto: '.$e->httpStatus);return;}
        throw new RuntimeException('Esperada rejeição HTTP '.$code);
    }
    function item(string $title,string $market='tauste',float $qty=1): array
    {
        return ['titulo'=>$title,'categoria'=>'Mercearia','qtd'=>$qty,'preco'=>12.5,
            'supermercado'=>$market,'observacao'=>'','imgUrl'=>''];
    }
    $db=new FakeDb();
    $service=new Shopping($db);
    $first=$service->applyChanges(['add'=>[['clientId'=>-1]+item('Leite')]]);
    ok($first['created']['-1']===1 && count($first['items'])===1,'Inclusão e mapeamento do ID temporário');
    $db->rows[1]['oculto']=1;
    $db->rows[2]=item('Arroz','confianca')+['id'=>2,'oculto'=>0,'dataHora'=>'2026-11-06 15:00:00'];
    $db->nextId=3;
    $changed=$service->applyChanges(['update'=>[['id'=>1]+item('Leite','tauste',2.5)]]);
    ok(count($changed['items'])===2 && $db->rows[1]['qtd']===2.5,'Atualiza apenas o produto escolhido');
    ok($db->rows[1]['oculto']===1,'Não altera marcação de comprado');
    ok($db->rows[2]['titulo']==='Arroz','Preserva a inclusão simultânea de outro familiar');
    $service->applyChanges(['remove'=>[1]]);
    ok(!isset($db->rows[1]) && isset($db->rows[2]),'Exclusão granular preserva outros itens');
    reject(fn()=>$service->applyChanges(['update'=>[['id'=>1]+item('Leite')]]),409);
    reject(fn()=>$service->applyChanges(['add'=>[['clientId'=>-3]+item('Inválido','tauste',0)]]),400);
    ok(count($db->rows)===1,'Validação inválida não altera o banco');
    reject(fn()=>$service->applyChanges(['add'=>[['clientId'=>-6]+item('Arroz','confianca')]]),409);
    reject(fn()=>$service->applyChanges(['add'=>[
        ['clientId'=>-4]+item('Produto A'),['clientId'=>-4]+item('Produto B')
    ]]),400);
    ok(count($db->rows)===1,'IDs temporários duplicados não incluem itens');
    echo "OK: inclusão, alteração, exclusão, lista familiar, comprados, rollback e conflitos.\n";
}
