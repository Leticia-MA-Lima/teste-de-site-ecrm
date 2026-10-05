# Painel privado eCRM360 — GA4

Primeira etapa do guia de 05/10/2026. Não faz consultas nem escritas no CRM e não modifica as quatro landing pages. O login pessoal Google não é usado como autenticação da API.

## Estrutura

- `server/`: coletor CLI, cliente OAuth/GA4 e cache; instalar em `/opt/ecrm360-analytics`, fora do DocumentRoot.
- `public/`: página com três visões, mapa local do IBGE e API de leitura; instalar em `/var/www/html/kuma-painel/growth/analytics`.
- `deploy/`: exemplos de Apache e cron, adaptar ao host existente.
- Configuração privada: `/etc/ecrm360-analytics/config.php`.
- Credencial privada: `/etc/ecrm360-analytics/service-account.json`.
- Cache: `/var/cache/ecrm360-analytics`, não usar o banco do CRM ou o SQLite dos contatos do protótipo.

## Instalação após inventário do servidor

1. Confirmar PHP 8.3, cURL, OpenSSL, Apache, usuário/grupo web, vhost e proteção do painel Kuma. Criar usuário de serviço `analytics`; permitir escrita somente no diretório de cache, grupo web com leitura. Diretórios privados 0750, arquivos 0640. A chave Google deve ser legível apenas pelo coletor (0600).
2. Habilitar Google Analytics Data API no projeto Cloud. Criar/reutilizar uma conta de serviço e conceder somente **Leitor** na propriedade `557146931`. A criação de credencial e concessão de acesso precisam de aprovação específica; não enviar chave em chat nem Git.
3. Copiar `server/config.example.php` para o caminho privado e ajustar nomes/caminhos. ID de medição confirmado: `G-VYQN0XPR6C`; ID da propriedade: `557146931`. Manter chaves fora da raiz pública.
4. Copiar os arquivos `server/` e `public/` para as pastas propostas. Não tornar `server/` público e não instalar o worker antigo.
5. Proteger **página, recursos e API** com a autenticação existente ou com o exemplo Apache em HTTPS. A API exige `REMOTE_USER` e falha com 403 sem ele. Se o Kuma usar outro mecanismo autenticado, adaptar esse requisito após revisão; não removê-lo para liberar acesso público.
6. Testar o coletor manualmente e confirmar títulos reais das páginas. Preencher `page_titles` com título → LP; desconhecidos ficam em Outras páginas. Confirmar fuso da propriedade como America/Sao_Paulo e tráfego dos quatro domínios. O mapa mantém o ranking sem bolhas até existir catálogo verificado de cidades.
7. Agendar **um** coletor por minuto, conforme exemplo. Relatórios diários são renovados a cada 15 minutos. As TVs leem o mesmo cache a cada 30 segundos; não fazem consultas ao Google.
8. Abrir a URL privada de teste. Visões rotacionam a cada 45 segundos; `?view=0`, `1` ou `2` fixa uma visão. Só depois da validação incluir a rota no ciclo existente do Kuma. Não modificar os Raspberry nesta etapa.

## Contrato e operação

`GET api.php` entrega `status`, `source`, `generated_at` UTC, `timezone`, `window_minutes`, `active_30m`, `active_5m`, `events_30m`, `pages_30m`, `cities_br`, `activity_30m` e `daily`.

Usuários distintos são consultados sem dimensões. Rankings de cidades não são somados para obter totais. Atividade é contagem de eventos, não usuários. `whatsapp_click` mede clique, não mensagem; `generate_lead` conta o sucesso registrado pelas LPs, não venda ou criação de cadastro no CRM. Relatórios com erro não são convertidos em zero. API responde 503 sem cache; cache antigo permanece disponível com `stale` após 180 segundos. Resumo diário de outro dia não é mostrado como hoje.

O cache é gravado por renomeação atômica sob lock. Token é reutilizado até perto do vencimento. Erros 429/5xx e rede usam até três tentativas com backoff e jitter. Erros 4xx não transitórios encerram a consulta. Paginação dos relatórios diários e verificação de compatibilidade evitam consultas incompletas. Resposta em tempo real acima de 1000 linhas é rejeitada, preservando o cache anterior, em vez de truncar silenciosamente.

Logs registram apenas fonte/status/código HTTP. Sem chave, token, payload OAuth ou dados pessoais. O painel usa dados agregados e `textContent`, sem `innerHTML` com conteúdo externo.

## Diagnóstico e rollback

403 Google: conferir API habilitada e acesso Leitor da conta de serviço. 400: conferir esquema, dimensões e compatibilidade. 429: revisar frequência/quota. 403 na API privada: conferir autenticação Apache e `REMOTE_USER`. Falha inicial mostra indisponibilidade; depois de coleta válida mantém a última leitura com aviso de atraso.

Antes da instalação, guardar backup da rota/vhost/cron modificados. Para rollback, remover somente o cron Analytics e o Alias/rota novos, restaurar o vhost e manter o cache para diagnóstico. Não reativar o worker CRM. Credenciais devem ser renovadas pelo responsável Cloud; atualizar o arquivo privado e invalidar apenas o cache OAuth, sem apagar dados do painel.

## Validação disponível e pendências

`php tests/analytics.php` testa contratos, zeros, contagem distinta, mapeamento, fuso, compatibilidade diária e cache (15 verificações). `php tests/analytics-http.php` testa quota 429, permissão 403 e resposta HTML com servidor local simulado. `node tests/analytics-browser.cjs` testa três visões, layout de TV, falha/recuperação e XSS com respostas explicitamente simuladas. Esses testes passaram em 05/10/2026; não validam acesso real ao GA4.

Ainda dependem da infraestrutura: credencial dedicada, consultas reais e mapa de títulos, repositório/vhost Kuma, autorização da rota, logo/fontes oficiais, catálogo de coordenadas e teste de um turno nas TVs reais. Não há números de demonstração na versão de produção. Não instalar em GitHub Pages: página e API são privadas e exigem PHP no servidor separado.

Referências: [Realtime GA4](https://developers.google.com/analytics/devguides/reporting/data/v1/realtime-api-schema), [OAuth servidor](https://developers.google.com/identity/protocols/oauth2/service-account), [Malhas IBGE](https://servicodados.ibge.gov.br/api/docs/malhas?versao=3).
