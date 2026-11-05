<?php
/**
 * Regras puras da emissão: referência (Idempotency-Key), descrição, valor, intervalos e chave de API.
 *
 * Sem dependência do WordPress (testadas com PHPUnit em tests/unit).
 *
 * @package Emissaoja_NFSe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Regras da emissão que não dependem do WordPress nem do WooCommerce.
 */
final class Emissaoja_NFSe_Regras {

	/**
	 * Tamanho máximo da descrição aceita pela API.
	 */
	const DESCRICAO_MAX = 2000;

	/**
	 * Consultas automáticas de um pedido de emissão antes de parar e pedir "Atualizar situação".
	 */
	const CONSULTAS_MAX = 40;

	/**
	 * Novas tentativas automáticas (mesma referência) depois de falhas temporárias.
	 */
	const RETENTATIVAS_MAX = 5;

	/**
	 * Estados em que o pedido de emissão para de mudar sozinho.
	 *
	 * @var string[]
	 */
	private static $estados_terminais = array( 'autorizada', 'recusada', 'incerta' );

	/**
	 * Erros que podem ser repetidos com a mesma referência sem risco de nota em dobro.
	 *
	 * @var string[]
	 */
	private static $erros_temporarios = array( 'rede', 'tempo_esgotado', 'rate_limited', 'fila_indisponivel', 'motor_indisponivel', 'receita_indisponivel' );

	/**
	 * Referência estável e única do pedido da loja: "wc-" + 8 primeiros hex do md5 da URL do site + "-" + id do pedido.
	 * Da segunda tentativa em diante (depois de uma nota recusada), ganha o sufixo "-2", "-3"...
	 *
	 * @param string $url_site  URL do site (opção home).
	 * @param int    $pedido_id Id do pedido no WooCommerce.
	 * @param int    $tentativa Número da tentativa (1 na primeira).
	 * @return string Ex.: wc-57f215b1-1234.
	 */
	public static function referencia( $url_site, $pedido_id, $tentativa = 1 ) {
		$referencia = 'wc-' . substr( md5( (string) $url_site ), 0, 8 ) . '-' . abs( (int) $pedido_id );
		$tentativa  = (int) $tentativa;
		if ( $tentativa > 1 ) {
			$referencia .= '-' . $tentativa;
		}
		return $referencia;
	}

	/**
	 * Formato aceito pela API no cabeçalho Idempotency-Key: 8 a 64 caracteres entre letras, números, _ e -.
	 *
	 * @param mixed $referencia Referência.
	 * @return bool
	 */
	public static function referencia_valida( $referencia ) {
		return is_string( $referencia ) && 1 === preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $referencia );
	}

	/**
	 * Monta a descrição da nota a partir do modelo, trocando {marcador} pelos valores.
	 *
	 * Marcadores sem valor ficam como estão. Tira emojis e caracteres de controle (o Emissor Nacional não os aceita
	 * bem), junta espaços repetidos e corta em 2.000 caracteres.
	 *
	 * @param string $modelo  Modelo com marcadores, ex.: "Pedido {pedido} - {itens}".
	 * @param array  $valores Valores por marcador, sem as chaves: array( 'pedido' => '1001', ... ).
	 * @return string
	 */
	public static function descricao( $modelo, array $valores ) {
		$trocas = array();
		foreach ( $valores as $marcador => $valor ) {
			$trocas[ '{' . $marcador . '}' ] = is_scalar( $valor ) ? (string) $valor : '';
		}
		$texto = strtr( (string) $modelo, $trocas );
		$texto = str_replace( array( "\r\n", "\r" ), "\n", $texto );
		$texto = self::trocar( '/[\x{10000}-\x{10FFFF}]/u', '', $texto );
		$texto = self::trocar( '/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $texto );
		$texto = self::trocar( '/[ ]{2,}/', ' ', $texto );
		$texto = self::trocar( '/ *\n */', "\n", $texto );
		$texto = trim( $texto );
		if ( self::comprimento( $texto ) > self::DESCRICAO_MAX ) {
			$texto = rtrim( self::cortar( $texto, self::DESCRICAO_MAX - 1 ) ) . '…';
		}
		return $texto;
	}

	/**
	 * Lista os itens do pedido para a descrição: "Consultoria, 2 x Curso de Excel".
	 *
	 * @param array $itens Lista de array( 'nome' => string, 'quantidade' => int|float ).
	 * @return string
	 */
	public static function itens_texto( array $itens ) {
		$partes = array();
		foreach ( $itens as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['nome'] ) || ! is_scalar( $item['nome'] ) ) {
				continue;
			}
			$nome = trim( (string) $item['nome'] );
			if ( '' === $nome ) {
				continue;
			}
			$quantidade = isset( $item['quantidade'] ) && is_numeric( $item['quantidade'] ) ? (float) $item['quantidade'] : 1.0;
			$partes[]   = $quantidade > 1 ? self::numero( $quantidade ) . ' x ' . $nome : $nome;
		}
		return implode( ', ', $partes );
	}

	/**
	 * Valor da nota em reais, com 2 casas, nunca negativo.
	 *
	 * @param mixed  $total             Total do pedido.
	 * @param mixed  $reembolsado       Total já reembolsado.
	 * @param mixed  $frete             Frete do pedido, com o imposto do frete.
	 * @param mixed  $frete_reembolsado Frete já reembolsado.
	 * @param string $base              'total' (total menos reembolsos) ou 'sem_frete' (o mesmo, sem o frete).
	 * @return float
	 */
	public static function valor( $total, $reembolsado, $frete, $frete_reembolsado, $base ) {
		$valor = self::decimal( $total ) - abs( self::decimal( $reembolsado ) );
		if ( 'sem_frete' === $base ) {
			$valor -= max( 0.0, self::decimal( $frete ) - abs( self::decimal( $frete_reembolsado ) ) );
		}
		return max( 0.0, round( $valor, 2 ) );
	}

	/**
	 * Segundos até a próxima consulta do pedido, a partir de proxima_consulta_ms (entre 5 s e 5 min; 10 s sem o campo).
	 *
	 * @param mixed $proxima_ms proxima_consulta_ms do pedido.
	 * @return int
	 */
	public static function intervalo_consulta( $proxima_ms ) {
		$ms       = is_numeric( $proxima_ms ) ? (int) $proxima_ms : 0;
		$segundos = $ms > 0 ? (int) ceil( $ms / 1000 ) : 10;
		return max( 5, min( 300, $segundos ) );
	}

	/**
	 * Espera antes da nova tentativa automática: 1, 2, 5, 15 e 30 minutos, ou o Retry-After da API, se maior (até 1 h).
	 *
	 * @param int $tentativa   Número da nova tentativa (1 na primeira repetição).
	 * @param int $retry_after Segundos pedidos pela API (cabeçalho Retry-After), ou 0.
	 * @return int Segundos.
	 */
	public static function espera_retentativa( $tentativa, $retry_after = 0 ) {
		$esperas = array( 60, 120, 300, 900, 1800 );
		$indice  = max( 0, min( count( $esperas ) - 1, (int) $tentativa - 1 ) );
		return max( $esperas[ $indice ], min( 3600, max( 0, (int) $retry_after ) ) );
	}

	/**
	 * Se o erro é temporário (pode repetir com a MESMA referência, sem risco de nota em dobro).
	 *
	 * @param string $code   Código do erro.
	 * @param int    $status HTTP (0 para rede ou tempo esgotado).
	 * @return bool
	 */
	public static function erro_temporario( $code, $status = 0 ) {
		if ( in_array( (string) $code, self::$erros_temporarios, true ) ) {
			return true;
		}
		return 'erro_desconhecido' === $code && (int) $status >= 500;
	}

	/**
	 * Se o estado do pedido de emissão é final (autorizada, recusada ou incerta).
	 *
	 * @param string $estado Estado.
	 * @return bool
	 */
	public static function estado_terminal( $estado ) {
		return in_array( (string) $estado, self::$estados_terminais, true );
	}

	/**
	 * Versão mascarada da chave de API, que nunca mostra mais que os 4 últimos caracteres.
	 *
	 * @param string $chave Chave.
	 * @return string Ex.: ej_live_••••••••a1b2.
	 */
	public static function mascarar_chave( $chave ) {
		$chave = trim( (string) $chave );
		if ( '' === $chave ) {
			return '';
		}
		$prefixo = '';
		foreach ( array( 'ej_live_', 'ej_test_' ) as $candidato ) {
			if ( 0 === strpos( $chave, $candidato ) ) {
				$prefixo = $candidato;
				break;
			}
		}
		return $prefixo . str_repeat( '•', 8 ) . substr( $chave, -4 );
	}

	/**
	 * Formato da chave de API do emissaoja: ej_live_ (produção) ou ej_test_ (sandbox) e o segredo.
	 *
	 * @param mixed $chave Chave.
	 * @return bool
	 */
	public static function chave_valida( $chave ) {
		return is_string( $chave ) && 1 === preg_match( '/^ej_(live|test)_[A-Za-z0-9_-]{8,200}$/', $chave );
	}

	/**
	 * Ambiente a que a chave pertence, pelo prefixo.
	 *
	 * @param string $chave Chave.
	 * @return string 'producao', 'sandbox' ou '' quando o prefixo é desconhecido.
	 */
	public static function ambiente_da_chave( $chave ) {
		$chave = (string) $chave;
		if ( 0 === strpos( $chave, 'ej_test_' ) ) {
			return 'sandbox';
		}
		if ( 0 === strpos( $chave, 'ej_live_' ) ) {
			return 'producao';
		}
		return '';
	}

	/**
	 * Formato dos ids da API (UUID): pedidos, tomadores, serviços e notas.
	 *
	 * @param mixed $id Id.
	 * @return bool
	 */
	public static function id_valido( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[0-9a-fA-F-]{36}$/', $id );
	}

	/**
	 * Corta um texto em UTF-8 sem quebrar caracteres.
	 *
	 * @param string $texto   Texto.
	 * @param int    $tamanho Máximo de caracteres.
	 * @return string
	 */
	public static function cortar( $texto, $tamanho ) {
		$texto = (string) $texto;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $texto, 0, (int) $tamanho, 'UTF-8' );
		}
		return substr( $texto, 0, (int) $tamanho );
	}

	/**
	 * Comprimento em caracteres (UTF-8).
	 *
	 * @param string $texto Texto.
	 * @return int
	 */
	private static function comprimento( $texto ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $texto, 'UTF-8' );
		}
		return strlen( $texto );
	}

	/**
	 * Substituição por expressão regular que mantém o texto original se a expressão falhar (UTF-8 inválido).
	 *
	 * @param string $padrao       Expressão.
	 * @param string $substituicao Substituição.
	 * @param string $texto        Texto.
	 * @return string
	 */
	private static function trocar( $padrao, $substituicao, $texto ) {
		$resultado = preg_replace( $padrao, $substituicao, $texto );
		return is_string( $resultado ) ? $resultado : $texto;
	}

	/**
	 * Número decimal de um valor do WooCommerce (float ou string com ponto).
	 *
	 * @param mixed $valor Valor.
	 * @return float
	 */
	private static function decimal( $valor ) {
		return is_numeric( $valor ) ? (float) $valor : 0.0;
	}

	/**
	 * Quantidade para a descrição: inteiro sem casas, fração com vírgula.
	 *
	 * @param float $quantidade Quantidade.
	 * @return string
	 */
	private static function numero( $quantidade ) {
		if ( floor( $quantidade ) === $quantidade ) {
			return (string) (int) $quantidade;
		}
		return str_replace( '.', ',', (string) round( $quantidade, 3 ) );
	}
}
