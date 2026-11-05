/**
 * emissaoja: NFS-e para WooCommerce, caixa "NFS-e (emissaoja)" do pedido.
 *
 * Pede confirmação antes de emitir em produção (a nota tem efeito fiscal) e evita o clique duplo nos botões.
 */
( function ( window, document ) {
	'use strict';

	document.addEventListener( 'click', function ( evento ) {
		var alvo = evento.target && evento.target.closest ? evento.target.closest( '.emissaoja-nfse-acoes a' ) : null;
		if ( ! alvo ) {
			return;
		}
		if ( alvo.getAttribute( 'aria-disabled' ) === 'true' ) {
			evento.preventDefault();
			return;
		}
		var pergunta = alvo.getAttribute( 'data-emissaoja-confirmar' );
		if ( pergunta && ! window.confirm( pergunta ) ) {
			evento.preventDefault();
			return;
		}
		// O download não sai da página: o botão continua ativo para baixar de novo.
		if ( alvo.href && alvo.href.indexOf( 'emissaoja_nfse_baixar' ) !== -1 ) {
			return;
		}
		alvo.setAttribute( 'aria-disabled', 'true' );
		alvo.classList.add( 'disabled' );
	} );
} )( window, document );
