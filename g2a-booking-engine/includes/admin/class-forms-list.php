<?php
/**
 * Forms admin — list view + drag-drop form builder.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Admin_Forms_List {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-forms';

	const FIELD_TYPES = array(
		'text'      => array( 'label' => 'Text',      'icon' => 'T' ),
		'email'     => array( 'label' => 'Email',     'icon' => '@' ),
		'phone'     => array( 'label' => 'Phone',     'icon' => '☎' ),
		'number'    => array( 'label' => 'Number',    'icon' => '#' ),
		'date'      => array( 'label' => 'Date',      'icon' => '📅' ),
		'time'      => array( 'label' => 'Time',      'icon' => '⏱' ),
		'dropdown'  => array( 'label' => 'Dropdown',  'icon' => '▼' ),
		'radio'     => array( 'label' => 'Radio',     'icon' => '◉' ),
		'checkbox'  => array( 'label' => 'Checkbox',  'icon' => '☑' ),
		'textarea'  => array( 'label' => 'Textarea',  'icon' => '¶' ),
		'waiver'    => array( 'label' => 'Waiver',    'icon' => '⚠' ),
		'terms'     => array( 'label' => 'Terms',     'icon' => '§' ),
		'hidden'    => array( 'label' => 'Hidden',    'icon' => '◌' ),
		'heading'   => array( 'label' => 'Heading',   'icon' => 'H' ),
		'divider'   => array( 'label' => 'Divider',   'icon' => '—' ),
		'file'      => array( 'label' => 'File',      'icon' => '📎' ),
		'signature' => array( 'label' => 'Signature', 'icon' => '✎' ),
	);

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'override_menu' ), 11 );
	}

	public function override_menu() {
		global $submenu;
		$idx = -1;
		if ( isset( $submenu['g2ab-bookings'] ) ) {
			foreach ( $submenu['g2ab-bookings'] as $i => $entry ) {
				if ( $entry[2] === self::PAGE_SLUG ) { $idx = $i; break; }
			}
		}
		remove_submenu_page( 'g2ab-bookings', self::PAGE_SLUG );
		add_submenu_page( 'g2ab-bookings', __( 'Forms', 'g2a-booking' ), __( 'Forms', 'g2a-booking' ), 'manage_g2ab_forms', self::PAGE_SLUG, array( $this, 'render' ) );
		if ( $idx >= 0 && isset( $submenu['g2ab-bookings'] ) ) {
			$new = array_pop( $submenu['g2ab-bookings'] );
			array_splice( $submenu['g2ab-bookings'], $idx, 0, array( $new ) );
		}
	}

	public function render() {
		if ( ! current_user_can( 'manage_g2ab_forms' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
		$this->print_styles();
		if ( 'edit' === $action || 'new' === $action ) $this->render_builder();
		else $this->render_list();
	}

	private function render_list() {
		global $wpdb;
		$ft = $wpdb->prefix . 'g2ab_forms';
		$ff = $wpdb->prefix . 'g2ab_form_fields';
		$btt = $wpdb->prefix . 'g2ab_booking_types';
		$forms = $wpdb->get_results( "SELECT f.*, (SELECT COUNT(*) FROM {$ff} WHERE form_id = f.id) AS field_count, (SELECT COUNT(*) FROM {$btt} WHERE form_id = f.id) AS type_count FROM {$ft} f ORDER BY f.id ASC" );
		?>
		<div class="wrap g2ab-admin g2ab-fb">
			<div class="g2ab-fb__header">
				<div>
					<h1><span class="g2ab-fb__stencil"><?php esc_html_e( 'FORMS', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-fb__sub"><?php esc_html_e( 'Drag-and-drop form builder · 17 field types · conditional logic ready', 'g2a-booking' ); ?></p>
				</div>
				<a class="g2ab-fb__btn g2ab-fb__btn--primary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>"><span>+</span> <?php esc_html_e( 'NEW FORM', 'g2a-booking' ); ?></a>
			</div>
			<?php if ( empty( $forms ) ) : ?>
				<div class="g2ab-fb__empty"><h2>No forms yet</h2><p>Create your first booking form.</p></div>
			<?php else : ?>
				<div class="g2ab-fb__grid">
					<?php foreach ( $forms as $f ) : ?>
						<article class="g2ab-fb__card <?php echo $f->is_active ? '' : 'is-inactive'; ?>">
							<header><h3><?php echo esc_html( $f->name ); ?></h3>
								<?php if ( ! $f->is_active ) : ?><span class="g2ab-fb__pill g2ab-fb__pill--off">INACTIVE</span><?php endif; ?>
							</header>
							<div class="g2ab-fb__meta">
								<span><strong><?php echo (int) $f->field_count; ?></strong> fields</span>
								<span>used by <strong><?php echo (int) $f->type_count; ?></strong> type(s)</span>
								<span>v<?php echo (int) $f->schema_version; ?></span>
							</div>
							<div class="g2ab-fb__slug"><code><?php echo esc_html( $f->slug ); ?></code></div>
							<?php if ( $f->description ) : ?><p class="g2ab-fb__desc"><?php echo esc_html( $f->description ); ?></p><?php endif; ?>
							<div class="g2ab-fb__actions">
								<a class="g2ab-fb__btn g2ab-fb__btn--primary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'edit', 'id' => $f->id ), admin_url( 'admin.php' ) ) ); ?>">EDIT BUILDER →</a>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_builder() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$is_new = ! $id;

		$form = (object) array( 'id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'is_active' => 1, 'settings' => array() );
		$fields = array();
		if ( ! $is_new ) {
			global $wpdb;
			$ft = $wpdb->prefix . 'g2ab_forms';
			$ff = $wpdb->prefix . 'g2ab_form_fields';
			$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ft} WHERE id = %d", $id ) );
			if ( ! $form ) { echo '<div class="wrap"><h1>Form not found</h1></div>'; return; }
			$form->settings = json_decode( (string) $form->settings, true ) ?: array();
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ff} WHERE form_id = %d ORDER BY sort_order ASC", $id ) );
			foreach ( $rows as $r ) {
				$fields[] = array(
					'field_key' => $r->field_key,
					'field_type' => $r->field_type,
					'label' => $r->label,
					'placeholder' => $r->placeholder,
					'help_text' => $r->help_text,
					'options' => json_decode( (string) $r->options, true ) ?: array(),
					'validation' => json_decode( (string) $r->validation, true ) ?: array(),
					'conditional' => json_decode( (string) $r->conditional, true ) ?: array(),
					'is_required' => (int) $r->is_required,
				);
			}
		}

		$rest_url = esc_url_raw( rest_url( G2AB_REST_NAMESPACE . '/' ) );
		$nonce = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap g2ab-admin g2ab-fb">
			<div class="g2ab-fb__header">
				<div>
					<h1><span class="g2ab-fb__stencil"><?php echo $is_new ? 'NEW FORM' : 'FORM BUILDER'; ?></span></h1>
					<p class="g2ab-fb__sub"><?php echo $is_new ? 'Build a new booking form' : 'Drag fields to reorder · click to edit · live preview'; ?></p>
				</div>
				<a class="g2ab-fb__btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">← BACK</a>
			</div>

			<div id="g2ab-builder-app"
				data-form-id="<?php echo (int) ( $form->id ?? 0 ); ?>"
				data-rest="<?php echo esc_attr( $rest_url ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
				data-form='<?php echo esc_attr( wp_json_encode( $form ) ); ?>'
				data-fields='<?php echo esc_attr( wp_json_encode( $fields ) ); ?>'
				data-types='<?php echo esc_attr( wp_json_encode( self::FIELD_TYPES ) ); ?>'>
				<div class="g2ab-fb__loading">Loading builder…</div>
			</div>
		</div>
		<?php
		$this->print_builder_js();
	}

	private function print_builder_js() {
		?>
		<script>
		(function(){
			const app = document.getElementById('g2ab-builder-app');
			if (!app) return;
			const REST = app.dataset.rest;
			const NONCE = app.dataset.nonce;
			const TYPES = JSON.parse(app.dataset.types);
			let formId = parseInt(app.dataset.formId, 10) || 0;
			let form = JSON.parse(app.dataset.form);
			let fields = JSON.parse(app.dataset.fields);
			let editing = -1;

			function h(s){const d=document.createElement('div');d.textContent=String(s);return d.innerHTML;}

			function render() {
				const palette = Object.entries(TYPES).map(([k,v])=>`
					<button type="button" class="g2ab-fb__palette-btn" data-add="${h(k)}">
						<span class="g2ab-fb__palette-icon">${v.icon}</span>${h(v.label)}
					</button>`).join('');

				const fieldRows = fields.map((f,i)=>{
					const t = TYPES[f.field_type] || {label:f.field_type,icon:'?'};
					return `
					<div class="g2ab-fb__field-row" draggable="true" data-idx="${i}">
						<span class="g2ab-fb__handle">⠿</span>
						<span class="g2ab-fb__field-icon">${t.icon}</span>
						<div class="g2ab-fb__field-info">
							<strong>${h(f.label||'(no label)')}</strong>
							<small>${h(f.field_key)} · ${h(t.label)}${f.is_required?' · REQUIRED':''}</small>
						</div>
						<button type="button" class="g2ab-fb__icon-btn" data-edit="${i}">EDIT</button>
						<button type="button" class="g2ab-fb__icon-btn g2ab-fb__icon-btn--del" data-del="${i}">×</button>
					</div>`;
				}).join('') || '<p class="g2ab-fb__hint">No fields yet — click a type on the right to add.</p>';

				app.innerHTML = `
				<div class="g2ab-fb__editor">
					<div class="g2ab-fb__main">
						<div class="g2ab-fb__panel">
							<h3>FORM SETTINGS</h3>
							<div class="g2ab-fb__field"><label>Name *</label><input type="text" id="fb-name" value="${h(form.name||'')}" /></div>
							<div class="g2ab-fb__field"><label>Slug</label><input type="text" id="fb-slug" value="${h(form.slug||'')}" placeholder="auto from name" /></div>
							<div class="g2ab-fb__field"><label>Description</label><textarea id="fb-desc" rows="2">${h(form.description||'')}</textarea></div>
							<div class="g2ab-fb__field"><label>Submit Label</label><input type="text" id="fb-submit-label" value="${h((form.settings&&form.settings.submit_label)||'Reserve My Spot')}" /></div>
							<div class="g2ab-fb__field"><label class="g2ab-fb__check"><input type="checkbox" id="fb-active" ${form.is_active?'checked':''} /> Active</label></div>
						</div>
						<div class="g2ab-fb__panel">
							<h3>FIELDS <small>(drag to reorder · click EDIT to configure)</small></h3>
							<div id="fb-fields">${fieldRows}</div>
						</div>
						${editing >= 0 ? renderEditor() : ''}
						<div class="g2ab-fb__actions">
							<button type="button" class="g2ab-fb__btn g2ab-fb__btn--primary" id="fb-save">SAVE FORM</button>
							<span id="fb-msg"></span>
						</div>
					</div>
					<aside class="g2ab-fb__palette">
						<h3>FIELD TYPES</h3>
						<div class="g2ab-fb__palette-grid">${palette}</div>
					</aside>
				</div>
				`;

				bind();
			}

			function renderEditor() {
				const f = fields[editing];
				if (!f) return '';
				const isOpts = ['dropdown','radio','checkbox'].includes(f.field_type);
				let optsHtml = '';
				if (isOpts) {
					const items = (f.options||[]).map((o,i)=>`
						<div class="g2ab-fb__opt-row" data-opt="${i}">
							<input type="text" data-opt-val placeholder="value" value="${h(o.value||'')}" />
							<input type="text" data-opt-lbl placeholder="label" value="${h(o.label||'')}" />
							<button type="button" data-opt-del="${i}">×</button>
						</div>`).join('');
					optsHtml = `<div class="g2ab-fb__field"><label>Options</label><div id="fb-opts">${items}</div><button type="button" class="g2ab-fb__btn" id="fb-add-opt">+ ADD OPTION</button></div>`;
				}
				return `
				<div class="g2ab-fb__panel g2ab-fb__panel--editor">
					<h3>EDIT FIELD: ${h(f.label||'(no label)')}</h3>
					<div class="g2ab-fb__field"><label>Field Key *</label><input type="text" id="fb-fkey" value="${h(f.field_key||'')}" /><small>Lowercase, alphanumeric + underscores. Used in form_data.</small></div>
					<div class="g2ab-fb__field"><label>Type</label><select id="fb-ftype">${Object.entries(TYPES).map(([k,v])=>`<option value="${h(k)}" ${k===f.field_type?'selected':''}>${h(v.label)}</option>`).join('')}</select></div>
					<div class="g2ab-fb__field"><label>Label</label><input type="text" id="fb-flabel" value="${h(f.label||'')}" /></div>
					<div class="g2ab-fb__field"><label>Placeholder</label><input type="text" id="fb-fph" value="${h(f.placeholder||'')}" /></div>
					<div class="g2ab-fb__field"><label>Help Text</label><textarea id="fb-fhelp" rows="2">${h(f.help_text||'')}</textarea></div>
					${optsHtml}
					<div class="g2ab-fb__field"><label class="g2ab-fb__check"><input type="checkbox" id="fb-freq" ${f.is_required?'checked':''} /> Required</label></div>
					<div class="g2ab-fb__field"><label>Conditional Logic <small>(optional)</small></label>
						<div class="g2ab-fb__cond-row">Show if <input type="text" id="fb-cond-key" placeholder="field_key" value="${h(f.conditional&&f.conditional.field||'')}" />
						<select id="fb-cond-op">${['=','!=','>','<','contains'].map(o=>`<option ${f.conditional&&f.conditional.op===o?'selected':''}>${o}</option>`).join('')}</select>
						<input type="text" id="fb-cond-val" placeholder="value" value="${h(f.conditional&&f.conditional.value||'')}" /></div>
					</div>
					<div class="g2ab-fb__editor-actions">
						<button type="button" class="g2ab-fb__btn g2ab-fb__btn--primary" id="fb-save-field">APPLY FIELD</button>
						<button type="button" class="g2ab-fb__btn" id="fb-cancel-field">CANCEL</button>
					</div>
				</div>`;
			}

			function bind() {
				// Palette: add field
				app.querySelectorAll('[data-add]').forEach(b => b.addEventListener('click', () => {
					const type = b.dataset.add;
					fields.push({
						field_key: type + '_' + (fields.length+1),
						field_type: type,
						label: TYPES[type].label,
						placeholder: '',
						help_text: '',
						options: ['dropdown','radio','checkbox'].includes(type) ? [{value:'option_1',label:'Option 1'}] : [],
						validation: {},
						conditional: {},
						is_required: 0,
					});
					editing = fields.length - 1;
					render();
				}));
				// Edit / delete
				app.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => { editing = parseInt(b.dataset.edit,10); render(); }));
				app.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', () => {
					if (!confirm('Delete this field?')) return;
					fields.splice(parseInt(b.dataset.del,10), 1);
					if (editing >= fields.length) editing = -1;
					render();
				}));
				// Drag-drop reorder
				let dragIdx = -1;
				app.querySelectorAll('.g2ab-fb__field-row').forEach(row => {
					row.addEventListener('dragstart', e => { dragIdx = parseInt(row.dataset.idx,10); row.style.opacity='0.4'; });
					row.addEventListener('dragend', e => { row.style.opacity='1'; });
					row.addEventListener('dragover', e => { e.preventDefault(); row.style.borderTop='2px solid #D2691E'; });
					row.addEventListener('dragleave', e => { row.style.borderTop=''; });
					row.addEventListener('drop', e => {
						e.preventDefault();
						const targetIdx = parseInt(row.dataset.idx,10);
						if (dragIdx < 0 || dragIdx === targetIdx) return;
						const [moved] = fields.splice(dragIdx, 1);
						fields.splice(targetIdx, 0, moved);
						if (editing === dragIdx) editing = targetIdx;
						render();
					});
				});
				// Field editor
				const sf = document.getElementById('fb-save-field');
				if (sf) sf.addEventListener('click', () => {
					const f = fields[editing]; if (!f) return;
					f.field_key = (document.getElementById('fb-fkey').value || '').trim().replace(/[^a-z0-9_]/gi,'_').toLowerCase();
					f.field_type = document.getElementById('fb-ftype').value;
					f.label = document.getElementById('fb-flabel').value;
					f.placeholder = document.getElementById('fb-fph').value;
					f.help_text = document.getElementById('fb-fhelp').value;
					f.is_required = document.getElementById('fb-freq').checked ? 1 : 0;
					const condKey = (document.getElementById('fb-cond-key')||{}).value || '';
					if (condKey) {
						f.conditional = { field: condKey, op: document.getElementById('fb-cond-op').value, value: document.getElementById('fb-cond-val').value };
					} else { f.conditional = {}; }
					// Options
					const optEls = app.querySelectorAll('.g2ab-fb__opt-row');
					if (optEls.length) {
						f.options = Array.from(optEls).map(r => ({
							value: r.querySelector('[data-opt-val]').value,
							label: r.querySelector('[data-opt-lbl]').value,
						})).filter(o => o.value || o.label);
					}
					editing = -1;
					render();
				});
				const cf = document.getElementById('fb-cancel-field');
				if (cf) cf.addEventListener('click', () => { editing = -1; render(); });
				const addOpt = document.getElementById('fb-add-opt');
				if (addOpt) addOpt.addEventListener('click', () => {
					const f = fields[editing];
					f.options = f.options || [];
					f.options.push({ value: 'option_' + (f.options.length+1), label: 'Option ' + (f.options.length+1) });
					render();
				});
				app.querySelectorAll('[data-opt-del]').forEach(b => b.addEventListener('click', () => {
					const f = fields[editing];
					f.options.splice(parseInt(b.dataset.optDel,10), 1);
					render();
				}));
				// Save form
				const sb = document.getElementById('fb-save');
				if (sb) sb.addEventListener('click', save);
			}

			function save() {
				const msg = document.getElementById('fb-msg');
				msg.textContent = 'Saving…';
				msg.style.color = '#8A95A5';
				const payload = {
					name: document.getElementById('fb-name').value,
					slug: document.getElementById('fb-slug').value,
					description: document.getElementById('fb-desc').value,
					is_active: document.getElementById('fb-active').checked ? 1 : 0,
					settings: { submit_label: document.getElementById('fb-submit-label').value },
					fields: fields,
				};
				const url = REST + 'forms' + (formId ? '/' + formId : '');
				fetch(url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
					body: JSON.stringify(payload),
				}).then(r => r.json()).then(json => {
					if (json && json.success) {
						msg.textContent = '✓ Saved';
						msg.style.color = '#4CAF50';
						if (!formId && json.data && json.data.id) {
							formId = json.data.id;
							const url = new URL(window.location.href);
							url.searchParams.set('action', 'edit');
							url.searchParams.set('id', formId);
							window.history.replaceState({}, '', url.toString());
						}
					} else {
						msg.textContent = '✗ ' + (json.message || 'Save failed');
						msg.style.color = '#C62828';
					}
				}).catch(() => { msg.textContent = '✗ Network error'; msg.style.color = '#C62828'; });
			}

			render();
		})();
		</script>
		<?php
	}

	private function print_styles() {
		echo '<style>
.g2ab-fb{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
.g2ab-fb__header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:20px;background:linear-gradient(135deg,#0F1115 0%,#1A1F26 100%);color:#E8E8E8;padding:24px 28px;margin:20px 0 16px;border-left:4px solid #D2691E;}
.g2ab-fb__stencil{font-family:"Rajdhani","Oswald",Impact,sans-serif;font-size:30px;font-weight:700;letter-spacing:.12em;color:#fff;text-shadow:2px 2px 0 #4A5D3A;}
.g2ab-fb__sub{margin:4px 0 0;color:#8A95A5;font-size:13px;text-transform:uppercase;letter-spacing:.06em;}
.g2ab-fb__btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;background:#fff;color:#0F1115;border:1px solid #d0d4d9;text-decoration:none;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border-radius:2px;cursor:pointer;}
.g2ab-fb__btn:hover{background:#f8f9fa;color:#D2691E;}
.g2ab-fb__btn--primary{background:#D2691E;color:#fff;border-color:#D2691E;}
.g2ab-fb__btn--primary:hover{background:#0F1115;color:#fff;}
.g2ab-fb__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:14px;}
.g2ab-fb__card{background:#fff;border:1px solid #d0d4d9;padding:20px;border-top:4px solid #4A5D3A;}
.g2ab-fb__card.is-inactive{opacity:.6;}
.g2ab-fb__card header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.g2ab-fb__card h3{margin:0;font-size:17px;color:#0F1115;}
.g2ab-fb__pill{display:inline-block;padding:2px 8px;font-size:9px;letter-spacing:.06em;font-weight:700;border-radius:2px;background:#0F1115;color:#fff;}
.g2ab-fb__pill--off{background:#C62828;}
.g2ab-fb__meta{display:flex;gap:14px;font-size:12px;color:#8A95A5;margin-bottom:8px;}
.g2ab-fb__meta strong{color:#0F1115;}
.g2ab-fb__slug code{background:#f0f1f3;padding:1px 6px;font-size:11px;color:#3c434a;}
.g2ab-fb__desc{color:#3c434a;font-size:13px;margin:8px 0 12px;}
.g2ab-fb__actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.g2ab-fb__empty{background:#fff;padding:60px 20px;text-align:center;border:1px dashed #d0d4d9;}
.g2ab-fb__editor{display:grid;grid-template-columns:1fr 240px;gap:14px;}
@media (max-width:1000px){.g2ab-fb__editor{grid-template-columns:1fr;}}
.g2ab-fb__panel{background:#fff;border:1px solid #d0d4d9;padding:20px 24px;margin-bottom:14px;}
.g2ab-fb__panel h3{font-family:"Rajdhani",sans-serif;font-size:13px;letter-spacing:.12em;color:#D2691E;margin:0 0 14px;font-weight:700;}
.g2ab-fb__panel h3 small{color:#8A95A5;font-weight:400;letter-spacing:0;text-transform:none;font-size:11px;margin-left:8px;}
.g2ab-fb__panel--editor{background:#fffbe6;border-left:4px solid #D2691E;}
.g2ab-fb__field{margin-bottom:12px;}
.g2ab-fb__field label{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;color:#3c434a;text-transform:uppercase;margin-bottom:5px;}
.g2ab-fb__field input[type="text"],.g2ab-fb__field input[type="number"],.g2ab-fb__field select,.g2ab-fb__field textarea{width:100%;padding:8px 12px;border:1px solid #d0d4d9;border-radius:2px;font-size:13px;font-family:inherit;}
.g2ab-fb__field input:focus,.g2ab-fb__field select:focus,.g2ab-fb__field textarea:focus{outline:none;border-color:#D2691E;box-shadow:0 0 0 2px rgba(210,105,30,.15);}
.g2ab-fb__field small{display:block;color:#8A95A5;font-size:11px;margin-top:3px;}
.g2ab-fb__check{display:inline-flex;align-items:center;gap:6px;text-transform:none;letter-spacing:0;font-weight:400;font-size:13px;}
.g2ab-fb__field-row{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f8f9fa;border:1px solid #f0f1f3;margin-bottom:6px;cursor:move;}
.g2ab-fb__field-row:hover{background:#fff;border-color:#D2691E;}
.g2ab-fb__handle{color:#8A95A5;font-size:18px;cursor:grab;}
.g2ab-fb__field-icon{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;background:#0F1115;color:#D2691E;border-radius:3px;font-weight:700;}
.g2ab-fb__field-info{flex:1;}
.g2ab-fb__field-info strong{display:block;font-size:14px;color:#0F1115;}
.g2ab-fb__field-info small{color:#8A95A5;font-size:11px;}
.g2ab-fb__icon-btn{padding:5px 10px;background:#fff;border:1px solid #d0d4d9;cursor:pointer;font-size:10px;font-weight:700;letter-spacing:.06em;border-radius:2px;}
.g2ab-fb__icon-btn:hover{background:#D2691E;color:#fff;border-color:#D2691E;}
.g2ab-fb__icon-btn--del:hover{background:#C62828;border-color:#C62828;}
.g2ab-fb__palette{position:sticky;top:40px;align-self:start;}
.g2ab-fb__palette-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;}
.g2ab-fb__palette-btn{display:flex;align-items:center;gap:6px;padding:10px 8px;background:#fff;border:1px solid #d0d4d9;cursor:pointer;font-size:11px;font-weight:600;color:#3c434a;text-align:left;}
.g2ab-fb__palette-btn:hover{background:#D2691E;color:#fff;border-color:#D2691E;}
.g2ab-fb__palette-icon{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;background:#f0f1f3;border-radius:3px;font-weight:700;color:#0F1115;}
.g2ab-fb__palette-btn:hover .g2ab-fb__palette-icon{background:rgba(255,255,255,.2);color:#fff;}
.g2ab-fb__opt-row{display:flex;gap:6px;margin-bottom:5px;}
.g2ab-fb__opt-row input{flex:1;padding:6px 10px;border:1px solid #d0d4d9;font-size:12px;}
.g2ab-fb__opt-row button{background:#fff;border:1px solid #d0d4d9;padding:0 10px;cursor:pointer;}
.g2ab-fb__cond-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:12px;color:#3c434a;}
.g2ab-fb__cond-row input,.g2ab-fb__cond-row select{padding:5px 8px;border:1px solid #d0d4d9;font-size:12px;width:auto;}
.g2ab-fb__editor-actions{display:flex;gap:8px;margin-top:14px;}
.g2ab-fb__hint{padding:30px;text-align:center;color:#8A95A5;background:#f8f9fa;border:1px dashed #d0d4d9;}
.g2ab-fb__loading{padding:40px;text-align:center;color:#8A95A5;}
</style>';
	}
}
