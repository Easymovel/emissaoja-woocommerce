<?php
/**
 * Configurações: valores padrão, leitura, limpeza e a chave de API.
 *
 * @package Emissaoja_NFSe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Configurações do plugin.
 */
final class Emissaoja_NFSe_Settings {

	/**
	 * Opção com as configurações (array).
	 */
	const OPTION = 'emissaoja_nfse_settings';

	/**
	 * Opção com a chave de API (separada, sem autoload).
	 */
	const KEY_OPTION = 'emissaoja_nfse_api_key';

	/**
	 * Último erro da conta (chave recusada, limite do plano...), para o aviso no painel.
	 */
	const ERR_OPTION = 'emissaoja_nfse_last_error';

	/**
	 * Constante opcional do wp-config.php com a chave (tem prioridade sobre a chave salva).
	 */
	const CONSTANTE = 'EMISSAOJA_NFSE_API_KEY';

	/**
	 * Configurações já lidas nesta requisição.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Valores padrão.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'ambiente'       => 'producao',
			'quando'         => 'manual',
			'base_valor'     => 'total',
			'descricao'      => 'Pedido {pedido} - {itens}',
			'servico_id'     => '',
			'servico_nome'   => '',
			'nota_cliente'   => true,
			'campo_checkout' => 'auto',
			'meta_cpf'       => '_billing_cpf',
			'meta_cnpj'      => '_billing_cnpj',
			'meta_tipo'      => '_billing_persontype',
		);
	}

	/**
	 * Todas as configurações, com os padrões.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$salvas      = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $salvas ) ? array_merge( self::defaults(), $salvas ) : self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Uma configuração.
	 *
	 * @param string $nome Nome.
	 * @return mixed
	 */
	public static function get( $nome ) {
		$todas = self::all();
		return isset( $todas[ $nome ] ) ? $todas[ $nome ] : null;
	}

	/**
	 * Limpa um array de configurações (valores fora da lista voltam ao padrão).
	 *
	 * @param mixed $entrada Valores crus.
	 * @return array
	 */
	public static function sanitize( $entrada ) {
		$padrao  = self::defaults();
		$entrada = is_array( $entrada ) ? $entrada : array();
		$saida   = array();

		$saida['ambiente']   = self::opcao( $entrada, 'ambiente', array( 'producao', 'sandbox' ), $padrao['ambiente'] );
		$saida['quando']     = self::opcao( $entrada, 'quando', array( 'manual', 'processing', 'completed' ), $padrao['quando'] );
		$saida['base_valor'] = self::opcao( $entrada, 'base_valor', array( 'total', 'sem_frete' ), $padrao['base_valor'] );

		$descricao          = isset( $entrada['descricao'] ) && is_string( $entrada['descricao'] ) ? sanitize_textarea_field( $entrada['descricao'] ) : $padrao['descricao'];
		$saida['descricao'] = Emissaoja_NFSe_Regras::cortar( $descricao, Emissaoja_NFSe_Regras::DESCRICAO_MAX );

		$servico               = isset( $entrada['servico_id'] ) && is_string( $entrada['servico_id'] ) ? trim( $entrada['servico_id'] ) : '';
		$saida['servico_id']   = Emissaoja_NFSe_Regras::id_valido( $servico ) ? $servico : '';
		$nome                  = isset( $entrada['servico_nome'] ) && is_string( $entrada['servico_nome'] ) ? sanitize_text_field( $entrada['servico_nome'] ) : '';
		$saida['servico_nome'] = '' !== $saida['servico_id'] ? Emissaoja_NFSe_Regras::cortar( $nome, 200 ) : '';

		$saida['nota_cliente']   = ! empty( $entrada['nota_cliente'] );
		$saida['campo_checkout'] = self::opcao( $entrada, 'campo_checkout', array( 'auto', 'sempre', 'nunca' ), $padrao['campo_checkout'] );

		foreach ( array( 'meta_cpf', 'meta_cnpj', 'meta_tipo' ) as $campo ) {
			$saida[ $campo ] = isset( $entrada[ $campo ] ) && is_string( $entrada[ $campo ] ) ? self::meta_key( $entrada[ $campo ] ) : $padrao[ $campo ];
		}

		return $saida;
	}

	/**
	 * Salva as configurações.
	 *
	 * @param array $valores Valores crus.
	 * @return void
	 */
	public static function update( $valores ) {
		update_option( self::OPTION, self::sanitize( $valores ) );
		self::$cache = null;
	}

	/**
	 * A chave de API. A constante EMISSAOJA_NFSE_API_KEY do wp-config.php tem prioridade sobre a chave salva.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( self::chave_por_constante() ) {
			return trim( (string) constant( self::CONSTANTE ) );
		}
		$chave = get_option( self::KEY_OPTION, '' );
		return is_string( $chave ) ? trim( $chave ) : '';
	}

	/**
	 * Se a chave vem do wp-config.php.
	 *
	 * @return bool
	 */
	public static function chave_por_constante() {
		return defined( self::CONSTANTE ) && is_string( constant( self::CONSTANTE ) ) && '' !== trim( constant( self::CONSTANTE ) );
	}

	/**
	 * Guarda a chave (sem autoload) ou apaga, quando vazia.
	 *
	 * @param string $chave Chave já limpa.
	 * @return void
	 */
	public static function set_api_key( $chave ) {
		$chave = trim( (string) $chave );
		if ( '' === $chave ) {
			delete_option( self::KEY_OPTION );
			return;
		}
		update_option( self::KEY_OPTION, $chave, false );
	}

	/**
	 * Registra o último erro da conta, para o aviso no painel (no máximo uma gravação a cada 5 minutos por código).
	 *
	 * @param string $code     Código da API.
	 * @param string $mensagem Mensagem (nunca contém a chave).
	 * @return void
	 */
	public static function record_error( $code, $mensagem ) {
		$atual = get_option( self::ERR_OPTION );
		if ( is_array( $atual ) && isset( $atual['code'], $atual['time'] ) && $atual['code'] === $code && ( time() - (int) $atual['time'] ) < 300 ) {
			return;
		}
		update_option(
			self::ERR_OPTION,
			array(
				'code'    => sanitize_key( $code ),
				'message' => sanitize_text_field( $mensagem ),
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Apaga o erro registrado.
	 *
	 * @return void
	 */
	public static function clear_error() {
		delete_option( self::ERR_OPTION );
	}

	/**
	 * Endereço da aba de configurações.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=emissaoja_nfse' );
	}

	/**
	 * URL do site usada na referência dos pedidos (opção home, sem filtros nem esquema trocado por requisição).
	 *
	 * @return string
	 */
	public static function url_site() {
		return untrailingslashit( (string) get_option( 'home' ) );
	}

	/**
	 * Valor de uma lista fechada.
	 *
	 * @param array  $entrada    Valores crus.
	 * @param string $campo      Campo.
	 * @param array  $permitidos Valores aceitos.
	 * @param string $padrao     Valor quando o recebido não é aceito.
	 * @return string
	 */
	private static function opcao( array $entrada, $campo, array $permitidos, $padrao ) {
		$valor = isset( $entrada[ $campo ] ) && is_string( $entrada[ $campo ] ) ? sanitize_key( $entrada[ $campo ] ) : '';
		return in_array( $valor, $permitidos, true ) ? $valor : $padrao;
	}

	/**
	 * Nome de meta de pedido: letras, números, _, - e / (campos do checkout em blocos usam "/").
	 *
	 * @param string $valor Valor cru.
	 * @return string
	 */
	private static function meta_key( $valor ) {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_\-\/]/', '', $valor ), 0, 191 );
	}
}
