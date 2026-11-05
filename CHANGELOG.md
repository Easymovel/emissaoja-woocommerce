# Changelog

Todas as mudanças relevantes deste plugin. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e as versões seguem o [Semantic Versioning](https://semver.org/lang/pt-BR/).

## [0.1.0] - 2026-11-05

Primeira versão pública.

### Adicionado

- Emissão da NFS-e dos pedidos do WooCommerce no Emissor Nacional pela API do emissaoja, sem certificado digital: manual (botão no pedido) ou automática, quando o pedido fica Processando ou Concluído.
- Cliente HTTP próprio com `wp_remote_request`: `Authorization: Bearer`, `Idempotency-Key`, User-Agent `emissaoja-woocommerce/0.1.0`, tempo máximo por chamada e erros com o `code` da API.
- Tomador criado (ou achado) por CPF ou CNPJ; para CPF, com o nome do cliente.
- Referência estável e única por pedido (`wc-<8 hex do md5 da URL do site>-<id do pedido>`) no `Idempotency-Key`; novas tentativas automáticas com a mesma referência nas falhas temporárias (`fila_indisponivel`, `rate_limited`, rede...).
- Acompanhamento em segundo plano com o Action Scheduler (`GET /v1/emissoes/{id}`, no ritmo de `proxima_consulta_ms`) até autorizada, recusada ou incerta, com limite de consultas.
- Caixa "NFS-e (emissaoja)" no pedido (HPOS e tela clássica): situação, número, chave de acesso, consulta pública e os botões Emitir NFS-e agora, Emitir mesmo assim, Conferir, Atualizar situação, Baixar PDF e Baixar XML (download pelo servidor da loja).
- Coluna "NFS-e" na lista de pedidos.
- Notas internas no pedido para cada passo, com os `bloqueios` de `nota_bloqueada`; nota para o cliente, opcional, com o número e o link da consulta pública.
- Aba WooCommerce > Configurações > NFS-e (emissaoja): chave de API (nunca reimpressa), ambiente (Produção ou Sandbox), Testar conexão, quando emitir, base do valor, modelo da descrição, serviço da conta e aviso ao cliente.
- Campo "CPF ou CNPJ para a nota fiscal" no checkout clássico e no checkout em blocos (WooCommerce 8.9+), com validação dos dígitos verificadores; leitura dos campos do Brazilian Market on WooCommerce.
- Compatibilidade declarada com HPOS e com o checkout em blocos.
- Testes PHPUnit das funções puras e CI com `php -l` e PHPUnit em PHP 7.4 a 8.4, e phpcs (WordPress-Extra + PHPCompatibilityWP).

[0.1.0]: https://github.com/Easymovel/emissaoja-woocommerce/releases/tag/v0.1.0
