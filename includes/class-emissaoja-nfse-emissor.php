<?php
/**
 * Emissão da NFS-e: tomador, POST /v1/notas, acompanhamento com o Action Scheduler, conferência e notas do pedido.
 *
 * @package Emissaoja_NFSe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fluxo da emissão de um pedido.
 *
 * 1. Cria (ou acha) o tomador pelo CPF/CNPJ: POST /v1/tomadores.
 * 2. Emite com a referência estável do pedido no Idempotency-Key: POST /v1/notas.
 * 3. Consulta GET /v1/emissoes/{id} em segundo plano, no ritmo de proxima_consulta_ms, até autorizada, recusada
 *    ou incerta (com limite de consultas).
 *
 * Falhas temporárias (rede, fila_indisponivel, rate_limited...) são repetidas com a MESMA referência, que nunca
 * emite duas vezes. Avisos de nota repetida (possivel_duplicada, emissao_repetida_recente) nunca são ignorados
 * sozinhos: só com o botão "Emitir mesmo assim" no pedido.
 */
final class Emissaoja_NFSe_Emissor {

	/**
	 * Grupo das ações no Action Scheduler.
	 */
	const GRUPO = 'emissaoja-nfse';

	/**
	 * Ação que emite a nota de um pedido.
	 */
	const HOOK_EMITIR = 'emissaoja_nfse_emitir';

	/**
	 * Ação que consulta a situação do pedido de emissão.
	 */
	const HOOK_CONSULTAR = 'emissaoja_nfse_consultar';

	/**
	 * Erros que valem para a conta inteira (viram aviso no painel).
	 *
	 * @var string[]
	 */
	private static $erros_da_conta = array( 'chave_invalida', 'limite_notas', 'sem_empresa', 'aceite_pendente', 'credencial_congelada' );

	/**
	 * Estados que a API devolve para o pedido de emissão.
	 *
	 * @var string[]
	 */
	private static $estados_api = array( 'na_fila', 'emitindo', 'confirmando', 'autorizada', 'recusada', 'incerta' );

	/**
	 * Registra os ganchos (em toda requisição: o Action Scheduler roda no cron e em requisições assíncronas).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK_EMITIR, array( __CLASS__, 'job_emitir' ), 10, 1 );
		add_action( self::HOOK_CONSULTAR, array( __CLASS__, 'job_consultar' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'ao_processar' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'ao_concluir' ), 20, 1 );
	}

	/**
	 * Pedido mudou para Processando (pago).
	 *
	 * @param int $order_id Id do pedido.
	 * @return void
	 */
	public static function ao_processar( $order_id ) {
		if ( 'processing' === Emissaoja_NFSe_Settings::get( 'quando' ) ) {
			self::automatica( $order_id, false );
		}
	}

	/**
	 * Pedido mudou para Concluído.
	 *
	 * @param int $order_id Id do pedido.
	 * @return void
	 */
	public static function ao_concluir( $order_id ) {
		$quando = Emissaoja_NFSe_Settings::get( 'quando' );
		if ( 'completed' === $quando ) {
			self::automatica( $order_id, false );
		} elseif ( 'processing' === $quando ) {
			// Pedidos só com produtos virtuais vão direto para Concluído, sem passar por Processando.
			self::automatica( $order_id, true );
		}
	}

	/**
	 * Ação do Action Scheduler: emite a nota do pedido.
	 *
	 * @param int $order_id Id do pedido.
	 * @return void
	 */
	public static function job_emitir( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( $order instanceof WC_Order ) {
			self::emitir( $order, 'automatica' );
		}
	}

	/**
	 * Ação do Action Scheduler: consulta a situação do pedido de emissão.
	 *
	 * @param int $order_id Id do pedido.
	 * @return void
	 */
	public static function job_consultar( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( $order instanceof WC_Order ) {
			self::consultar( $order, true );
		}
	}

	/**
	 * Emite a NFS-e do pedido.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param string   $origem 'manual' (botão), 'confirmada' (botão "Emitir mesmo assim") ou 'automatica'.
	 * @return array Resultado para o aviso no painel: 'tipo' (success, info, warning, error) e 'mensagem'.
	 */
	public static function emitir( $order, $origem = 'manual' ) {
		$manual   = in_array( $origem, array( 'manual', 'confirmada' ), true );
		$ambiente = 'sandbox' === Emissaoja_NFSe_Settings::get( 'ambiente' ) ? 'sandbox' : 'producao';

		if ( '' === Emissaoja_NFSe_Settings::api_key() ) {
			return self::resultado( 'error', __( 'Configure a chave de API do emissaoja em WooCommerce > Configurações > NFS-e (emissaoja).', 'emissaoja-nfse' ) );
		}
		if ( $manual ) {
			$order->update_meta_data( Emissaoja_NFSe_Pedido::RETENTATIVAS, 0 );
		}

		// Pedido que já tem pedido de emissão.
		$pedido_id = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::PEDIDO_ID );
		if ( '' !== $pedido_id ) {
			$estado          = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::ESTADO );
			$ambiente_pedido = Emissaoja_NFSe_Pedido::ambiente( $order );
			if ( $ambiente_pedido !== $ambiente ) {
				if ( 'producao' === $ambiente_pedido ) {
					return self::resultado( 'error', __( 'Este pedido já tem NFS-e pedida em produção. O plugin está no sandbox: volte o ambiente para Produção.', 'emissaoja-nfse' ) );
				}
				if ( ! $manual ) {
					return self::resultado( 'info', __( 'Este pedido tem uma emissão de teste (sandbox). Use "Emitir NFS-e agora" para emitir em produção.', 'emissaoja-nfse' ) );
				}
				// Teste no sandbox e loja agora em produção: deixa o teste de lado e emite de verdade.
				self::nota(
					$order,
					/* translators: %s: id do pedido de emissão no sandbox. */
					sprintf( __( 'emissaoja: a emissão de teste (sandbox, pedido %s) foi deixada de lado; a NFS-e agora será emitida em produção.', 'emissaoja-nfse' ), $pedido_id ),
					$origem
				);
				Emissaoja_NFSe_Pedido::limpar_emissao( $order, true );
			} elseif ( 'recusada' === $estado && $manual ) {
				// A mesma referência devolveria o pedido recusado: a nova tentativa ganha uma referência nova.
				$tentativa = max( 1, (int) Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::TENTATIVA ) ) + 1;
				Emissaoja_NFSe_Pedido::limpar_emissao( $order, false );
				$order->update_meta_data( Emissaoja_NFSe_Pedido::TENTATIVA, $tentativa );
				$order->update_meta_data( Emissaoja_NFSe_Pedido::REFERENCIA, Emissaoja_NFSe_Regras::referencia( Emissaoja_NFSe_Settings::url_site(), $order->get_id(), $tentativa ) );
			} else {
				if ( ! Emissaoja_NFSe_Regras::estado_terminal( $estado ) ) {
					self::agendar_consulta( $order, 10, false );
				}
				return self::resultado(
					'info',
					/* translators: %s: situação da NFS-e (ex.: Autorizada). */
					sprintf( __( 'Este pedido já tem um pedido de emissão (%s). Nada foi reenviado.', 'emissaoja-nfse' ), Emissaoja_NFSe_Pedido::rotulo_estado( $estado ) )
				);
			}
		}

		$documento = Emissaoja_NFSe_Pedido::documento( $order );
		if ( null === $documento ) {
			return self::falhou( $order, self::erro( 'sem_documento', __( 'O pedido não tem CPF nem CNPJ válido do cliente. Preencha o documento nos dados de cobrança do pedido e emita de novo.', 'emissaoja-nfse' ) ), $origem );
		}
		$valor = Emissaoja_NFSe_Pedido::valor( $order );
		if ( $valor <= 0 ) {
			return self::falhou( $order, self::erro( 'valor_zerado', __( 'O valor da nota ficou zerado (pedido gratuito ou reembolsado). Nada foi emitido.', 'emissaoja-nfse' ) ), $origem );
		}

		// Referência estável: calculada na primeira tentativa e guardada no pedido antes de chamar a API.
		$referencia = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::REFERENCIA );
		if ( ! Emissaoja_NFSe_Regras::referencia_valida( $referencia ) ) {
			$tentativa  = max( 1, (int) Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::TENTATIVA ) );
			$referencia = Emissaoja_NFSe_Regras::referencia( Emissaoja_NFSe_Settings::url_site(), $order->get_id(), $tentativa );
			$order->update_meta_data( Emissaoja_NFSe_Pedido::TENTATIVA, $tentativa );
			$order->update_meta_data( Emissaoja_NFSe_Pedido::REFERENCIA, $referencia );
		}
		$order->update_meta_data( Emissaoja_NFSe_Pedido::AMBIENTE, $ambiente );
		$order->save();

		$api     = Emissaoja_NFSe_Api::da_loja( $ambiente );
		$tomador = self::tomador( $order, $api, $documento );
		if ( is_wp_error( $tomador ) ) {
			return self::falhou( $order, $tomador, $origem );
		}

		$campos    = array(
			'tomador_tipo' => $tomador['tipo'],
			'tomador_id'   => $tomador['id'],
			'valor'        => $valor,
		);
		$descricao = Emissaoja_NFSe_Pedido::descricao( $order );
		if ( '' !== $descricao ) {
			$campos['descricao'] = $descricao;
		}
		$servico = (string) Emissaoja_NFSe_Settings::get( 'servico_id' );
		if ( '' !== $servico ) {
			$campos['servico_id'] = $servico;
		}
		if ( 'confirmada' === $origem ) {
			// O "sim" consciente à nota igual: a trava de 2 minutos e a nota igual de hoje mostrada no aviso.
			$campos['confirmo_repeticao'] = true;
			$repetida                     = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::REPETIDA_DE );
			if ( Emissaoja_NFSe_Regras::id_valido( $repetida ) ) {
				$campos['permitir_repetida_de'] = $repetida;
			}
		}

		/**
		 * Filtra os campos enviados em POST /v1/notas (tomador_tipo, tomador_id, valor, descricao, servico_id...).
		 *
		 * @param array    $campos Campos da nota, em snake_case como a API.
		 * @param WC_Order $order  Pedido.
		 */
		$campos = (array) apply_filters( 'emissaoja_nfse_campos_nota', $campos, $order );

		$resposta = $api->emitir( $referencia, $campos );
		if ( is_wp_error( $resposta ) && 'tomador_nao_encontrado' === $resposta->get_error_code() && $tomador['guardado'] ) {
			// O tomador guardado no pedido não existe nesta conta (a chave mudou de conta): cria de novo e repete
			// uma vez, com a mesma referência (o erro veio antes de existir pedido de emissão).
			$order->delete_meta_data( Emissaoja_NFSe_Pedido::TOMADOR );
			$tomador = self::tomador( $order, $api, $documento );
			if ( is_wp_error( $tomador ) ) {
				return self::falhou( $order, $tomador, $origem );
			}
			$campos['tomador_tipo'] = $tomador['tipo'];
			$campos['tomador_id']   = $tomador['id'];
			$resposta               = $api->emitir( $referencia, $campos );
		}
		$adotado = false;
		if ( is_wp_error( $resposta ) && 'chave_idempotencia_conflito' === $resposta->get_error_code() ) {
			// A referência já criou um pedido de emissão com outros dados (o pedido mudou depois): é ele o deste pedido.
			$extras = Emissaoja_NFSe_Api::dados_erro( $resposta )['extras'];
			if ( isset( $extras['pedido_id'] ) && is_string( $extras['pedido_id'] ) && '' !== $extras['pedido_id'] ) {
				$resposta = $api->emissao( $extras['pedido_id'] );
				$adotado  = true;
			}
		}
		if ( is_wp_error( $resposta ) ) {
			return self::falhou( $order, $resposta, $origem );
		}

		$pedido = isset( $resposta['pedido'] ) && is_array( $resposta['pedido'] ) ? $resposta['pedido'] : array();
		if ( empty( $pedido['id'] ) || ! is_scalar( $pedido['id'] ) ) {
			return self::falhou( $order, self::erro( 'resposta_inesperada', __( 'A API do emissaoja respondeu sem o pedido de emissão. Tente de novo: a mesma referência não emite duas vezes.', 'emissaoja-nfse' ) ), $origem );
		}

		$order->delete_meta_data( Emissaoja_NFSe_Pedido::ERRO );
		$order->delete_meta_data( Emissaoja_NFSe_Pedido::ERRO_CODE );
		$order->delete_meta_data( Emissaoja_NFSe_Pedido::REPETIDA_DE );
		$order->update_meta_data( Emissaoja_NFSe_Pedido::RETENTATIVAS, 0 );
		$order->update_meta_data( Emissaoja_NFSe_Pedido::CONSULTAS, 0 );

		if ( $adotado ) {
			$texto = sprintf(
				/* translators: 1: referência, 2: id do pedido de emissão. */
				__( 'emissaoja: a referência %1$s já tinha criado um pedido de emissão com outros dados (o pedido mudou depois). Acompanhando o pedido de emissão original (%2$s); confira o valor da nota.', 'emissaoja-nfse' ),
				$referencia,
				(string) $pedido['id']
			);
		} elseif ( ! empty( $resposta['repetido'] ) ) {
			$texto = sprintf(
				/* translators: 1: referência, 2: id do pedido de emissão. */
				__( 'emissaoja: a referência %1$s já tinha sido enviada; acompanhando o mesmo pedido de emissão (%2$s). Nada foi emitido em dobro.', 'emissaoja-nfse' ),
				$referencia,
				(string) $pedido['id']
			);
		} else {
			$texto = sprintf(
				/* translators: 1: CPF ou CNPJ, 2: documento formatado, 3: valor, 4: referência. */
				__( 'emissaoja: pedido de emissão da NFS-e enviado (tomador %1$s %2$s, valor %3$s, referência %4$s).', 'emissaoja-nfse' ),
				Emissaoja_NFSe_Documento::rotulo( $documento['tipo'] ),
				Emissaoja_NFSe_Documento::formatar( $documento['numero'] ),
				Emissaoja_NFSe_Pedido::valor_formatado( $order, $valor ),
				$referencia
			);
			if ( 'sandbox' === $ambiente ) {
				$texto .= ' ' . __( 'Sandbox: nada vai ao fisco.', 'emissaoja-nfse' );
			}
		}
		self::nota( $order, $texto, $origem );

		$estado = self::aplicar_pedido( $order, $pedido, $origem );
		self::desagendar( self::HOOK_EMITIR, $order->get_id() );

		if ( ! Emissaoja_NFSe_Regras::estado_terminal( $estado ) ) {
			self::agendar_consulta( $order, Emissaoja_NFSe_Regras::intervalo_consulta( isset( $pedido['proxima_consulta_ms'] ) ? $pedido['proxima_consulta_ms'] : null ), true );
			return self::resultado( 'success', __( 'Pedido de emissão enviado. A situação da NFS-e aparece neste pedido assim que o Emissor Nacional responder.', 'emissaoja-nfse' ) );
		}
		return self::resultado(
			'autorizada' === $estado ? 'success' : 'warning',
			/* translators: %s: situação da NFS-e (ex.: Autorizada). */
			sprintf( __( 'Situação da NFS-e: %s.', 'emissaoja-nfse' ), Emissaoja_NFSe_Pedido::rotulo_estado( $estado ) )
		);
	}

	/**
	 * Consulta a situação do pedido de emissão (GET /v1/emissoes/{id}) e atualiza o pedido.
	 *
	 * @param WC_Order $order    Pedido.
	 * @param bool     $agendado Se veio do Action Scheduler (conta no limite de consultas automáticas).
	 * @return array Resultado para o aviso no painel.
	 */
	public static function consultar( $order, $agendado = false ) {
		$pedido_id = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::PEDIDO_ID );
		if ( '' === $pedido_id ) {
			return self::resultado( 'info', __( 'Este pedido ainda não tem pedido de emissão.', 'emissaoja-nfse' ) );
		}
		$origem   = $agendado ? 'automatica' : 'manual';
		$api      = Emissaoja_NFSe_Api::da_loja( Emissaoja_NFSe_Pedido::ambiente( $order ) );
		$resposta = $api->emissao( $pedido_id );

		if ( is_wp_error( $resposta ) ) {
			$code  = (string) $resposta->get_error_code();
			$dados = Emissaoja_NFSe_Api::dados_erro( $resposta );
			$texto = self::mensagem_erro( $resposta );
			if ( Emissaoja_NFSe_Regras::erro_temporario( $code, $dados['status'] ) ) {
				if ( $agendado ) {
					$retry = isset( $dados['extras']['retry_after'] ) ? (int) $dados['extras']['retry_after'] : 0;
					self::proxima_consulta( $order, max( 60, $retry ) );
				}
				return self::resultado( 'warning', $texto );
			}
			if ( 'pedido_nao_encontrado' === $code ) {
				// O pedido de emissão não existe nesta conta (no sandbox, as notas somem a cada publicação).
				Emissaoja_NFSe_Pedido::limpar_emissao( $order, false );
				$order->update_meta_data( Emissaoja_NFSe_Pedido::ESTADO, 'erro' );
				$texto = sprintf(
					/* translators: 1: id do pedido de emissão, 2: mensagem da API. */
					__( 'O pedido de emissão %1$s não foi encontrado nesta conta do emissaoja (%2$s). Se a nota não existe, emita de novo.', 'emissaoja-nfse' ),
					$pedido_id,
					$texto
				);
			}
			$order->update_meta_data( Emissaoja_NFSe_Pedido::ERRO, $texto );
			$order->update_meta_data( Emissaoja_NFSe_Pedido::ERRO_CODE, $code );
			/* translators: %s: mensagem do erro. */
			self::nota( $order, sprintf( __( 'emissaoja: não foi possível consultar a NFS-e. %s', 'emissaoja-nfse' ), $texto ), $origem );
			$order->save();
			self::registrar_erro_da_conta( $resposta );
			return self::resultado( 'error', $texto );
		}

		$pedido = isset( $resposta['pedido'] ) && is_array( $resposta['pedido'] ) ? $resposta['pedido'] : array();
		if ( empty( $pedido['id'] ) || ! is_scalar( $pedido['id'] ) ) {
			$pedido['id'] = $pedido_id;
		}
		$estado = self::aplicar_pedido( $order, $pedido, $origem );

		if ( Emissaoja_NFSe_Regras::estado_terminal( $estado ) ) {
			return self::resultado(
				'autorizada' === $estado ? 'success' : 'warning',
				/* translators: %s: situação da NFS-e (ex.: Autorizada). */
				sprintf( __( 'Situação da NFS-e: %s.', 'emissaoja-nfse' ), Emissaoja_NFSe_Pedido::rotulo_estado( $estado ) )
			);
		}

		$segundos = Emissaoja_NFSe_Regras::intervalo_consulta( isset( $pedido['proxima_consulta_ms'] ) ? $pedido['proxima_consulta_ms'] : null );
		if ( $agendado ) {
			self::proxima_consulta( $order, $segundos );
		} else {
			self::agendar_consulta( $order, $segundos, true );
		}
		return self::resultado(
			'info',
			/* translators: %s: situação da NFS-e (ex.: Emitindo). */
			sprintf( __( 'Situação da NFS-e: %s. O plugin continua acompanhando.', 'emissaoja-nfse' ), Emissaoja_NFSe_Pedido::rotulo_estado( $estado ) )
		);
	}

	/**
	 * Pede a conferência de um pedido incerto (POST /v1/emissoes/{id}/conferir) e volta a acompanhar.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array Resultado para o aviso no painel.
	 */
	public static function conferir( $order ) {
		$pedido_id = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::PEDIDO_ID );
		if ( '' === $pedido_id ) {
			return self::resultado( 'info', __( 'Este pedido ainda não tem pedido de emissão.', 'emissaoja-nfse' ) );
		}
		$api      = Emissaoja_NFSe_Api::da_loja( Emissaoja_NFSe_Pedido::ambiente( $order ) );
		$resposta = $api->conferir( $pedido_id );

		if ( is_wp_error( $resposta ) ) {
			$code = (string) $resposta->get_error_code();
			if ( 'nao_incerta' === $code ) {
				return self::consultar( $order, false );
			}
			if ( 'reconciliacao_em_andamento' === $code ) {
				self::agendar_consulta( $order, 10, true );
				return self::resultado( 'info', __( 'A conferência já está em andamento no portal. A situação aparece neste pedido em instantes.', 'emissaoja-nfse' ) );
			}
			$texto = self::mensagem_erro( $resposta );
			/* translators: %s: mensagem do erro. */
			self::nota( $order, sprintf( __( 'emissaoja: não foi possível pedir a conferência da NFS-e. %s', 'emissaoja-nfse' ), $texto ), 'manual' );
			self::registrar_erro_da_conta( $resposta );
			return self::resultado( 'error', $texto );
		}

		self::nota( $order, __( 'emissaoja: conferência da NFS-e incerta pedida ao Emissor Nacional.', 'emissaoja-nfse' ), 'manual' );
		$pedido = isset( $resposta['pedido'] ) && is_array( $resposta['pedido'] ) ? $resposta['pedido'] : array();
		if ( empty( $pedido['id'] ) || ! is_scalar( $pedido['id'] ) ) {
			$pedido['id'] = $pedido_id;
		}
		$estado = self::aplicar_pedido( $order, $pedido, 'manual' );
		if ( ! Emissaoja_NFSe_Regras::estado_terminal( $estado ) ) {
			self::agendar_consulta( $order, Emissaoja_NFSe_Regras::intervalo_consulta( isset( $pedido['proxima_consulta_ms'] ) ? $pedido['proxima_consulta_ms'] : null ), true );
		}
		return self::resultado( 'success', __( 'Conferência pedida. A situação da NFS-e aparece neste pedido assim que o portal responder.', 'emissaoja-nfse' ) );
	}

	/**
	 * Atualiza a situação agora (botão "Atualizar situação").
	 *
	 * @param WC_Order $order Pedido.
	 * @return array Resultado para o aviso no painel.
	 */
	public static function atualizar( $order ) {
		return self::consultar( $order, false );
	}

	/**
	 * Data da próxima ação agendada para o pedido (timestamp), ou 0.
	 *
	 * @param string $hook     Ação.
	 * @param int    $order_id Id do pedido.
	 * @return int
	 */
	public static function proximo_agendamento( $hook, $order_id ) {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 0;
		}
		$proxima = as_next_scheduled_action( $hook, self::args( $order_id ), self::GRUPO );
		if ( true === $proxima ) {
			return time();
		}
		return is_numeric( $proxima ) ? (int) $proxima : 0;
	}

	/**
	 * Emissão automática por mudança de status (agenda uma ação assíncrona; não atrasa o checkout).
	 *
	 * @param int  $order_id        Id do pedido.
	 * @param bool $so_primeira_vez Só se o pedido nunca passou pelo plugin.
	 * @return void
	 */
	private static function automatica( $order_id, $so_primeira_vez ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || '' === Emissaoja_NFSe_Settings::api_key() ) {
			return;
		}
		// Já tem pedido de emissão (inclusive recusado: emitir de novo é decisão de quem cuida da loja).
		if ( '' !== Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::PEDIDO_ID ) ) {
			return;
		}
		if ( $so_primeira_vez && '' !== Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::ESTADO ) ) {
			return;
		}
		if ( self::tem_agendamento( self::HOOK_EMITIR, $order->get_id() ) ) {
			return;
		}

		$permitida = true;
		if ( 'producao' === Emissaoja_NFSe_Settings::get( 'ambiente' ) && function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
			$permitida = false;
		}

		/**
		 * Se a emissão automática pode acontecer neste pedido. Por padrão, não acontece em produção quando o site
		 * não está marcado como produção (WP_ENVIRONMENT_TYPE): uma cópia de teste da loja não emite nota de verdade.
		 *
		 * @param bool     $permitida Se pode emitir.
		 * @param WC_Order $order     Pedido.
		 */
		if ( ! apply_filters( 'emissaoja_nfse_emissao_automatica', $permitida, $order ) ) {
			if ( ! $permitida ) {
				self::nota( $order, __( 'emissaoja: emissão automática não feita: este site não está marcado como produção (WP_ENVIRONMENT_TYPE) e o plugin está no ambiente de Produção. Se este for o site real, use "Emitir NFS-e agora" no pedido.', 'emissaoja-nfse' ), 'automatica' );
			}
			return;
		}

		$order->update_meta_data( Emissaoja_NFSe_Pedido::ESTADO, 'agendada' );
		$order->update_meta_data( Emissaoja_NFSe_Pedido::RETENTATIVAS, 0 );
		$order->delete_meta_data( Emissaoja_NFSe_Pedido::ERRO );
		$order->delete_meta_data( Emissaoja_NFSe_Pedido::ERRO_CODE );
		$order->save();
		self::enfileirar( self::HOOK_EMITIR, $order->get_id() );
	}

	/**
	 * Cria (ou acha) o tomador da nota. Guarda no pedido o tipo, o id, o documento e o ambiente, para as próximas
	 * tentativas no mesmo ambiente.
	 *
	 * @param WC_Order           $order     Pedido.
	 * @param Emissaoja_NFSe_Api $api       Cliente.
	 * @param array              $documento Documento ('tipo' e 'numero').
	 * @return array|WP_Error Array com 'tipo', 'id' e 'guardado' (se veio do que estava guardado no pedido).
	 */
	private static function tomador( $order, $api, array $documento ) {
		$guardado = explode( '|', Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::TOMADOR ) );
		if ( 4 === count( $guardado ) && $guardado[2] === $documento['numero'] && $guardado[3] === $api->ambiente() && Emissaoja_NFSe_Regras::id_valido( $guardado[1] ) ) {
			return array(
				'tipo'     => $guardado[0],
				'id'       => $guardado[1],
				'guardado' => true,
			);
		}

		$nome = '';
		if ( 'pf' === $documento['tipo'] ) {
			$nome = Emissaoja_NFSe_Pedido::nome_cliente( $order );
		}
		$resposta = $api->criar_tomador( $documento['numero'], $nome );
		if ( is_wp_error( $resposta ) ) {
			return $resposta;
		}

		$tomador = isset( $resposta['tomador'] ) && is_array( $resposta['tomador'] ) ? $resposta['tomador'] : array();
		$tipo    = isset( $tomador['tipo'] ) && in_array( $tomador['tipo'], array( 'pf', 'pj' ), true ) ? $tomador['tipo'] : $documento['tipo'];
		$id      = isset( $tomador['id'] ) && is_string( $tomador['id'] ) ? $tomador['id'] : '';
		if ( ! Emissaoja_NFSe_Regras::id_valido( $id ) ) {
			return self::erro( 'resposta_inesperada', __( 'A API do emissaoja respondeu sem o id do tomador.', 'emissaoja-nfse' ) );
		}
		$order->update_meta_data( Emissaoja_NFSe_Pedido::TOMADOR, $tipo . '|' . $id . '|' . $documento['numero'] . '|' . $api->ambiente() );
		$order->save();
		return array(
			'tipo'     => $tipo,
			'id'       => $id,
			'guardado' => false,
		);
	}

	/**
	 * Registra uma falha antes de existir o pedido de emissão. Falhas temporárias são repetidas sozinhas,
	 * com a mesma referência (no máximo RETENTATIVAS_MAX vezes); as outras ficam no pedido para quem cuida da loja.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param WP_Error $erro   Erro.
	 * @param string   $origem Origem da emissão.
	 * @return array Resultado para o aviso no painel.
	 */
	private static function falhou( $order, $erro, $origem ) {
		$code  = (string) $erro->get_error_code();
		$dados = Emissaoja_NFSe_Api::dados_erro( $erro );
		$texto = self::mensagem_erro( $erro );

		if ( Emissaoja_NFSe_Regras::erro_temporario( $code, $dados['status'] ) ) {
			$tentativa = (int) Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::RETENTATIVAS ) + 1;
			if ( $tentativa <= Emissaoja_NFSe_Regras::RETENTATIVAS_MAX ) {
				$retry  = isset( $dados['extras']['retry_after'] ) ? (int) $dados['extras']['retry_after'] : 0;
				$espera = Emissaoja_NFSe_Regras::espera_retentativa( $tentativa, $retry );
				$order->update_meta_data( Emissaoja_NFSe_Pedido::RETENTATIVAS, $tentativa );
				$order->update_meta_data( Emissaoja_NFSe_Pedido::ESTADO, 'agendada' );
				$order->update_meta_data( Emissaoja_NFSe_Pedido::ERRO, $texto );
				$order->update_meta_data( Emissaoja_NFSe_Pedido::ERRO_CODE, $code );
				self::nota(
					$order,
					sprintf(
						/* translators: 1: mensagem do erro, 2: minutos até a nova tentativa. */
						__( 'emissaoja: a NFS-e ainda não foi emitida (%1$s). Nova tentativa automática, com a mesma referência, em %2$d min.', 'emissaoja-nfse' ),
						$texto,
						max( 1, (int) ceil( $espera / 60 ) )
					),
					$origem
				);
				$order->save();
				self::agendar( self::HOOK_EMITIR, $order->get_id(), $espera );
				return self::resultado(
					'warning',
					/* translators: %s: mensagem do erro. */
					sprintf( __( 'A NFS-e ainda não saiu (%s). O plugin tenta de novo sozinho, com a mesma referência.', 'emissaoja-nfse' ), $texto )
				);
			}
		}

		$order->update_meta_data( Emissaoja_NFSe_Pedido::ESTADO, 'erro' );
		$order->update_meta_data( Emissaoja_NFSe_Pedido::ERRO, $texto );
		$order->update_meta_data( Emissaoja_NFSe_Pedido::ERRO_CODE, $code );
		$order->update_meta_data( Emissaoja_NFSe_Pedido::RETENTATIVAS, 0 );
		$repetida = self::pedido_repetido( $code, $dados['extras'] );
		if ( '' !== $repetida ) {
			$order->update_meta_data( Emissaoja_NFSe_Pedido::REPETIDA_DE, $repetida );
		} else {
			$order->delete_meta_data( Emissaoja_NFSe_Pedido::REPETIDA_DE );
		}

		/* translators: %s: mensagem do erro. */
		$nota = sprintf( __( 'emissaoja: a NFS-e não foi emitida. %s', 'emissaoja-nfse' ), $texto );
		if ( '' !== $dados['request_id'] ) {
			$nota .= ' (request_id: ' . $dados['request_id'] . ')';
		}
		self::nota( $order, $nota, $origem );
		$order->save();
		// Erro final: nenhuma nova tentativa automática fica pendente (a ação em execução não é afetada).
		self::desagendar( self::HOOK_EMITIR, $order->get_id() );

		self::registrar_erro_da_conta( $erro );
		self::log( sprintf( 'Pedido #%d: %s', $order->get_id(), $texto ) );
		return self::resultado( 'error', $texto );
	}

	/**
	 * Grava no pedido a situação devolvida pela API e escreve as notas das mudanças de estado.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param array    $pedido Pedido de emissão da API.
	 * @param string   $origem Origem (para o autor das notas).
	 * @return string Estado.
	 */
	private static function aplicar_pedido( $order, array $pedido, $origem ) {
		$anterior = Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::ESTADO );
		$estado   = isset( $pedido['estado'] ) && is_string( $pedido['estado'] ) ? $pedido['estado'] : '';
		if ( ! in_array( $estado, self::$estados_api, true ) ) {
			$estado = 'confirmando';
		}

		$texto = static function ( $campo ) use ( $pedido ) {
			return isset( $pedido[ $campo ] ) && is_scalar( $pedido[ $campo ] ) ? sanitize_text_field( (string) $pedido[ $campo ] ) : '';
		};
		$numero = $texto( 'numero' );
		$chave  = $texto( 'chave_acesso' );
		$url    = isset( $pedido['consulta_publica_url'] ) && is_string( $pedido['consulta_publica_url'] ) ? esc_url_raw( $pedido['consulta_publica_url'], array( 'https', 'http' ) ) : '';
		$motivo = '' !== $texto( 'motivo' ) ? $texto( 'motivo' ) : $texto( 'motivo_code' );
		$acoes  = array();
		if ( isset( $pedido['acoes'] ) && is_array( $pedido['acoes'] ) ) {
			foreach ( $pedido['acoes'] as $acao ) {
				if ( is_string( $acao ) && '' !== $acao ) {
					$acoes[] = sanitize_key( $acao );
				}
			}
		}

		$order->update_meta_data( Emissaoja_NFSe_Pedido::PEDIDO_ID, $texto( 'id' ) );
		$order->update_meta_data( Emissaoja_NFSe_Pedido::ESTADO, $estado );
		$order->update_meta_data( Emissaoja_NFSe_Pedido::ACOES, implode( ',', $acoes ) );
		self::meta_ou_apaga( $order, Emissaoja_NFSe_Pedido::NUMERO, $numero );
		self::meta_ou_apaga( $order, Emissaoja_NFSe_Pedido::CHAVE_ACESSO, $chave );
		self::meta_ou_apaga( $order, Emissaoja_NFSe_Pedido::CONSULTA_URL, $url );
		self::meta_ou_apaga( $order, Emissaoja_NFSe_Pedido::MOTIVO, ( 'recusada' === $estado || 'incerta' === $estado ) ? $motivo : '' );
		if ( isset( $pedido['nota'] ) && is_array( $pedido['nota'] ) && isset( $pedido['nota']['id'] ) && is_scalar( $pedido['nota']['id'] ) && '' !== (string) $pedido['nota']['id'] ) {
			$order->update_meta_data( Emissaoja_NFSe_Pedido::NOTA_ORIGEM, isset( $pedido['nota']['origem'] ) && 'importada' === $pedido['nota']['origem'] ? 'importada' : 'local' );
			$order->update_meta_data( Emissaoja_NFSe_Pedido::NOTA_ID, sanitize_text_field( (string) $pedido['nota']['id'] ) );
		}
		if ( 'autorizada' === $estado ) {
			$order->delete_meta_data( Emissaoja_NFSe_Pedido::ERRO );
			$order->delete_meta_data( Emissaoja_NFSe_Pedido::ERRO_CODE );
		}

		if ( $estado !== $anterior ) {
			if ( 'autorizada' === $estado ) {
				/* translators: 1: número da NFS-e, 2: chave de acesso. */
				$nota = sprintf( __( 'emissaoja: NFS-e nº %1$s autorizada pelo Emissor Nacional. Chave de acesso: %2$s.', 'emissaoja-nfse' ), $numero, $chave );
				if ( 'sandbox' === Emissaoja_NFSe_Pedido::ambiente( $order ) ) {
					$nota .= ' ' . __( 'Sandbox: nota de teste, nada foi enviado ao fisco e o cliente não foi avisado.', 'emissaoja-nfse' );
				}
				self::nota( $order, $nota, $origem );
				self::avisar_cliente( $order, $numero, $url );
			} elseif ( 'recusada' === $estado ) {
				self::nota(
					$order,
					/* translators: %s: motivo da recusa. */
					sprintf( __( 'emissaoja: a NFS-e foi recusada. Motivo: %s. Corrija o que for preciso e use "Emitir novamente" no pedido.', 'emissaoja-nfse' ), '' !== $motivo ? $motivo : '?' ),
					$origem
				);
			} elseif ( 'incerta' === $estado ) {
				self::nota( $order, __( 'emissaoja: o Emissor Nacional não confirmou se a NFS-e saiu (situação incerta). Use "Conferir" no pedido e não emita de novo antes disso.', 'emissaoja-nfse' ), $origem );
			}
		}

		$order->save();
		return $estado;
	}

	/**
	 * Avisa o cliente (nota do pedido para o cliente, enviada por e-mail pelo WooCommerce) com o número e o link
	 * da consulta pública. Só em produção, uma vez por pedido, e se a opção estiver ligada.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param string   $numero Número da NFS-e.
	 * @param string   $url    Consulta pública.
	 * @return void
	 */
	private static function avisar_cliente( $order, $numero, $url ) {
		if ( ! Emissaoja_NFSe_Settings::get( 'nota_cliente' ) || '' === $numero ) {
			return;
		}
		if ( 'sandbox' === Emissaoja_NFSe_Pedido::ambiente( $order ) || '1' === Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::CLIENTE_AVISADO ) ) {
			return;
		}
		/* translators: %s: número da NFS-e. */
		$texto = sprintf( esc_html__( 'Sua nota fiscal de serviço (NFS-e) nº %s foi emitida.', 'emissaoja-nfse' ), esc_html( $numero ) );
		if ( '' !== $url ) {
			$link   = '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
			$texto .= ' ' . sprintf(
				/* translators: %s: link da consulta pública no Portal Nacional da NFS-e. */
				esc_html__( 'Consulte no Portal Nacional da NFS-e: %s', 'emissaoja-nfse' ),
				$link
			);
		}
		$order->add_order_note( $texto, 1, false );
		$order->update_meta_data( Emissaoja_NFSe_Pedido::CLIENTE_AVISADO, '1' );
	}

	/**
	 * Texto do erro para o pedido: a mensagem da API, os bloqueios (nota_bloqueada) e o código.
	 *
	 * @param WP_Error $erro Erro.
	 * @return string
	 */
	private static function mensagem_erro( $erro ) {
		$code   = (string) $erro->get_error_code();
		$extras = Emissaoja_NFSe_Api::dados_erro( $erro )['extras'];
		$texto  = trim( $erro->get_error_message() );

		if ( 'nota_bloqueada' === $code && isset( $extras['bloqueios'] ) && is_array( $extras['bloqueios'] ) ) {
			$itens = array();
			foreach ( $extras['bloqueios'] as $bloqueio ) {
				if ( is_array( $bloqueio ) && isset( $bloqueio['mensagem'] ) && is_string( $bloqueio['mensagem'] ) && '' !== trim( $bloqueio['mensagem'] ) ) {
					$itens[] = '- ' . sanitize_text_field( $bloqueio['mensagem'] );
				}
			}
			if ( ! empty( $itens ) ) {
				$texto .= "\n" . implode( "\n", $itens );
			}
		}
		if ( 'possivel_duplicada' === $code && isset( $extras['nota_igual_recente']['numero_nfse'] ) && is_scalar( $extras['nota_igual_recente']['numero_nfse'] ) && '' !== (string) $extras['nota_igual_recente']['numero_nfse'] ) {
			/* translators: %s: número da NFS-e igual emitida hoje. */
			$texto .= ' ' . sprintf( __( 'Nota igual de hoje: nº %s.', 'emissaoja-nfse' ), sanitize_text_field( (string) $extras['nota_igual_recente']['numero_nfse'] ) );
		}
		if ( in_array( $code, array( 'possivel_duplicada', 'emissao_repetida_recente' ), true ) ) {
			$texto .= ' ' . __( 'Se for mesmo outra nota, use "Emitir mesmo assim" no pedido.', 'emissaoja-nfse' );
		}
		if ( 'incerta_pendente' === $code ) {
			$texto .= ' ' . __( 'Não emita de novo: acompanhe a nota pendente no app do emissaoja.', 'emissaoja-nfse' );
		}
		if ( 'limite_notas' === $code ) {
			$texto .= ' ' . __( 'Veja os planos em https://emissaoja.com.br/#precos.', 'emissaoja-nfse' );
		}
		return $texto . ' [' . $code . ']';
	}

	/**
	 * Id do pedido de emissão que o aviso de nota repetida mostrou (para o "sim" do botão "Emitir mesmo assim").
	 *
	 * @param string $code   Código do erro.
	 * @param array  $extras Extras do erro.
	 * @return string Id, ou '' quando o erro não é de nota repetida.
	 */
	private static function pedido_repetido( $code, array $extras ) {
		$id = '';
		if ( 'possivel_duplicada' === $code && isset( $extras['nota_igual_recente']['pedido_id'] ) ) {
			$id = $extras['nota_igual_recente']['pedido_id'];
		} elseif ( 'emissao_repetida_recente' === $code && isset( $extras['pedido_id'] ) ) {
			$id = $extras['pedido_id'];
		}
		return Emissaoja_NFSe_Regras::id_valido( $id ) ? $id : '';
	}

	/**
	 * Guarda o erro da conta (chave recusada, limite do plano...) para o aviso no painel.
	 *
	 * @param WP_Error $erro Erro.
	 * @return void
	 */
	private static function registrar_erro_da_conta( $erro ) {
		$code = (string) $erro->get_error_code();
		if ( Emissaoja_NFSe_Api::dados_erro( $erro )['status'] > 0 && in_array( $code, self::$erros_da_conta, true ) ) {
			Emissaoja_NFSe_Settings::record_error( $code, $erro->get_error_message() );
		}
	}

	/**
	 * Agenda a próxima consulta automática, respeitando o limite.
	 *
	 * @param WC_Order $order    Pedido.
	 * @param int      $segundos Espera.
	 * @return void
	 */
	private static function proxima_consulta( $order, $segundos ) {
		$feitas = (int) Emissaoja_NFSe_Pedido::meta( $order, Emissaoja_NFSe_Pedido::CONSULTAS ) + 1;
		$order->update_meta_data( Emissaoja_NFSe_Pedido::CONSULTAS, $feitas );
		if ( $feitas >= Emissaoja_NFSe_Regras::CONSULTAS_MAX ) {
			self::nota( $order, __( 'emissaoja: a NFS-e ainda está em processamento no Emissor Nacional. O acompanhamento automático parou; use "Atualizar situação" no pedido mais tarde.', 'emissaoja-nfse' ), 'automatica' );
			$order->save();
			return;
		}
		$order->save();
		self::agendar( self::HOOK_CONSULTAR, $order->get_id(), (int) $segundos + wp_rand( 0, 5 ) );
	}

	/**
	 * Agenda uma consulta.
	 *
	 * @param WC_Order $order     Pedido.
	 * @param int      $segundos  Espera.
	 * @param bool     $reiniciar Se zera a contagem de consultas automáticas.
	 * @return void
	 */
	private static function agendar_consulta( $order, $segundos, $reiniciar ) {
		if ( $reiniciar ) {
			$order->update_meta_data( Emissaoja_NFSe_Pedido::CONSULTAS, 0 );
			$order->save();
		}
		self::agendar( self::HOOK_CONSULTAR, $order->get_id(), $segundos );
	}

	/**
	 * Argumentos das ações (sempre o mesmo formato, para o Action Scheduler achar e cancelar).
	 *
	 * @param int $order_id Id do pedido.
	 * @return array
	 */
	private static function args( $order_id ) {
		return array( 'order_id' => (int) $order_id );
	}

	/**
	 * Se há ação pendente ou rodando para o pedido.
	 *
	 * @param string $hook     Ação.
	 * @param int    $order_id Id do pedido.
	 * @return bool
	 */
	private static function tem_agendamento( $hook, $order_id ) {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( $hook, self::args( $order_id ), self::GRUPO );
		}
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			return false !== as_next_scheduled_action( $hook, self::args( $order_id ), self::GRUPO );
		}
		return false;
	}

	/**
	 * Agenda uma ação única (substitui a pendente do mesmo pedido).
	 *
	 * @param string $hook     Ação.
	 * @param int    $order_id Id do pedido.
	 * @param int    $segundos Espera.
	 * @return void
	 */
	private static function agendar( $hook, $order_id, $segundos ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		self::desagendar( $hook, $order_id );
		as_schedule_single_action( time() + max( 1, (int) $segundos ), $hook, self::args( $order_id ), self::GRUPO );
	}

	/**
	 * Enfileira uma ação assíncrona (roda assim que possível, fora da requisição atual).
	 *
	 * @param string $hook     Ação.
	 * @param int    $order_id Id do pedido.
	 * @return void
	 */
	private static function enfileirar( $hook, $order_id ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, self::args( $order_id ), self::GRUPO );
			return;
		}
		self::agendar( $hook, $order_id, 1 );
	}

	/**
	 * Cancela as ações pendentes do pedido.
	 *
	 * @param string $hook     Ação.
	 * @param int    $order_id Id do pedido.
	 * @return void
	 */
	private static function desagendar( $hook, $order_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, self::args( $order_id ), self::GRUPO );
		}
	}

	/**
	 * Grava uma meta, ou apaga quando o valor está vazio.
	 *
	 * @param WC_Order $order Pedido.
	 * @param string   $chave Meta.
	 * @param string   $valor Valor.
	 * @return void
	 */
	private static function meta_ou_apaga( $order, $chave, $valor ) {
		if ( '' === $valor ) {
			$order->delete_meta_data( $chave );
			return;
		}
		$order->update_meta_data( $chave, $valor );
	}

	/**
	 * Nota interna no pedido (texto puro).
	 *
	 * @param WC_Order $order  Pedido.
	 * @param string   $texto  Texto.
	 * @param string   $origem Origem: notas de ações manuais levam o nome de quem clicou.
	 * @return void
	 */
	private static function nota( $order, $texto, $origem ) {
		$order->add_order_note( esc_html( $texto ), 0, in_array( $origem, array( 'manual', 'confirmada' ), true ) );
	}

	/**
	 * Erro do próprio plugin (sem chamada à API).
	 *
	 * @param string $code     Código.
	 * @param string $mensagem Mensagem.
	 * @return WP_Error
	 */
	private static function erro( $code, $mensagem ) {
		return new WP_Error(
			$code,
			$mensagem,
			array(
				'status'     => 0,
				'extras'     => array(),
				'request_id' => '',
			)
		);
	}

	/**
	 * Resultado para o aviso no painel.
	 *
	 * @param string $tipo     success, info, warning ou error.
	 * @param string $mensagem Mensagem.
	 * @return array
	 */
	private static function resultado( $tipo, $mensagem ) {
		return array(
			'tipo'     => $tipo,
			'mensagem' => $mensagem,
		);
	}

	/**
	 * Registra no log do WooCommerce (WooCommerce > Status > Logs, fonte emissaoja-nfse). Nunca registra a chave.
	 *
	 * @param string $mensagem Mensagem.
	 * @return void
	 */
	private static function log( $mensagem ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $mensagem, array( 'source' => 'emissaoja-nfse' ) );
		}
	}
}
