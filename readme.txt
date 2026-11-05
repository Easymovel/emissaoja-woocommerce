=== emissaoja NFS-e for WooCommerce ===
Contributors: emissaoja
Tags: nfse, nota fiscal, woocommerce, mei, simples nacional
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Emita NFS-e no Emissor Nacional para os pedidos do WooCommerce, sem certificado digital. Para MEI e Simples Nacional, pela API do emissaoja.

== Description ==

O **emissaoja NFS-e for WooCommerce** emite a **NFS-e (nota fiscal de serviço eletrônica)** dos pedidos da sua loja no **Emissor Nacional** (nfse.gov.br), **sem certificado digital**. Quem fala com o portal é o [emissaoja](https://emissaoja.com.br/?origem=lib-wordpress), com o CNPJ e a senha do Emissor Nacional da empresa. Feito para **MEI** e empresas do **Simples Nacional** que vendem serviços pelo WooCommerce: cursos, consultorias, mentorias, assinaturas, serviços técnicos.

= Como funciona =

1. O cliente compra e informa o CPF ou o CNPJ no checkout (campos do Brazilian Market on WooCommerce ou o campo do próprio plugin).
2. Você clica em **Emitir NFS-e agora** no pedido, ou deixa o plugin emitir quando o pedido fica Processando ou Concluído.
3. O plugin cria o tomador pelo documento (com CNPJ, o nome e o endereço vêm da Receita Federal), pede a emissão ao emissaoja e acompanha em segundo plano até a nota ser autorizada, recusada ou ficar incerta.
4. O pedido mostra o número, a chave de acesso e o link da consulta pública, com botões para baixar o PDF (DANFSe) e o XML. O cliente pode receber uma nota do pedido, por e-mail, com o número e o link.

= Recursos =

* Emissão manual (botão no pedido) ou automática, quando o pedido fica Processando ou Concluído. O padrão é manual.
* Acompanhamento em segundo plano com o Action Scheduler (que vem com o WooCommerce), no ritmo que a API pede, sem atrasar o checkout.
* Nota em dobro evitada: cada pedido tem uma referência única (Idempotency-Key). Falhas temporárias são repetidas com a mesma referência, e avisos de nota repetida nunca são ignorados sozinhos.
* Bloqueios da nota (cadastro incompleto, alíquota que falta...) aparecem nas notas do pedido, com o que resolver.
* Situação incerta resolvida pelo botão **Conferir**, que confere no portal sem emitir de novo.
* PDF e XML baixados pelo painel: a chave de API nunca vai ao navegador.
* Coluna **NFS-e** na lista de pedidos.
* Valor da nota: total do pedido ou total sem o frete, descontados os reembolsos.
* Descrição com marcadores: `{pedido}`, `{itens}` e `{loja}`.
* Escolha do serviço da conta (código de tributação e alíquota), com a lista carregada da API.
* CPF e CNPJ lidos dos campos do **Brazilian Market on WooCommerce** (metas configuráveis). Sem eles, o plugin adiciona um campo opcional "CPF ou CNPJ para a nota fiscal" no checkout clássico e no checkout em blocos, com validação dos dígitos verificadores.
* Compatível com HPOS (armazenamento de pedidos de alto desempenho) e com o checkout em blocos.
* Ambiente de testes (sandbox) grátis: nada vai ao fisco.

= Para quem é =

MEI, ME e EPP optantes pelo Simples Nacional, com ISS tributável ou não incidência. Isenção, imunidade e exportação de serviço ficam fora do emissaoja. A empresa (prestador) precisa de CNPJ numérico e de acesso ao Emissor Nacional.

= Planos =

O plano Grátis do emissaoja tem 1 nota por mês. A nota conta quando o Emissor Nacional aceita o pedido; nota recusada não conta. Os outros planos estão em [emissaoja.com.br/#precos](https://emissaoja.com.br/#precos).

= In English =

Brazilian NFS-e (service invoice) for WooCommerce orders, issued through the national issuer (Emissor Nacional, nfse.gov.br) with the emissaoja API, without a digital certificate. Built for MEI and Simples Nacional companies. Manual or automatic issuing, background status tracking with Action Scheduler, idempotent requests, PDF and XML downloads, CPF/CNPJ field for the classic and block-based checkout, HPOS compatible, free sandbox. Sign up at [emissaoja.com.br](https://emissaoja.com.br/?origem=lib-wordpress).

O emissaoja é um serviço privado e não tem vínculo com a Receita Federal nem com o Emissor Nacional.

== Installation ==

1. Instale e ative o plugin (Plugins > Adicionar novo). Ele precisa do WooCommerce 8.0 ou mais novo.
2. Crie a sua conta em [emissaoja.com.br](https://emissaoja.com.br/?origem=lib-wordpress): entre com o Google, aceite os termos e conecte a empresa com o CNPJ e a senha do Emissor Nacional.
3. No app do emissaoja, crie a chave de API em **Conta > API**. Ela aparece uma vez só e começa com `ej_live_`.
4. Em **WooCommerce > Configurações > NFS-e (emissaoja)**, cole a chave, salve e clique em **Testar conexão**: o plugin mostra a empresa conectada.
5. Escolha quando emitir (só manualmente, Concluído ou Processando), a base do valor, o modelo da descrição e o serviço.
6. Abra um pedido e clique em **Emitir NFS-e agora** na caixa "NFS-e (emissaoja)".

**Para testar antes (sandbox):** crie a conta em [sandbox.emissaoja.com.br](https://sandbox.emissaoja.com.br/), conecte a empresa de exemplo `11111111000191` (qualquer senha), crie uma chave `ej_test_`, escolha o ambiente **Sandbox** nas configurações e emita um pedido com o CNPJ `12345678000195` ou o CPF `52998224725`. Valores com significado: R$ 13,13 deixa a nota incerta (use **Conferir**) e R$ 66,66 faz o fisco simulado recusar.

== Frequently Asked Questions ==

= Precisa de certificado digital? =

Não. O emissaoja entra no Emissor Nacional com o CNPJ e a senha do próprio portal, e o plugin fala só com a API do emissaoja.

= Funciona para MEI? =

Sim. O emissaoja foi feito para MEI e para ME e EPP do Simples Nacional. Antes da primeira nota, o app do emissaoja confere o cadastro fiscal da empresa e pergunta o que faltar (por exemplo, a alíquota do Simples).

= E se o cliente não informar CPF ou CNPJ? =

A NFS-e precisa de um tomador identificado, então o plugin não emite: o pedido recebe uma nota explicando. Preencha o CPF ou o CNPJ no pedido (campo "CPF ou CNPJ (NFS-e)" dos dados de cobrança, campo "CPF ou CNPJ para a nota fiscal" do checkout em blocos ou os campos do Brazilian Market on WooCommerce), salve e clique em **Emitir NFS-e agora**. Para pedir o documento a todos os clientes, use o filtro `emissaoja_nfse_campo_checkout` e torne o campo obrigatório.

= Tem ambiente de testes? =

Sim: o sandbox (https://sandbox.emissaoja.com.br) é grátis e simula o Emissor Nacional, sem nada ir ao fisco. Escolha **Sandbox** nas configurações e use uma chave `ej_test_`. No sandbox, o cliente nunca é avisado.

= A nota pode sair em dobro? =

O plugin manda a mesma referência (Idempotency-Key) em todas as tentativas de um pedido, e a API nunca emite duas vezes com a mesma referência. Se a API avisar que já existe uma nota igual hoje (mesmo cliente, valor e competência), o plugin para e mostra o aviso no pedido; só o botão **Emitir mesmo assim** emite outra.

= O que é a situação "incerta"? =

O Emissor Nacional não confirmou se a nota saiu. Clique em **Conferir** no pedido: o emissaoja confere no portal, sem emitir de novo, e o pedido mostra o resultado.

= A minha chave de API fica segura? =

A chave fica no servidor (opção sem carregamento automático) e só vai nas chamadas do servidor para a API do emissaoja. Ela nunca é mostrada de novo inteira nem enviada ao navegador; o PDF e o XML passam pelo servidor da loja. Também dá para deixá-la fora do banco de dados, no wp-config.php: `define( 'EMISSAOJA_NFSE_API_KEY', 'ej_live_...' );`.

= A emissão automática funciona numa cópia de teste da loja? =

Não em produção: quando o site não está marcado como produção (constante `WP_ENVIRONMENT_TYPE`) e o plugin está no ambiente de Produção, a emissão automática não acontece, para uma cópia da loja não emitir notas de verdade. O botão **Emitir NFS-e agora** continua funcionando.

= Funciona com o checkout em blocos e com o HPOS? =

Sim. O campo de CPF/CNPJ do checkout em blocos usa a API de campos adicionais do WooCommerce (8.9 ou mais novo), e o plugin lê e grava os pedidos só pela API de pedidos do WooCommerce, compatível com o HPOS.

= Para desenvolvedores: ganchos =

* `emissaoja_nfse_campos_nota` (array): campos enviados em `POST /v1/notas`.
* `emissaoja_nfse_descricao` (string) e `emissaoja_nfse_valor` (float): descrição e valor da nota.
* `emissaoja_nfse_documento` (array|null): documento do tomador.
* `emissaoja_nfse_campo_checkout` (array): argumentos do campo do checkout clássico.
* `emissaoja_nfse_registrar_campo_blocos` (bool): registra o campo do checkout em blocos.
* `emissaoja_nfse_emissao_automatica` (bool): libera ou impede a emissão automática de um pedido.
* `emissaoja_nfse_base_url` (string): URL base da API.

As metas do pedido ficam em `_emissaoja_pedido_id`, `_emissaoja_estado`, `_emissaoja_numero`, `_emissaoja_chave_acesso`, `_emissaoja_nota_origem`, `_emissaoja_nota_id` e `_emissaoja_erro`.

== External services ==

Este plugin se conecta à **API do emissaoja** (https://emissaoja.com.br), serviço da BINOBO LTDA, para emitir a NFS-e dos pedidos no Emissor Nacional. Sem ela, o plugin não emite notas.

* **O que é enviado:** a chave de API da loja (no cabeçalho Authorization); o CPF ou o CNPJ do cliente e, quando é CPF, o nome do cliente; o valor da nota; a descrição da nota (com o número do pedido, os nomes e as quantidades dos itens e o nome da loja, conforme o modelo configurado); o serviço escolhido; e uma referência do pedido (`wc-` + um resumo do endereço do site + o número interno do pedido). Nenhum e-mail, telefone, endereço ou IP do cliente é enviado. As chamadas partem do servidor da loja, nunca do navegador do visitante.
* **Quando:** ao emitir uma nota (pelo botão no pedido ou automaticamente, quando a emissão automática está ligada); ao acompanhar, conferir ou atualizar a situação de uma nota; ao baixar o PDF ou o XML; e quando um administrador usa "Testar conexão" ou "Carregar serviços da conta" nas configurações.
* **Endereços:** `https://emissaoja.com.br/v1/...` (produção) ou `https://sandbox.emissaoja.com.br/v1/...` (ambiente de testes, quando escolhido).
* **Para onde os dados vão depois:** o emissaoja transmite a nota ao Emissor Nacional da NFS-e (nfse.gov.br), em nome da empresa da loja. O link de consulta pública mostrado ao cliente aponta para o portal nfse.gov.br; o plugin não chama esse portal.
* Termos de uso: https://emissaoja.com.br/termos/
* Política de privacidade: https://emissaoja.com.br/privacidade/

In English: this plugin sends the store's API key, the customer's CPF or CNPJ (plus the customer's name for a CPF), the invoice amount, the invoice description (order number, item names and quantities, store name) and an order reference to the emissaoja API (https://emissaoja.com.br, or https://sandbox.emissaoja.com.br when the sandbox is selected) when an invoice is issued, tracked, checked or downloaded, and when an administrator tests the connection or loads the services list. emissaoja forwards the invoice to Brazil's national NFS-e issuer (nfse.gov.br). Terms: https://emissaoja.com.br/termos/ - Privacy: https://emissaoja.com.br/privacidade/

O plugin não rastreia visitantes, não carrega arquivos de fora (scripts, estilos ou fontes) e não envia dados de uso.

== Changelog ==

= 0.1.0 =
* 2026-11-05: primeira versão pública.
* Emissão da NFS-e no Emissor Nacional pela API do emissaoja: manual ou quando o pedido fica Processando ou Concluído.
* Acompanhamento em segundo plano (Action Scheduler) até autorizada, recusada ou incerta; botões Conferir e Atualizar situação.
* Referência estável por pedido (Idempotency-Key) e novas tentativas com a mesma referência nas falhas temporárias.
* PDF e XML pelo painel, coluna NFS-e na lista de pedidos e nota para o cliente com o número e a consulta pública.
* Campo "CPF ou CNPJ para a nota fiscal" no checkout clássico e no checkout em blocos; leitura dos campos do Brazilian Market on WooCommerce.
* Compatível com HPOS e com o checkout em blocos.

== Upgrade Notice ==

= 0.1.0 =
Primeira versão pública.
