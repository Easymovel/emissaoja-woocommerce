# emissaoja NFS-e for WooCommerce (plugin WordPress)

Plugin oficial do [emissaoja](https://emissaoja.com.br/?origem=lib-wordpress) para WordPress e WooCommerce: emite a **NFS-e (nota fiscal de serviço eletrônica)** dos pedidos da loja no **Emissor Nacional** (nfse.gov.br), **sem certificado digital**, para **MEI** e empresas do **Simples Nacional**. Cria o tomador pelo CPF ou CNPJ do cliente, acompanha a emissão em segundo plano e deixa o PDF (DANFSe) e o XML a um clique, no pedido.

- Emissão manual (botão no pedido) ou automática, quando o pedido fica Processando ou Concluído.
- Acompanhamento com o Action Scheduler do WooCommerce, no ritmo de `proxima_consulta_ms`, sem atrasar o checkout.
- Sem nota em dobro: referência estável por pedido no `Idempotency-Key`; falhas temporárias repetidas com a mesma referência.
- Campo "CPF ou CNPJ para a nota fiscal" no checkout clássico e no checkout em blocos, ou os campos do Brazilian Market on WooCommerce.
- Compatível com HPOS e com o checkout em blocos. PHP 7.4 a 8.4, WordPress 6.3+, WooCommerce 8.0+.
- Sem dependências em produção: só a API HTTP do WordPress (`wp_remote_request`). A chave de API nunca vai ao navegador.

Slug no diretório do WordPress: `emissaoja-nfse` (Text Domain `emissaoja-nfse`).

## Instalação

- Pelo painel do WordPress, quando o plugin estiver no diretório: **Plugins > Adicionar novo**, busque por "emissaoja".
- A partir deste repositório: gere o zip do plugin (o `.gitattributes` deixa de fora testes, CI e arquivos de desenvolvimento) e envie em **Plugins > Adicionar novo > Enviar plugin**:

```bash
git archive --format=zip --prefix=emissaoja-nfse/ -o emissaoja-nfse.zip HEAD
```

## Teste no sandbox em 2 minutos

O sandbox é grátis e simula o Emissor Nacional: nada vai ao fisco.

1. Crie a conta em [sandbox.emissaoja.com.br](https://sandbox.emissaoja.com.br/) com o Google e aceite os termos.
2. Conecte a empresa de exemplo **`11111111000191`** (MEI pronto para emitir) com qualquer senha.
3. Em **Conta > API**, crie uma chave. No sandbox ela começa com `ej_test_`.
4. No WordPress, em **WooCommerce > Configurações > NFS-e (emissaoja)**: cole a chave, escolha o ambiente **Sandbox**, salve e clique em **Testar conexão**. Deve aparecer "Empresa conectada" e "Tudo certo para emitir".
5. Faça um pedido de teste com o CNPJ **`12345678000195`** (ou o CPF **`52998224725`**) no campo "CPF ou CNPJ para a nota fiscal".
6. Abra o pedido e clique em **Emitir NFS-e agora**. Em instantes a caixa "NFS-e (emissaoja)" mostra a nota autorizada, com número, chave de acesso e os botões **Baixar PDF** e **Baixar XML**.

Valores com significado no sandbox: R$ 13,13 deixa a nota **incerta** (resolva com **Conferir**), R$ 77,77 fica incerta e não sai, R$ 99,99 é **recusada** pelo fisco simulado.

## Em produção

1. Crie a conta em [emissaoja.com.br](https://emissaoja.com.br/?origem=lib-wordpress), aceite os termos e conecte a empresa com o CNPJ e a senha do Emissor Nacional.
2. Crie a chave em **Conta > API** (aparece uma vez só; começa com `ej_live_`).
3. Nas configurações do plugin, cole a chave e escolha **Produção**.

A chave é secreta: quem tem a chave emite em nome da empresa. Ela fica só no servidor (opção sem autoload), nunca é mostrada de novo inteira e nunca vai ao navegador. Para deixá-la fora do banco de dados, defina no `wp-config.php`:

```php
define( 'EMISSAOJA_NFSE_API_KEY', 'ej_live_...' );
```

## Configurações

| Opção | O que faz |
| --- | --- |
| Chave de API | Campo senha; a chave salva aparece mascarada (`ej_live_••••••••a1b2`). |
| Ambiente | Produção (`https://emissaoja.com.br`) ou Sandbox (`https://sandbox.emissaoja.com.br`). Botão **Testar conexão** (`GET /v1/status` e `GET /v1/empresa`). |
| Quando emitir | Só manualmente (padrão), quando o pedido for Concluído, ou quando for Processando (pago; pedidos que vão direto para Concluído também). |
| Valor da nota | Total do pedido ou total sem o frete, descontados os reembolsos. |
| Descrição da nota | Modelo com `{pedido}`, `{itens}` e `{loja}` (até 2.000 caracteres). Em branco, vale a descrição do serviço. |
| Serviço | Serviço principal da conta ou outro, carregado de `GET /v1/servicos`. |
| Aviso ao cliente | Nota do pedido para o cliente (e-mail do WooCommerce) com o número e o link da consulta pública, quando a NFS-e é autorizada. Nunca no sandbox. |
| CPF/CNPJ no checkout | Automático (só quando a loja não tem campos de CPF/CNPJ), sempre ou nunca. |
| Metas do documento | De onde ler o documento: `_billing_cpf`, `_billing_cnpj` e `_billing_persontype` (Brazilian Market on WooCommerce). |

## Como a emissão funciona

1. **Tomador:** `POST /v1/tomadores` com o documento (para CPF, também o nome do cliente; para CNPJ, nome e endereço vêm da Receita). O id do tomador fica guardado no pedido.
2. **Emissão:** `POST /v1/notas` com `Idempotency-Key: wc-<8 hex do md5 da URL do site>-<id do pedido>` (ex.: `wc-57f215b1-1234`). A referência é calculada uma vez e guardada no pedido.
3. **Acompanhamento:** ação `emissaoja_nfse_consultar` no Action Scheduler, que chama `GET /v1/emissoes/{id}` no ritmo de `proxima_consulta_ms` (entre 5 s e 5 min) até `autorizada`, `recusada` ou `incerta`, no máximo 40 vezes. Depois disso, o botão **Atualizar situação** continua.
4. **Resultado:** metas e notas internas no pedido; nota para o cliente quando autorizada (se ligado).

A emissão automática roda na ação assíncrona `emissaoja_nfse_emitir`. Por segurança, ela não acontece em Produção quando o site não está marcado como produção (`WP_ENVIRONMENT_TYPE`), como numa cópia de teste da loja.

Metas gravadas no pedido (pela API de pedidos do WooCommerce, compatível com HPOS):

| Meta | Conteúdo |
| --- | --- |
| `_emissaoja_pedido_id` | Id do pedido de emissão na API |
| `_emissaoja_estado` | `agendada`, `na_fila`, `emitindo`, `confirmando`, `autorizada`, `recusada`, `incerta` ou `erro` |
| `_emissaoja_numero` | Número da NFS-e |
| `_emissaoja_chave_acesso` | Chave de acesso |
| `_emissaoja_nota_origem`, `_emissaoja_nota_id` | Par que baixa o PDF e o XML |
| `_emissaoja_erro` | Último erro (mensagem da API, bloqueios e código) |

Também ficam `_emissaoja_referencia`, `_emissaoja_ambiente`, `_emissaoja_consulta_url` e outras de controle. A desinstalação apaga as opções do plugin, mas não as metas dos pedidos.

## Erros e o que o plugin faz

| `code` | O que acontece |
| --- | --- |
| `nota_bloqueada` (422) | Não emite; a nota interna lista os `bloqueios` (o que resolver no app do emissaoja). |
| `possivel_duplicada`, `emissao_repetida_recente` (409) | Não emite de novo sozinho; o pedido ganha o botão **Emitir mesmo assim** (`confirmo_repeticao` e `permitir_repetida_de`). |
| `fila_indisponivel`, `motor_indisponivel`, `receita_indisponivel` (503), `rate_limited` (429), rede, tempo esgotado | Nova tentativa automática com a **mesma referência** em 1, 2, 5, 15 e 30 minutos (ou o `Retry-After`). |
| `chave_idempotencia_conflito` (409) | A referência já criou um pedido de emissão com outros dados: o plugin passa a acompanhar esse pedido original. |
| `incerta_pendente` (409) | Não emite: já há uma nota igual sendo conferida. |
| `chave_invalida` (401), `limite_notas` (402), `sem_empresa`, `aceite_pendente`, `credencial_congelada` (409) | Não emite; aviso no painel do WordPress até ser resolvido ou dispensado. |
| `recusada` (estado) | O motivo vai para o pedido; **Emitir novamente** usa uma referência nova (`...-2`), porque a mesma devolveria o pedido recusado. |
| `incerta` (estado) | O botão **Conferir** chama `POST /v1/emissoes/{id}/conferir` e o acompanhamento recomeça. |

## Idempotência e nota em dobro

- Cada pedido tem uma referência estável, usada como `Idempotency-Key` em todas as tentativas. Repetir a mesma referência devolve o mesmo pedido de emissão (`repetido: true`) e nunca emite duas vezes.
- Em `503 fila_indisponivel`, o plugin repete com a **mesma** referência.
- Nota igual no mesmo dia (mesmo tomador, valor e competência) volta `409 possivel_duplicada` com `nota_igual_recente`; a mesma nota em menos de 2 minutos volta `409 emissao_repetida_recente`. O plugin para e só emite outra com o botão **Emitir mesmo assim**, que manda `confirmo_repeticao: true` e `permitir_repetida_de` com o pedido mostrado no aviso.
- Só depois de uma nota **recusada** o plugin usa uma referência nova (sufixo `-2`, `-3`...), e só pelo botão **Emitir novamente**.

## Perguntas frequentes

**Precisa de certificado digital?** Não. O emissaoja entra no Emissor Nacional com o CNPJ e a senha do portal.

**Funciona para MEI?** Sim, para MEI e para ME e EPP do Simples Nacional (ISS tributável ou não incidência).

**E se o cliente não informar CPF ou CNPJ?** O plugin não emite e explica no pedido. Preencha o documento no pedido e clique em **Emitir NFS-e agora**. Para pedir o documento a todos, torne o campo obrigatório com o filtro `emissaoja_nfse_campo_checkout`.

**Tem ambiente de testes?** Sim: o sandbox (`https://sandbox.emissaoja.com.br`), grátis, com chaves `ej_test_`.

**Quanto custa?** O plano Grátis tem 1 nota por mês (nota recusada não conta). Os planos estão em [emissaoja.com.br/#precos](https://emissaoja.com.br/#precos).

**Tem webhook?** Ainda não: o plugin acompanha cada nota com `GET /v1/emissoes/{id}`, no ritmo de `proxima_consulta_ms`.

## Para desenvolvedores

Filtros:

| Filtro | Para quê |
| --- | --- |
| `emissaoja_nfse_campos_nota` | Campos de `POST /v1/notas` (array, snake_case) |
| `emissaoja_nfse_descricao`, `emissaoja_nfse_valor` | Descrição e valor da nota |
| `emissaoja_nfse_documento` | Documento do tomador: array com `tipo` (`pf` ou `pj`) e `numero` (só dígitos), ou `null` |
| `emissaoja_nfse_campo_checkout` | Campo do checkout clássico (por exemplo, `required => true`) |
| `emissaoja_nfse_registrar_campo_blocos` | Registrar o campo no checkout em blocos |
| `emissaoja_nfse_emissao_automatica` | Liberar ou impedir a emissão automática de um pedido |
| `emissaoja_nfse_base_url` | URL base da API (desenvolvimento) |

Testes e padrões (as dependências de desenvolvimento ficam no `composer.json`; o plugin não usa Composer em produção):

```bash
composer install
vendor/bin/phpunit   # funções puras: CPF/CNPJ, referência, descrição, valor, intervalos
vendor/bin/phpcs     # WordPress-Extra + PHPCompatibilityWP (PHP 7.4+)
```

O CI (`.github/workflows/ci.yml`) roda `php -l` e o PHPUnit em PHP 7.4, 8.0, 8.1, 8.2, 8.3 e 8.4, e o phpcs (WordPress-Extra).

## Links

- Documentação da API: [emissaoja.com.br/docs](https://emissaoja.com.br/docs/) · Markdown: [docs.md](https://emissaoja.com.br/docs.md)
- OpenAPI 3.1: [emissaoja.com.br/v1/openapi.json](https://emissaoja.com.br/v1/openapi.json)
- Para IAs: [llms.txt](https://emissaoja.com.br/llms.txt) · MCP: `https://emissaoja.com.br/mcp`
- Status da API: [emissaoja.com.br/v1/status](https://emissaoja.com.br/v1/status)
- Outras bibliotecas oficiais: npm [`emissaoja`](https://www.npmjs.com/package/emissaoja), PyPI [`emissaoja`](https://pypi.org/project/emissaoja/), Packagist [`emissaoja/emissaoja`](https://packagist.org/packages/emissaoja/emissaoja), NuGet [`Emissaoja`](https://www.nuget.org/packages/Emissaoja), Go `emissaoja.com.br/go`, RubyGems [`emissaoja`](https://rubygems.org/gems/emissaoja), n8n [`n8n-nodes-emissaoja`](https://www.npmjs.com/package/n8n-nodes-emissaoja)
- Suporte: [suporte@emissaoja.com.br](mailto:suporte@emissaoja.com.br) · Problemas e sugestões: [issues](https://github.com/Easymovel/emissaoja-woocommerce/issues)

## In English

Official WordPress/WooCommerce plugin for [emissaoja](https://emissaoja.com.br/?origem=lib-wordpress): issues Brazilian **NFS-e** (electronic service invoices) for WooCommerce orders through the national issuer (Emissor Nacional, nfse.gov.br), **without a digital certificate**, for MEI and Simples Nacional companies. Manual or automatic issuing, background status tracking with Action Scheduler, idempotent requests (one stable `Idempotency-Key` per order), PDF/XML downloads proxied by the server, a CPF/CNPJ field for the classic and block-based checkout, HPOS compatible. Try it for free in the sandbox (`https://sandbox.emissaoja.com.br`).

## Licença

GPL-2.0-or-later (exigência do diretório de plugins do WordPress). Copyright (c) 2026 BINOBO LTDA. Veja [LICENSE](LICENSE).

O emissaoja é um serviço privado e não tem vínculo com a Receita Federal nem com o Emissor Nacional.
