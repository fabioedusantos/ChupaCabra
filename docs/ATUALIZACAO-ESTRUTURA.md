# Infraestrutura e atualização entre versões

O ChupaCabra possui **dois modos de implantação**: o portátil (`compose.standalone.yaml`), indicado para quem clonou o GitHub, e a instalação privada original (`compose.yaml`), que depende de rede LAN externa e Cloudflare Tunnel.

## Instalação nova a partir do GitHub

Siga o [README](../README.md). **Não** crie manualmente redes Docker externas, certificados ou túneis Cloudflare: o arquivo Compose portátil contém somente os serviços necessários. Os dados ficam em `backend/docker/production/data/` dentro da raiz clonada.

## Atualização da instalação privada

1. Faça backup consistente de `.env`, MariaDB, Redis e certificados. Pare os serviços antes de mover arquivos do banco (`docker compose down`, **sem `-v`**).
2. Atualize somente os arquivos de código/documentação; mantenha `.env` e todas as pastas `data/` particulares.
3. Caso sua instalação antiga ainda guarde dados em `./docker/data/{db,redis}`, mova-os **com os contêineres parados** para `./backend/docker/production/data/{db,redis}`. Confira se os diretórios destino estão vazios antes de mover; não sobrescreva um banco já existente. Preserve permissões/UID do Docker.
4. Revise os caminhos da persistência no Compose e `.env`: `CADDY_DATA_DIR` e `CADDY_CONFIG_DIR` devem continuar apontando para as pastas de dados do Caddy dentro da raiz do projeto.
5. A rede externa `orangepi-lan` e as reservas de IP/MAC são particulares dessa implantação: **não são recriadas automaticamente**. Mantenha os valores corretos do `.env` existente; não copie os valores ilustrativos de `.env.example` para produção.
6. Aplique `docker compose up -d --build` e confira `docker compose ps` e `docker compose logs --tail=100 migrate api worker scheduler`.

Não há um script automático de migração de diretórios incluído nesta versão. A migração de dados entre versões antigas exige conferência dos caminhos reais antes de mover qualquer coisa.

| Local antigo (se existir) | Local atual |
| --- | --- |
| `./docker/data/db` | `./backend/docker/production/data/db` |
| `./docker/data/redis` | `./backend/docker/production/data/redis` |
| `./backend/Dockerfile` | `./backend/docker/production/Dockerfile` |
| `./web/Dockerfile` | `./web/docker/production/Dockerfile` |
| `./caddy/Caddyfile` | `./web/docker/production/caddy/Caddyfile` |

**Não** execute `docker compose down -v`, não apague `./backend/docker/production/data/` e nunca versione dumps privados. Uma cópia fria consistente ou um dump restaurável é indispensável para uma migração segura.
