<?php
/**
 * Testes das regras puras da emissão (Emissaoja_NFSe_Regras).
 *
 * @package Emissaoja_NFSe
 */

use PHPUnit\Framework\TestCase;

/**
 * Referência, descrição, valor, intervalos e chave de API.
 */
final class RegrasTest extends TestCase {

	/**
	 * A referência é "wc-" + 8 hex do md5 da URL do site + "-" + id do pedido.
	 */
	public function test_referencia_estavel() {
		$this->assertSame( 'wc-57f215b1-1234', Emissaoja_NFSe_Regras::referencia( 'https://loja.exemplo.com.br', 1234 ) );
		$this->assertSame( 'wc-57f215b1-1234', Emissaoja_NFSe_Regras::referencia( 'https://loja.exemplo.com.br', '1234' ) );
		$this->assertSame(
			Emissaoja_NFSe_Regras::referencia( 'https://loja.exemplo.com.br', 77 ),
			Emissaoja_NFSe_Regras::referencia( 'https://loja.exemplo.com.br', 77 )
		);
		$this->assertSame( 'wc-37d007a5-7', Emissaoja_NFSe_Regras::referencia( 'http://localhost:8080', 7 ) );
	}

	/**
	 * Sites diferentes nunca geram a mesma referência para o mesmo id de pedido.
	 */
	public function test_referencia_unica_por_site() {
		$this->assertNotSame(
			Emissaoja_NFSe_Regras::referencia( 'https://loja-a.com.br', 10 ),
			Emissaoja_NFSe_Regras::referencia( 'https://loja-b.com.br', 10 )
		);
	}

	/**
	 * Nova tentativa (depois de nota recusada) ganha sufixo; a primeira não.
	 */
	public function test_referencia_tentativas() {
		$this->assertSame( 'wc-57f215b1-1234', Emissaoja_NFSe_Regras::referencia( 'https://loja.exemplo.com.br', 1234, 1 ) );
		$this->assertSame( 'wc-57f215b1-1234-2', Emissaoja_NFSe_Regras::referencia( 'https://loja.exemplo.com.br', 1234, 2 ) );
		$this->assertSame( 'wc-57f215b1-1234', Emissaoja_NFSe_Regras::referencia( 'https://loja.exemplo.com.br', 1234, 0 ) );
	}

	/**
	 * Toda referência gerada passa no formato da API (8 a 64 caracteres), mesmo com id curto ou longo.
	 */
	public function test_referencia_sempre_valida() {
		foreach ( array( 1, 7, 1234, 2147483647 ) as $id ) {
			$referencia = Emissaoja_NFSe_Regras::referencia( 'https://loja.exemplo.com.br', $id, 99 );
			$this->assertTrue( Emissaoja_NFSe_Regras::referencia_valida( $referencia ), $referencia );
			$this->assertTrue( Emissaoja_NFSe_Regras::referencia_valida( Emissaoja_NFSe_Regras::referencia( 'https://loja.exemplo.com.br', $id ) ) );
		}
	}

	/**
	 * Formato do Idempotency-Key.
	 */
	public function test_referencia_valida() {
		$this->assertTrue( Emissaoja_NFSe_Regras::referencia_valida( 'wc-57f215b1-1234' ) );
		$this->assertTrue( Emissaoja_NFSe_Regras::referencia_valida( str_repeat( 'a', 64 ) ) );
		$this->assertTrue( Emissaoja_NFSe_Regras::referencia_valida( 'pedido_0001' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::referencia_valida( 'curta' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::referencia_valida( str_repeat( 'a', 65 ) ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::referencia_valida( 'tem espaço 123' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::referencia_valida( 'pedido/2026/1' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::referencia_valida( null ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::referencia_valida( 12345678 ) );
	}

	/**
	 * Marcadores do modelo de descrição.
	 */
	public function test_descricao_marcadores() {
		$valores = array(
			'pedido' => '1001',
			'itens'  => 'Consultoria, 2 x Curso de Excel',
			'loja'   => 'Loja Exemplo',
		);
		$this->assertSame( 'Pedido 1001 - Consultoria, 2 x Curso de Excel', Emissaoja_NFSe_Regras::descricao( 'Pedido {pedido} - {itens}', $valores ) );
		$this->assertSame( 'Loja Exemplo, pedido 1001', Emissaoja_NFSe_Regras::descricao( '{loja}, pedido {pedido}', $valores ) );
		$this->assertSame( 'Pedido 1001 de {cliente}', Emissaoja_NFSe_Regras::descricao( 'Pedido {pedido} de {cliente}', $valores ) );
		$this->assertSame( '', Emissaoja_NFSe_Regras::descricao( '', $valores ) );
		$this->assertSame( 'Pedido', Emissaoja_NFSe_Regras::descricao( 'Pedido {pedido}', array( 'pedido' => '' ) ) );
	}

	/**
	 * Espaços repetidos, quebras de linha, emojis e caracteres de controle.
	 */
	public function test_descricao_limpeza() {
		$this->assertSame( 'a b', Emissaoja_NFSe_Regras::descricao( "  a \t  b  ", array() ) );
		$this->assertSame( "Linha 1\nLinha 2", Emissaoja_NFSe_Regras::descricao( "Linha 1  \r\n   Linha 2", array() ) );
		$this->assertSame( 'Curso online', Emissaoja_NFSe_Regras::descricao( "Curso \u{1F680} online", array() ) );
		$this->assertSame( 'Serviço de manutenção', Emissaoja_NFSe_Regras::descricao( "Serviço\x07 de manutenção", array() ) );
	}

	/**
	 * Corte em 2.000 caracteres, sem quebrar acentos.
	 */
	public function test_descricao_corte() {
		$longa = Emissaoja_NFSe_Regras::descricao( str_repeat( 'ç', 2500 ), array() );
		$this->assertSame( 2000, mb_strlen( $longa, 'UTF-8' ) );
		$this->assertSame( '…', mb_substr( $longa, -1, 1, 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $longa, 'UTF-8' ) );

		$exata = str_repeat( 'x', 2000 );
		$this->assertSame( $exata, Emissaoja_NFSe_Regras::descricao( $exata, array() ) );
	}

	/**
	 * Lista de itens para {itens}.
	 */
	public function test_itens_texto() {
		$this->assertSame(
			'Consultoria, 2 x Curso de Excel',
			Emissaoja_NFSe_Regras::itens_texto(
				array(
					array(
						'nome'       => 'Consultoria',
						'quantidade' => 1,
					),
					array(
						'nome'       => 'Curso de Excel',
						'quantidade' => 2,
					),
				)
			)
		);
		$this->assertSame(
			'1,5 x Hora técnica',
			Emissaoja_NFSe_Regras::itens_texto(
				array(
					array(
						'nome'       => 'Hora técnica',
						'quantidade' => '1.5',
					),
				)
			)
		);
		$this->assertSame(
			'Mentoria',
			Emissaoja_NFSe_Regras::itens_texto(
				array(
					array(
						'nome'       => '  ',
						'quantidade' => 3,
					),
					array( 'nome' => 'Mentoria' ),
					'não é item',
				)
			)
		);
		$this->assertSame( '', Emissaoja_NFSe_Regras::itens_texto( array() ) );
	}

	/**
	 * Valor: total ou sem frete, descontando reembolsos, nunca negativo.
	 */
	public function test_valor() {
		$this->assertEqualsWithDelta( 110.0, Emissaoja_NFSe_Regras::valor( 110, 0, 10, 0, 'total' ), 0.001 );
		$this->assertEqualsWithDelta( 100.0, Emissaoja_NFSe_Regras::valor( 110, 0, 10, 0, 'sem_frete' ), 0.001 );
		$this->assertEqualsWithDelta( 90.0, Emissaoja_NFSe_Regras::valor( 110, 20, 10, 0, 'total' ), 0.001 );
		// Frete reembolsado inteiro: não é descontado duas vezes.
		$this->assertEqualsWithDelta( 100.0, Emissaoja_NFSe_Regras::valor( 110, 10, 10, 10, 'sem_frete' ), 0.001 );
		// Reembolso negativo (como o WooCommerce às vezes guarda) vale o mesmo.
		$this->assertEqualsWithDelta( 90.0, Emissaoja_NFSe_Regras::valor( 110, -20, 10, 0, 'total' ), 0.001 );
		$this->assertEqualsWithDelta( 99.99, Emissaoja_NFSe_Regras::valor( '99.99', '0', '0', '0', 'total' ), 0.001 );
		$this->assertSame( 0.0, Emissaoja_NFSe_Regras::valor( 50, 60, 0, 0, 'total' ) );
		$this->assertSame( 0.0, Emissaoja_NFSe_Regras::valor( 'abc', null, null, null, 'total' ) );
		$this->assertSame( 13.13, Emissaoja_NFSe_Regras::valor( 13.13, 0, 0, 0, 'total' ) );
		$this->assertSame( 0.3, Emissaoja_NFSe_Regras::valor( 0.1 + 0.2, 0, 0, 0, 'total' ) );
	}

	/**
	 * Intervalo da próxima consulta, a partir de proxima_consulta_ms.
	 */
	public function test_intervalo_consulta() {
		$this->assertSame( 5, Emissaoja_NFSe_Regras::intervalo_consulta( 2000 ) );
		$this->assertSame( 5, Emissaoja_NFSe_Regras::intervalo_consulta( 5000 ) );
		$this->assertSame( 7, Emissaoja_NFSe_Regras::intervalo_consulta( '6500' ) );
		$this->assertSame( 30, Emissaoja_NFSe_Regras::intervalo_consulta( 30000 ) );
		$this->assertSame( 300, Emissaoja_NFSe_Regras::intervalo_consulta( 999999 ) );
		$this->assertSame( 10, Emissaoja_NFSe_Regras::intervalo_consulta( null ) );
		$this->assertSame( 10, Emissaoja_NFSe_Regras::intervalo_consulta( 0 ) );
	}

	/**
	 * Espera das novas tentativas automáticas.
	 */
	public function test_espera_retentativa() {
		$this->assertSame( 60, Emissaoja_NFSe_Regras::espera_retentativa( 1 ) );
		$this->assertSame( 120, Emissaoja_NFSe_Regras::espera_retentativa( 2 ) );
		$this->assertSame( 1800, Emissaoja_NFSe_Regras::espera_retentativa( 5 ) );
		$this->assertSame( 1800, Emissaoja_NFSe_Regras::espera_retentativa( 9 ) );
		$this->assertSame( 300, Emissaoja_NFSe_Regras::espera_retentativa( 1, 300 ) );
		$this->assertSame( 3600, Emissaoja_NFSe_Regras::espera_retentativa( 1, 99999 ) );
		$this->assertSame( 60, Emissaoja_NFSe_Regras::espera_retentativa( 0, -5 ) );
	}

	/**
	 * Só erros temporários são repetidos sozinhos.
	 */
	public function test_erro_temporario() {
		foreach ( array( 'fila_indisponivel', 'motor_indisponivel', 'receita_indisponivel', 'rate_limited', 'rede', 'tempo_esgotado' ) as $code ) {
			$this->assertTrue( Emissaoja_NFSe_Regras::erro_temporario( $code ), $code );
		}
		foreach ( array( 'nota_bloqueada', 'possivel_duplicada', 'emissao_repetida_recente', 'limite_notas', 'chave_invalida', 'incerta_pendente' ) as $code ) {
			$this->assertFalse( Emissaoja_NFSe_Regras::erro_temporario( $code ), $code );
		}
		$this->assertTrue( Emissaoja_NFSe_Regras::erro_temporario( 'erro_desconhecido', 502 ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::erro_temporario( 'erro_desconhecido', 400 ) );
	}

	/**
	 * Estados finais do pedido de emissão.
	 */
	public function test_estado_terminal() {
		$this->assertTrue( Emissaoja_NFSe_Regras::estado_terminal( 'autorizada' ) );
		$this->assertTrue( Emissaoja_NFSe_Regras::estado_terminal( 'recusada' ) );
		$this->assertTrue( Emissaoja_NFSe_Regras::estado_terminal( 'incerta' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::estado_terminal( 'na_fila' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::estado_terminal( 'confirmando' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::estado_terminal( '' ) );
	}

	/**
	 * A chave mascarada nunca mostra mais que os 4 últimos caracteres.
	 */
	public function test_mascarar_chave() {
		$this->assertSame( 'ej_live_••••••••1234', Emissaoja_NFSe_Regras::mascarar_chave( 'ej_live_abcdefghijklmnopqrstuvwxyz1234' ) );
		$this->assertSame( 'ej_test_••••••••wxyz', Emissaoja_NFSe_Regras::mascarar_chave( 'ej_test_abcdefghijklmnopqrstuvwxyz' ) );
		$this->assertSame( '••••••••xyz9', Emissaoja_NFSe_Regras::mascarar_chave( 'outra-chave-xyz9' ) );
		$this->assertSame( '', Emissaoja_NFSe_Regras::mascarar_chave( '' ) );
	}

	/**
	 * Formato e ambiente da chave de API.
	 */
	public function test_chave() {
		$live = 'ej_live_' . str_repeat( 'A1_-', 11 );
		$this->assertTrue( Emissaoja_NFSe_Regras::chave_valida( $live ) );
		$this->assertTrue( Emissaoja_NFSe_Regras::chave_valida( 'ej_test_abcdefgh' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::chave_valida( 'ej_test_abc' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::chave_valida( 'sk_live_abcdefghijkl' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::chave_valida( 'ej_live_abc defghijk' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::chave_valida( null ) );

		$this->assertSame( 'producao', Emissaoja_NFSe_Regras::ambiente_da_chave( $live ) );
		$this->assertSame( 'sandbox', Emissaoja_NFSe_Regras::ambiente_da_chave( 'ej_test_abcdefgh' ) );
		$this->assertSame( '', Emissaoja_NFSe_Regras::ambiente_da_chave( 'outra' ) );
	}

	/**
	 * Ids da API (UUID).
	 */
	public function test_id_valido() {
		$this->assertTrue( Emissaoja_NFSe_Regras::id_valido( '6f1c2b0e-8a4d-4c8e-9b7a-2d4f5e6a7b8c' ) );
		$this->assertTrue( Emissaoja_NFSe_Regras::id_valido( '6F1C2B0E-8A4D-4C8E-9B7A-2D4F5E6A7B8C' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::id_valido( '6f1c2b0e' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::id_valido( '6f1c2b0e-8a4d-4c8e-9b7a-2d4f5e6a7b8z' ) );
		$this->assertFalse( Emissaoja_NFSe_Regras::id_valido( null ) );
	}

	/**
	 * Corte sem quebrar caracteres.
	 */
	public function test_cortar() {
		$this->assertSame( 'açã', Emissaoja_NFSe_Regras::cortar( 'ação', 3 ) );
		$this->assertSame( 'abc', Emissaoja_NFSe_Regras::cortar( 'abc', 10 ) );
	}
}
