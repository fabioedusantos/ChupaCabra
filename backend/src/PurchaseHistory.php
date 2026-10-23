<?php
declare(strict_types=1);
namespace ChupaCabra;

use DateInterval;
use DateTimeImmutable;
use PDO;

/** Fechamentos permanentes, independentes da lista compartilhada e do catálogo. */
final class PurchaseHistory
{
    public function __construct(private PDO $db) {}

    /** Um fechamento atômico: fotografa a lista inteira e a libera para a próxima compra. */
    public function close(mixed $amount, mixed $note, string $username): array
    {
        // Não aceitar valores fora do padrão monetário nem arredondamentos silenciosos.
        if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
            throw new ApiException('Informe o total pago em reais, com até duas casas decimais.');
        }
        $raw = trim((string)$amount);
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $raw)) {
            throw new ApiException('Informe o total pago em reais, com até duas casas decimais.');
        }
        $paidCents = (int)round((float)$raw * 100);
        if ($paidCents > 999999999) throw new ApiException('Total pago acima do limite permitido.');
        if (!is_string($note) || mb_strlen(trim($note)) > 250) {
            throw new ApiException('A observação da compra deve ter até 250 caracteres.');
        }
        $note = trim($note);

        $this->db->beginTransaction();
        try {
            // FOR UPDATE impede dois fechamentos simultâneos da mesma lista.
            // Nunca confiar apenas na contagem de itens enviada pelo navegador.
            $locked = $this->db->query('SELECT id, oculto FROM saved ORDER BY id FOR UPDATE')->fetchAll();
            if (!$locked) throw new ApiException('A lista está vazia. Monte uma compra antes de fechá-la.', 409);
            foreach ($locked as $item) {
                if (!(int)$item['oculto']) {
                    throw new ApiException('A lista mudou: ainda existem produtos pendentes. Atualize-a para continuar.', 409);
                }
            }
            // Usa EXATAMENTE a mesma regra de preço atual da lista de compras.
            $snapshot = (new Shopping($this->db))->saved();
            if (count($snapshot) !== count($locked)) throw new ApiException('A lista mudou. Atualize-a e tente novamente.', 409);

            $lines = [];
            $estimateCents = 0;
            foreach ($snapshot as $item) {
                $unit = (float)($item['preco_atual'] ?? $item['preco']);
                $qty = (float)$item['qtd'];
                if (!is_finite($unit) || !is_finite($qty) || $unit < 0 || $qty <= 0) {
                    throw new ApiException('Preço ou quantidade inválida em um item. Revise a lista antes de fechar.', 409);
                }
                $cents = (int)round($unit * $qty * 100, 0, PHP_ROUND_HALF_UP);
                $estimateCents += $cents;
                $lines[] = [$item['id'], $item['titulo'], $item['categoria'], $item['supermercado'],
                    $item['observacao'] ?? '', $item['qtd'], number_format($unit, 3, '.', ''),
                    self::currency($cents)];
            }
            $insert = $this->db->prepare('INSERT INTO purchase_history (recorded_by, item_count, estimated_total, actual_total, note) VALUES (?,?,?,?,?)');
            $insert->execute([$username, count($lines), self::currency($estimateCents), self::currency($paidCents), $note === '' ? null : $note]);
            $purchaseId = (int)$this->db->lastInsertId();
            $detail = $this->db->prepare('INSERT INTO purchase_history_items (purchase_id, saved_item_id, titulo, categoria, supermercado, observacao, quantidade, estimated_unit_price, estimated_subtotal) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($lines as $line) $detail->execute([$purchaseId, ...$line]);
            // Mantém a última lista (produtos, quantidades, observações, ordem),
            // apenas limpa a marcação de comprado para a próxima ida ao mercado.
            $this->db->exec('UPDATE saved SET oculto=0');
            $this->db->commit();
            return ['id' => $purchaseId, 'item_count' => count($lines),
                'estimated_total' => self::currency($estimateCents),
                'actual_total' => self::currency($paidCents),
                'difference' => self::currency($estimateCents - $paidCents)];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Filtros por meses civis: 1 (atual), 3, 6, 9 ou 12 (incluindo o atual). */
    public function report(int $months): array
    {
        if (!in_array($months, [1,3,6,9,12], true)) throw new ApiException('Período inválido.');
        $first = new DateTimeImmutable('first day of this month 00:00:00');
        $start = $first->sub(new DateInterval('P'.($months-1).'M'));
        $end = $first->add(new DateInterval('P1M'));
        $stmt = $this->db->prepare('SELECT id, completed_at, recorded_by, item_count, estimated_total, actual_total, note FROM purchase_history WHERE completed_at >= ? AND completed_at < ? ORDER BY completed_at DESC, id DESC');
        $stmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
        $purchases = $stmt->fetchAll();
        $series = [];
        for ($i=0; $i<$months; $i++) {
            $day = $start->add(new DateInterval('P'.$i.'M'));
            $key = $day->format('Y-m');
            $series[$key] = ['month' => $key, 'count' => 0, 'estimated_total' => 0, 'actual_total' => 0, 'difference' => 0];
        }
        $sumEstimate = 0;
        $sumActual = 0;
        foreach ($purchases as &$purchase) {
            $estimated = self::cents($purchase['estimated_total']);
            $actual = self::cents($purchase['actual_total']);
            $delta = $estimated - $actual;
            $sumEstimate += $estimated;
            $sumActual += $actual;
            $key = substr($purchase['completed_at'], 0, 7);
            if (isset($series[$key])) {
                $series[$key]['count']++;
                $series[$key]['estimated_total'] += $estimated;
                $series[$key]['actual_total'] += $actual;
                $series[$key]['difference'] += $delta;
            }
            $purchase['difference'] = self::currency($delta);
        }
        unset($purchase);
        foreach ($series as &$month) {
            foreach (['estimated_total','actual_total','difference'] as $field) $month[$field] = self::currency($month[$field]);
        }
        unset($month);
        return [
            'months' => $months,
            'summary' => ['purchase_count' => count($purchases), 'estimated_total' => self::currency($sumEstimate),
                'actual_total' => self::currency($sumActual), 'difference' => self::currency($sumEstimate-$sumActual),
                'difference_percent' => $sumEstimate ? round(($sumEstimate-$sumActual)/$sumEstimate*100, 1) : null],
            'monthly' => array_values($series), 'purchases' => $purchases
        ];
    }

    public function detail(int $id): array
    {
        $stmt = $this->db->prepare('SELECT id, completed_at, recorded_by, item_count, estimated_total, actual_total, note FROM purchase_history WHERE id=?');
        $stmt->execute([$id]);
        $purchase = $stmt->fetch();
        if (!$purchase) throw new ApiException('Compra não encontrada.', 404);
        $stmt = $this->db->prepare('SELECT saved_item_id, titulo, categoria, supermercado, observacao, quantidade, estimated_unit_price, estimated_subtotal FROM purchase_history_items WHERE purchase_id=? ORDER BY id');
        $stmt->execute([$id]);
        $purchase['difference'] = self::currency(self::cents($purchase['estimated_total']) - self::cents($purchase['actual_total']));
        $purchase['items'] = $stmt->fetchAll();
        return $purchase;
    }

    private static function currency(int $cents): string
    {
        return number_format($cents/100, 2, '.', '');
    }

    private static function cents(string|int|float $value): int
    {
        return (int)round((float)$value * 100);
    }
}
