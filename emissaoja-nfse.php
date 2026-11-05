<?php
/**
 * Plugin Name:          emissaoja NFS-e for WooCommerce
 * Plugin URI:           https://github.com/Easymovel/emissaoja-woocommerce
 * Description:          Emite a NFS-e (nota fiscal de serviço eletrônica) dos pedidos do WooCommerce no Emissor Nacional, sem certificado digital, pela API do emissaoja. Para MEI e empresas do Simples Nacional.
 * Version:              0.1.0
 * Requires at least:    6.3
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               emissaoja
 * Author URI:           https://emissaoja.com.br/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          emissaoja-nfse
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 *
 * @package Emissaoja_NFSe
 */

defined( 'ABSPATH' ) || exit;

define( 'EMISSAOJA_NFSE_VERSION', '0.1.0' );
define( 'EMISSAOJA_NFSE_FILE', __FILE__ );
define( 'EMISSAOJA_NFSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'EMISSAOJA_NFSE_URL', plugin_dir_url( __FILE__ ) );

require_once EMISSAOJA_NFSE_DIR . 'includes/class-emissaoja-nfse-documento.php';
require_once EMISSAOJA_NFSE_DIR . 'includes/class-emissaoja-nfse-regras.php';
require_once EMISSAOJA_NFSE_DIR . 'includes/class-emissaoja-nfse-settings.php';
require_once EMISSAOJA_NFSE_DIR . 'includes/class-emissaoja-nfse-api.php';
require_once EMISSAOJA_NFSE_DIR . 'includes/class-emissaoja-nfse-checkout.php';
require_once EMISSAOJA_NFSE_DIR . 'includes/class-emissaoja-nfse-pedido.php';
require_once EMISSAOJA_NFSE_DIR . 'includes/class-emissaoja-nfse-emissor.php';
require_once EMISSAOJA_NFSE_DIR . 'includes/class-emissaoja-nfse-admin.php';
require_once EMISSAOJA_NFSE_DIR . 'includes/class-emissaoja-nfse-order-admin.php';
require_once EMISSAOJA_NFSE_DIR . 'includes/class-emissaoja-nfse-plugin.php';

// Compatibilidade declarada com o HPOS (tabelas próprias de pedidos) e com o checkout em blocos.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

register_deactivation_hook( __FILE__, array( 'Emissaoja_NFSe_Plugin', 'desativar' ) );

Emissaoja_NFSe_Plugin::instance();
