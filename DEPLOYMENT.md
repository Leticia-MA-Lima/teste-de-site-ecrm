# Ambiente de testes — 01/10/2026

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
