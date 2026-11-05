<?php
/**
 * Inicialização do plugin.
 *
 * @package Emissaoja_NFSe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liga as partes do plugin quando o WooCommerce está ativo.
 */
final class Emissaoja_NFSe_Plugin {

	/**
	 * Versão mínima do WooCommerce.
	 */
	const WC_MINIMO = '8.0';

	/**
	 * Instância.
	 *
	 * @var Emissaoja_NFSe_Plugin|null
	 */
	private static $instancia = null;

	/**
	 * Instância única.
	 *
	 * @return Emissaoja_NFSe_Plugin
	 */
	public static function instance() {
		if ( null === self::$instancia ) {
			self::$instancia = new self();
		}
		return self::$instancia;
	}

	/**
	 * Construtor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'iniciar' ), 20 );
	}

	/**
	 * Inicia os componentes (ou avisa que falta o WooCommerce).
	 *
	 * @return void
	 */
	public function iniciar() {
		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, self::WC_MINIMO, '<' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'aviso_woocommerce' ) );
			return;
		}

		// O emissor fica ligado em toda requisição: o Action Scheduler roda no cron e em requisições assíncronas.
		Emissaoja_NFSe_Emissor::init();
		Emissaoja_NFSe_Checkout::init();
		if ( is_admin() ) {
			Emissaoja_NFSe_Admin::init();
			Emissaoja_NFSe_Order_Admin::init();
		}
	}

	/**
	 * Aviso de WooCommerce ausente ou antigo.
	 *
	 * @return void
	 */
	public static function aviso_woocommerce() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			/* translators: %s: versão mínima do WooCommerce. */
			esc_html( sprintf( __( 'emissaoja NFS-e for WooCommerce precisa do WooCommerce %s ou mais novo, instalado e ativo.', 'emissaoja-nfse' ), self::WC_MINIMO ) )
		);
	}

	/**
	 * Desativação: cancela as emissões e consultas pendentes (as metas dos pedidos ficam).
	 *
	 * @return void
	 */
	public static function desativar() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Emissaoja_NFSe_Emissor::HOOK_EMITIR );
			as_unschedule_all_actions( Emissaoja_NFSe_Emissor::HOOK_CONSULTAR );
		}
	}
}
