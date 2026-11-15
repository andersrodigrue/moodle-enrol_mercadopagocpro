# Desenvolvimento seguro — Moodle enrol_mercadopagocpro

## Origem e estado

Base: ZIP `enrol_mercadopagocpro_2026091800.zip`, componente `enrol_mercadopagocpro`, versao declarada 1.1.1.
A fonte original foi preservada no primeiro commit do historico local.
Esta ramificacao e **experimental** e nao deve processar pagamentos reais.

## Mudancas preparadas

1. Em configuracoes que exigem assinatura, tratar `SIG_SKIPPED` como falha (HTTP 401).
2. Rejeitar moeda ausente e pagamento inferior ao valor acordado, comparando centavos.
3. Adicionar testes de regressao para os dois controles.

**SEC-01 (desenvolvimento):** eliminado o fallback para a ultima transacao pendente. Pagamentos novos sao consultados pela API usando a instancia do webhook e vinculados exclusivamente pela `external_reference` retornada. A tabela de auditoria conserva `enrolid` para notificacoes adiadas. Nao instalar em producao sem PHPUnit e testes reais de integracao.

## Pendencias obrigatorias antes de producao

- Executar testes PHPUnit dentro de uma instalacao Moodle compativel.
- Auditar resolucao de `external_reference` para notificacoes sem transacao local previamente associada.
- Revisar particularidades de moedas com quantidades de casas decimais diferentes.
- Testar SDK e APIs no Moodle 5.3 / PHP 8.3; atualizar `supported` somente apos homologacao.
- Testar concorrencia, idempotencia, reembolsos e reconciliacao.
- Configurar webhooks assinados e uma conta Mercado Pago de teste; nao incluir credenciais no repositorio.
- Revisar licenca GPL-3.0-or-later e preservar atribuicoes do autor original.

## Checklist de testes

- `php -l` em todos os arquivos PHP do plugin.
- PHPUnit: `webhook_handler_test`, `payment_processor_test`.
- Webhook assinatura valida, invalida, ausente ou nao verificavel.
- Pagamento BRL correto, um centavo a menos, moeda ausente ou divergente.
- Notificacao repetida e dois compradores concorrentes.
- Pagamento pendente, aprovado, estornado, e conciliacao sem retorno do navegador.
