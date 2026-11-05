<?php
/**
 * Bootstrap dos testes unitários: carrega só as classes puras, sem WordPress nem WooCommerce.
 *
 * @package Emissaoja_NFSe
 */

// Os arquivos do plugin saem cedo sem ABSPATH (proteção contra acesso direto).
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-emissaoja-nfse-documento.php';
require_once dirname( __DIR__ ) . '/includes/class-emissaoja-nfse-regras.php';
