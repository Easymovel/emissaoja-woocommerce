<?php
/**
 * Cliente HTTP da API do emissaoja (https://emissaoja.com.br/docs/), com wp_remote_request.
 *
 * @package Emissaoja_NFSe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fala com https://emissaoja.com.br (produção) ou https://sandbox.emissaoja.com.br (testes).
 *
 * Os métodos devolvem o JSON da resposta (array) ou um WP_Error cujo código é o `code` da API
 * (nota_bloqueada, possivel_duplicada, fila_indisponivel...) e cujos dados são
 * array( 'status' => HTTP, 'extras' => demais campos do erro, 'request_id' => x-request-id ).
 * Falha de rede vira o código `rede`; tempo esgotado, `tempo_esgotado`; sem chave, `chave_invalida` (status 0).
 *
 * O cliente não repete chamadas sozinho: quem repete (o emissor, com a MESMA referência) é que decide.
 */
final class Emissaoja_NFSe_Api {

	const BASE_PRODUCAO = 'https://emissaoja.com.br';
	const BASE_SANDBOX  = 'https://sandbox.emissaoja.com.br';

	/**
	 * Chave de API (ej_live_... ou ej_test_...).
	 *
	 * @var string
	 */
	private $chave;

	/**
	 * 'producao' ou 'sandbox'.
	 *
	 * @var string
	 */
	private $ambiente;

	/**
	 * URL base, sem barra no fim.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Cliente.
	 *
	 * @param string $chave    Chave de API.
	 * @param string $ambiente 'producao' ou 'sandbox'.
	 */
	public function __construct( $chave, $ambiente = 'producao' ) {
		$this->chave    = is_string( $chave ) ? trim( $chave ) : '';
		$this->ambiente = 'sandbox' === $ambiente ? 'sandbox' : 'producao';
		$base           = 'sandbox' === $this->ambiente ? self::BASE_SANDBOX : self::BASE_PRODUCAO;

		/**
		 * Filtra a URL base da API (por exemplo, para desenvolver contra um servidor local).
		 *
		 * @param string $base     URL base, sem barra no fim.
		 * @param string $ambiente 'producao' ou 'sandbox'.
		 */
		$this->base = untrailingslashit( (string) apply_filters( 'emissaoja_nfse_base_url', $base, $this->ambiente ) );
	}

	/**
	 * Cliente com a chave da loja.
	 *
	 * @param string $ambiente 'producao' ou 'sandbox'; vazio = o ambiente das configurações.
	 * @return Emissaoja_NFSe_Api
	 */
	public static function da_loja( $ambiente = '' ) {
		if ( '' === $ambiente ) {
			$ambiente = (string) Emissaoja_NFSe_Settings::get( 'ambiente' );
		}
		return new self( Emissaoja_NFSe_Settings::api_key(), $ambiente );
	}

	/**
	 * Ambiente deste cliente.
	 *
	 * @return string
	 */
	public function ambiente() {
		return $this->ambiente;
	}

	/**
	 * GET /v1/status (sem chave).
	 *
	 * @return array|WP_Error
	 */
	public function status() {
		return $this->json( 'GET', '/v1/status', null, array(), array(), false, 15 );
	}

	/**
	 * GET /v1/empresa: empresa conectada, credencial, onboarding e aceite.
	 *
	 * @return array|WP_Error
	 */
	public function empresa() {
		return $this->json( 'GET', '/v1/empresa' );
	}

	/**
	 * GET /v1/servicos?ativos=1.
	 *
	 * @return array|WP_Error Array com 'servicos'.
	 */
	public function servicos() {
		return $this->json( 'GET', '/v1/servicos', null, array( 'ativos' => '1' ) );
	}

	/**
	 * POST /v1/tomadores: cria (ou acha, com ja_existia) o tomador pelo documento.
	 *
	 * @param string $documento CPF ou CNPJ, só dígitos.
	 * @param string $nome      Nome completo (obrigatório para CPF; para CNPJ o nome vem da Receita).
	 * @return array|WP_Error Array com 'tomador' e 'ja_existia'.
	 */
	public function criar_tomador( $documento, $nome = '' ) {
		$corpo = array( 'documento' => (string) $documento );
		if ( '' !== $nome ) {
			$corpo['nome'] = (string) $nome;
		}
		return $this->json( 'POST', '/v1/tomadores', $corpo );
	}

	/**
	 * POST /v1/notas com Idempotency-Key = referência: prévia e emissão num passo.
	 *
	 * A referência é conferida antes de qualquer chamada. Campos nulos não vão no corpo.
	 *
	 * @param string $referencia Referência única da nota (8 a 64 caracteres: letras, números, _ e -).
	 * @param array  $campos     tomador_tipo, tomador_id, valor e opcionais (servico_id, descricao...).
	 * @return array|WP_Error Array com 'pedido' (e 'previa', ou 'repetido' quando a referência já foi usada).
	 */
	public function emitir( $referencia, array $campos ) {
		if ( ! Emissaoja_NFSe_Regras::referencia_valida( $referencia ) ) {
			return self::erro_local( 'chave_idempotencia_invalida', __( 'Referência inválida: use de 8 a 64 caracteres entre letras, números, _ e -.', 'emissaoja-nfse' ) );
		}
		$corpo = array();
		foreach ( $campos as $campo => $valor ) {
			if ( null !== $valor ) {
				$corpo[ $campo ] = $valor;
			}
		}
		return $this->json( 'POST', '/v1/notas', $corpo, array(), array( 'Idempotency-Key' => $referencia ), true, 30 );
	}

	/**
	 * GET /v1/emissoes/{id}: situação do pedido de emissão.
	 *
	 * @param string $pedido_id Id do pedido de emissão.
	 * @return array|WP_Error Array com 'pedido'.
	 */
	public function emissao( $pedido_id ) {
		return $this->json( 'GET', '/v1/emissoes/' . rawurlencode( (string) $pedido_id ) );
	}

	/**
	 * POST /v1/emissoes/{id}/conferir: confere no portal um pedido incerto.
	 *
	 * Vai com o corpo JSON {} explícito. A API (Fastify) recusa um POST sem corpo que traga Content-Type:
	 * application/json com corpo vazio dá 400 e application/x-www-form-urlencoded dá 415. E o WordPress
	 * (Requests, nos transportes cURL e fsockopen) sempre põe Content-Type form-urlencoded num POST sem corpo.
	 * Com {} o corpo não é vazio e o tipo é JSON; a rota ignora o corpo.
	 *
	 * @param string $pedido_id Id do pedido de emissão.
	 * @return array|WP_Error Array com 'pedido'.
	 */
	public function conferir( $pedido_id ) {
		return $this->json( 'POST', '/v1/emissoes/' . rawurlencode( (string) $pedido_id ) . '/conferir', new stdClass() );
	}

	/**
	 * GET /v1/notas/{origem}/{id}/pdf ou /xml: o arquivo da nota.
	 *
	 * @param string $origem  'local' ou 'importada'.
	 * @param string $nota_id Id da nota.
	 * @param string $formato 'pdf' ou 'xml'.
	 * @return array|WP_Error Array com 'corpo' (bytes), 'tipo' (Content-Type) e 'nome' (nome do arquivo sugerido pela API).
	 */
	public function arquivo( $origem, $nota_id, $formato ) {
		$formato  = 'xml' === $formato ? 'xml' : 'pdf';
		$caminho  = '/v1/notas/' . rawurlencode( (string) $origem ) . '/' . rawurlencode( (string) $nota_id ) . '/' . $formato;
		$resposta = $this->enviar( 'GET', $caminho, null, array(), array(), true, 30 );
		if ( is_wp_error( $resposta ) ) {
			return $resposta;
		}
		return array(
			'corpo' => (string) wp_remote_retrieve_body( $resposta ),
			'tipo'  => self::cabecalho( $resposta, 'content-type' ),
			'nome'  => self::nome_do_arquivo( self::cabecalho( $resposta, 'content-disposition' ) ),
		);
	}

	/**
	 * Dados de um erro devolvido por este cliente.
	 *
	 * @param WP_Error $erro Erro.
	 * @return array 'status' (int), 'extras' (array) e 'request_id' (string).
	 */
	public static function dados_erro( $erro ) {
		$dados = is_wp_error( $erro ) ? $erro->get_error_data() : null;
		$dados = is_array( $dados ) ? $dados : array();
		return array(
			'status'     => isset( $dados['status'] ) ? (int) $dados['status'] : 0,
			'extras'     => isset( $dados['extras'] ) && is_array( $dados['extras'] ) ? $dados['extras'] : array(),
			'request_id' => isset( $dados['request_id'] ) && is_string( $dados['request_id'] ) ? $dados['request_id'] : '',
		);
	}

	/**
	 * Chamada que devolve JSON.
	 *
	 * @param string     $metodo     Método HTTP.
	 * @param string     $caminho    Caminho a partir da base (segmentos já codificados).
	 * @param mixed|null $corpo      Corpo (vira JSON) ou null.
	 * @param array      $query      Parâmetros da query string (nulos e vazios ficam fora).
	 * @param array      $cabecalhos Cabeçalhos extras.
	 * @param bool       $autenticar Se manda a chave.
	 * @param int        $timeout    Tempo máximo, em segundos.
	 * @return array|WP_Error
	 */
	private function json( $metodo, $caminho, $corpo = null, array $query = array(), array $cabecalhos = array(), $autenticar = true, $timeout = 20 ) {
		$resposta = $this->enviar( $metodo, $caminho, $corpo, $query, $cabecalhos, $autenticar, $timeout );
		if ( is_wp_error( $resposta ) ) {
			return $resposta;
		}
		$dados = json_decode( (string) wp_remote_retrieve_body( $resposta ), true );
		return is_array( $dados ) ? $dados : array();
	}

	/**
	 * Faz a chamada. Respostas 2xx voltam como a resposta do WordPress; as demais, como WP_Error com o `code` da API.
	 *
	 * @param string     $metodo     Método HTTP.
	 * @param string     $caminho    Caminho.
	 * @param mixed|null $corpo      Corpo ou null.
	 * @param array      $query      Query string.
	 * @param array      $cabecalhos Cabeçalhos extras.
	 * @param bool       $autenticar Se manda a chave.
	 * @param int        $timeout    Segundos.
	 * @return array|WP_Error
	 */
	private function enviar( $metodo, $caminho, $corpo, array $query, array $cabecalhos, $autenticar, $timeout ) {
		if ( $autenticar && '' === $this->chave ) {
			return self::erro_local( 'chave_invalida', __( 'Configure a chave de API do emissaoja em WooCommerce > Configurações > NFS-e (emissaoja).', 'emissaoja-nfse' ) );
		}

		$url   = $this->base . $caminho;
		$query = array_filter( $query, array( __CLASS__, 'tem_valor' ) );
		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$headers = array_merge( array( 'Accept' => 'application/json' ), $cabecalhos );
		if ( $autenticar ) {
			$headers['Authorization'] = 'Bearer ' . $this->chave;
		}
		$args = array(
			'method'      => $metodo,
			'timeout'     => $timeout,
			'redirection' => 0,
			'user-agent'  => 'emissaoja-woocommerce/' . EMISSAOJA_NFSE_VERSION,
			'headers'     => $headers,
		);
		if ( null !== $corpo ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $corpo );
		}

		$resposta = wp_remote_request( $url, $args );
		if ( is_wp_error( $resposta ) ) {
			$mensagem = $resposta->get_error_message();
			$esgotado = false !== stripos( $mensagem, 'timed out' ) || false !== stripos( $mensagem, 'cURL error 28' );
			return new WP_Error(
				$esgotado ? 'tempo_esgotado' : 'rede',
				$esgotado
					? __( 'A API do emissaoja não respondeu a tempo.', 'emissaoja-nfse' )
					/* translators: %s: mensagem de erro de rede do WordPress. */
					: sprintf( __( 'Falha de rede ao falar com a API do emissaoja: %s', 'emissaoja-nfse' ), sanitize_text_field( $mensagem ) ),
				array(
					'status'     => 0,
					'extras'     => array(),
					'request_id' => '',
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $resposta );
		if ( $status >= 200 && $status < 300 ) {
			return $resposta;
		}
		return self::erro_da_resposta( $resposta, $status );
	}

	/**
	 * Converte uma resposta de erro da API em WP_Error.
	 *
	 * @param array $resposta Resposta do WordPress.
	 * @param int   $status   HTTP.
	 * @return WP_Error
	 */
	private static function erro_da_resposta( $resposta, $status ) {
		$texto  = (string) wp_remote_retrieve_body( $resposta );
		$dados  = json_decode( $texto, true );
		$extras = array();
		if ( is_array( $dados ) ) {
			$code     = isset( $dados['code'] ) && is_string( $dados['code'] ) && '' !== $dados['code'] ? $dados['code'] : 'erro_desconhecido';
			$mensagem = isset( $dados['message'] ) && is_string( $dados['message'] ) && '' !== $dados['message'] ? $dados['message'] : 'HTTP ' . $status;
			$extras   = array_diff_key( $dados, array_flip( array( 'status', 'code', 'message' ) ) );
		} else {
			$code     = 'erro_desconhecido';
			$limpo    = trim( wp_strip_all_tags( $texto ) );
			$mensagem = '' !== $limpo ? Emissaoja_NFSe_Regras::cortar( $limpo, 200 ) : 'HTTP ' . $status;
		}

		$retry = self::cabecalho( $resposta, 'retry-after' );
		if ( ! isset( $extras['retry_after'] ) && '' !== $retry && is_numeric( $retry ) ) {
			$extras['retry_after'] = (int) $retry;
		}

		return new WP_Error(
			sanitize_key( $code ),
			sanitize_text_field( $mensagem ),
			array(
				'status'     => (int) $status,
				'extras'     => $extras,
				'request_id' => sanitize_text_field( self::cabecalho( $resposta, 'x-request-id' ) ),
			)
		);
	}

	/**
	 * Erro gerado aqui, sem chamar a rede.
	 *
	 * @param string $code     Código.
	 * @param string $mensagem Mensagem.
	 * @return WP_Error
	 */
	private static function erro_local( $code, $mensagem ) {
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
	 * Um cabeçalho da resposta, como texto.
	 *
	 * @param array  $resposta Resposta do WordPress.
	 * @param string $nome     Nome do cabeçalho.
	 * @return string
	 */
	private static function cabecalho( $resposta, $nome ) {
		$valor = wp_remote_retrieve_header( $resposta, $nome );
		if ( is_array( $valor ) ) {
			$valor = reset( $valor );
		}
		return is_string( $valor ) ? trim( $valor ) : '';
	}

	/**
	 * Nome do arquivo no Content-Disposition da API, filtrado.
	 *
	 * @param string $disposicao Cabeçalho Content-Disposition.
	 * @return string Nome seguro, ou '' quando não veio.
	 */
	private static function nome_do_arquivo( $disposicao ) {
		if ( 1 !== preg_match( '/filename="?([^";]+)"?/i', $disposicao, $achado ) ) {
			return '';
		}
		return sanitize_file_name( $achado[1] );
	}

	/**
	 * Se um parâmetro de query deve ir na URL (nulos e vazios ficam fora). Uso interno (callback de array_filter).
	 *
	 * @param mixed $valor Valor.
	 * @return bool
	 */
	public static function tem_valor( $valor ) {
		return null !== $valor && '' !== $valor;
	}
}
