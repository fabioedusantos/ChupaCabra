<?php
declare(strict_types=1);

/** Teste autocontido da lógica de fechamento e relatórios, sem afetar o banco real.
 * Execute: php backend/tests/PurchaseHistoryTest.php
 * Para validar SQL/MariaDB e autenticação, use também os testes manuais de docs/COMPRAS.md.
 */
namespace ChupaCabra {
    if (!function_exists('ChupaCabra\mb_strlen')) {
        function mb_strlen(string $text): int { return \function_exists('mb_strlen') ? \mb_strlen($text) : strlen($text); }
    }
}
namespace ChupaCabra\Tests {
    use ChupaCabra\ApiException;
    use ChupaCabra\PurchaseHistory;
    use PDO;
    use PDOStatement;
    use RuntimeException;

    require dirname(__DIR__).'/src/ApiException.php';
    require dirname(__DIR__).'/src/Shopping.php';
    require dirname(__DIR__).'/src/PurchaseHistory.php';

    final class FakeStatement extends PDOStatement
    {
        private array $results = [];
        private int $position = 0;
        public function __construct(private FakeDb $db, private string $sql, array $results = []) { $this->results = $results; }
        public function execute(?array $params = null): bool
        {
            $params ??= [];
            if (str_starts_with($this->sql, 'INSERT INTO purchase_history (')) {
                $id = $this->db->nextId++;
                $this->db->insertId = $id;
                $this->db->history[] = [
                    'id' => $id, 'completed_at' => date('Y-m-d H:i:s'),
                    'recorded_by' => $params[0], 'item_count' => $params[1],
                    'estimated_total' => $params[2], 'actual_total' => $params[3], 'note' => $params[4]
                ];
            } elseif (str_starts_with($this->sql, 'INSERT INTO purchase_history_items (')) {
                $this->db->lines[] = ['purchase_id' => $params[0], 'saved_item_id' => $params[1],
                    'titulo' => $params[2], 'categoria' => $params[3], 'supermercado' => $params[4],
                    'observacao' => $params[5], 'quantidade' => $params[6],
                    'estimated_unit_price' => $params[7], 'estimated_subtotal' => $params[8]];
            } elseif (str_contains($this->sql, 'FROM purchase_history WHERE completed_at >= ?')) {
                $this->results = array_values(array_filter($this->db->history, fn($p) => $p['completed_at'] >= $params[0] && $p['completed_at'] < $params[1]));
            } elseif (str_contains($this->sql, 'FROM purchase_history WHERE id=?')) {
                $this->results = array_values(array_filter($this->db->history, fn($p) => $p['id'] == $params[0]));
            } elseif (str_contains($this->sql, 'FROM purchase_history_items WHERE purchase_id=?')) {
                $this->results = array_values(array_filter($this->db->lines, fn($p) => $p['purchase_id'] == $params[0]));
            } else throw new RuntimeException('Query não simulada: '.$this->sql);
            return true;
        }
        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->results; }
        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->results[$this->position++] ?? false; }
    }
    final class FakeDb extends PDO
    {
        public array $saved = [];
        public array $history = [];
        public array $lines = [];
        public int $nextId = 1;
        public int $insertId = 0;
        private bool $active = false;
        public function __construct() {}
        public function beginTransaction(): bool { $this->active=true;return true; }
        public function commit(): bool { $this->active=false;return true; }
        public function rollBack(): bool { $this->active=false;return true; }
        public function inTransaction(): bool { return $this->active; }
        public function lastInsertId(?string $name = null): string|false { return (string)$this->insertId; }
        public function exec(string $statement): int|false {
            if ($statement !== 'UPDATE saved SET oculto=0') throw new RuntimeException('Execução inesperada');
            foreach ($this->saved as &$row) $row['oculto']=0;
            unset($row);
            return count($this->saved);
        }
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
            if (str_starts_with($query, 'SELECT id, oculto FROM saved ')) return new FakeStatement($this,$query,array_map(fn($r)=>['id'=>$r['id'],'oculto'=>$r['oculto']],$this->saved));
            if (str_starts_with($query, 'SELECT s.id,')) return new FakeStatement($this,$query,$this->saved);
            throw new RuntimeException('SELECT inesperado: '.$query);
        }
        public function prepare(string $query, array $options = []): PDOStatement|false { return new FakeStatement($this,$query); }
    }
    function expect(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
    function rejects(callable $fn, int $status): void {
        try {$fn();}catch(ApiException $e){expect($e->httpStatus===$status,'HTTP status incorreto');return;}
        throw new RuntimeException('A operação deveria falhar.');
    }

    $db = new FakeDb();
    $service = new PurchaseHistory($db);
    $db->saved = [[
        'id'=>1,'titulo'=>'Café','categoria'=>'Mercearia','supermercado'=>'tauste',
        'observacao'=>'Sem açúcar','qtd'=>'2.000','preco'=>'10.000','preco_atual'=>'9.995','oculto'=>0
    ], [
        'id'=>2,'titulo'=>'Tomate','categoria'=>'Hortifruti','supermercado'=>'confianca',
        'observacao'=>null,'qtd'=>'1.500','preco'=>'4.000','preco_atual'=>'5.000','oculto'=>1
    ]];
    rejects(fn()=>$service->close('25.50','','familia'),409); // lista incompleta
    $db->saved[0]['oculto']=1;
    rejects(fn()=>$service->close('abc','','familia'),400);
    rejects(fn()=>$service->close('25.999','','familia'),400);
    rejects(fn()=>$service->close('25.50',str_repeat('z',251),'familia'),400);
    $closed = $service->close('25.50','Compra de teste','familia');
    expect($closed['id']===1 && $closed['estimated_total']==='27.49','Total estimado e arredondamento por item');
    expect($closed['actual_total']==='25.50' && $closed['difference']==='1.99','Diferença entre estimado e pago');
    expect(count($db->lines)===2,'Cada item deve ter snapshot');
    expect($db->lines[0]['estimated_unit_price']==='9.995','Preço unitário imutável (3 casas)');
    expect($db->lines[0]['estimated_subtotal']==='19.99','Preço e quantidade gravados juntos');
    expect($db->saved[0]['oculto']===0 && $db->saved[1]['oculto']===0,'Liberar lista seguinte');
    rejects(fn()=>$service->close('25.50','','familia'),409); // proteção contra duplo clique
    $db->saved[0]['preco_atual']='17.500';
    $db->saved[0]['titulo']='Outro produto';
    $detail = $service->detail(1);
    expect($detail['items'][0]['titulo']==='Café' && $detail['items'][0]['estimated_unit_price']==='9.995','Snapshot não muda com nova raspagem/lista');
    expect($detail['note']==='Compra de teste','Observação da compra');
    rejects(fn()=>$service->detail(999),404);
    $report = $service->report(3);
    expect($report['summary']['purchase_count']===1,'Compra aparece no relatório');
    expect($report['summary']['difference']==='1.99' && count($report['monthly'])===3,'Saldo e período mensal');
    expect($report['summary']['difference_percent']===7.2,'Porcentagem ponderada');
    foreach ([1,3,6,9,12] as $period) {
        $window = $service->report($period);
        expect(count($window['monthly']) === $period, 'Número de meses do relatório');
        expect($window['summary']['purchase_count'] === 1, 'Compra incluída em todos os períodos');
    }
    $db->saved[0]['oculto']=1;
    $db->saved[1]['oculto']=1;
    $second = $service->close('60.00','','familia');
    expect($second['estimated_total']==='42.50' && $second['difference']==='-17.50','Compra acima do orçamento');
    $summary = $service->report(12)['summary'];
    expect($summary['purchase_count'] === 2 && $summary['estimated_total'] === '69.99','Estimativa acumulada');
    expect($summary['actual_total'] === '85.50' && $summary['difference'] === '-15.51','Saldo negativo acumulado');
    expect($service->detail(1)['items'][0]['titulo'] === 'Café','Fechamentos posteriores não alteram o passado');
    rejects(fn()=>$service->report(2),400);
    echo "OK: fechamento, validação, snapshots, anti-duplicação, reinício, gastos acima/abaixo e todos os períodos.\n";
}
