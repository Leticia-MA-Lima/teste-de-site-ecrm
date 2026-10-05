# Ambiente de testes — 01/10/2026

**Estado atualizado em 05/10/2026:** agendamento `/etc/cron.d/ecrm-lead-test` removido para backup privado em `/var/tmp/ecrm-sync-disabled-20261005`, configuração `dry_run=true`, SQLite preservado. Não há sincronização automática com o CRM. O painel GA4 em `analytics/` ainda depende do servidor Kuma e da conta de serviço; as referências de sincronização abaixo são históricas.

URL: https://tst.snet.app.plataformadecrm.com.br/lead-test/

- Site e coletor: `/var/www/html/eCRM360/Snet/eCRM360evo/lead-test`.
- Configuração privada: `/etc/ecrm-tracking/config.php` (root:apache, 0640).
- Banco privado: `/var/lib/ecrm-lead-test/events.sqlite`.
- Worker: `/etc/cron.d/ecrm-lead-test`, a cada minuto, usuário apache.
- Sincronização real habilitada, responsável `19x27` (Integrador Make).
- Pontos: visita 1, clique 2, início de formulário 3, campo 1, envio 20. Envio válido qualifica automaticamente.
- Bolha de teste usa o número pessoal fornecido, `5511997831059`. Trocar antes de reutilizar em produção.
- Página sinalizada como `noindex, nofollow`.

## Validação

14 cenários de pontuação/fila passaram; 3 cenários de confirmação após resposta incerta passaram. Os formulários de contato e WhatsApp foram verificados em navegador, inclusive no site instalado, com respostas simuladas para evitar cadastros adicionais. Layout móvel inspecionado visualmente.

O teste real confirmou o lead `10x65752`, status `Pré qualificado`, pontuação atualizada de 26 para 28 na descrição, preservando o cadastro ao repetir o envio.

O erro de serialização do CRM foi contornado com confirmação por consultas projetadas, sem alterar arquivos centrais. A alteração em `DataTransform.php` foi expressamente recusada pelo usuário e não foi executada.

## Limites e registros técnicos

A deduplicação alcança somente os leads visíveis para a credencial. Cadastros atribuídos a `19x21` não aparecem nas consultas desta credencial. Para deduplicar todo o CRM ou mudar o responsável, é necessário fornecer uma credencial com o acesso correspondente, sem modificar código central.

As tentativas anteriores à confirmação de permissões deixaram os registros técnicos `65750` e `65751`, atribuídos a `19x21`; não são contatos reais. Os respectivos envios permanecem em revisão na fila, sem reenvio automático. Uma pessoa com acesso a esses registros poderá conciliá-los/removê-los. O registro `65752` é o teste final validado.

Clique em agenda não confirma reserva. O formulário da bolha registra o contato e oferece um link para WhatsApp; mensagem efetivamente enviada não é confirmada.

As alterações são versionadas neste repositório. Credenciais não estão incluídas nos arquivos.

## Correção do formulário — 05/10/2026

O GitHub Pages não executa PHP. O endereço do coletor passou a apontar explicitamente para o servidor de testes, com a origem `https://leticia-ma-lima.github.io` autorizada na configuração privada do coletor. A chave do CRM permanece somente no servidor. Ao importar para outro domínio, autorize a nova origem nessa configuração.

O formulário trata respostas vazias/HTML e só confirma sucesso após `accepted: true`. Tentativas automáticas e manuais preservam o ID do envio. Testes em Chromium e WebKit cobrem recuperação após resposta HTML temporária, erro vazio e nova tentativa, além dos dois formulários.

A publicação foi verificada no GitHub Pages. Um envio real no WebKit recebeu HTTP 200 e gerou o lead técnico `10x65761`, com 29 pontos e fila `synced`. O contato está identificado como teste e usa e-mail `example.invalid`. O teste dos dois formulários com respostas simuladas também passou na versão publicada.
