<?php
/**
 * Desinstalação: apaga as opções do plugin (configurações, chave de API e último erro) e as ações pendentes.
 *
 * As metas dos pedidos (_emissaoja_*) ficam: são o histórico fiscal da loja (número, chave de acesso e
 * referência de cada NFS-e).
 *
 * @package Emissaoja_NFSe
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'emissaoja_nfse_settings' );
delete_option( 'emissaoja_nfse_api_key' );
delete_option( 'emissaoja_nfse_last_error' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'emissaoja_nfse_emitir' );
	as_unschedule_all_actions( 'emissaoja_nfse_consultar' );
}
