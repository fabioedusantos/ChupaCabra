# Extensão dos raspadores e recuperação de falhas

## Como funciona

A tabela `url_search` define a origem por UID, categoria, supermercado e `enabled`. O scheduler verifica as URLs ativas após 09h (`America/Sao_Paulo`). Uma tarefa única por `UID + data` é persistida em `scrape_jobs` e enfileirada pelo Redis. O agendador continua criando o registro diário, mas consolida solicitações aguardando da **mesma fonte (UID)**, independentemente da data e de terem sido criadas manualmente ou automaticamente. O worker consome IDs, atualiza heartbeat e registra a tentativa em `scrape_attempts`.

Os raspadores ficam em `backend/src/Scraper`:

- `ConfiancaScraper`: conserva `window.state = JSON.parse(decodeURI(...))`, `searchRepository.pages.results.records`, `sku.activePrice`/`sku.minActivePrice`, paginação `?page=` e detecção de páginas repetidas.
- `TausteScraper`: conserva os seletores XPath do `ImportModel::processTauste`, a paginação `?p=` e a conversão de preços `R$ 1.234,56`.
- `HtmlProvider`: interface única `fetch(url): DOMDocument`, permitindo adotar navegador renderizado futuramente sem reescrever as regras de produtos.
- `HtmlFetcher`: transporte atual com cURL/TLS, limite de tamanho, validação de host e redirects.

## Adicionar um supermercado

1. Criar classe que implemente `StoreScraper::scrape(url,categoria,heartbeat)`, retornando array de `{titulo,preco,categoria,imgUrl}`.
2. Registrar em `ScraperFactory` e declarar os domínios confiáveis em `SCRAPE_HOSTS` e no mapeamento de hosts da factory.
3. Criar a tabela de produtos `data_<alias>` conforme o modelo atual, adicionar o mercado em `supermercados` e URLs de categorias em `url_search`.
4. Atualizar a whitelist e consultas de pesquisa em `Shopping`, que atualmente conhecem apenas Confiança e Tauste. Não interpolar nome de tabela enviado pelo cliente.
5. Validar paginação, parsing, zero resultados, HTTP 403/429, mudanças no HTML, idempotência, corte de energia e concorrência em homologação.

## Quando precisar renderizar JavaScript

Substituir/adicionar uma implementação de `HtmlProvider` que receba HTML já renderizado de um worker de navegador (por exemplo, Playwright isolado em contêiner próprio). A implementação deve restringir URLs e downloads, bloquear acesso à rede interna/metadata, impor timeout e limites de memória. **Não é necessária nem está habilitada nesta versão**; os dois raspadores atuais continuam usando cURL.

## Execuções manuais pela central de importações

- **Iniciar importação geral** (`POST /api/jobs/run-all`): registra uma solicitação para cada fonte **ativa**, todas com o mesmo identificador de lote `manual-all:*`. A gravação é transacional e todas as fontes são validadas antes de inserir tarefas. Não modifica o agendamento das 9h; porém, as solicitações ainda não iniciadas para o mesmo UID são consolidadas em uma única execução, preservando o histórico de tarefas substituídas.
- **Por supermercado** e **por categoria**: continuam disponíveis separadamente. Todas as ações exigem sessão autenticada e proteção CSRF da API.
- **Pausar fila**: pausa o consumo dos workers usando a chave persistida `scrape:paused` no Redis. Trabalhos já em execução terminam. **A pausa não tem expiração**: até clicar em **Retomar fila**, ela impede o início de novos trabalhos, inclusive os agendados para os dias seguintes. Por isso, a interface só oferece "Pausar" enquanto houver trabalhos e mantém "Retomar" visível quando aplicável, com aviso do efeito no agendamento.
- Uma execução manual é enfileirada pelo scheduler na próxima verificação (aproximadamente 30 segundos), sem bloquear a navegação. Não é uma interrupção ou um reinício dos workers.

## Consolidação de pendências e proteção contra duplicatas

- **Chave única diária:** `schedule_key` continua garantindo uma solicitação agendada por `UID + dia`.
- **Apenas a mais recente por fonte:** o scheduler mantém no máximo uma solicitação aguardando por UID (`pending`, `queued` ou `retry`), ainda que existam sete dias de tarefas, ou disparos manuais e automáticos. As anteriores recebem `status=superseded` ("Substituída"), com motivo, sem serem apagadas nem contadas como falhas. Elas são removidas do Redis se estiverem enfileiradas; um ID antigo que ainda apareça no Redis é ignorado pelo worker sem requisitar URL; cópias do ID de um trabalho iniciado também são removidas da lista.
- **Reparo da fila real:** após 15 minutos o scheduler só reenfileira um ID em estado `queued` se ele estiver *ausente* da lista Redis. Uma fila pausada ou extensa deixa de acumular cópias a cada 15 minutos. Isso também cobre trabalhos com tentativas anteriores.
- **Exclusão mútua por fonte:** `GET_LOCK` do banco impede dois workers de rasparem a mesma fonte ao mesmo tempo; um pedido concorrente não executa nem gasta tentativas (será atendido pelo trabalho em andamento ou reenfileirado pelo scheduler). Fontes diferentes podem continuar em paralelo.
- **Importação concluída satisfaz pendências:** após uma raspagem bem-sucedida, outras solicitações que aguardem para a mesma fonte são marcadas como substituídas. Falhas mantêm o mecanismo de novas tentativas, e uma nova importação manual pode ser solicitada depois de um sucesso.
- **Exemplo:** se a fila ficar pausada por sete dias, o histórico registrará sete solicitações automáticas por UID, mas só a mais recente abrirá a URL; as seis mais antigas serão identificadas como substituídas. Se o scheduler também estiver desligado, não são criados agendamentos retroativos de dias anteriores.
- A consolidação usa o **UID da fonte**, e não exclusivamente a URL textual: fontes/categorias distintas podem precisar de atualizações separadas mesmo quando compartilham endereço semelhante.

## Política de repetição

- Erros HTTP/HTML, páginas repetidas ou extrações vazias fazem a tarefa falhar.
- Cada falha fica registrada; a próxima tentativa automática é agendada para o início da próxima hora, até `max_attempts=5`.
- O usuário pode reprocessar uma categoria pelo frontend. O reprocessamento manual gera trabalho novo, com novo log, sem apagar os registros anteriores.
- Somente extração bem-sucedida com pelo menos um produto substitui, em transação, o conjunto de produtos daquela categoria. Falha preserva dados históricos do catálogo.
- Não force raspagens muito frequentes ou em concorrência excessiva: respeite os termos dos supermercados, taxas de requisição e respostas HTTP de limitação de acesso.

## Gerenciar URLs no painel (versão pública)

A página **Importações → Fontes e desempenho** permite criar, editar, ativar, desativar e excluir URLs sem edição manual do SQL. As operações usam `POST /api/sources`, `PUT /api/sources/{uid}` e `DELETE /api/sources/{uid}`, todas protegidas por sessão e CSRF. O mercado é escolhido entre os raspadores implementados; a categoria e a URL são editáveis, mas **não é possível trocar o mercado de uma fonte existente**: crie outra fonte quando necessário.

Todas as URLs devem ser HTTPS, possuir host autorizado por `SCRAPE_HOSTS` e pelo `ScraperFactory`, e não podem conter credenciais ou porta personalizada. Não é permitido cadastrar a mesma categoria ou URL duas vezes para um supermercado. Alterações e desativações preservam os produtos existentes; a desativação substitui apenas tarefas ainda pendentes. A exclusão física só é permitida quando não existe histórico em `scrape_jobs`, de forma a não perder auditoria.

A listagem de supermercados usada nos filtros de compra considera se há **fonte ativa, produto em catálogo ou item na lista**. Por isso, um supermercado sem configuração nem dados não ocupa espaço na interface de compras, mas continua disponível no painel para configuração.
