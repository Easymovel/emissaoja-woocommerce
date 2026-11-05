<?php
/**
 * Testes de CPF e CNPJ (Emissaoja_NFSe_Documento).
 *
 * @package Emissaoja_NFSe
 */

use PHPUnit\Framework\TestCase;

/**
 * Normalização, dígitos verificadores, máscara e escolha do documento.
 */
final class DocumentoTest extends TestCase {

	/**
	 * CPFs válidos.
	 *
	 * @return array
	 */
	public static function cpfs_validos() {
		return array(
			'sem máscara'          => array( '52998224725' ),
			'com máscara'          => array( '529.982.247-25' ),
			'com espaços'          => array( ' 529 982 247 25 ' ),
			'outro'                => array( '123.456.789-09' ),
			'dígitos terminando 5' => array( '39053344705' ),
		);
	}

	/**
	 * CPFs inválidos.
	 *
	 * @return array
	 */
	public static function cpfs_invalidos() {
		return array(
			'dígito errado'  => array( '529.982.247-24' ),
			'repetido'       => array( '111.111.111-11' ),
			'zerado'         => array( '00000000000' ),
			'curto'          => array( '5299822472' ),
			'longo'          => array( '529982247250' ),
			'com letra'      => array( '529.982.247-2X' ),
			'letra no fim'   => array( '52998224725a' ),
			'vazio'          => array( '' ),
			'nulo'           => array( null ),
			'array'          => array( array( '52998224725' ) ),
			'CNPJ no lugar'  => array( '12.345.678/0001-95' ),
		);
	}

	/**
	 * CNPJs válidos.
	 *
	 * @return array
	 */
	public static function cnpjs_validos() {
		return array(
			'sem máscara'      => array( '12345678000195' ),
			'com máscara'      => array( '12.345.678/0001-95' ),
			'outro'            => array( '11.222.333/0001-81' ),
			'BINOBO'           => array( '68.649.497/0001-07' ),
			'zeros à esquerda' => array( '00.000.000/0001-91' ),
		);
	}

	/**
	 * CNPJs inválidos.
	 *
	 * @return array
	 */
	public static function cnpjs_invalidos() {
		return array(
			'dígito errado'  => array( '11.222.333/0001-82' ),
			'repetido'       => array( '11111111111111' ),
			'zerado'         => array( '00.000.000/0000-00' ),
			'curto'          => array( '1122233300018' ),
			'alfanumérico'   => array( '12.ABC.345/01DE-35' ),
			'símbolo'        => array( '11.222.333/0001*81' ),
			'vazio'          => array( '' ),
			'CPF no lugar'   => array( '52998224725' ),
		);
	}

	/**
	 * CPF válido.
	 *
	 * @dataProvider cpfs_validos
	 * @param mixed $cpf CPF.
	 */
	public function test_cpf_valido( $cpf ) {
		$this->assertTrue( Emissaoja_NFSe_Documento::cpf_valido( $cpf ) );
	}

	/**
	 * CPF inválido.
	 *
	 * @dataProvider cpfs_invalidos
	 * @param mixed $cpf CPF.
	 */
	public function test_cpf_invalido( $cpf ) {
		$this->assertFalse( Emissaoja_NFSe_Documento::cpf_valido( $cpf ) );
	}

	/**
	 * CNPJ válido.
	 *
	 * @dataProvider cnpjs_validos
	 * @param mixed $cnpj CNPJ.
	 */
	public function test_cnpj_valido( $cnpj ) {
		$this->assertTrue( Emissaoja_NFSe_Documento::cnpj_valido( $cnpj ) );
	}

	/**
	 * CNPJ inválido.
	 *
	 * @dataProvider cnpjs_invalidos
	 * @param mixed $cnpj CNPJ.
	 */
	public function test_cnpj_invalido( $cnpj ) {
		$this->assertFalse( Emissaoja_NFSe_Documento::cnpj_valido( $cnpj ) );
	}

	/**
	 * Normalização tira só a máscara.
	 */
	public function test_normalizar() {
		$this->assertSame( '52998224725', Emissaoja_NFSe_Documento::normalizar( ' 529.982.247-25 ' ) );
		$this->assertSame( '12345678000195', Emissaoja_NFSe_Documento::normalizar( '12.345.678/0001-95' ) );
		$this->assertSame( '', Emissaoja_NFSe_Documento::normalizar( '12.ABC.345/01DE-35' ) );
		$this->assertSame( '', Emissaoja_NFSe_Documento::normalizar( null ) );
		$this->assertSame( '191', Emissaoja_NFSe_Documento::normalizar( 191 ) );
	}

	/**
	 * Classificação em pessoa física ou jurídica.
	 */
	public function test_classificar() {
		$this->assertSame(
			array(
				'tipo'   => 'pf',
				'numero' => '52998224725',
			),
			Emissaoja_NFSe_Documento::classificar( '529.982.247-25' )
		);
		$this->assertSame(
			array(
				'tipo'   => 'pj',
				'numero' => '12345678000195',
			),
			Emissaoja_NFSe_Documento::classificar( '12.345.678/0001-95' )
		);
		$this->assertNull( Emissaoja_NFSe_Documento::classificar( '123' ) );
		$this->assertNull( Emissaoja_NFSe_Documento::classificar( '' ) );
	}

	/**
	 * Máscara.
	 */
	public function test_formatar() {
		$this->assertSame( '529.982.247-25', Emissaoja_NFSe_Documento::formatar( '52998224725' ) );
		$this->assertSame( '12.345.678/0001-95', Emissaoja_NFSe_Documento::formatar( '12345678000195' ) );
		$this->assertSame( 'abc', Emissaoja_NFSe_Documento::formatar( ' abc ' ) );
	}

	/**
	 * Tipo de pessoa do Brazilian Market escolhe o campo; sem ele, o CNPJ vem antes do CPF.
	 */
	public function test_resolver_pelo_tipo_de_pessoa() {
		$cpf  = '529.982.247-25';
		$cnpj = '12.345.678/0001-95';

		$this->assertSame( 'pf', Emissaoja_NFSe_Documento::resolver( '1', $cpf, $cnpj )['tipo'] );
		$this->assertSame( 'pj', Emissaoja_NFSe_Documento::resolver( '2', $cpf, $cnpj )['tipo'] );
		$this->assertSame( 'pj', Emissaoja_NFSe_Documento::resolver( '', $cpf, $cnpj )['tipo'] );
		$this->assertSame( 'pf', Emissaoja_NFSe_Documento::resolver( '', $cpf, '' )['tipo'] );
		// Pessoa física sem CPF: o CNPJ esquecido no outro campo não vale.
		$this->assertNull( Emissaoja_NFSe_Documento::resolver( '1', '', $cnpj ) );
	}

	/**
	 * Os campos do plugin entram depois das metas configuradas, na ordem dada.
	 */
	public function test_resolver_com_outros_campos() {
		$doc = Emissaoja_NFSe_Documento::resolver( '', 'x', 'y', array( '', '12345678000195' ) );
		$this->assertSame( '12345678000195', $doc['numero'] );

		$doc = Emissaoja_NFSe_Documento::resolver( '1', '529.982.247-24', '', array( '529.982.247-25' ) );
		$this->assertSame( '52998224725', $doc['numero'] );

		$this->assertNull( Emissaoja_NFSe_Documento::resolver( null, null, null, array( '', 'abc' ) ) );
	}

	/**
	 * Nome do tipo de documento.
	 */
	public function test_rotulo() {
		$this->assertSame( 'CPF', Emissaoja_NFSe_Documento::rotulo( 'pf' ) );
		$this->assertSame( 'CNPJ', Emissaoja_NFSe_Documento::rotulo( 'pj' ) );
	}
}
