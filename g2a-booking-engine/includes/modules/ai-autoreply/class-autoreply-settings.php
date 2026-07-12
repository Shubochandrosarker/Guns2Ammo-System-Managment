<?php
/**
 * AI Auto-Reply — admin settings tab.
 *
 * @package G2AB\Modules\AIAutoReply
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_AutoReply_Settings {

	public function __construct() {
		add_filter( 'g2ab_settings_tabs', array( $this, 'register_tab' ) );
		add_action( 'g2ab_settings_render_ai_autoreply', array( $this, 'render' ) );
		add_action( 'admin_post_g2ab_save_ai_settings', array( $this, 'save' ) );
		add_action( 'admin_post_g2ab_test_ai', array( $this, 'test' ) );
		add_action( 'admin_post_g2ab_save_kb', array( $this, 'save_kb' ) );
		add_action( 'admin_post_g2ab_seed_kb', array( $this, 'seed_kb' ) );
	}

	public function register_tab( $tabs ) {
		$tabs['ai_autoreply'] = 'AI Auto-Reply';
		return $tabs;
	}

	public function render() {
		$client = new G2AB_OpenRouter_Client();
		$engine = new G2AB_AutoReply_Engine();
		$kb = $engine->get_knowledge_base();
		$tones = $engine->tone_options();
		$providers = G2AB_OpenRouter_Client::providers();
		$current_provider = $client->provider_key();
		$current_cfg      = $client->provider_config();

		$msg = '';
		if ( ! empty( $_GET['saved'] ) ) $msg = '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
		if ( ! empty( $_GET['test_result'] ) ) {
			$result = base64_decode( $_GET['test_result'] ); // phpcs:ignore
			$ok = ! empty( $_GET['test_ok'] );
			$cls = $ok ? 'success' : 'error';
			$msg = '<div class="notice notice-' . $cls . ' is-dismissible"><p><strong>Test:</strong> ' . esc_html( $result ) . '</p></div>';
		}
		?>
		<style>
			.g2ab-ai__row{margin-bottom:18px;max-width:760px;}
			.g2ab-ai__row label{display:block;font-weight:600;margin-bottom:6px;}
			.g2ab-ai__row input[type=text],.g2ab-ai__row input[type=password],.g2ab-ai__row input[type=url],.g2ab-ai__row select,.g2ab-ai__row textarea{width:100%;}
			.g2ab-ai__row textarea{min-height:120px;}
			.g2ab-ai__panel{background:#fff;border:1px solid #d0d4d9;padding:24px;margin-bottom:24px;}
			.g2ab-ai__kb-row{display:grid;grid-template-columns:1fr 2fr 60px;gap:8px;margin-bottom:8px;}
			.g2ab-ai__hint{font-size:12px;color:#666;margin-top:4px;}
			.g2ab-ai__pill-ok{background:#4CAF50;color:#fff;padding:3px 8px;border-radius:2px;font-size:11px;letter-spacing:.06em;}
			.g2ab-ai__pill-no{background:#C62828;color:#fff;padding:3px 8px;border-radius:2px;font-size:11px;letter-spacing:.06em;}
			.g2ab-ai__hidden{display:none;}
		</style>

		<?php echo $msg; // phpcs:ignore ?>

		<div class="g2ab-ai__panel">
			<h2 style="margin-top:0;">AI Provider Connection
				<?php if ( $client->is_configured() ) : ?>
					<span class="g2ab-ai__pill-ok">CONNECTED</span>
				<?php else : ?>
					<span class="g2ab-ai__pill-no">NOT CONFIGURED</span>
				<?php endif; ?>
			</h2>
			<p>Pick any OpenAI-compatible provider. Groq has the most reliable free tier; OpenRouter has the most free models; OpenAI is the cheapest paid option.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="g2ab-ai-form">
				<?php wp_nonce_field( 'g2ab_save_ai_settings', '_g2ab_nonce' ); ?>
				<input type="hidden" name="action" value="g2ab_save_ai_settings" />

				<div class="g2ab-ai__row">
					<label>Provider</label>
					<select name="provider" id="g2ab-ai-provider">
						<?php foreach ( $providers as $pkey => $pcfg ) : ?>
							<option value="<?php echo esc_attr( $pkey ); ?>" <?php selected( $current_provider, $pkey ); ?>><?php echo esc_html( $pcfg['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<div class="g2ab-ai__hint" id="g2ab-ai-provider-hint"><?php echo esc_html( $current_cfg['keys_hint'] ); ?></div>
				</div>

				<div class="g2ab-ai__row">
					<label>API Key</label>
					<?php $key_saved = '' !== (string) get_option( G2AB_OpenRouter_Client::OPTION_KEY, '' ); ?>
					<input type="password" name="api_key" value="" placeholder="<?php echo esc_attr( $key_saved ? __( 'saved — enter new value to replace', 'g2a-booking' ) : 'sk-...' ); ?>" autocomplete="off" />
					<div class="g2ab-ai__hint" id="g2ab-ai-keys-link">
						<?php if ( ! empty( $current_cfg['keys_url'] ) ) : ?>
							Get your key at <a href="<?php echo esc_url( $current_cfg['keys_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $current_cfg['keys_url'], PHP_URL_HOST ) ); ?></a>.
						<?php endif; ?>
						Or set <code>G2AB_OPENROUTER_KEY</code> in wp-config.php (works for any provider).
					</div>
				</div>

				<div class="g2ab-ai__row <?php echo 'custom' === $current_provider ? '' : 'g2ab-ai__hidden'; ?>" id="g2ab-ai-endpoint-row">
					<label>Endpoint URL</label>
					<input type="url" name="endpoint" value="<?php echo esc_attr( get_option( G2AB_OpenRouter_Client::OPTION_ENDPOINT, '' ) ); ?>" placeholder="https://your-host.example.com/v1/chat/completions" />
					<div class="g2ab-ai__hint">Must be a complete OpenAI-compatible /chat/completions URL.</div>
				</div>

				<div class="g2ab-ai__row">
					<label>Model</label>
					<select name="model" id="g2ab-ai-model">
						<?php foreach ( $providers as $pkey => $pcfg ) : ?>
							<?php foreach ( $pcfg['models'] as $mid => $mlabel ) : ?>
								<option value="<?php echo esc_attr( $mid ); ?>" data-provider="<?php echo esc_attr( $pkey ); ?>" <?php selected( $client->model(), $mid ); ?> <?php echo $pkey === $current_provider ? '' : 'hidden'; ?>><?php echo esc_html( $mlabel ); ?></option>
							<?php endforeach; ?>
						<?php endforeach; ?>
						<option value="__custom__" data-provider="custom" <?php selected( 'custom', $current_provider ); ?> <?php echo 'custom' === $current_provider ? '' : 'hidden'; ?>>— Enter custom model name below —</option>
					</select>
					<div class="g2ab-ai__hint">Model list changes when you switch providers.</div>
				</div>

				<div class="g2ab-ai__row <?php echo 'custom' === $current_provider ? '' : 'g2ab-ai__hidden'; ?>" id="g2ab-ai-custom-model-row">
					<label>Custom model ID</label>
					<input type="text" name="custom_model" value="<?php echo esc_attr( 'custom' === $current_provider ? get_option( G2AB_OpenRouter_Client::OPTION_MODEL, '' ) : '' ); ?>" placeholder="meta-llama/Llama-3.3-70B-Instruct" />
					<div class="g2ab-ai__hint">Used when "Custom" provider is selected.</div>
				</div>

				<div class="g2ab-ai__row">
					<label>Reply Tone</label>
					<select name="tone">
						<?php foreach ( $tones as $key => $desc ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( get_option( G2AB_AutoReply_Engine::OPTION_TONE, 'professional_friendly' ), $key ); ?>>
								<?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="g2ab-ai__row">
					<label>
						<input type="checkbox" name="auto_send" value="1" <?php checked( ! empty( get_option( G2AB_AutoReply_Engine::OPTION_AUTO_SEND, 0 ) ) ); ?> />
						Auto-send drafts (otherwise: save as draft for staff to review)
					</label>
					<div class="g2ab-ai__hint"><strong>Recommended: leave OFF</strong>. AI replies should be reviewed by staff before sending.</div>
				</div>

				<div class="g2ab-ai__row">
					<label>Custom System Prompt (optional)</label>
					<textarea name="system_prompt"><?php echo esc_textarea( get_option( G2AB_AutoReply_Engine::OPTION_SYSTEM_PROMPT, '' ) ); ?></textarea>
					<div class="g2ab-ai__hint">Extra instructions appended to the default system prompt. Useful for brand-specific phrasing or compliance language.</div>
				</div>

				<button class="button button-primary">Save Settings</button>
			</form>

			<hr style="margin:24px 0;" />

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'g2ab_test_ai', '_g2ab_nonce' ); ?>
				<input type="hidden" name="action" value="g2ab_test_ai" />
				<button class="button">Test Connection</button>
			</form>
		</div>

		<div class="g2ab-ai__panel">
			<h2 style="margin-top:0;">Knowledge Base</h2>
			<p>Q&A pairs the AI uses to answer customer questions accurately. Add facts about hours, policies, equipment, classes, etc.</p>

			<?php if ( empty( $kb ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'g2ab_seed_kb', '_g2ab_nonce' ); ?>
					<input type="hidden" name="action" value="g2ab_seed_kb" />
					<p><em>No entries yet.</em></p>
					<button class="button">Seed with starter KB (5 firearms-range FAQs)</button>
				</form>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'g2ab_save_kb', '_g2ab_nonce' ); ?>
				<input type="hidden" name="action" value="g2ab_save_kb" />

				<div id="g2ab-ai-kb-list">
					<div class="g2ab-ai__kb-row" style="font-weight:bold;">
						<div>Question</div><div>Answer</div><div></div>
					</div>
					<?php
					$rows = ! empty( $kb ) ? $kb : array( array( 'question' => '', 'answer' => '' ) );
					foreach ( $rows as $i => $r ) : ?>
						<div class="g2ab-ai__kb-row">
							<input type="text" name="kb[<?php echo (int) $i; ?>][question]" value="<?php echo esc_attr( $r['question'] ); ?>" />
							<textarea name="kb[<?php echo (int) $i; ?>][answer]" rows="2"><?php echo esc_textarea( $r['answer'] ); ?></textarea>
							<button type="button" class="button" onclick="this.parentNode.remove();">−</button>
						</div>
					<?php endforeach; ?>
				</div>

				<button type="button" class="button" onclick="
					var c = document.getElementById('g2ab-ai-kb-list');
					var i = c.querySelectorAll('.g2ab-ai__kb-row').length;
					var d = document.createElement('div');
					d.className = 'g2ab-ai__kb-row';
					d.innerHTML = '<input type=\'text\' name=\'kb['+i+'][question]\' /><textarea name=\'kb['+i+'][answer]\' rows=\'2\'></textarea><button type=\'button\' class=\'button\' onclick=\'this.parentNode.remove();\'>−</button>';
					c.appendChild(d);
				">+ Add Entry</button>
				<button class="button button-primary">Save Knowledge Base</button>
			</form>
		</div>

		<div class="g2ab-ai__panel">
			<h2 style="margin-top:0;">Try It</h2>
			<p>Paste a sample customer question to see what the AI would draft.</p>
			<div class="g2ab-ai__row">
				<textarea id="g2ab-ai-test-input" placeholder="Hi, I want to bring my 14-year-old son to try shooting for his birthday. What are your age rules and do you offer beginner instruction?"></textarea>
			</div>
			<button class="button button-primary" id="g2ab-ai-test-btn">Generate Draft</button>
			<div id="g2ab-ai-test-output" style="margin-top:16px;padding:16px;background:#F4F5F7;border-left:3px solid #E8802F;display:none;white-space:pre-wrap;"></div>
		</div>

		<script>
		(function(){
			// ── Provider → model + endpoint swap ─────────────────────────
			var providers = <?php echo wp_json_encode( $providers ); ?>;
			var providerSelect = document.getElementById('g2ab-ai-provider');
			var modelSelect    = document.getElementById('g2ab-ai-model');
			var endpointRow    = document.getElementById('g2ab-ai-endpoint-row');
			var customModelRow = document.getElementById('g2ab-ai-custom-model-row');
			var hintEl         = document.getElementById('g2ab-ai-provider-hint');
			var keysLink       = document.getElementById('g2ab-ai-keys-link');
			if (providerSelect && modelSelect) {
				providerSelect.addEventListener('change', function(){
					var p = providerSelect.value;
					var cfg = providers[p] || {};
					Array.prototype.forEach.call(modelSelect.options, function(opt){
						opt.hidden = (opt.dataset.provider !== p);
					});
					var firstVisible = modelSelect.querySelector('option:not([hidden])');
					if (firstVisible) modelSelect.value = firstVisible.value;
					if (endpointRow) endpointRow.classList.toggle('g2ab-ai__hidden', p !== 'custom');
					if (customModelRow) customModelRow.classList.toggle('g2ab-ai__hidden', p !== 'custom');
					if (hintEl) hintEl.textContent = cfg.keys_hint || '';
					if (keysLink && cfg.keys_url) {
						keysLink.innerHTML = 'Get your key at <a href="' + cfg.keys_url + '" target="_blank" rel="noopener">' + (new URL(cfg.keys_url)).host + '</a>. Or set <code>G2AB_OPENROUTER_KEY</code> in wp-config.php (works for any provider).';
					}
				});
			}

			// ── Try-It draft tester ───────────────────────────────────────
			var btn = document.getElementById('g2ab-ai-test-btn');
			var inp = document.getElementById('g2ab-ai-test-input');
			var out = document.getElementById('g2ab-ai-test-output');
			if (!btn) return;
			btn.addEventListener('click', function(){
				if (!inp.value.trim()) return;
				out.style.display = 'block';
				out.textContent = 'Generating draft (this may take 5-15 seconds)...';
				btn.disabled = true;
				fetch('<?php echo esc_url_raw( rest_url( G2AB_REST_NAMESPACE . '/ai/draft' ) ); ?>', {
					method: 'POST',
					headers: {'Content-Type':'application/json','X-WP-Nonce':'<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'},
					body: JSON.stringify({message: inp.value})
				}).then(r => r.json()).then(j => {
					btn.disabled = false;
					out.textContent = (j && j.success) ? j.data.draft : ('Error: ' + (j && j.message ? j.message : 'unknown'));
				}).catch(e => { btn.disabled = false; out.textContent = 'Network error.'; });
			});
		})();
		</script>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_save_ai_settings', '_g2ab_nonce' );

		$providers = G2AB_OpenRouter_Client::providers();
		$provider  = sanitize_key( $_POST['provider'] ?? 'openrouter' );
		if ( ! isset( $providers[ $provider ] ) ) {
			$provider = 'openrouter';
		}
		update_option( G2AB_OpenRouter_Client::OPTION_PROVIDER, $provider );
		$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		if ( '' !== $api_key ) {
			// The field renders masked/empty — an empty submit keeps the stored key.
			update_option( G2AB_OpenRouter_Client::OPTION_KEY, $api_key );
		}

		if ( 'custom' === $provider ) {
			update_option( G2AB_OpenRouter_Client::OPTION_ENDPOINT, esc_url_raw( wp_unslash( $_POST['endpoint'] ?? '' ) ) );
			$custom_model = sanitize_text_field( wp_unslash( $_POST['custom_model'] ?? '' ) );
			update_option( G2AB_OpenRouter_Client::OPTION_MODEL, $custom_model );
		} else {
			// Built-in providers: use the dropdown value but verify it actually belongs to this provider.
			$picked = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
			$valid_models = $providers[ $provider ]['models'];
			if ( ! isset( $valid_models[ $picked ] ) ) {
				$picked = (string) array_key_first( $valid_models );
			}
			update_option( G2AB_OpenRouter_Client::OPTION_MODEL, $picked );
		}

		update_option( G2AB_AutoReply_Engine::OPTION_TONE,   sanitize_key( $_POST['tone'] ?? 'professional_friendly' ) );
		update_option( G2AB_AutoReply_Engine::OPTION_AUTO_SEND, ! empty( $_POST['auto_send'] ) ? 1 : 0 );
		update_option( G2AB_AutoReply_Engine::OPTION_SYSTEM_PROMPT, wp_kses_post( wp_unslash( $_POST['system_prompt'] ?? '' ) ) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'ai_autoreply', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function test() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_test_ai', '_g2ab_nonce' );

		$client = new G2AB_OpenRouter_Client();
		$result = $client->test_connection();
		$ok = ! is_wp_error( $result );
		$msg = $ok ? ( 'Connection OK. Response: ' . $result ) : $result->get_error_message();

		wp_safe_redirect( add_query_arg( array(
			'page'        => 'g2ab-settings',
			'tab'         => 'ai_autoreply',
			'test_result' => base64_encode( $msg ),
			'test_ok'     => $ok ? 1 : 0,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function save_kb() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_save_kb', '_g2ab_nonce' );

		$engine = new G2AB_AutoReply_Engine();
		$engine->save_knowledge_base( $_POST['kb'] ?? array() ); // phpcs:ignore

		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'ai_autoreply', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function seed_kb() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_seed_kb', '_g2ab_nonce' );

		$engine = new G2AB_AutoReply_Engine();
		$engine->save_knowledge_base( $engine->default_kb() );

		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'ai_autoreply', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
