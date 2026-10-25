# Segurança — ChupaCabra 2.0

- O legado tinha senha, usuário e chaves AES literais dentro de `ImportModel.php`. Rotacione as senhas antigas; não replique chaves nem tokens no novo repositório.
- A autenticação nova recebe um JSON `usuário -> password_hash(ARGON2ID)` codificado em base64 como `AUTH_USERS_B64` no `.env`. **Base64 não é cifragem**: a senha real está apenas representada pelo hash Argon2id, que é unidirecional.
- Senhas em texto claro nunca são registradas em log nem aceitas como variáveis de ambiente. O gerador pergunta a senha sem eco no terminal TTY.
- Sessões utilizam identificadores de 256 bits, armazenados por hash SHA-256 no Redis com TTL padrão de 30 dias, configurável por `SESSION_LIFETIME_DAYS` (1 a 365 dias). Com `SESSION_RENEW_ON_ACTIVITY=1`, cada acesso autenticado renova tanto a expiração no Redis quanto o cookie do navegador; depois de 30 dias sem uso, a sessão expira. Cookie `HttpOnly`, `SameSite=Lax` e `Secure=1` para produção. POST/PUT/PATCH/DELETE autenticados exigem também token CSRF.
- A API fica no mesmo domínio do frontend, sem CORS. Login tem limite de tentativas por origem e conta. Todas as consultas e edições usam prepared statements PDO.
- O diretório PHP não é servido como arquivos estáticos. Nginx envia somente `/api/` ao PHP-FPM. Produtos do site externo são exibidos com interpolação escapada pelo Vue.
- O Redis não publica porta no host. Na implantação privada o MariaDB pode expor a porta 3308 para HeidiSQL, mas `DB_BIND_IP` deve ser restrito ao loopback/LAN com firewall. O Compose público portátil não expõe o banco nem o Redis; publica somente HTTP em `127.0.0.1`. A implantação privada possui Caddy e túnel configurados pelo operador.
- Segurança na raspagem: domínios de destino são **explicitamente allowlisted** em `SCRAPE_HOSTS`; não são aceitos caminhos a hosts arbitrários; o fetcher valida o host em cada redirecionamento e mantém verificação TLS. Não implemente cadastro de URLs livres sem revisar SSRF, DNS, redirects e IPs internos.
- Configure firewall e Cloudflare Access/restrições adicionais caso o domínio esteja público. Senhas fortes e sessões não substituem TLS nem controle de acessibilidade.
- Atualize PHP e bibliotecas regularmente; `composer audit` roda durante build. Faça também auditoria do frontend e monitore novas versões críticas.
- Não publique `backend/docker/production/database/legacy-data.sql`: contém a lista de compras e o catálogo atual do usuário. O dump original também continha sessões e tokens, intencionalmente descartados desta versão.

- O PWA instala um service worker que **nunca intercepta ou armazena** as respostas `/api/`; seu cache contém somente assets públicos com hash, a arte pública e a página de aviso offline. A lista depende da conexão.
- O contexto de build do backend exclui o diretório de dados persistentes e o dump privado `legacy-data.sql` via `.dockerignore`.

- O painel de fontes permite CRUD somente para supermercados com scraper implementado e URLs HTTPS em domínios autorizados. Fontes com histórico de processamento não podem ser excluídas; desative-as. Antes de tornar o Git público, revise também arquivos antigos no histórico Git, não apenas a árvore atual.
