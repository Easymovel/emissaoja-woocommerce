<?php
/**
 * Tela do pedido: caixa "NFS-e (emissaoja)", coluna "NFS-e" na lista, botões e download do PDF/XML.
 *
 * @package Emissaoja_NFSe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Painel do pedido (HPOS e tela clássica de posts).
 *
 * Os botões apontam para admin-post.php com nonce amarrado à ação e ao pedido, e conferem a capability
 * edit_shop_orders. O PDF e o XML passam pelo servidor da loja (proxy): a chave de API nunca vai ao navegador.
 */
final class Emissaoja_NFSe_Order_Admin {

	/**
	 * Id da coluna na lista de pedidos.
	 */
	const COLUNA = 'emissaoja_nfse';

	/**
	 * Ações aceitas pelos botões.
	 *
	 * @var string[]
	 */
	private static $acoes = array( 'emitir', 'emitir_mesmo_assim', 'conferir', 'atualizar' );

	/**
	 * Erros de nota repetida que liberam o botão "Emitir mesmo assim".
	 *
	 * @var string[]
	 */
	private static $avisos_de_repeticao = array( 'possivel_duplicada', 'emissao_repetida_recente' );

	/**
	 * Registra os ganchos.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'caixa' ), 30 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'colunas' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'coluna_legado' ), 20, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'colunas' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'coluna_hpos' ), 20, 2 );
		add_action( 'admin_post_emissaoja_nfse_acao', array( __CLASS__, 'acao' ) );
		add_action( 'admin_post_emissaoja_nfse_baixar', array( __CLASS__, 'baixar' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'aviso' ) );
	}

	/**
	 * Adiciona a caixa à tela do pedido (HPOS: woocommerce_page_wc-orders; clássica: shop_order).
	 *
	 * @return void
	 */
	public static function caixa() {
		add_meta_box( 'emissaoja-nfse', __( 'NFS-e (emissaoja)', 'emissaoja-nfse' ), array( __CLASS__, 'render_caixa' ), self::tela_pedido(), 'side', 'default' );
	}

	/**
	 * Mostra a caixa.
	 *
	 * @param WP_Post|WC_Order $objeto Post (tela clássica) ou pedido (HPOS).
	 * @return void
	 */
	public static function render_caixa( $objeto ) {
		$order = $objeto instanceof WP_Post ? wc_get_order( $objeto->ID ) : $objeto;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$pedido_id  = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::PEDIDO_ID );
		$estado     = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::ESTADO );
		$numero     = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::NUMERO );
		$chave      = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::CHAVE_ACESSO );
		$url        = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::CONSULTA_URL );
		$nota_id    = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::NOTA_ID );
		$motivo     = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::MOTIVO );
		$erro       = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::ERRO );
		$erro_code  = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::ERRO_CODE );
		$acoes      = array_filter( explode( ',', Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::ACOES ) ) );
		$ambiente   = 'sandbox' === Emissaoja_NFSe_Settings::get( 'ambiente' ) ? 'sandbox' : 'producao';
		$amb_pedido = Emissaoja_NFSe_Pedido::ambiente( $order );
		$documento  = Emissaoja_NFSe_Pedido::documento( $order );

		echo '<div class="emissaoja-nfse-caixa">';

		printf(
			'<p><strong>%1$s</strong> <span class="emissaoja-nfse-estado emissaoja-nfse-estado--%2$s">%3$s</span></p>',
			esc_html__( 'Situação:', 'emissaoja-nfse' ),
			esc_attr( '' !== $estado ? $estado : 'nenhuma' ),
			esc_html( Emissaoja_NFSe_Pedido::rotulo_estado( $estado ) )
		);
		if ( '' !== $pedido_id && 'sandbox' === $amb_pedido ) {
			echo '<p class="emissaoja-nfse-sandbox">' . esc_html__( 'Emissão de teste (sandbox): nada foi enviado ao fisco.', 'emissaoja-nfse' ) . '</p>';
		}
		if ( '' !== $numero ) {
			printf( '<p><strong>%1$s</strong> %2$s</p>', esc_html__( 'Número:', 'emissaoja-nfse' ), esc_html( $numero ) );
		}
		if ( '' !== $chave ) {
			printf( '<p><strong>%1$s</strong><br /><code class="emissaoja-nfse-chave">%2$s</code></p>', esc_html__( 'Chave de acesso:', 'emissaoja-nfse' ), esc_html( $chave ) );
		}
		if ( '' !== $url ) {
			printf( '<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>', esc_url( $url ), esc_html__( 'Consulta pública no Portal Nacional da NFS-e', 'emissaoja-nfse' ) );
		}
		if ( '' !== $motivo && ( 'recusada' === $estado || 'incerta' === $estado ) ) {
			printf( '<p><strong>%1$s</strong> %2$s</p>', esc_html__( 'Motivo:', 'emissaoja-nfse' ), esc_html( $motivo ) );
		}
		if ( '' !== $erro ) {
			echo '<p class="emissaoja-nfse-erro">' . nl2br( esc_html( $erro ) ) . '</p>';
		}

		$proxima = Emissaoja_NFSe_Emissor::proximo_agendamento( Emissaoja_NFSe_Emissor::HOOK_EMITIR, $order->get_id() );
		if ( $proxima > 0 ) {
			printf(
				'<p class="description">%s</p>',
				/* translators: %s: data e hora da emissão agendada. */
				esc_html( sprintf( __( 'Emissão agendada para %s.', 'emissaoja-nfse' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $proxima ) ) )
			);
		}

		if ( null !== $documento ) {
			printf(
				'<p><strong>%1$s</strong> %2$s %3$s</p>',
				esc_html__( 'Tomador:', 'emissaoja-nfse' ),
				esc_html( Emissaoja_NFSe_Documento::rotulo( $documento['tipo'] ) ),
				esc_html( Emissaoja_NFSe_Documento::formatar( $documento['numero'] ) )
			);
		} elseif ( '' === $pedido_id ) {
			echo '<p class="emissaoja-nfse-erro">' . esc_html__( 'Sem CPF nem CNPJ válido no pedido. Preencha o documento nos dados de cobrança e salve o pedido antes de emitir.', 'emissaoja-nfse' ) . '</p>';
		}
		if ( '' === $pedido_id ) {
			printf(
				'<p><strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Valor da nota:', 'emissaoja-nfse' ),
				esc_html( Emissaoja_NFSe_Pedido::valor_formatado( $order, Emissaoja_NFSe_Pedido::valor( $order ) ) )
			);
		}

		self::botoes( $order, $pedido_id, $estado, $nota_id, $acoes, $erro_code, $ambiente, $amb_pedido );

		echo '</div>';
	}

	/**
	 * Botões da caixa, conforme a situação.
	 *
	 * @param WC_Order $order      Pedido.
	 * @param string   $pedido_id  Id do pedido de emissão.
	 * @param string   $estado     Estado.
	 * @param string   $nota_id    Id da nota autorizada.
	 * @param array    $acoes      Ações sugeridas pela API.
	 * @param string   $erro_code  Código do último erro.
	 * @param string   $ambiente   Ambiente das configurações.
	 * @param string   $amb_pedido Ambiente do pedido de emissão.
	 * @return void
	 */
	private static function botoes( $order, $pedido_id, $estado, $nota_id, array $acoes, $erro_code, $ambiente, $amb_pedido ) {
		if ( '' === Emissaoja_NFSe_Settings::api_key() ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( Emissaoja_NFSe_Settings::page_url() ),
				esc_html__( 'Configure a chave de API do emissaoja para emitir.', 'emissaoja-nfse' )
			);
			return;
		}
		if ( '' !== $pedido_id && $amb_pedido !== $ambiente && 'producao' === $amb_pedido ) {
			echo '<p class="description">' . esc_html__( 'Esta NFS-e é de produção. Volte o ambiente para Produção nas configurações para consultar ou baixar.', 'emissaoja-nfse' ) . '</p>';
			return;
		}

		$teste_antigo = '' !== $pedido_id && $amb_pedido !== $ambiente;

		echo '<p class="emissaoja-nfse-acoes">';
		if ( '' === $pedido_id || $teste_antigo ) {
			self::botao( self::url_acao( $order, 'emitir' ), __( 'Emitir NFS-e agora', 'emissaoja-nfse' ), 'button button-primary', self::confirmacao_emitir( $order, $ambiente ) );
		} elseif ( 'recusada' === $estado ) {
			self::botao( self::url_acao( $order, 'emitir' ), __( 'Emitir novamente', 'emissaoja-nfse' ), 'button button-primary', self::confirmacao_emitir( $order, $ambiente ) );
		}
		if ( '' === $pedido_id && in_array( $erro_code, self::$avisos_de_repeticao, true ) ) {
			self::botao( self::url_acao( $order, 'emitir_mesmo_assim' ), __( 'Emitir mesmo assim', 'emissaoja-nfse' ), 'button', __( 'O emissaoja avisou que já existe uma nota igual (mesmo cliente e valor) emitida hoje. Emitir outra nota mesmo assim?', 'emissaoja-nfse' ) );
		}
		if ( '' !== $pedido_id && ! $teste_antigo ) {
			if ( 'incerta' === $estado && ( empty( $acoes ) || in_array( 'conferir', $acoes, true ) ) ) {
				self::botao( self::url_acao( $order, 'conferir' ), __( 'Conferir', 'emissaoja-nfse' ), 'button button-primary', '' );
			}
			if ( 'autorizada' !== $estado ) {
				self::botao( self::url_acao( $order, 'atualizar' ), __( 'Atualizar situação', 'emissaoja-nfse' ), 'button', '' );
			}
			if ( 'autorizada' === $estado && '' !== $nota_id ) {
				self::botao( self::url_baixar( $order, 'pdf' ), __( 'Baixar PDF', 'emissaoja-nfse' ), 'button', '' );
				self::botao( self::url_baixar( $order, 'xml' ), __( 'Baixar XML', 'emissaoja-nfse' ), 'button', '' );
			}
		}
		echo '</p>';

		if ( 'incerta' === $estado && ! empty( $acoes ) && ! in_array( 'conferir', $acoes, true ) ) {
			echo '<p class="description">' . esc_html__( 'A conferência automática não resolveu esta nota: confira no portal do Emissor Nacional ou fale com o suporte do emissaoja (suporte@emissaoja.com.br) antes de emitir de novo.', 'emissaoja-nfse' ) . '</p>';
		}
		if ( $teste_antigo ) {
			echo '<p class="description">' . esc_html__( 'A emissão deste pedido foi de teste (sandbox). "Emitir NFS-e agora" emite em produção.', 'emissaoja-nfse' ) . '</p>';
		}
	}

	/**
	 * Executa um botão da caixa e volta ao pedido com o resultado num aviso.
	 *
	 * @return void
	 */
	public static function acao() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- O nonce é conferido logo abaixo e é amarrado à ação e ao pedido.
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$acao     = isset( $_GET['acao'] ) ? sanitize_key( wp_unslash( $_GET['acao'] ) ) : '';
		// phpcs:enable
		if ( ! $order_id || ! in_array( $acao, self::$acoes, true ) ) {
			wp_die( esc_html__( 'Ação inválida.', 'emissaoja-nfse' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'emissaoja_nfse_' . $acao . '_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para isso.', 'emissaoja-nfse' ), '', array( 'response' => 403 ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Pedido não encontrado.', 'emissaoja-nfse' ), '', array( 'response' => 404 ) );
		}

		if ( 'emitir_mesmo_assim' === $acao ) {
			$codigo    = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::ERRO_CODE );
			$resultado = Emissaoja_NFSe_Emissor::emitir( $order, in_array( $codigo, self::$avisos_de_repeticao, true ) ? 'confirmada' : 'manual' );
		} elseif ( 'conferir' === $acao ) {
			$resultado = Emissaoja_NFSe_Emissor::conferir( $order );
		} elseif ( 'atualizar' === $acao ) {
			$resultado = Emissaoja_NFSe_Emissor::atualizar( $order );
		} else {
			$resultado = Emissaoja_NFSe_Emissor::emitir( $order, 'manual' );
		}

		set_transient( self::chave_aviso(), $resultado, 120 );
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Baixa o PDF (DANFSe) ou o XML da nota pela API, sem expor a chave ao navegador.
	 *
	 * @return void
	 */
	public static function baixar() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- O nonce é conferido logo abaixo e é amarrado ao formato e ao pedido.
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$formato  = isset( $_GET['formato'] ) ? sanitize_key( wp_unslash( $_GET['formato'] ) ) : '';
		// phpcs:enable
		if ( ! $order_id || ! in_array( $formato, array( 'pdf', 'xml' ), true ) ) {
			wp_die( esc_html__( 'Pedido de arquivo inválido.', 'emissaoja-nfse' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'emissaoja_nfse_baixar_' . $formato . '_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para isso.', 'emissaoja-nfse' ), '', array( 'response' => 403 ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Pedido não encontrado.', 'emissaoja-nfse' ), '', array( 'response' => 404 ) );
		}
		$nota_id = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::NOTA_ID );
		if ( '' === $nota_id ) {
			wp_die( esc_html__( 'Este pedido ainda não tem NFS-e autorizada.', 'emissaoja-nfse' ), '', array( 'response' => 404 ) );
		}

		$origem  = 'importada' === Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::NOTA_ORIGEM ) ? 'importada' : 'local';
		$api     = Emissaoja_NFSe_Api::da_loja( Emissaoja_NFSe_Pedido::ambiente( $order ) );
		$arquivo = $api->arquivo( $origem, $nota_id, $formato );
		if ( is_wp_error( $arquivo ) ) {
			wp_die(
				/* translators: 1: mensagem do erro, 2: código do erro. */
				esc_html( sprintf( __( 'Não foi possível baixar o arquivo da NFS-e: %1$s [%2$s]', 'emissaoja-nfse' ), $arquivo->get_error_message(), $arquivo->get_error_code() ) ),
				esc_html__( 'NFS-e (emissaoja)', 'emissaoja-nfse' ),
				array(
					'response'  => 502,
					'back_link' => true,
				)
			);
		}

		$numero = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::NUMERO );
		$nome   = $arquivo['nome'];
		if ( '' === $nome ) {
			$nome = sanitize_file_name( 'nfse-' . ( '' !== $numero ? $numero : $order->get_order_number() ) . '.' . $formato );
		}
		if ( strtolower( substr( $nome, -4 ) ) !== '.' . $formato ) {
			$nome .= '.' . $formato;
		}

		nocache_headers();
		header( 'Content-Type: ' . ( 'pdf' === $formato ? 'application/pdf' : 'application/xml; charset=utf-8' ) );
		header( 'Content-Disposition: attachment; filename="' . $nome . '"' );
		header( 'Content-Length: ' . strlen( $arquivo['corpo'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $arquivo['corpo']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Arquivo da NFS-e (PDF ou XML) entregue como anexo a quem pode editar pedidos.
		exit;
	}

	/**
	 * Adiciona a coluna "NFS-e" depois da coluna de status.
	 *
	 * @param array $colunas Colunas.
	 * @return array
	 */
	public static function colunas( $colunas ) {
		if ( ! is_array( $colunas ) ) {
			return $colunas;
		}
		$novas = array();
		foreach ( $colunas as $id => $rotulo ) {
			$novas[ $id ] = $rotulo;
			if ( 'order_status' === $id ) {
				$novas[ self::COLUNA ] = __( 'NFS-e', 'emissaoja-nfse' );
			}
		}
		if ( ! isset( $novas[ self::COLUNA ] ) ) {
			$novas[ self::COLUNA ] = __( 'NFS-e', 'emissaoja-nfse' );
		}
		return $novas;
	}

	/**
	 * Coluna na lista clássica (posts).
	 *
	 * @param string $coluna  Coluna.
	 * @param int    $post_id Id do pedido.
	 * @return void
	 */
	public static function coluna_legado( $coluna, $post_id ) {
		if ( self::COLUNA === $coluna ) {
			self::render_coluna( wc_get_order( $post_id ) );
		}
	}

	/**
	 * Coluna na lista do HPOS.
	 *
	 * @param string   $coluna Coluna.
	 * @param WC_Order $order  Pedido.
	 * @return void
	 */
	public static function coluna_hpos( $coluna, $order ) {
		if ( self::COLUNA === $coluna ) {
			self::render_coluna( $order );
		}
	}

	/**
	 * Carrega o estilo e o script nas telas de pedidos.
	 *
	 * @return void
	 */
	public static function enqueue() {
		$tela = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $tela || ! in_array( $tela->id, array( 'shop_order', 'edit-shop_order', self::tela_pedido() ), true ) ) {
			return;
		}
		wp_enqueue_style( 'emissaoja-nfse-admin', EMISSAOJA_NFSE_URL . 'assets/css/admin.css', array(), EMISSAOJA_NFSE_VERSION );
		wp_enqueue_script( 'emissaoja-nfse-pedido', EMISSAOJA_NFSE_URL . 'assets/js/admin-pedido.js', array(), EMISSAOJA_NFSE_VERSION, array( 'in_footer' => true ) );
	}

	/**
	 * Mostra (uma vez) o resultado do último botão clicado.
	 *
	 * @return void
	 */
	public static function aviso() {
		$chave = self::chave_aviso();
		$aviso = get_transient( $chave );
		if ( ! is_array( $aviso ) || empty( $aviso['mensagem'] ) ) {
			return;
		}
		delete_transient( $chave );
		$tipo = isset( $aviso['tipo'] ) && in_array( $aviso['tipo'], array( 'success', 'info', 'warning', 'error' ), true ) ? $aviso['tipo'] : 'info';
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong> %3$s</p></div>',
			esc_attr( $tipo ),
			esc_html__( 'NFS-e (emissaoja):', 'emissaoja-nfse' ),
			nl2br( esc_html( (string) $aviso['mensagem'] ) )
		);
	}

	/**
	 * Conteúdo da coluna.
	 *
	 * @param WC_Order|false $order Pedido.
	 * @return void
	 */
	private static function render_coluna( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$estado = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::ESTADO );
		if ( '' === $estado ) {
			echo '<span class="na">&ndash;</span>';
			return;
		}
		$numero = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::NUMERO );
		$texto  = Emissaoja_NFSe_Pedido::rotulo_estado( $estado );
		if ( 'autorizada' === $estado && '' !== $numero ) {
			/* translators: %s: número da NFS-e. */
			$texto = sprintf( __( 'Nº %s', 'emissaoja-nfse' ), $numero );
		}
		printf( '<span class="emissaoja-nfse-estado emissaoja-nfse-estado--%1$s">%2$s</span>', esc_attr( $estado ), esc_html( $texto ) );
	}

	/**
	 * Um botão (link) da caixa.
	 *
	 * @param string $url       Endereço (já com o nonce).
	 * @param string $rotulo    Texto.
	 * @param string $classe    Classes CSS.
	 * @param string $confirmar Pergunta de confirmação (vazia = sem confirmação).
	 * @return void
	 */
	private static function botao( $url, $rotulo, $classe, $confirmar ) {
		printf(
			'<a href="%1$s" class="%2$s" data-emissaoja-confirmar="%3$s">%4$s</a> ',
			esc_url( $url ),
			esc_attr( $classe ),
			esc_attr( $confirmar ),
			esc_html( $rotulo )
		);
	}

	/**
	 * Pergunta antes de emitir em produção (a nota tem efeito fiscal). No sandbox, não pergunta.
	 *
	 * @param WC_Order $order    Pedido.
	 * @param string   $ambiente Ambiente.
	 * @return string
	 */
	private static function confirmacao_emitir( $order, $ambiente ) {
		if ( 'sandbox' === $ambiente ) {
			return '';
		}
		$texto = __( 'Emitir a NFS-e deste pedido no Emissor Nacional? A nota tem efeito fiscal.', 'emissaoja-nfse' );
		if ( ! $order->is_paid() ) {
			/* translators: %s: status do pedido. */
			$texto .= ' ' . sprintf( __( 'Atenção: o pedido não está pago (status: %s).', 'emissaoja-nfse' ), wc_get_order_status_name( $order->get_status() ) );
		}
		return $texto;
	}

	/**
	 * Endereço de uma ação (admin-post.php com nonce da ação e do pedido).
	 *
	 * @param WC_Order $order Pedido.
	 * @param string   $acao  Ação.
	 * @return string
	 */
	private static function url_acao( $order, $acao ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'emissaoja_nfse_acao',
					'acao'     => $acao,
					'order_id' => $order->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			'emissaoja_nfse_' . $acao . '_' . $order->get_id()
		);
	}

	/**
	 * Endereço do download (admin-post.php com nonce do formato e do pedido).
	 *
	 * @param WC_Order $order   Pedido.
	 * @param string   $formato 'pdf' ou 'xml'.
	 * @return string
	 */
	private static function url_baixar( $order, $formato ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'emissaoja_nfse_baixar',
					'formato'  => $formato,
					'order_id' => $order->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			'emissaoja_nfse_baixar_' . $formato . '_' . $order->get_id()
		);
	}

	/**
	 * Id da tela de edição do pedido.
	 *
	 * @return string
	 */
	private static function tela_pedido() {
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$tela = wc_get_page_screen_id( 'shop-order' );
			if ( is_string( $tela ) && '' !== $tela ) {
				return $tela;
			}
		}
		return 'shop_order';
	}

	/**
	 * Transient do aviso do usuário atual.
	 *
	 * @return string
	 */
	private static function chave_aviso() {
		return 'emissaoja_nfse_aviso_' . get_current_user_id();
	}
}
