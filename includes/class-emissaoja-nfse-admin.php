<?php
/**
 * Aba WooCommerce > Configurações > NFS-e (emissaoja), "Testar conexão", lista de serviços e avisos do painel.
 *
 * @package Emissaoja_NFSe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tela de configurações e avisos.
 */
final class Emissaoja_NFSe_Admin {

	/**
	 * Id da aba nas configurações do WooCommerce.
	 */
	const ABA = 'emissaoja_nfse';

	/**
	 * Ação do nonce das chamadas AJAX da tela.
	 */
	const NONCE = 'emissaoja_nfse_admin';

	/**
	 * Link de cadastro (com a origem do plugin).
	 */
	const CADASTRO = 'https://emissaoja.com.br/?origem=lib-wordpress';

	/**
	 * Registra os ganchos.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'aba' ), 60 );
		add_action( 'woocommerce_settings_' . self::ABA, array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_settings_save_' . self::ABA, array( __CLASS__, 'salvar' ) );
		add_action( 'wp_ajax_emissaoja_nfse_testar', array( __CLASS__, 'ajax_testar' ) );
		add_action( 'wp_ajax_emissaoja_nfse_servicos', array( __CLASS__, 'ajax_servicos' ) );
		add_action( 'admin_post_emissaoja_nfse_dispensar', array( __CLASS__, 'dispensar_erro' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'avisos' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EMISSAOJA_NFSE_FILE ), array( __CLASS__, 'links' ) );
	}

	/**
	 * Adiciona a aba.
	 *
	 * @param array $abas Abas.
	 * @return array
	 */
	public static function aba( $abas ) {
		$abas[ self::ABA ] = __( 'NFS-e (emissaoja)', 'emissaoja-nfse' );
		return $abas;
	}

	/**
	 * Carrega o script e o estilo da aba.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::eh_tela_config() ) {
			return;
		}
		wp_enqueue_style( 'emissaoja-nfse-admin', EMISSAOJA_NFSE_URL . 'assets/css/admin.css', array(), EMISSAOJA_NFSE_VERSION );
		wp_enqueue_script( 'emissaoja-nfse-config', EMISSAOJA_NFSE_URL . 'assets/js/admin-config.js', array(), EMISSAOJA_NFSE_VERSION, array( 'in_footer' => true ) );
		wp_localize_script(
			'emissaoja-nfse-config',
			'emissaojaNfseConfig',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'testando'   => __( 'Testando…', 'emissaoja-nfse' ),
					'carregando' => __( 'Carregando…', 'emissaoja-nfse' ),
					'falhou'     => __( 'Não foi possível concluir. Tente de novo.', 'emissaoja-nfse' ),
					'principal'  => __( '(principal)', 'emissaoja-nfse' ),
					/* translators: %d: quantidade de serviços ativos. */
					'carregados' => __( 'Serviços ativos encontrados: %d.', 'emissaoja-nfse' ),
					'nenhum'     => __( 'Nenhum serviço ativo nesta conta. Cadastre um no app do emissaoja.', 'emissaoja-nfse' ),
					'sumiu'      => __( 'O serviço salvo não está mais ativo: escolha outro ou use o principal, e salve.', 'emissaoja-nfse' ),
				),
			)
		);
	}

	/**
	 * Mostra a aba (o formulário, o nonce e o botão de salvar são do WooCommerce).
	 *
	 * @return void
	 */
	public static function render() {
		$s     = Emissaoja_NFSe_Settings::all();
		$chave = Emissaoja_NFSe_Settings::api_key();
		$link  = array(
			'a' => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
		);
		?>
		<h2><?php esc_html_e( 'NFS-e pelo emissaoja', 'emissaoja-nfse' ); ?></h2>
		<p><?php esc_html_e( 'Emite a NFS-e dos pedidos no Emissor Nacional, sem certificado digital, pela API do emissaoja. A nota sai em nome da empresa conectada à sua conta do emissaoja.', 'emissaoja-nfse' ); ?></p>
		<?php if ( 'sandbox' === $s['ambiente'] ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Ambiente de testes (sandbox) ligado: as notas são simuladas, nada vai ao fisco e os clientes não são avisados.', 'emissaoja-nfse' ); ?></p></div>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="emissaoja_nfse_api_key"><?php esc_html_e( 'Chave de API', 'emissaoja-nfse' ); ?></label></th>
				<td>
					<?php if ( Emissaoja_NFSe_Settings::chave_por_constante() ) : ?>
						<p><span class="emissaoja-nfse-chave-atual"><?php echo esc_html( Emissaoja_NFSe_Regras::mascarar_chave( $chave ) ); ?></span> &mdash; <?php esc_html_e( 'definida pela constante EMISSAOJA_NFSE_API_KEY no wp-config.php.', 'emissaoja-nfse' ); ?></p>
					<?php else : ?>
						<?php if ( '' !== $chave ) : ?>
							<p><?php esc_html_e( 'Chave salva:', 'emissaoja-nfse' ); ?> <span class="emissaoja-nfse-chave-atual"><?php echo esc_html( Emissaoja_NFSe_Regras::mascarar_chave( $chave ) ); ?></span></p>
						<?php endif; ?>
						<input type="password" class="regular-text" id="emissaoja_nfse_api_key" name="emissaoja_nfse_api_key" value="" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr( '' !== $chave ? __( 'Cole uma nova chave para trocar a salva', 'emissaoja-nfse' ) : 'ej_live_...' ); ?>" />
						<?php if ( '' !== $chave ) : ?>
							<p><label><input type="checkbox" name="emissaoja_nfse_remover_chave" value="1" /> <?php esc_html_e( 'Apagar a chave salva', 'emissaoja-nfse' ); ?></label></p>
						<?php endif; ?>
						<p class="description">
							<?php
							printf(
								wp_kses(
									/* translators: %s: link de cadastro no emissaoja. */
									__( 'Ainda não tem chave? <a href="%s" target="_blank" rel="noopener noreferrer">Crie a sua conta no emissaoja</a>, entre com o Google, aceite os termos e conecte a empresa com o CNPJ e a senha do Emissor Nacional. A chave é criada em Conta &rarr; API e aparece uma vez só.', 'emissaoja-nfse' ),
									$link
								),
								esc_url( self::CADASTRO )
							);
							?>
							<br /><?php esc_html_e( 'A chave fica só no servidor: nunca é mostrada de novo inteira nem enviada ao navegador. Ela também pode ficar fora do banco de dados, no wp-config.php: define( \'EMISSAOJA_NFSE_API_KEY\', \'ej_live_...\' );', 'emissaoja-nfse' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="emissaoja_nfse_ambiente"><?php esc_html_e( 'Ambiente', 'emissaoja-nfse' ); ?></label></th>
				<td>
					<select id="emissaoja_nfse_ambiente" name="emissaoja_nfse[ambiente]">
						<option value="producao" <?php selected( 'producao', $s['ambiente'] ); ?>><?php esc_html_e( 'Produção (notas de verdade no Emissor Nacional)', 'emissaoja-nfse' ); ?></option>
						<option value="sandbox" <?php selected( 'sandbox', $s['ambiente'] ); ?>><?php esc_html_e( 'Sandbox (testes: nada vai ao fisco)', 'emissaoja-nfse' ); ?></option>
					</select>
					<p class="description">
						<?php
						printf(
							wp_kses(
								/* translators: %s: endereço do sandbox. */
								__( 'O sandbox é grátis e simula o Emissor Nacional: crie a conta em <a href="%s" target="_blank" rel="noopener noreferrer">sandbox.emissaoja.com.br</a> e use uma chave ej_test_ (as chaves ej_live_ só valem em produção). Empresa de exemplo pronta para emitir: CNPJ 11111111000191, com qualquer senha.', 'emissaoja-nfse' ),
								$link
							),
							esc_url( 'https://sandbox.emissaoja.com.br/' )
						);
						?>
					</p>
					<p>
						<button type="button" class="button" id="emissaoja-nfse-testar"><?php esc_html_e( 'Testar conexão', 'emissaoja-nfse' ); ?></button>
						<span class="emissaoja-nfse-resultado" id="emissaoja-nfse-testar-resultado" role="status" aria-live="polite"></span>
					</p>
					<p class="description"><?php esc_html_e( 'Confere se a API está no ar, se a chave vale neste ambiente e qual empresa está conectada. Usa a chave digitada acima, se houver; senão, a salva.', 'emissaoja-nfse' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Quando emitir', 'emissaoja-nfse' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Quando emitir', 'emissaoja-nfse' ); ?></legend>
						<label class="emissaoja-nfse-opcao"><input type="radio" name="emissaoja_nfse[quando]" value="manual" <?php checked( 'manual', $s['quando'] ); ?> /> <?php esc_html_e( 'Só manualmente, pelo botão "Emitir NFS-e agora" no pedido (padrão)', 'emissaoja-nfse' ); ?></label>
						<label class="emissaoja-nfse-opcao"><input type="radio" name="emissaoja_nfse[quando]" value="completed" <?php checked( 'completed', $s['quando'] ); ?> /> <?php esc_html_e( 'Quando o pedido for Concluído', 'emissaoja-nfse' ); ?></label>
						<label class="emissaoja-nfse-opcao"><input type="radio" name="emissaoja_nfse[quando]" value="processing" <?php checked( 'processing', $s['quando'] ); ?> /> <?php esc_html_e( 'Quando o pedido for Processando (pago); pedidos que vão direto para Concluído também', 'emissaoja-nfse' ); ?></label>
						<p class="description"><?php esc_html_e( 'A emissão automática roda em segundo plano (Action Scheduler) e não atrasa o checkout. Por segurança, ela não acontece em produção quando o site não está marcado como produção (WP_ENVIRONMENT_TYPE), como numa cópia de teste da loja.', 'emissaoja-nfse' ); ?></p>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Valor da nota', 'emissaoja-nfse' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Valor da nota', 'emissaoja-nfse' ); ?></legend>
						<label class="emissaoja-nfse-opcao"><input type="radio" name="emissaoja_nfse[base_valor]" value="total" <?php checked( 'total', $s['base_valor'] ); ?> /> <?php esc_html_e( 'Total do pedido', 'emissaoja-nfse' ); ?></label>
						<label class="emissaoja-nfse-opcao"><input type="radio" name="emissaoja_nfse[base_valor]" value="sem_frete" <?php checked( 'sem_frete', $s['base_valor'] ); ?> /> <?php esc_html_e( 'Total do pedido sem o frete', 'emissaoja-nfse' ); ?></label>
						<p class="description"><?php esc_html_e( 'Reembolsos feitos antes da emissão são descontados.', 'emissaoja-nfse' ); ?></p>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="emissaoja_nfse_descricao"><?php esc_html_e( 'Descrição da nota', 'emissaoja-nfse' ); ?></label></th>
				<td>
					<textarea id="emissaoja_nfse_descricao" name="emissaoja_nfse[descricao]" rows="3" class="large-text"><?php echo esc_textarea( $s['descricao'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Marcadores: {pedido} (número do pedido), {itens} (produtos e quantidades) e {loja} (nome do site). Até 2.000 caracteres. Em branco, vale a descrição do serviço cadastrado no emissaoja.', 'emissaoja-nfse' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="emissaoja_nfse_servico_id"><?php esc_html_e( 'Serviço', 'emissaoja-nfse' ); ?></label></th>
				<td>
					<select id="emissaoja_nfse_servico_id" name="emissaoja_nfse[servico_id]">
						<option value=""><?php esc_html_e( 'Serviço principal da conta (padrão)', 'emissaoja-nfse' ); ?></option>
						<?php if ( '' !== $s['servico_id'] ) : ?>
							<option value="<?php echo esc_attr( $s['servico_id'] ); ?>" selected="selected"><?php echo esc_html( '' !== $s['servico_nome'] ? $s['servico_nome'] : $s['servico_id'] ); ?></option>
						<?php endif; ?>
					</select>
					<input type="hidden" id="emissaoja_nfse_servico_nome" name="emissaoja_nfse[servico_nome]" value="<?php echo esc_attr( $s['servico_nome'] ); ?>" />
					<button type="button" class="button" id="emissaoja-nfse-servicos"><?php esc_html_e( 'Carregar serviços da conta', 'emissaoja-nfse' ); ?></button>
					<span class="emissaoja-nfse-resultado" id="emissaoja-nfse-servicos-resultado" role="status" aria-live="polite"></span>
					<p class="description"><?php esc_html_e( 'O serviço define o código de tributação e a alíquota. Os serviços são cadastrados no app do emissaoja.', 'emissaoja-nfse' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Aviso ao cliente', 'emissaoja-nfse' ); ?></th>
				<td>
					<label><input type="checkbox" name="emissaoja_nfse[nota_cliente]" value="1" <?php checked( $s['nota_cliente'] ); ?> /> <?php esc_html_e( 'Quando a NFS-e for autorizada, adicionar uma nota ao pedido para o cliente, com o número e o link da consulta pública', 'emissaoja-nfse' ); ?></label>
					<p class="description"><?php esc_html_e( 'O WooCommerce envia a nota ao cliente por e-mail e a mostra em Minha conta. Nunca acontece no sandbox.', 'emissaoja-nfse' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="emissaoja_nfse_campo_checkout"><?php esc_html_e( 'CPF/CNPJ no checkout', 'emissaoja-nfse' ); ?></label></th>
				<td>
					<select id="emissaoja_nfse_campo_checkout" name="emissaoja_nfse[campo_checkout]">
						<option value="auto" <?php selected( 'auto', $s['campo_checkout'] ); ?>><?php esc_html_e( 'Automático: só quando a loja não tem campos de CPF/CNPJ', 'emissaoja-nfse' ); ?></option>
						<option value="sempre" <?php selected( 'sempre', $s['campo_checkout'] ); ?>><?php esc_html_e( 'Sempre mostrar o campo do plugin', 'emissaoja-nfse' ); ?></option>
						<option value="nunca" <?php selected( 'nunca', $s['campo_checkout'] ); ?>><?php esc_html_e( 'Nunca mostrar o campo do plugin', 'emissaoja-nfse' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'O campo opcional "CPF ou CNPJ para a nota fiscal" aparece no checkout clássico e no checkout em blocos (WooCommerce 8.9 ou mais novo), com validação dos dígitos.', 'emissaoja-nfse' ); ?>
						<?php if ( Emissaoja_NFSe_Checkout::tem_brazilian_market() ) : ?>
							<br /><strong><?php esc_html_e( 'Brazilian Market on WooCommerce ativo: o plugin usa os campos de CPF e CNPJ dele.', 'emissaoja-nfse' ); ?></strong>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Metas do documento', 'emissaoja-nfse' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Metas do documento', 'emissaoja-nfse' ); ?></legend>
						<label class="emissaoja-nfse-meta"><?php esc_html_e( 'CPF', 'emissaoja-nfse' ); ?> <input type="text" class="regular-text code" name="emissaoja_nfse[meta_cpf]" value="<?php echo esc_attr( $s['meta_cpf'] ); ?>" /></label>
						<label class="emissaoja-nfse-meta"><?php esc_html_e( 'CNPJ', 'emissaoja-nfse' ); ?> <input type="text" class="regular-text code" name="emissaoja_nfse[meta_cnpj]" value="<?php echo esc_attr( $s['meta_cnpj'] ); ?>" /></label>
						<label class="emissaoja-nfse-meta"><?php esc_html_e( 'Tipo de pessoa', 'emissaoja-nfse' ); ?> <input type="text" class="regular-text code" name="emissaoja_nfse[meta_tipo]" value="<?php echo esc_attr( $s['meta_tipo'] ); ?>" /></label>
						<p class="description"><?php esc_html_e( 'Avançado. Metas do pedido de onde o plugin lê o documento do tomador. O padrão (_billing_cpf, _billing_cnpj e _billing_persontype, com 1 para pessoa física e 2 para jurídica) é o do Brazilian Market on WooCommerce. Depois delas, o plugin lê o próprio campo "CPF ou CNPJ para a nota fiscal".', 'emissaoja-nfse' ); ?></p>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Salva a aba. O WooCommerce já conferiu o nonce; ele é conferido de novo aqui.
	 *
	 * @return void
	 */
	public static function salvar() {
		check_admin_referer( 'woocommerce-settings' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce do WooCommerce conferido acima.
		$valores = isset( $_POST['emissaoja_nfse'] ) && is_array( $_POST['emissaoja_nfse'] ) ? map_deep( wp_unslash( $_POST['emissaoja_nfse'] ), 'sanitize_textarea_field' ) : array();
		Emissaoja_NFSe_Settings::update( $valores );

		if ( ! Emissaoja_NFSe_Settings::chave_por_constante() ) {
			if ( ! empty( $_POST['emissaoja_nfse_remover_chave'] ) ) {
				Emissaoja_NFSe_Settings::set_api_key( '' );
				Emissaoja_NFSe_Settings::clear_error();
			} elseif ( isset( $_POST['emissaoja_nfse_api_key'] ) ) {
				$nova = trim( sanitize_text_field( wp_unslash( $_POST['emissaoja_nfse_api_key'] ) ) );
				if ( '' !== $nova && Emissaoja_NFSe_Regras::chave_valida( $nova ) ) {
					Emissaoja_NFSe_Settings::set_api_key( $nova );
					Emissaoja_NFSe_Settings::clear_error();
				} elseif ( '' !== $nova ) {
					self::erro_da_tela( __( 'A chave não foi salva: a chave do emissaoja começa com ej_live_ (produção) ou ej_test_ (sandbox).', 'emissaoja-nfse' ) );
				}
			}
		}
		// phpcs:enable

		$da_chave = Emissaoja_NFSe_Regras::ambiente_da_chave( Emissaoja_NFSe_Settings::api_key() );
		$ambiente = (string) Emissaoja_NFSe_Settings::get( 'ambiente' );
		if ( 'sandbox' === $da_chave && 'producao' === $ambiente ) {
			self::erro_da_tela( __( 'A chave salva é do sandbox (ej_test_), mas o ambiente escolhido é Produção. Troque a chave ou o ambiente.', 'emissaoja-nfse' ) );
		} elseif ( 'producao' === $da_chave && 'sandbox' === $ambiente ) {
			self::erro_da_tela( __( 'A chave salva é de produção (ej_live_), mas o ambiente escolhido é Sandbox. Troque a chave ou o ambiente.', 'emissaoja-nfse' ) );
		}
	}

	/**
	 * AJAX "Testar conexão": GET /v1/status e GET /v1/empresa.
	 *
	 * @return void
	 */
	public static function ajax_testar() {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'linhas' => array( __( 'Você não tem permissão para isso.', 'emissaoja-nfse' ) ) ), 403 );
		}

		$digitada = self::chave_digitada();
		$api      = self::api_do_formulario( $digitada );
		$chave    = '' !== $digitada ? $digitada : Emissaoja_NFSe_Settings::api_key();
		$linhas   = array();

		$status = $api->status();
		if ( is_wp_error( $status ) ) {
			/* translators: %s: mensagem do erro. */
			$linhas[] = sprintf( __( 'Não foi possível falar com a API do emissaoja: %s', 'emissaoja-nfse' ), $status->get_error_message() );
			wp_send_json_error( array( 'linhas' => $linhas ) );
		}
		$no_sandbox = isset( $status['ambiente'] ) && 'sandbox' === $status['ambiente'];
		/* translators: %s: ambiente da API (produção ou sandbox). */
		$linhas[] = sprintf( __( 'API no ar (ambiente: %s).', 'emissaoja-nfse' ), $no_sandbox ? __( 'sandbox', 'emissaoja-nfse' ) : __( 'produção', 'emissaoja-nfse' ) );
		if ( isset( $status['motor'] ) && 'degradado' === $status['motor'] ) {
			$linhas[] = __( 'O Emissor Nacional está instável agora: as emissões podem demorar.', 'emissaoja-nfse' );
		}

		if ( '' === $chave ) {
			$linhas[] = __( 'Nenhuma chave salva ainda.', 'emissaoja-nfse' );
			wp_send_json_error( array( 'linhas' => $linhas ) );
		}
		$da_chave = Emissaoja_NFSe_Regras::ambiente_da_chave( $chave );
		if ( '' !== $da_chave && $da_chave !== $api->ambiente() ) {
			$linhas[] = 'sandbox' === $da_chave
				? __( 'Atenção: a chave é do sandbox (ej_test_), mas o ambiente escolhido é Produção.', 'emissaoja-nfse' )
				: __( 'Atenção: a chave é de produção (ej_live_), mas o ambiente escolhido é Sandbox.', 'emissaoja-nfse' );
		}

		$dados = $api->empresa();
		if ( is_wp_error( $dados ) ) {
			if ( 'chave_invalida' === $dados->get_error_code() ) {
				$linhas[] = __( 'A chave foi recusada. Confira se ela foi copiada inteira, se não foi revogada (Conta → API no app) e se o ambiente bate: chaves ej_test_ só valem no sandbox e ej_live_ só em produção.', 'emissaoja-nfse' );
			} else {
				/* translators: 1: mensagem do erro, 2: código do erro. */
				$linhas[] = sprintf( __( 'A API respondeu com erro: %1$s [%2$s]', 'emissaoja-nfse' ), $dados->get_error_message(), $dados->get_error_code() );
			}
			wp_send_json_error( array( 'linhas' => $linhas ) );
		}

		$conta = isset( $dados['usuario']['email'] ) && is_string( $dados['usuario']['email'] ) ? sanitize_email( $dados['usuario']['email'] ) : '';
		/* translators: %s: e-mail da conta no emissaoja. */
		$linhas[] = '' !== $conta ? sprintf( __( 'Chave aceita (conta %s).', 'emissaoja-nfse' ), $conta ) : __( 'Chave aceita.', 'emissaoja-nfse' );

		$empresa = isset( $dados['empresa'] ) && is_array( $dados['empresa'] ) ? $dados['empresa'] : null;
		if ( null === $empresa ) {
			$linhas[] = __( 'Nenhuma empresa conectada nesta conta. Conecte a empresa no app do emissaoja, com o CNPJ e a senha do Emissor Nacional.', 'emissaoja-nfse' );
			wp_send_json_error( array( 'linhas' => $linhas ) );
		}

		$nome = isset( $empresa['nome'] ) && is_string( $empresa['nome'] ) && '' !== $empresa['nome'] ? sanitize_text_field( $empresa['nome'] ) : __( '(sem nome)', 'emissaoja-nfse' );
		$cnpj = isset( $empresa['cnpj'] ) && is_scalar( $empresa['cnpj'] ) ? Emissaoja_NFSe_Documento::formatar( (string) $empresa['cnpj'] ) : '';
		if ( isset( $empresa['cnpj_formatado'] ) && is_string( $empresa['cnpj_formatado'] ) && '' !== $empresa['cnpj_formatado'] ) {
			$cnpj = sanitize_text_field( $empresa['cnpj_formatado'] );
		}
		$porte = '';
		if ( isset( $empresa['porte_simples'] ) && 'mei' === $empresa['porte_simples'] ) {
			$porte = ' ' . __( '(MEI)', 'emissaoja-nfse' );
		} elseif ( isset( $empresa['porte_simples'] ) && 'me_epp' === $empresa['porte_simples'] ) {
			$porte = ' ' . __( '(ME/EPP do Simples Nacional)', 'emissaoja-nfse' );
		}
		/* translators: 1: razão social, 2: CNPJ. */
		$linhas[] = sprintf( __( 'Empresa conectada: %1$s, CNPJ %2$s', 'emissaoja-nfse' ), $nome, $cnpj ) . $porte . '.';

		$pronta     = true;
		$onboarding = isset( $dados['onboarding']['estado'] ) && is_string( $dados['onboarding']['estado'] ) ? sanitize_key( $dados['onboarding']['estado'] ) : '';
		if ( 'pronto' !== $onboarding ) {
			$pronta = false;
			/* translators: %s: estado do cadastro da empresa no emissaoja. */
			$linhas[] = sprintf( __( 'A empresa ainda não está pronta para emitir (cadastro: %s). Conclua a configuração no app do emissaoja.', 'emissaoja-nfse' ), '' !== $onboarding ? $onboarding : '?' );
		}
		if ( isset( $dados['credencial']['estado'] ) && 'congelada' === $dados['credencial']['estado'] ) {
			$pronta   = false;
			$linhas[] = __( 'O Emissor Nacional recusou a senha da empresa. Atualize a senha no app do emissaoja.', 'emissaoja-nfse' );
		}
		if ( ! empty( $dados['aceite']['pendente'] ) ) {
			$pronta   = false;
			$linhas[] = __( 'Há termos para aceitar no app do emissaoja antes de emitir.', 'emissaoja-nfse' );
		}

		if ( '' !== $digitada ) {
			$linhas[] = __( 'Lembre de salvar as alterações para guardar esta chave.', 'emissaoja-nfse' );
		}
		if ( ! $pronta ) {
			wp_send_json_error( array( 'linhas' => $linhas ) );
		}
		if ( '' === $digitada ) {
			Emissaoja_NFSe_Settings::clear_error();
		}
		$linhas[] = __( 'Tudo certo para emitir.', 'emissaoja-nfse' );
		wp_send_json_success( array( 'linhas' => $linhas ) );
	}

	/**
	 * AJAX "Carregar serviços da conta": GET /v1/servicos?ativos=1.
	 *
	 * @return void
	 */
	public static function ajax_servicos() {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'linhas' => array( __( 'Você não tem permissão para isso.', 'emissaoja-nfse' ) ) ), 403 );
		}

		$resposta = self::api_do_formulario( self::chave_digitada() )->servicos();
		if ( is_wp_error( $resposta ) ) {
			/* translators: 1: mensagem do erro, 2: código do erro. */
			wp_send_json_error( array( 'linhas' => array( sprintf( __( 'Não foi possível listar os serviços: %1$s [%2$s]', 'emissaoja-nfse' ), $resposta->get_error_message(), $resposta->get_error_code() ) ) ) );
		}

		$servicos = array();
		$lista    = isset( $resposta['servicos'] ) && is_array( $resposta['servicos'] ) ? $resposta['servicos'] : array();
		foreach ( $lista as $servico ) {
			if ( ! is_array( $servico ) || ! isset( $servico['id'] ) || ! Emissaoja_NFSe_Regras::id_valido( $servico['id'] ) ) {
				continue;
			}
			$servicos[] = array(
				'id'        => $servico['id'],
				'descricao' => isset( $servico['descricao'] ) && is_string( $servico['descricao'] ) ? sanitize_text_field( $servico['descricao'] ) : $servico['id'],
				'principal' => ! empty( $servico['principal'] ),
			);
		}
		wp_send_json_success( array( 'servicos' => $servicos ) );
	}

	/**
	 * Avisos no painel: erro da conta (chave recusada, limite do plano...) e chave que falta.
	 *
	 * @return void
	 */
	public static function avisos() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$erro = get_option( Emissaoja_NFSe_Settings::ERR_OPTION );
		if ( is_array( $erro ) && ! empty( $erro['code'] ) ) {
			$textos = array(
				'chave_invalida'       => __( 'emissaoja: a chave de API foi recusada, e as NFS-e não estão sendo emitidas. Confira a chave e o ambiente nas configurações.', 'emissaoja-nfse' ),
				'limite_notas'         => __( 'emissaoja: o limite de notas do seu plano neste mês acabou. As NFS-e voltam a sair no próximo mês ou com outro plano (emissaoja.com.br/#precos).', 'emissaoja-nfse' ),
				'sem_empresa'          => __( 'emissaoja: nenhuma empresa conectada à conta. Conecte a empresa no app do emissaoja para emitir NFS-e.', 'emissaoja-nfse' ),
				'aceite_pendente'      => __( 'emissaoja: há termos para aceitar no app do emissaoja antes de emitir NFS-e.', 'emissaoja-nfse' ),
				'credencial_congelada' => __( 'emissaoja: o Emissor Nacional recusou a senha da empresa. Atualize a senha no app do emissaoja para voltar a emitir.', 'emissaoja-nfse' ),
			);
			$texto    = isset( $textos[ $erro['code'] ] ) ? $textos[ $erro['code'] ] : ( isset( $erro['message'] ) ? (string) $erro['message'] : '' );
			$dispensa = wp_nonce_url( admin_url( 'admin-post.php?action=emissaoja_nfse_dispensar' ), 'emissaoja_nfse_dispensar' );
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a> | <a href="%4$s">%5$s</a></p></div>',
				esc_html( $texto ),
				esc_url( Emissaoja_NFSe_Settings::page_url() ),
				esc_html__( 'Configurações', 'emissaoja-nfse' ),
				esc_url( $dispensa ),
				esc_html__( 'Dispensar', 'emissaoja-nfse' )
			);
			return;
		}

		$tela = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $tela && 'plugins' === $tela->id && '' === Emissaoja_NFSe_Settings::api_key() ) {
			printf(
				'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'emissaoja NFS-e for WooCommerce precisa de uma chave de API para emitir as notas.', 'emissaoja-nfse' ),
				esc_url( Emissaoja_NFSe_Settings::page_url() ),
				esc_html__( 'Configurar', 'emissaoja-nfse' )
			);
		}
	}

	/**
	 * Dispensa o aviso de erro da conta.
	 *
	 * @return void
	 */
	public static function dispensar_erro() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para isso.', 'emissaoja-nfse' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'emissaoja_nfse_dispensar' );
		Emissaoja_NFSe_Settings::clear_error();
		$volta = wp_get_referer();
		wp_safe_redirect( $volta ? $volta : admin_url() );
		exit;
	}

	/**
	 * Link "Configurações" na tela de plugins.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( Emissaoja_NFSe_Settings::page_url() ) . '">' . esc_html__( 'Configurações', 'emissaoja-nfse' ) . '</a>' );
		return $links;
	}

	/**
	 * Se a tela atual é a aba do plugin.
	 *
	 * @return bool
	 */
	private static function eh_tela_config() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Só identifica a tela.
		$pagina = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$aba    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable
		return 'wc-settings' === $pagina && self::ABA === $aba;
	}

	/**
	 * Chave digitada no formulário (ainda não salva), nas chamadas AJAX.
	 *
	 * @return string
	 */
	private static function chave_digitada() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Conferido por check_ajax_referer() em quem chama.
		return isset( $_POST['chave'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['chave'] ) ) ) : '';
	}

	/**
	 * Cliente com a chave digitada (ou a salva) e o ambiente escolhido no formulário (ou o salvo).
	 *
	 * @param string $digitada Chave digitada.
	 * @return Emissaoja_NFSe_Api
	 */
	private static function api_do_formulario( $digitada ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Conferido por check_ajax_referer() em quem chama.
		$ambiente = isset( $_POST['ambiente'] ) ? sanitize_key( wp_unslash( $_POST['ambiente'] ) ) : '';
		if ( ! in_array( $ambiente, array( 'producao', 'sandbox' ), true ) ) {
			$ambiente = (string) Emissaoja_NFSe_Settings::get( 'ambiente' );
		}
		return new Emissaoja_NFSe_Api( '' !== $digitada ? $digitada : Emissaoja_NFSe_Settings::api_key(), $ambiente );
	}

	/**
	 * Mensagem de erro na tela de configurações do WooCommerce.
	 *
	 * @param string $texto Mensagem.
	 * @return void
	 */
	private static function erro_da_tela( $texto ) {
		if ( class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error( $texto );
		}
	}
}
