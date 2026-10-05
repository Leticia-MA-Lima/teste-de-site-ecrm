# Teste estático eCRM — rastreamento e integração central

**Diretriz atual (05/10/2026):** o monitoramento é independente do CRM. O novo projeto está em [analytics/README.md](analytics/README.md): coleta GA4, cache privado e três telas para o Kuma. A sincronização do teste com o CRM foi desativada no servidor e o código exige habilitação explícita para qualquer retomada. As seções abaixo documentam o protótipo anterior; não seguir suas instruções de sincronização para instalar o painel.

O site estático usa `js/ecrm-tracking.js` para enviar eventos ao coletor PHP. A credencial do CRM fica exclusivamente no servidor. Contact Form 7 não é carregado nesta exportação: o envio é validado pelo navegador e pelo PHP, sem depender do WordPress original.

## Pontuação inicial configurável

| Evento | Pontos | Limite |
|---|---:|---|
| Visita | 1 | Uma por sessão da aba |
| Clique | 2 | Um por categoria/sessão: contato, agendamento, WhatsApp, navegação, CTA |
| Início de formulário | 3 | Um por formulário/visitante |
| Campo preenchido | 1 | Um por campo/formulário/visitante |
| Envio válido | 20 | Um por formulário/visitante |

O envio válido qualifica automaticamente, independente da pontuação. Visitas/cliques não criam leads anônimos no CRM. Campos são contados sem transmitir seus valores; nome, e-mail e demais dados só seguem no envio. O select pré-preenchido só pontua quando o visitante o altera. Há teto de 100 eventos por visitante/dia e 120 requisições/IP/minuto (IP persistido apenas como HMAC temporário). Pontos são sinais de interesse, não comprovação de identidade; eventos do navegador podem ser simulados.

## Instalação PHP

1. Servir o repositório em HTTPS com PHP 8.1+ e extensões PDO SQLite e cURL; banco/configuração ficam fora da raiz pública.
2. Copiar `tracking/config.example.php` para `/etc/ecrm-tracking/config.php`. Substituir o segredo, preencher token, origens permitidas, sites e responsável. Liberar escrita do usuário PHP em `/var/lib/ecrm-tracking`, com acesso restrito. Outra localização pode ser indicada pela variável `ECRM_TRACKING_CONFIG`.
3. Confirmar pela operação `describe` os campos customizados, o usuário atribuído e os valores de `leadsource`, `leadstatus` e `rating`. `score_field` só deve ser preenchido com um campo numérico existente. Sem ele, pontuação fica no banco e no texto da descrição do CRM.
4. Ajustar `js/ecrm-config.js`: coletor (se outro domínio) e número WhatsApp com código do país. A bolha fica desativada até informar o número. O formulário da bolha registra o contato e oferece link para continuar no WhatsApp; não confirma mensagem enviada. Links de agenda apenas registram clique.
5. Testar primeiro com `dry_run => true`. Executar `php tests/tracking.php` e `php tracking/worker.php`. A resposta do formulário confirma recebimento no coletor, não sincronização com CRM.
6. Para sincronização real, definir `dry_run => false` e agendar o worker a cada minuto como usuário com acesso ao banco/configuração: `* * * * * /usr/bin/php /CAMINHO/tracking/worker.php`. O worker mantém retenção de 30 dias e processa até 50 itens por execução.

## Fila e duplicidade

O coletor salva eventos e o contato de forma transacional. IDs repetidos não somam pontos nem duplicam envios. O worker serializa as sincronizações e procura Leads por e-mail antes de criar; usa `revise` ao encontrar um único lead. Preserva responsável, rating, status comercial e anotações existentes, atualiza dados de contato e origem, e mantém um bloco próprio na descrição com a pontuação. Campos opcionais vazios não apagam empresa/telefone existentes. Mais de um cadastro com o mesmo e-mail exige conciliação. Visitantes diferentes com o mesmo e-mail são associados ao cadastro encontrado; suas pontuações permanecem separadas, sem soma entre dispositivos.

O ambiente inspecionado aceita `Web Site`/`Formulário WhatsApp` como origem, `Pré qualificado` como status e `Ativo` como rating; `Novo` e `Hot` do exemplo inicial não são valores válidos dessas listas. O cliente usa o cabeçalho `corebos-authorization` porque o servidor descarta o cabeçalho com underscore; o código do CRM aceita ambas as grafias.

Neste teste o responsável é `19x27` (Integrador Make), usuário da credencial atual. Essa credencial não consegue consultar os leads atribuídos a `19x21`, o que impede confirmação e deduplicação. Para trocar o responsável, usar credencial que consiga consultar os cadastros atribuídos a ele; o coletor não altera permissões do CRM.

O CRM atual pode gravar um cadastro e falhar com `TypeError` ao serializar a resposta completa. Sem modificar seu código central, o coletor consulta somente os campos enviados após uma resposta incerta e compara **todos** os valores, inclusive o bloco de descrição com UUID do visitante. Somente um registro único e idêntico confirma a escrita. Não há segunda criação automática; ausência, falta de acesso, divergência ou duplicidade mantém o item em revisão. Essa estratégia também cobre respostas perdidas por timeout.

Falhas de consulta podem ser repetidas até cinco tentativas. Escritas que não puderam ser confirmadas ou processamento interrompido ficam em `review`, para evitar duplicar um lead quando o CRM pode ter recebido a requisição. Consultar `outbox.state/error` no banco privado; reconciliar o cadastro pelo e-mail e preencher `visitors.crm_id` antes de reenfileirar. Não existe painel público nem endpoint de leitura de dados pessoais.

O identificador local vale por navegador/site e não acompanha a mesma pessoa entre domínios ou dispositivos. Bloqueadores e limpeza do armazenamento limitam o histórico. O armazenamento atual dispara na entrada: integrar ao mecanismo de consentimento da empresa antes de expandir para sites públicos, se essa for a política definida.

## Testes

`php tests/tracking.php` cobre pontuação, duplicidade, validação, qualificação, fila em simulação, isolamento entre visitantes, atualização posterior e interrupção do worker. `php tests/api-fallback.php` cobre confirmação após resposta HTML, atualização e rejeição de registros divergentes. `node tests/browser.cjs` testa os formulários, dados de eventos, link WhatsApp e layout móvel com coletor simulado (requer Playwright e Edge; `BROWSER_CHANNEL` permite outro navegador). `node --check js/ecrm-tracking.js` verifica sintaxe do cliente. Não incluir credenciais em commits ou no JavaScript.
