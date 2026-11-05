<?php
/**
 * Campo "CPF ou CNPJ para a nota fiscal" no checkout clássico, no checkout em blocos e no pedido (painel).
 *
 * @package Emissaoja_NFSe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coleta o documento do tomador quando a loja não tem campos de CPF/CNPJ.
 *
 * Com o Brazilian Market on WooCommerce (ou outro plugin que grave CPF/CNPJ nas metas configuradas), o modo
 * "automático" não adiciona nada: o emissor lê as metas desse plugin.
 */
final class Emissaoja_NFSe_Checkout {

	/**
	 * Campo do checkout clássico (o WooCommerce grava como a meta _billing_emissaoja_nfse_documento).
	 */
	const CAMPO_CLASSICO = 'billing_emissaoja_nfse_documento';

	/**
	 * Meta do campo do checkout clássico e do campo do pedido no painel.
	 */
	const META_CLASSICO = '_billing_emissaoja_nfse_documento';

	/**
	 * Id do campo no checkout em blocos (Additional Checkout Fields API).
	 */
	const CAMPO_BLOCOS = 'emissaoja-nfse/documento';

	/**
	 * Meta em que o WooCommerce grava o campo do checkout em blocos (local "contact").
	 */
	const META_BLOCOS = '_wc_other/emissaoja-nfse/documento';

	/**
	 * Se o campo do checkout em blocos foi registrado nesta requisição.
	 *
	 * @var bool
	 */
	private static $bloco_registrado = false;

	/**
	 * Registra os ganchos.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'campo_classico' ), 9999 );
		add_filter( 'woocommerce_process_checkout_field_' . self::CAMPO_CLASSICO, array( __CLASS__, 'formatar_valor' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validar_classico' ), 20, 2 );
		add_filter( 'woocommerce_admin_billing_fields', array( __CLASS__, 'campo_admin' ) );
		add_action( 'woocommerce_init', array( __CLASS__, 'registrar_campo_blocos' ) );
	}

	/**
	 * Se o Brazilian Market on WooCommerce (woocommerce-extra-checkout-fields-for-brazil) está ativo.
	 *
	 * @return bool
	 */
	public static function tem_brazilian_market() {
		return class_exists( 'Extra_Checkout_Fields_For_Brazil' );
	}

	/**
	 * Adiciona o campo ao checkout clássico.
	 *
	 * @param array $campos Campos do checkout, por grupo.
	 * @return array
	 */
	public static function campo_classico( $campos ) {
		if ( ! is_array( $campos ) || ! isset( $campos['billing'] ) || ! is_array( $campos['billing'] ) ) {
			return $campos;
		}
		$modo = self::modo();
		if ( 'nunca' === $modo || ( 'auto' === $modo && self::loja_tem_documento( $campos['billing'] ) ) ) {
			return $campos;
		}

		$campo = array(
			'label'             => __( 'CPF ou CNPJ para a nota fiscal', 'emissaoja-nfse' ),
			'required'          => false,
			'type'              => 'text',
			'class'             => array( 'form-row-wide' ),
			'priority'          => 35,
			'autocomplete'      => 'off',
			'custom_attributes' => array(
				'maxlength'  => '18',
				'inputmode'  => 'numeric',
				'spellcheck' => 'false',
			),
		);

		/**
		 * Filtra o campo "CPF ou CNPJ para a nota fiscal" do checkout clássico (por exemplo, para torná-lo obrigatório).
		 *
		 * @param array $campo Argumentos do campo (os mesmos de woocommerce_form_field()).
		 */
		$campos['billing'][ self::CAMPO_CLASSICO ] = apply_filters( 'emissaoja_nfse_campo_checkout', $campo );
		return $campos;
	}

	/**
	 * Guarda o documento com a máscara quando ele é válido.
	 *
	 * @param mixed $valor Valor enviado.
	 * @return string
	 */
	public static function formatar_valor( $valor ) {
		$valor = is_scalar( $valor ) ? trim( sanitize_text_field( (string) $valor ) ) : '';
		if ( null === Emissaoja_NFSe_Documento::classificar( $valor ) ) {
			return $valor;
		}
		return Emissaoja_NFSe_Documento::formatar( $valor );
	}

	/**
	 * Valida o campo no checkout clássico (vazio é aceito: o campo é opcional).
	 *
	 * @param array    $dados Dados enviados no checkout.
	 * @param WP_Error $erros Erros do checkout.
	 * @return void
	 */
	public static function validar_classico( $dados, $erros ) {
		if ( ! is_array( $dados ) || ! isset( $dados[ self::CAMPO_CLASSICO ] ) || ! is_wp_error( $erros ) ) {
			return;
		}
		$valor = is_scalar( $dados[ self::CAMPO_CLASSICO ] ) ? trim( (string) $dados[ self::CAMPO_CLASSICO ] ) : '';
		if ( '' !== $valor && null === Emissaoja_NFSe_Documento::classificar( $valor ) ) {
			$erros->add( 'validation', self::mensagem_invalido() );
		}
	}

	/**
	 * Campo editável "CPF ou CNPJ (NFS-e)" nos dados de cobrança do pedido, no painel (lojas com checkout clássico).
	 *
	 * Serve para completar o documento de pedidos em que o cliente não informou. O WooCommerce grava o valor
	 * na meta _billing_emissaoja_nfse_documento ao salvar o pedido.
	 *
	 * @param array $campos Campos de cobrança do painel.
	 * @return array
	 */
	public static function campo_admin( $campos ) {
		if ( ! is_array( $campos ) || ! self::usar_campo_admin() ) {
			return $campos;
		}
		$campos['emissaoja_nfse_documento'] = array(
			'label'         => __( 'CPF ou CNPJ (NFS-e)', 'emissaoja-nfse' ),
			'show'          => true,
			'wrapper_class' => 'form-field-wide',
		);
		return $campos;
	}

	/**
	 * Registra o campo no checkout em blocos (Additional Checkout Fields API, WooCommerce 8.9 ou mais novo).
	 *
	 * @return void
	 */
	public static function registrar_campo_blocos() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		$modo = self::modo();
		if ( 'nunca' === $modo || ( 'auto' === $modo && self::tem_brazilian_market() ) ) {
			return;
		}

		$usa_bloco = true;
		$utils     = 'Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils';
		if ( class_exists( $utils ) && method_exists( $utils, 'is_checkout_block_default' ) ) {
			$usa_bloco = (bool) call_user_func( array( $utils, 'is_checkout_block_default' ) );
		}

		/**
		 * Se registra o campo "CPF ou CNPJ para a nota fiscal" no checkout em blocos.
		 * Por padrão, só quando a página de checkout usa o bloco Checkout.
		 *
		 * @param bool $registrar Padrão: se a página de checkout usa o bloco Checkout.
		 */
		if ( ! apply_filters( 'emissaoja_nfse_registrar_campo_blocos', $usa_bloco ) ) {
			return;
		}

		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'                => self::CAMPO_BLOCOS,
					'label'             => __( 'CPF ou CNPJ para a nota fiscal', 'emissaoja-nfse' ),
					'optionalLabel'     => __( 'CPF ou CNPJ para a nota fiscal (opcional)', 'emissaoja-nfse' ),
					'location'          => 'contact',
					'type'              => 'text',
					'required'          => false,
					'attributes'        => array(
						'autocomplete' => 'off',
						'maxLength'    => 18,
					),
					'sanitize_callback' => array( __CLASS__, 'formatar_valor' ),
					'validate_callback' => array( __CLASS__, 'validar_blocos' ),
				)
			);
			self::$bloco_registrado = true;
		} catch ( Exception $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( 'Campo do checkout em blocos não registrado: ' . $e->getMessage(), array( 'source' => 'emissaoja-nfse' ) );
			}
		}
	}

	/**
	 * Valida o campo no checkout em blocos (Store API).
	 *
	 * @param mixed $valor Valor.
	 * @return true|WP_Error
	 */
	public static function validar_blocos( $valor ) {
		$valor = is_scalar( $valor ) ? trim( (string) $valor ) : '';
		if ( '' === $valor || null !== Emissaoja_NFSe_Documento::classificar( $valor ) ) {
			return true;
		}
		return new WP_Error( 'emissaoja_nfse_documento_invalido', self::mensagem_invalido() );
	}

	/**
	 * Mensagem de documento inválido.
	 *
	 * @return string
	 */
	private static function mensagem_invalido() {
		return __( 'Confira o CPF ou CNPJ para a nota fiscal: os dígitos verificadores não batem.', 'emissaoja-nfse' );
	}

	/**
	 * Modo do campo: 'auto', 'sempre' ou 'nunca'.
	 *
	 * @return string
	 */
	private static function modo() {
		return (string) Emissaoja_NFSe_Settings::get( 'campo_checkout' );
	}

	/**
	 * Se a loja já coleta CPF/CNPJ: Brazilian Market ativo ou campos com o nome das metas configuradas.
	 *
	 * @param array $cobranca Campos de cobrança do checkout.
	 * @return bool
	 */
	private static function loja_tem_documento( array $cobranca ) {
		if ( self::tem_brazilian_market() ) {
			return true;
		}
		foreach ( array( 'meta_cpf', 'meta_cnpj' ) as $config ) {
			$campo = ltrim( (string) Emissaoja_NFSe_Settings::get( $config ), '_' );
			if ( '' !== $campo && isset( $cobranca[ $campo ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Se o campo do pedido no painel deve aparecer.
	 *
	 * @return bool
	 */
	private static function usar_campo_admin() {
		$modo = self::modo();
		if ( 'nunca' === $modo || self::$bloco_registrado ) {
			return false;
		}
		return 'sempre' === $modo || ! self::tem_brazilian_market();
	}
}
