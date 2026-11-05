<?php
/**
 * Leitura do pedido do WooCommerce para a NFS-e: metas, documento, valor, descrição e rótulos.
 *
 * @package Emissaoja_NFSe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Metas do pedido (sempre pela API de pedidos do WooCommerce, compatível com HPOS) e dados da nota.
 */
final class Emissaoja_NFSe_Pedido {

	const PEDIDO_ID       = '_emissaoja_pedido_id';
	const ESTADO          = '_emissaoja_estado';
	const NUMERO          = '_emissaoja_numero';
	const CHAVE_ACESSO    = '_emissaoja_chave_acesso';
	const NOTA_ORIGEM     = '_emissaoja_nota_origem';
	const NOTA_ID         = '_emissaoja_nota_id';
	const ERRO            = '_emissaoja_erro';
	const ERRO_CODE       = '_emissaoja_erro_code';
	const CONSULTA_URL    = '_emissaoja_consulta_url';
	const MOTIVO          = '_emissaoja_motivo';
	const ACOES           = '_emissaoja_acoes';
	const REFERENCIA      = '_emissaoja_referencia';
	const TENTATIVA       = '_emissaoja_tentativa';
	const AMBIENTE        = '_emissaoja_ambiente';
	const TOMADOR         = '_emissaoja_tomador';
	const CONSULTAS       = '_emissaoja_consultas';
	const RETENTATIVAS    = '_emissaoja_retentativas';
	const REPETIDA_DE     = '_emissaoja_repetida_de';
	const CLIENTE_AVISADO = '_emissaoja_cliente_avisado';

	/**
	 * Uma meta do plugin, como texto.
	 *
	 * @param WC_Order $order Pedido.
	 * @param string   $chave Meta.
	 * @return string
	 */
	public static function meta( $order, $chave ) {
		$valor = $order->get_meta( $chave, true );
		return is_scalar( $valor ) ? (string) $valor : '';
	}

	/**
	 * Ambiente em que a nota deste pedido foi pedida ('producao' quando não registrado).
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	public static function ambiente( $order ) {
		return 'sandbox' === self::meta( $order, self::AMBIENTE ) ? 'sandbox' : 'producao';
	}

	/**
	 * Documento do tomador (CPF ou CNPJ) lido das metas configuradas e dos campos do plugin.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array|null Array com 'tipo' ('pf' ou 'pj') e 'numero' (só dígitos), ou null.
	 */
	public static function documento( $order ) {
		$config = Emissaoja_NFSe_Settings::all();
		$ler    = static function ( $chave ) use ( $order ) {
			return '' === $chave ? '' : $order->get_meta( $chave, true );
		};

		$documento = Emissaoja_NFSe_Documento::resolver(
			$ler( $config['meta_tipo'] ),
			$ler( $config['meta_cpf'] ),
			$ler( $config['meta_cnpj'] ),
			array(
				$order->get_meta( Emissaoja_NFSe_Checkout::META_CLASSICO, true ),
				self::campo_blocos( $order ),
			)
		);

		/**
		 * Filtra o documento do tomador da NFS-e.
		 *
		 * @param array|null $documento Array com 'tipo' ('pf' ou 'pj') e 'numero' (só dígitos), ou null.
		 * @param WC_Order   $order     Pedido.
		 */
		$documento = apply_filters( 'emissaoja_nfse_documento', $documento, $order );
		if ( ! is_array( $documento ) || ! isset( $documento['numero'] ) ) {
			return null;
		}
		return Emissaoja_NFSe_Documento::classificar( $documento['numero'] );
	}

	/**
	 * Nome do cliente para o tomador pessoa física (nome e sobrenome de cobrança).
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	public static function nome_cliente( $order ) {
		$nome = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$nome = (string) preg_replace( '/\s+/u', ' ', $nome );
		return Emissaoja_NFSe_Regras::cortar( $nome, 150 );
	}

	/**
	 * Valor da nota conforme a configuração (total do pedido ou total sem frete, menos reembolsos).
	 *
	 * @param WC_Order $order Pedido.
	 * @return float
	 */
	public static function valor( $order ) {
		$valor = Emissaoja_NFSe_Regras::valor(
			$order->get_total(),
			$order->get_total_refunded(),
			(float) $order->get_shipping_total() + (float) $order->get_shipping_tax(),
			$order->get_total_shipping_refunded(),
			(string) Emissaoja_NFSe_Settings::get( 'base_valor' )
		);

		/**
		 * Filtra o valor da NFS-e do pedido, em reais.
		 *
		 * @param float    $valor Valor.
		 * @param WC_Order $order Pedido.
		 */
		return round( (float) apply_filters( 'emissaoja_nfse_valor', $valor, $order ), 2 );
	}

	/**
	 * Descrição da nota a partir do modelo das configurações ({pedido}, {itens}, {loja}).
	 *
	 * @param WC_Order $order Pedido.
	 * @return string Vazio quando o modelo está vazio (a API usa a descrição do serviço).
	 */
	public static function descricao( $order ) {
		$itens = array();
		foreach ( $order->get_items() as $item ) {
			$itens[] = array(
				'nome'       => wp_strip_all_tags( $item->get_name() ),
				'quantidade' => $item->get_quantity(),
			);
		}

		$descricao = Emissaoja_NFSe_Regras::descricao(
			(string) Emissaoja_NFSe_Settings::get( 'descricao' ),
			array(
				'pedido' => $order->get_order_number(),
				'itens'  => Emissaoja_NFSe_Regras::itens_texto( $itens ),
				'loja'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			)
		);

		/**
		 * Filtra a descrição (discriminação do serviço) da NFS-e do pedido. Até 2.000 caracteres.
		 *
		 * @param string   $descricao Descrição.
		 * @param WC_Order $order     Pedido.
		 */
		$descricao = apply_filters( 'emissaoja_nfse_descricao', $descricao, $order );
		return Emissaoja_NFSe_Regras::cortar( trim( (string) $descricao ), Emissaoja_NFSe_Regras::DESCRICAO_MAX );
	}

	/**
	 * Valor formatado na moeda do pedido, em texto puro (para as notas do pedido).
	 *
	 * @param WC_Order $order Pedido.
	 * @param float    $valor Valor.
	 * @return string
	 */
	public static function valor_formatado( $order, $valor ) {
		$html = wc_price( $valor, array( 'currency' => $order->get_currency() ) );
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Apaga os dados do pedido de emissão para começar outro.
	 *
	 * @param WC_Order $order Pedido.
	 * @param bool     $tudo  Também a referência, a tentativa, o ambiente e o tomador guardados.
	 * @return void
	 */
	public static function limpar_emissao( $order, $tudo ) {
		$metas = array( self::PEDIDO_ID, self::ESTADO, self::NUMERO, self::CHAVE_ACESSO, self::NOTA_ORIGEM, self::NOTA_ID, self::ERRO, self::ERRO_CODE, self::CONSULTA_URL, self::MOTIVO, self::ACOES, self::CONSULTAS, self::RETENTATIVAS, self::REPETIDA_DE, self::CLIENTE_AVISADO );
		if ( $tudo ) {
			$metas = array_merge( $metas, array( self::REFERENCIA, self::TENTATIVA, self::AMBIENTE, self::TOMADOR ) );
		}
		foreach ( $metas as $meta ) {
			$order->delete_meta_data( $meta );
		}
	}

	/**
	 * Rótulo do estado da NFS-e no pedido.
	 *
	 * @param string $estado Estado.
	 * @return string
	 */
	public static function rotulo_estado( $estado ) {
		$rotulos = array(
			'agendada'    => __( 'Agendada', 'emissaoja-nfse' ),
			'na_fila'     => __( 'Na fila', 'emissaoja-nfse' ),
			'emitindo'    => __( 'Emitindo', 'emissaoja-nfse' ),
			'confirmando' => __( 'Confirmando', 'emissaoja-nfse' ),
			'autorizada'  => __( 'Autorizada', 'emissaoja-nfse' ),
			'recusada'    => __( 'Recusada', 'emissaoja-nfse' ),
			'incerta'     => __( 'Incerta', 'emissaoja-nfse' ),
			'erro'        => __( 'Não emitida', 'emissaoja-nfse' ),
		);
		return isset( $rotulos[ $estado ] ) ? $rotulos[ $estado ] : __( 'Sem NFS-e', 'emissaoja-nfse' );
	}

	/**
	 * Valor do campo do checkout em blocos (pela API do WooCommerce quando existe; senão, pela meta).
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	private static function campo_blocos( $order ) {
		$valor   = '';
		$pacote  = 'Automattic\WooCommerce\Blocks\Package';
		$servico = 'Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields';
		if ( class_exists( $pacote ) && class_exists( $servico ) && method_exists( $pacote, 'container' ) ) {
			try {
				$campos = call_user_func( array( $pacote, 'container' ) )->get( $servico );
				if ( is_object( $campos ) && method_exists( $campos, 'get_field_from_object' ) ) {
					$valor = $campos->get_field_from_object( Emissaoja_NFSe_Checkout::CAMPO_BLOCOS, $order, 'other' );
				}
			} catch ( Throwable $e ) {
				$valor = '';
			}
		}
		if ( ! is_string( $valor ) || '' === $valor ) {
			$valor = $order->get_meta( Emissaoja_NFSe_Checkout::META_BLOCOS, true );
		}
		return is_scalar( $valor ) ? (string) $valor : '';
	}
}
