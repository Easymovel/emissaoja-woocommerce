/**
 * emissaoja: NFS-e para WooCommerce, aba de configurações.
 *
 * Botões "Testar conexão" e "Carregar serviços da conta". As chamadas vão para o admin-ajax.php do próprio site,
 * que fala com a API do emissaoja no servidor: a chave salva nunca chega ao navegador.
 */
( function ( window, document ) {
	'use strict';

	var config = window.emissaojaNfseConfig || {};
	var i18n = config.i18n || {};

	function valor( id ) {
		var campo = document.getElementById( id );
		return campo ? campo.value : '';
	}

	function postar( acao ) {
		var corpo = new window.FormData();
		corpo.append( 'action', acao );
		corpo.append( '_ajax_nonce', config.nonce || '' );
		corpo.append( 'chave', valor( 'emissaoja_nfse_api_key' ) );
		corpo.append( 'ambiente', valor( 'emissaoja_nfse_ambiente' ) );
		return window
			.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: corpo } )
			.then( function ( resposta ) {
				return resposta.json();
			} );
	}

	function mostrar( saida, linhas, ok ) {
		saida.textContent = '';
		linhas.forEach( function ( linha ) {
			var span = document.createElement( 'span' );
			span.className = 'emissaoja-nfse-linha';
			span.textContent = String( linha );
			saida.appendChild( span );
		} );
		saida.className = 'emissaoja-nfse-resultado ' + ( ok ? 'emissaoja-nfse-ok' : 'emissaoja-nfse-falha' );
	}

	function linhasDe( resposta ) {
		if ( resposta && resposta.data && Array.isArray( resposta.data.linhas ) && resposta.data.linhas.length ) {
			return resposta.data.linhas;
		}
		return [ i18n.falhou || 'Erro' ];
	}

	function iniciarTeste() {
		var botao = document.getElementById( 'emissaoja-nfse-testar' );
		var saida = document.getElementById( 'emissaoja-nfse-testar-resultado' );
		if ( ! botao || ! saida ) {
			return;
		}
		botao.addEventListener( 'click', function ( evento ) {
			evento.preventDefault();
			botao.disabled = true;
			mostrar( saida, [ i18n.testando || '…' ], true );
			postar( 'emissaoja_nfse_testar' )
				.then( function ( resposta ) {
					mostrar( saida, linhasDe( resposta ), !! ( resposta && resposta.success ) );
				} )
				.catch( function () {
					mostrar( saida, [ i18n.falhou || 'Erro' ], false );
				} )
				.then( function () {
					botao.disabled = false;
				} );
		} );
	}

	function iniciarServicos() {
		var botao = document.getElementById( 'emissaoja-nfse-servicos' );
		var lista = document.getElementById( 'emissaoja_nfse_servico_id' );
		var nome = document.getElementById( 'emissaoja_nfse_servico_nome' );
		var saida = document.getElementById( 'emissaoja-nfse-servicos-resultado' );
		if ( ! botao || ! lista || ! nome || ! saida ) {
			return;
		}

		function guardarNome() {
			var opcao = lista.options[ lista.selectedIndex ];
			nome.value = opcao && opcao.value ? opcao.textContent : '';
		}

		lista.addEventListener( 'change', guardarNome );

		botao.addEventListener( 'click', function ( evento ) {
			evento.preventDefault();
			botao.disabled = true;
			mostrar( saida, [ i18n.carregando || '…' ], true );
			postar( 'emissaoja_nfse_servicos' )
				.then( function ( resposta ) {
					if ( ! resposta || ! resposta.success ) {
						mostrar( saida, linhasDe( resposta ), false );
						return;
					}
					var servicos = ( resposta.data && Array.isArray( resposta.data.servicos ) ) ? resposta.data.servicos : [];
					var atual = lista.value;
					while ( lista.options.length > 1 ) {
						lista.remove( 1 );
					}
					servicos.forEach( function ( servico ) {
						var opcao = document.createElement( 'option' );
						opcao.value = String( servico.id );
						opcao.textContent = servico.principal
							? String( servico.descricao ) + ' ' + ( i18n.principal || '' )
							: String( servico.descricao );
						lista.appendChild( opcao );
					} );
					lista.value = atual;
					var mensagens = [];
					if ( lista.value !== atual ) {
						lista.value = '';
						mensagens.push( i18n.sumiu || '' );
					}
					guardarNome();
					mensagens.unshift(
						servicos.length
							? String( i18n.carregados || '%d' ).replace( '%d', String( servicos.length ) )
							: ( i18n.nenhum || '' )
					);
					mostrar( saida, mensagens, true );
				} )
				.catch( function () {
					mostrar( saida, [ i18n.falhou || 'Erro' ], false );
				} )
				.then( function () {
					botao.disabled = false;
				} );
		} );
	}

	function iniciar() {
		iniciarTeste();
		iniciarServicos();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', iniciar );
	} else {
		iniciar();
	}
} )( window, document );
