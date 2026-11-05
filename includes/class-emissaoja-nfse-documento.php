<?php
/**
 * CPF e CNPJ do tomador: limpeza, dígitos verificadores, máscara e escolha do documento do pedido.
 *
 * Funções puras, sem dependência do WordPress (testadas com PHPUnit em tests/unit).
 *
 * @package Emissaoja_NFSe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Documento (CPF ou CNPJ) do tomador da NFS-e.
 *
 * Só o CNPJ numérico é aceito: a API do emissaoja ainda não aceita o CNPJ alfanumérico para o tomador.
 */
final class Emissaoja_NFSe_Documento {

	/**
	 * Pesos do CNPJ. O primeiro dígito verificador usa os pesos a partir do segundo.
	 *
	 * @var int[]
	 */
	private static $pesos_cnpj = array( 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );

	/**
	 * Tira a máscara (pontos, traços, barras e espaços). Qualquer outro caractere invalida o valor.
	 *
	 * @param mixed $valor CPF ou CNPJ digitado, com ou sem máscara.
	 * @return string Só os dígitos, ou '' quando o valor tem letras ou outros símbolos.
	 */
	public static function normalizar( $valor ) {
		if ( ! is_string( $valor ) && ! is_int( $valor ) ) {
			return '';
		}
		$limpo = preg_replace( '/[\s.\/-]+/u', '', (string) $valor );
		if ( ! is_string( $limpo ) || 1 !== preg_match( '/^\d+$/', $limpo ) ) {
			return '';
		}
		return $limpo;
	}

	/**
	 * Confere um CPF (11 dígitos, dígitos verificadores corretos, não repetido).
	 *
	 * @param mixed $valor CPF com ou sem máscara.
	 * @return bool
	 */
	public static function cpf_valido( $valor ) {
		$cpf = self::normalizar( $valor );
		if ( 11 !== strlen( $cpf ) || self::repetido( $cpf ) ) {
			return false;
		}
		for ( $posicao = 9; $posicao < 11; $posicao++ ) {
			$soma = 0;
			for ( $i = 0; $i < $posicao; $i++ ) {
				$soma += (int) $cpf[ $i ] * ( $posicao + 1 - $i );
			}
			$digito = ( $soma * 10 ) % 11 % 10;
			if ( (int) $cpf[ $posicao ] !== $digito ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Confere um CNPJ numérico (14 dígitos, dígitos verificadores corretos, não repetido).
	 *
	 * @param mixed $valor CNPJ com ou sem máscara.
	 * @return bool
	 */
	public static function cnpj_valido( $valor ) {
		$cnpj = self::normalizar( $valor );
		if ( 14 !== strlen( $cnpj ) || self::repetido( $cnpj ) ) {
			return false;
		}
		for ( $posicao = 12; $posicao < 14; $posicao++ ) {
			$soma = 0;
			for ( $i = 0; $i < $posicao; $i++ ) {
				$soma += (int) $cnpj[ $i ] * self::$pesos_cnpj[ $i + 13 - $posicao ];
			}
			$resto  = $soma % 11;
			$digito = $resto < 2 ? 0 : 11 - $resto;
			if ( (int) $cnpj[ $posicao ] !== $digito ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Classifica um documento válido.
	 *
	 * @param mixed $valor CPF ou CNPJ, com ou sem máscara.
	 * @return array|null Array com 'tipo' ('pf' ou 'pj') e 'numero' (só dígitos), ou null quando inválido.
	 */
	public static function classificar( $valor ) {
		$numero = self::normalizar( $valor );
		if ( self::cpf_valido( $numero ) ) {
			return array(
				'tipo'   => 'pf',
				'numero' => $numero,
			);
		}
		if ( self::cnpj_valido( $numero ) ) {
			return array(
				'tipo'   => 'pj',
				'numero' => $numero,
			);
		}
		return null;
	}

	/**
	 * Aplica a máscara de CPF (000.000.000-00) ou de CNPJ (00.000.000/0000-00).
	 *
	 * @param mixed $valor Documento.
	 * @return string Documento mascarado; o valor original (sem espaços nas pontas) quando não tem 11 nem 14 dígitos.
	 */
	public static function formatar( $valor ) {
		$numero = self::normalizar( $valor );
		if ( 11 === strlen( $numero ) ) {
			return substr( $numero, 0, 3 ) . '.' . substr( $numero, 3, 3 ) . '.' . substr( $numero, 6, 3 ) . '-' . substr( $numero, 9, 2 );
		}
		if ( 14 === strlen( $numero ) ) {
			return substr( $numero, 0, 2 ) . '.' . substr( $numero, 2, 3 ) . '.' . substr( $numero, 5, 3 ) . '/' . substr( $numero, 8, 4 ) . '-' . substr( $numero, 12, 2 );
		}
		return is_scalar( $valor ) ? trim( (string) $valor ) : '';
	}

	/**
	 * Escolhe o documento do tomador entre os campos do pedido.
	 *
	 * Com o tipo de pessoa do Brazilian Market on WooCommerce ("1" = pessoa física, "2" = pessoa jurídica),
	 * vale só o campo correspondente; sem ele, o CNPJ tem preferência sobre o CPF. Os valores de $outros
	 * (campo próprio do plugin, checkout em blocos) entram depois, na ordem dada. Ganha o primeiro válido.
	 *
	 * @param mixed $tipo_pessoa Valor da meta de tipo de pessoa.
	 * @param mixed $cpf         Valor da meta de CPF.
	 * @param mixed $cnpj        Valor da meta de CNPJ.
	 * @param array $outros      Outros valores candidatos, em ordem de preferência.
	 * @return array|null Mesmo formato de classificar(), ou null.
	 */
	public static function resolver( $tipo_pessoa, $cpf, $cnpj, array $outros = array() ) {
		$tipo_pessoa = is_scalar( $tipo_pessoa ) ? trim( (string) $tipo_pessoa ) : '';
		if ( '1' === $tipo_pessoa ) {
			$candidatos = array( $cpf );
		} elseif ( '2' === $tipo_pessoa ) {
			$candidatos = array( $cnpj );
		} else {
			$candidatos = array( $cnpj, $cpf );
		}
		foreach ( array_merge( $candidatos, $outros ) as $candidato ) {
			$documento = self::classificar( $candidato );
			if ( null !== $documento ) {
				return $documento;
			}
		}
		return null;
	}

	/**
	 * Nome do tipo de documento.
	 *
	 * @param string $tipo 'pf' ou 'pj'.
	 * @return string 'CPF' ou 'CNPJ'.
	 */
	public static function rotulo( $tipo ) {
		return 'pf' === $tipo ? 'CPF' : 'CNPJ';
	}

	/**
	 * Todos os dígitos iguais (00000000000, 11111111111111...): passam no cálculo, mas não existem.
	 *
	 * @param string $numero Só dígitos.
	 * @return bool
	 */
	private static function repetido( $numero ) {
		return 1 === preg_match( '/^(\d)\1+$/', $numero );
	}
}
