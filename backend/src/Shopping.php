<?php
declare(strict_types=1);
namespace ChupaCabra;

use PDO;

final class Shopping
{
    public function __construct(private PDO $db) {}

    public function saved(): array
    {
        // Sem alterar IDs; a lista é compartilhada pela família.
        // O legado não gravava a imagem em `saved`. Recuperamos diretamente do
        // catálogo do supermercado, sem duplicar o alias imgUrl de `s.*`.
        // SELECT explícito é importante: SELECT s.*, ... AS imgUrl podia devolver
        // o campo vazio de saved em vez da fotografia recuperada.
        $sql = "SELECT s.id,s.titulo,s.categoria,s.qtd,s.preco,s.supermercado,
                       s.dataHora,s.oculto,s.observacao,
                  COALESCE((SELECT MIN(d.preco) FROM data_confianca d
                            WHERE d.titulo=s.titulo AND d.preco>0 AND s.supermercado='confianca'),
                           (SELECT MIN(t.preco) FROM data_tauste t
                            WHERE t.titulo=s.titulo AND t.preco>0 AND s.supermercado='tauste'),
                           s.preco) AS preco_atual,
                  COALESCE(CASE WHEN s.supermercado='confianca' THEN
                          (SELECT d.imgUrl FROM data_confianca d
                           WHERE d.titulo=s.titulo AND d.imgUrl IS NOT NULL AND d.imgUrl<>''
                           ORDER BY d.id DESC LIMIT 1)
                     WHEN s.supermercado='tauste' THEN
                          (SELECT t.imgUrl FROM data_tauste t
                           WHERE t.titulo=s.titulo AND t.imgUrl IS NOT NULL AND t.imgUrl<>''
                           ORDER BY t.id DESC LIMIT 1) END, NULLIF(TRIM(s.imgUrl),'')) AS imgUrl
                FROM saved s ORDER BY s.id ASC";
        return $this->db->query($sql)->fetchAll();
    }

    public function search(string $text): array
    {
        // Transcrição parametrizada da relevância do CompareModel antigo.
        $text = trim(mb_strtolower($text, 'UTF-8'));
        $text = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)));
        if (mb_strlen($text) < 2) return [];
        $words = array_slice(array_values(array_unique(array_filter(explode(' ', $text)))), 0, 6);
        $full = "CONCAT_WS(' ',LOWER(titulo),LOWER(categoria))";
        $match=[];$matched=[];$relevance=[];$paramsMatched=[];$paramsRelevant=[];$paramsWhere=[];
        foreach($words as $word) {
            $match[] = "$full LIKE ?";
            $paramsWhere[]='%'.$word.'%';
            $matched[]="(CASE WHEN $full LIKE ? THEN 1 ELSE 0 END)";
            $paramsMatched[]='%'.$word.'%';
            $relevance[]='(CASE WHEN LOWER(titulo) LIKE ? THEN 12 ELSE 0 END)';
            $relevance[]='(CASE WHEN LOWER(titulo) LIKE ? THEN 5 ELSE 0 END)';
            $relevance[]='(CASE WHEN LOWER(categoria) LIKE ? THEN 2 ELSE 0 END)';
            array_push($paramsRelevant,$word.'%','%'.$word.'%','%'.$word.'%');
        }
        $termsSql=implode(' + ',$matched);
        $rankSql='(CASE WHEN LOWER(titulo)=? THEN 120 ELSE 0 END) + (CASE WHEN LOWER(titulo) LIKE ? THEN 60 ELSE 0 END) + (CASE WHEN LOWER(titulo) LIKE ? THEN 35 ELSE 0 END) + (CASE WHEN LOWER(categoria) LIKE ? THEN 8 ELSE 0 END) + '.implode(' + ',$relevance);
        $params = [...$paramsMatched,$text,$text.'%','%'.$text.'%','%'.$text.'%',...$paramsRelevant,...$paramsWhere];
        $all=[];
        foreach(['confianca','tauste'] as $market) {
            $table='data_'.$market;
            $sql="SELECT titulo,preco,categoria,imgUrl,image_id,'$market' supermercado,($termsSql) AS termos_encontrados,($rankSql) AS relevancia
                  FROM (SELECT titulo,MIN(preco) preco,MAX(categoria) categoria,MAX(NULLIF(imgUrl,'')) imgUrl,MAX(CASE WHEN imgUrl IS NOT NULL AND imgUrl<>'' THEN id ELSE NULL END) image_id FROM $table WHERE preco>0 GROUP BY titulo) produtos
                  WHERE (".implode(' OR ',$match).")
                  ORDER BY termos_encontrados DESC,relevancia DESC,preco ASC LIMIT 60";
            $st=$this->db->prepare($sql);$st->execute($params);
            $all=array_merge($all,$st->fetchAll());
        }
        usort($all,fn($a,$b)=>((int)$b['termos_encontrados']<=>(int)$a['termos_encontrados']) ?: ((int)$b['relevancia']<=>(int)$a['relevancia']) ?: ((float)$a['preco']<=>(float)$b['preco']));
        return array_slice($all,0,80);
    }

    public function replace(array $input): array
    {
        if (count($input) > 500) throw new ApiException('Limite de 500 itens por lista.');
        $this->db->beginTransaction();
        try {
            $ids = [];
            foreach ($input as $row) {
                if (!is_array($row)) throw new ApiException('Item inválido.');
                $id = (int)($row['id'] ?? 0);
                $title = trim((string)($row['titulo'] ?? ''));
                $category = trim((string)($row['categoria'] ?? ''));
                $market = (string)($row['supermercado'] ?? '');
                $qty = (float)($row['qtd'] ?? 1);
                $price = (float)($row['preco'] ?? 0);
                $note = trim((string)($row['observacao'] ?? ''));
                $image = trim((string)($row['imgUrl'] ?? ''));
                if ($image !== '' && (strlen($image) > 2048 || !filter_var($image, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($image, PHP_URL_SCHEME)), ['http','https'], true))) throw new ApiException('URL da imagem inválida.');
                if ($title === '' || mb_strlen($title)>254 || mb_strlen($category)>100 || mb_strlen($note)>500 || !in_array($market,['confianca','tauste'],true) || !is_finite($qty) || $qty <= 0 || $qty > 100000 || !is_finite($price) || $price<0 || $price>999999) throw new ApiException('Revise os campos, quantidades e observações da lista.');
                if ($id > 0 && !in_array($id, $ids, true)) {
                    $imageReplaced = ($row['imageReplaced'] ?? false) === true;
                    $st = $this->db->prepare("UPDATE saved SET titulo=?, categoria=?, qtd=?, preco=?, supermercado=?, observacao=?, imgUrl=CASE WHEN ?=1 THEN ? ELSE COALESCE(NULLIF(?,''),imgUrl) END WHERE id=?");
                    $st->execute([$title,$category,$qty,$price,$market,$note,$imageReplaced?1:0,$image,$image,$id]);
                    if ($st->rowCount() === 0) {
                        // update de linha idêntica retorna 0: precisamos distinguir do item inexistente.
                        $check=$this->db->prepare('SELECT 1 FROM saved WHERE id=?');$check->execute([$id]);
                        if (!$check->fetchColumn()) throw new ApiException('A lista foi alterada por outro dispositivo. Atualize a página.',409);
                    }
                    $ids[] = $id;
                } elseif ($id <= 0) {
                    $st = $this->db->prepare('INSERT INTO saved (titulo,categoria,qtd,preco,supermercado,observacao,imgUrl) VALUES (?,?,?,?,?,?,?)');
                    $st->execute([$title,$category,$qty,$price,$market,$note,$image]);
                    $ids[] = (int)$this->db->lastInsertId();
                } else throw new ApiException('Identificador duplicado.');
            }
            if (!$ids) $this->db->exec('DELETE FROM saved');
            else {
                $marks=implode(',',array_fill(0,count($ids),'?'));
                $st=$this->db->prepare("DELETE FROM saved WHERE id NOT IN ($marks)");$st->execute($ids);
            }
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        return $this->saved();
    }

    /**
     * Persiste apenas os itens modificados na montagem da lista. Diferente de
     * replace(), alterações de outros familiares não são removidas pelo envio.
     * Os IDs temporários negativos permitem relacionar inclusões à tela.
     */
    public function applyChanges(array $input): array
    {
        $adds=$input['add']??[];
        $updates=$input['update']??[];
        $removes=$input['remove']??[];
        if (!is_array($adds) || !array_is_list($adds) || !is_array($updates) || !array_is_list($updates)
            || !is_array($removes) || !array_is_list($removes)
            || count($adds)+count($updates)+count($removes)>500) {
            throw new ApiException('Alterações da lista inválidas.');
        }
        $created=[];
        $this->db->beginTransaction();
        try {
            // Trava o conjunto atual para verificar exclusões e limite da lista.
            $existing=array_fill_keys(array_map('intval',$this->db->query('SELECT id FROM saved FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN)),true);
            $seen=[];
            foreach ($removes as $id) {
                if (!is_int($id) || $id<=0 || isset($seen[$id]) || !isset($existing[$id])) {
                    throw new ApiException('A lista foi modificada em outro dispositivo. Atualize a página.',409);
                }
                $seen[$id]=true;
            }
            foreach ($updates as $row) {
                $id=is_array($row)?($row['id']??null):null;
                if (!is_int($id) || $id<=0 || isset($seen[$id]) || !isset($existing[$id])) {
                    throw new ApiException('O produto foi removido em outro dispositivo. Atualize a página.',409);
                }
                $seen[$id]=true;
            }
            if (count($existing)-count($removes)+count($adds)>500) {
                throw new ApiException('Limite de 500 itens por lista.');
            }
            $tempIds=[];
            foreach ($adds as $row) {
                $clientId=is_array($row)?($row['clientId']??null):null;
                if (!is_int($clientId) || $clientId>=0 || isset($tempIds[$clientId])) {
                    throw new ApiException('Identificador temporário inválido.');
                }
                $tempIds[$clientId]=true;
            }
            foreach ($removes as $id) {
                $this->db->prepare('DELETE FROM saved WHERE id=?')->execute([$id]);
            }
            foreach ($updates as $row) {
                [$title,$category,$qty,$price,$market,$note,$image]=$this->checkedItem($row);
                $replaceImage=($row['imageReplaced']??false)===true;
                $this->db->prepare("UPDATE saved SET titulo=?, categoria=?, qtd=?, preco=?, supermercado=?, observacao=?, imgUrl=CASE WHEN ?=1 THEN ? ELSE imgUrl END WHERE id=?")
                    ->execute([$title,$category,$qty,$price,$market,$note,$replaceImage?1:0,$image,$row['id']]);
            }
            foreach ($adds as $row) {
                [$title,$category,$qty,$price,$market,$note,$image]=$this->checkedItem($row);
                // Evita duplicações em retries após perda de conexão e entre
                // familiares que adicionem o mesmo produto ao mesmo tempo.
                $duplicate=$this->db->prepare('SELECT id FROM saved WHERE supermercado=? AND titulo=? LIMIT 1');
                $duplicate->execute([$market,$title]);
                if ($duplicate->fetchColumn()!==false) {
                    throw new ApiException('Este produto já está na lista compartilhada. Atualize a página.',409);
                }
                $this->db->prepare('INSERT INTO saved (titulo,categoria,qtd,preco,supermercado,observacao,imgUrl) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$title,$category,$qty,$price,$market,$note,$image]);
                $created[(string)$row['clientId']]=(int)$this->db->lastInsertId();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['items'=>$this->saved(),'created'=>$created];
    }

    private function checkedItem(array $row): array
    {
        $title=trim((string)($row['titulo']??''));
        $category=trim((string)($row['categoria']??''));
        $market=(string)($row['supermercado']??'');
        $qty=(float)($row['qtd']??0);
        $price=(float)($row['preco']??0);
        $note=trim((string)($row['observacao']??''));
        $image=trim((string)($row['imgUrl']??''));
        if ($image!=='' && (strlen($image)>2048 || !filter_var($image,FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url($image,PHP_URL_SCHEME)),['http','https'],true))) {
            throw new ApiException('URL da imagem inválida.');
        }
        if ($title==='' || mb_strlen($title)>254 || mb_strlen($category)>100 || mb_strlen($note)>500
            || !in_array($market,['confianca','tauste'],true) || !is_finite($qty) || $qty<=0 || $qty>100000
            || !is_finite($price) || $price<0 || $price>999999) {
            throw new ApiException('Revise os campos, quantidades e observações da lista.');
        }
        return [$title,$category,$qty,$price,$market,$note,$image];
    }

    public function purchase(int $id, bool $bought): void
    {
        $st=$this->db->prepare('UPDATE saved SET oculto=? WHERE id=?'); $st->execute([$bought?1:0,$id]);
        if ($st->rowCount()===0) { $check=$this->db->prepare('SELECT 1 FROM saved WHERE id=?'); $check->execute([$id]); if (!$check->fetchColumn()) throw new ApiException('Item não encontrado.',404); }
    }

    public function restoreAll(): void { $this->db->exec('UPDATE saved SET oculto=0'); }
    public function clear(): void { $this->db->exec('DELETE FROM saved'); }
}
