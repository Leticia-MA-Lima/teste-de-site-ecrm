# Migração para monitoramento independente — 05/10/2026

## Diretriz recebida

O coletor não deve criar ou alterar leads no CRM de testes. Os eventos devem alimentar armazenamento independente e uma tela integrada. Dados existentes são preservados; a configuração central do CRM não é alterada.

## Documentos analisados

- Especificação Técnica do Painel de Desempenho de Campanhas, versão 1.0, 02/10/2026: banco de métricas no servidor SNet, quatro telas no Kuma, integrações Meta/WhatsApp/Central de API/CRM. Lead scoring e Mautic ficam fora da primeira fase.
- Guia de monitoramento das landing pages, 05/10/2026: primeira entrega com Google Analytics 4, cache privado compartilhado e três telas de 45 segundos. Meta, WhatsApp Cloud e CRM ficam para etapa futura. Não modificar pixels, endpoints ou formulários das quatro LPs existentes.

Os documentos têm escopos distintos. A escolha da primeira entrega precisa ser confirmada antes de substituir o coletor ou integrar o painel existente.

## Fluxo proposto para a etapa GA4

GA4 → coletor PHP autenticado no servidor → cache privado → API interna protegida → painel Kuma/TV.

O coletor roda uma vez por minuto, independente da quantidade de TVs. A página consulta somente a API interna a cada 30 segundos. O browser não recebe chave, token, nome, telefone ou e-mail.

As três telas apresentam Brasil/cidades, jornada das landing pages e resumo diário/origens. Totais de usuários vêm de consultas sem dimensões; não somar usuários por cidade, página ou minuto. Dados ausentes não são apresentados como zero. Em falha, preservar o último cache e indicar atraso.

O ID de medição informado no guia não é o ID numérico da propriedade. O mapeamento de títulos das LPs deve ser confirmado em consulta real. Cidades sem coordenadas verificadas permanecem no ranking, sem posição inventada no mapa.

## Impactos no código existente

- Desativar a execução automática de `tracking/worker.php` e qualquer criação/revisão de Leads.
- Preservar o SQLite do teste; ele contém histórico técnico e contatos, não é uma fonte GA4.
- Retirar do fluxo ativo pontuação e qualificação automática caso a primeira fase siga o guia. Eventos de contato, clique no WhatsApp e envio aceito são métricas distintas.
- Manter o teste estático separado das quatro LPs já instrumentadas. Não importar seu handler de formulário para essas LPs.
- Implementar coletor GA4, cache e painel em diretório/rota novos após inventário do servidor Kuma.
- Não ativar APIs externas ou exibir números simulados como reais para suprir credenciais ausentes.

## Informações necessárias

1. Documento prioritário e confirmação do alcance da integração com CRM/Central de API.
2. Acesso ao servidor Kuma/SNet, repositório do painel atual, DocumentRoot, usuário PHP e proteção de acesso existente.
3. ID numérico da propriedade GA4 e caminho de credencial dedicada com permissão de leitura no servidor; não incluir a chave no Git.
4. Logo oficial, fontes locais e mapa do Brasil com fonte/licença. Catálogo de cidades é opcional para iniciar com mapa e ranking sem bolhas.

## Inventário GA4 confirmado em 05/10/2026

Consulta somente de leitura pela interface autenticada do Analytics:

- Conta: Snet | eCRM360 (`406526245`).
- Propriedade: eCRM360 — Landing Page (`557146931`).
- Fluxo web: Revisional GPT (`15949217117`), URL `https://crm-revisional.ecrm360.com.br`.
- ID de medição: `G-VYQN0XPR6C` (o caractere depois de QN é o número zero).
- O fluxo informou coleta ativa nas últimas 48 horas; a página inicial apresentou atividade em tempo real.
- O acesso atual exibiu controles de alteração do fluxo desabilitados. Nenhuma configuração ou permissão foi alterada.

A credencial de servidor ainda não foi configurada. O login pessoal não substitui a autenticação própria do coletor. A existência de tráfego nas outras três LPs ainda precisa ser confirmada; um único fluxo pode receber eventos de múltiplos domínios.

## Verificações para a entrega

Consultas reais compatíveis com o GA4, eventos de sucesso sem contar formulários com erro, cache atômico compartilhado, preservação após falhas, rota privada sem segredos, três telas sem cortes em 1920×1080 e 1366×768, integração com um ciclo completo do painel existente e validação posterior nas TVs/Raspberry reais.
