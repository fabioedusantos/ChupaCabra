# Histórico e fechamento de compras

## Como funciona

1. **Montar lista** funciona como antes: produtos, quantidades, observações, categoria e supermercado são reaproveitados da última lista. O preço exibido é o preço **atual disponível** no catálogo; se um produto desaparecer da raspagem, a tela utiliza o preço persistido no item (`saved.preco`) como referência.
2. Durante as compras, marque cada item normalmente. Ao marcar o **último item**, abre-se a janela **Fechamento da compra**. Se ela for dispensada, o botão **Encerrar compra** continua disponível. Nada é registrado até confirmar o pagamento.
3. Digite o **valor total efetivamente pago** nos cupons fiscais (ex.: `153,48`). Pode incluir uma breve observação para identificar aquela compra.
4. Ao confirmar, a API verifica no banco que **todos os itens estão comprados**, grava de maneira **atômica** o cabeçalho, cada item com preço unitário estimado, quantidade, subtotal, categoria, mercado e observação, e **reinicia somente as marcações de comprado**. O conjunto de produtos da lista é mantido para a próxima ida ao mercado.
5. Em **Relatórios**, escolha **este mês**, **3**, **6**, **9** ou **12 meses**. Esses intervalos são meses civis, incluindo o mês atual. Veja os totais estimado e gasto, a diferença, um gráfico mensal, tabela opcional e o detalhamento de cada compra.

## O que significa economizar?

**Saldo = soma dos valores estimados − soma dos totais pagos**.

- **Saldo positivo:** a família pagou menos do que a estimativa.
- **Saldo negativo:** foi gasto mais do que a estimativa.
- **Saldo zero:** gasto e estimativa coincidirem.
- **Percentual:** saldo / estimativa × 100, calculado sobre os totais acumulados (e não média simples dos percentuais por compra). Se a estimativa for zero, não se calcula percentual.

**Importante:** a comparação é **orçado versus realizado**, não um desconto comprovado. Troca de marcas, produtos não previstos na lista, itens não levados ao caixa e diferenças de peso podem explicar o resultado. Se sua lista contiver produtos de dois mercados, o aplicativo solicitará **a soma dos cupons**; por esse motivo, não distribui ficticiamente o valor real entre produtos ou mercados. O preço por item no histórico é o **estimado**, congelado no fechamento. O valor real é guardado apenas para a compra inteira.

## Segurança e consistência

- Tabelas novas: `purchase_history` e `purchase_history_items` (schema migrado automaticamente pelo serviço Docker `migrate`). **Não remove nem modifica os registros antigos de `saved` ou de catálogo.**
- A operação de fechamento usa `SELECT ... FOR UPDATE` e uma transação MariaDB, impedindo o duplo fechamento de uma lista já reiniciada. Se um familiar alterou a lista, a API retorna conflito e solicita atualização.
- Novas rotas autenticadas: `POST /api/purchases` (CSRF obrigatório, `actual_total` e `note` opcional); `GET /api/purchases?months=1|3|6|9|12`; `GET /api/purchases/{id}`.
- O preço do histórico é salvo numericamente, independente dos dados futuros da raspagem. Os dados de compras e itens permanecem nas tabelas dedicadas mesmo após excluir ou alterar a lista de compras.
- O total estimado é a soma dos subtotais de cada produto arredondados para centavos. O total pago aceita somente valores não negativos com até duas casas decimais, máximo de R$ 9.999.999,99.

## Implantação no servidor existente

1. Faça um **backup do banco MariaDB** antes da atualização. Não elimine os diretórios de persistência.
2. Copie os **arquivos alterados do pacote** para suas respectivas pastas no projeto `/docker/chupacabra` (mantenha `compose.yaml`, `.env`, dados de MariaDB, Redis e Caddy).
3. Na raiz, execute:

   ```bash
   cd /docker/chupacabra
   docker compose up -d --build
   docker compose logs --tail=100 migrate
   docker compose ps
   ```

4. Confira se `migrate` terminou com sucesso e se `api`/`web` estão saudáveis. Se a migração não tiver sido executada, rode `docker compose run --rm migrate` e, depois, `docker compose up -d`.
5. Acesse a lista, faça um teste com dois itens e preencha o total do recibo; confirme que o histórico surge em **Relatórios** e que a lista permanece com os itens pendentes para a próxima compra.

**Não use** `docker compose down -v` nem exclua `/docker/chupacabra/backend/docker/production/data/db`. A raspagem às 9h e a fila Redis não foram modificadas.

### Verificações recomendadas

- Fechar com item pendente ou lista vazia é bloqueado; após fechar, não é possível registrar novamente a mesma lista sem marcar os itens em outra compra.
- Um produto cujo preço mudou ou desapareceu do catálogo continua com **seu valor antigo no histórico**; na lista atual, o preço passa a refletir o catálogo disponível.
- Duas sessões tentando fechar a mesma lista não criam compras duplicadas.
- Compras de meses diferentes entram somente nas janelas correspondentes; o histórico não muda quando `saved` é apagada.
- Pagamentos com centavos, valor zero, valor negativo e valores excessivos devem respeitar a validação.

Teste de lógica automatizado independente do banco (requer PHP CLI):

```bash
php backend/tests/PurchaseHistoryTest.php
```

Esse teste usa um PDO simulado para validar fechamento, snapshots e relatórios. **Não substitui um teste integrado na instalação MariaDB/PHP 8.5.12**; execute o roteiro manual após atualizar o servidor.
