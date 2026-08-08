/* Formistic — admin interactions
 * View submission, reply via smooth modal, delete. */
( function () {
	'use strict';

	var cfg = window.Wpistic_Formistic || {};

	function $( sel, ctx ) { return ( ctx || document ).querySelector( sel ); }
	function $all( sel, ctx ) { return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) ); }

	var viewModal  = $( '#wpistic-formistic-modal-view' );
	var replyModal = $( '#wpistic-formistic-modal-reply' );
	var currentId  = 0;

	/* ---------- Modal helpers ---------- */
	function openModal( modal ) {
		if ( ! modal ) { return; }
		modal.hidden = false;
		document.body.style.overflow = 'hidden';
	}
	function closeModal( modal ) {
		if ( ! modal ) { return; }
		modal.hidden = true;
		if ( viewModal.hidden && replyModal.hidden ) {
			document.body.style.overflow = '';
		}
	}
	function closeAll() {
		closeModal( viewModal );
		closeModal( replyModal );
		document.body.style.overflow = '';
	}

	$all( '[data-close]' ).forEach( function ( el ) {
		el.addEventListener( 'click', closeAll );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) { closeAll(); }
	} );

	/* ---------- AJAX ---------- */
	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) { body.append( k, data[ k ] ); } );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } );
	}

	/* Cached submission details for the currently open View modal. */
	var currentDetail = {};

	/* ---------- View ---------- */
	function viewSubmission( id ) {
		currentId = id;
		$( '#wpistic-formistic-view-body' ).innerHTML = '<div class="wpistic-formistic-loading">' + ( cfg.i18n.loading || 'Loading…' ) + '</div>';
		openModal( viewModal );

		post( 'wpistic_formistic_get_submission', { id: id } ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				$( '#wpistic-formistic-view-body' ).innerHTML = '<div class="wpistic-formistic-loading">' + ( ( res && res.data && res.data.message ) || cfg.i18n.error ) + '</div>';
				return;
			}
			var d = res.data;
			currentDetail = d;
			$( '#wpistic-formistic-view-body' ).innerHTML = d.html;
			$( '#wpistic-formistic-view-title' ).textContent = d.form || cfg.i18n.detailsTitle || 'Submission Details';

			var replyBtn = $( '#wpistic-formistic-view-reply' );
			replyBtn.dataset.id       = d.id;
			replyBtn.dataset.email    = d.email || '';
			replyBtn.dataset.subject  = d.subject || '';
			replyBtn.dataset.original = d.original || '';
			replyBtn.dataset.created  = d.createdAt || '';
			replyBtn.dataset.name     = d.name || '';
			replyBtn.disabled = ! d.email;

			// Reflect the new "read" status in the table row.
			markRow( d.id, d.status );
		} ).catch( function () {
			$( '#wpistic-formistic-view-body' ).innerHTML = '<div class="wpistic-formistic-loading">' + cfg.i18n.error + '</div>';
		} );
	}

	/* ---------- Templates ---------- */
	var templatesLoaded = false;
	function loadTemplatesOnce() {
		if ( templatesLoaded ) { return; }
		templatesLoaded = true;
		post( 'wpistic_formistic_list_templates', {} ).then( function ( res ) {
			if ( ! res || ! res.success || ! res.data || ! res.data.templates ) { return; }
			var sel = $( '#wpistic-formistic-reply-template' );
			if ( ! sel ) { return; }
			res.data.templates.forEach( function ( t ) {
				var opt = document.createElement( 'option' );
				opt.value = t.id;
				opt.textContent = t.name;
				opt.dataset.subject = t.subject || '';
				opt.dataset.body    = t.body    || '';
				sel.appendChild( opt );
			} );
		} ).catch( function () {} );
	}

	function applyPlaceholders( str ) {
		var subject = currentDetail.subject || '';
		// Strip leading "Re: " from the cached subject so {subject} reads cleanly.
		subject = subject.replace( /^Re:\s*/i, '' );
		return ( str || '' )
			.replace( /\{name\}/g,      currentDetail.name      || '' )
			.replace( /\{form\}/g,      currentDetail.form      || '' )
			.replace( /\{message\}/g,   currentDetail.original  || '' )
			.replace( /\{subject\}/g,   subject )
			.replace( /\{date\}/g,      currentDetail.createdAt || '' );
	}

	/* ---------- Reply ---------- */
	function openReply( id, email, subject ) {
		if ( ! email ) {
			window.alert( cfg.i18n.noEmail );
			return;
		}
		var form = $( '#wpistic-formistic-reply-form' );
		form.querySelector( '[name=submission_id]' ).value = id;
		form.querySelector( '[name=to]' ).value = email;
		form.querySelector( '[name=subject]' ).value = subject || '';
		form.querySelector( '[name=body]' ).value = '';
		form.querySelector( '[name=cc]' ).value = '';
		form.querySelector( '[name=bcc]' ).value = '';
		$( '#wpistic-formistic-reply-html' ).checked = false;
		// Reset the CC/BCC reveal.
		$( '#wpistic-formistic-reply-extras' ).hidden = true;
		var toggle = $( '#wpistic-formistic-reply-toggle-extras' );
		if ( toggle ) { toggle.textContent = cfg.i18n.showExtras || 'Show CC / BCC'; }
		// Reset the template picker to placeholder.
		var tpl = $( '#wpistic-formistic-reply-template' );
		if ( tpl ) { tpl.value = ''; }

		var status = $( '#wpistic-formistic-reply-status' );
		status.hidden = true;
		status.className = 'wpistic-formistic-reply-status';
		$( '#wpistic-formistic-reply-send' ).disabled = false;

		loadTemplatesOnce();
		openModal( replyModal );
		setTimeout( function () { form.querySelector( '[name=body]' ).focus(); }, 120 );
	}

	/* CC/BCC reveal toggle. */
	document.addEventListener( 'click', function ( e ) {
		var t = e.target.closest( '#wpistic-formistic-reply-toggle-extras' );
		if ( ! t ) { return; }
		var box = $( '#wpistic-formistic-reply-extras' );
		box.hidden = ! box.hidden;
		t.textContent = box.hidden
			? ( cfg.i18n.showExtras || 'Show CC / BCC' )
			: ( cfg.i18n.hideExtras || 'Hide CC / BCC' );
	} );

	/* Quote original button. */
	document.addEventListener( 'click', function ( e ) {
		var t = e.target.closest( '#wpistic-formistic-reply-quote' );
		if ( ! t ) { return; }
		var ta     = $( '#wpistic-formistic-reply-form [name=body]' );
		var header = ( cfg.i18n.quotedHeader || '\n\n— On {date}, {name} wrote: —\n' )
			.replace( /\{date\}/g, currentDetail.createdAt || '' )
			.replace( /\{name\}/g, currentDetail.name || cfg.i18n.statusNew || '' );
		var original = currentDetail.original || '';
		var quoted   = original.split( /\r?\n/ ).map( function ( l ) { return '> ' + l; } ).join( '\n' );
		ta.value = ( ta.value || '' ) + header + quoted + '\n';
		ta.focus();
	} );

	/* Template picker. */
	document.addEventListener( 'change', function ( e ) {
		var t = e.target.closest( '#wpistic-formistic-reply-template' );
		if ( ! t ) { return; }
		var opt = t.options[ t.selectedIndex ];
		if ( ! opt || ! opt.value ) { return; }
		var ta = $( '#wpistic-formistic-reply-form [name=body]' );
		var subjInput = $( '#wpistic-formistic-reply-form [name=subject]' );
		var newBody    = applyPlaceholders( opt.dataset.body || '' );
		var newSubject = applyPlaceholders( opt.dataset.subject || '' );
		ta.value = newBody;
		if ( newSubject ) { subjInput.value = newSubject; }
		// Reset to placeholder so picking the same template again still works.
		t.value = '';
	} );

	function sendReply() {
		var form   = $( '#wpistic-formistic-reply-form' );
		var sendBtn = $( '#wpistic-formistic-reply-send' );
		var status = $( '#wpistic-formistic-reply-status' );
		var id      = form.querySelector( '[name=submission_id]' ).value;
		var subject = form.querySelector( '[name=subject]' ).value.trim();
		var bodyTxt = form.querySelector( '[name=body]' ).value.trim();

		if ( ! subject || ! bodyTxt ) {
			status.hidden = false;
			status.className = 'wpistic-formistic-reply-status wpistic-formistic-reply-status--err';
			status.textContent = cfg.i18n.error;
			return;
		}

		sendBtn.disabled = true;
		sendBtn.textContent = cfg.i18n.sending;
		status.hidden = true;

		var cc      = ( form.querySelector( '[name=cc]' )  || {} ).value || '';
		var bcc     = ( form.querySelector( '[name=bcc]' ) || {} ).value || '';
		var html    = $( '#wpistic-formistic-reply-html' ).checked ? '1' : '';

		post( 'wpistic_formistic_send_reply', { submission_id: id, subject: subject, body: bodyTxt, cc: cc, bcc: bcc, html_mode: html } ).then( function ( res ) {
			status.hidden = false;
			if ( res && res.success ) {
				status.className = 'wpistic-formistic-reply-status wpistic-formistic-reply-status--ok';
				status.textContent = res.data.message || cfg.i18n.sent;
				markRow( id, 'replied' );
				setTimeout( closeAll, 1100 );
			} else {
				status.className = 'wpistic-formistic-reply-status wpistic-formistic-reply-status--err';
				status.textContent = ( res && res.data && res.data.message ) || cfg.i18n.error;
				sendBtn.disabled = false;
			}
			sendBtn.textContent = cfg.i18n.sendReply || 'Send Reply';
		} ).catch( function () {
			status.hidden = false;
			status.className = 'wpistic-formistic-reply-status wpistic-formistic-reply-status--err';
			status.textContent = cfg.i18n.error;
			sendBtn.disabled = false;
			sendBtn.textContent = cfg.i18n.sendReply || 'Send Reply';
		} );
	}

	/* ---------- Delete ---------- */
	function deleteSubmission( id, row ) {
		if ( ! window.confirm( cfg.i18n.confirmDel ) ) { return; }
		post( 'wpistic_formistic_delete', { id: id } ).then( function ( res ) {
			if ( res && res.success && row ) {
				row.style.transition = 'opacity .2s ease';
				row.style.opacity = '0';
				setTimeout( function () { row.remove(); }, 200 );
			} else {
				window.alert( ( res && res.data && res.data.message ) || cfg.i18n.error );
			}
		} );
	}

	/* ---------- Row status reflect ---------- */
	function markRow( id, status ) {
		var row = $( '.wpistic-formistic-row[data-id="' + id + '"]' );
		if ( ! row ) { return; }
		row.classList.remove( 'wpistic-formistic-row--new', 'wpistic-formistic-row--read', 'wpistic-formistic-row--replied' );
		row.classList.add( 'wpistic-formistic-row--' + status );
		var pill = row.querySelector( '.wpistic-formistic-pill' );
		if ( pill ) {
			var labels = {
				replied: cfg.i18n.statusReplied || 'Replied',
				read:    cfg.i18n.statusRead    || 'Viewed',
				new:     cfg.i18n.statusNew     || 'New'
			};
			pill.className = 'wpistic-formistic-pill wpistic-formistic-pill--' + status;
			pill.textContent = labels[ status ] || status;
		}
	}

	/* ---------- Event delegation ---------- */
	document.addEventListener( 'click', function ( e ) {
		var view  = e.target.closest( '.wpistic-formistic-btn--view' );
		var reply = e.target.closest( '.wpistic-formistic-btn--reply' );
		var del   = e.target.closest( '.wpistic-formistic-btn--del' );

		if ( view ) {
			viewSubmission( view.dataset.id );
		} else if ( reply && ! reply.disabled ) {
			var row = reply.closest( '.wpistic-formistic-row' );
			var email = row ? ( row.querySelector( '.wpistic-formistic-from-email' ) || {} ).textContent : '';
			var form  = row ? ( row.querySelector( '.wpistic-formistic-formtag' ) || {} ).textContent : '';
			openReply( reply.dataset.id, ( email || '' ).trim(), 'Re: ' + ( form || '' ).trim() );
		} else if ( del ) {
			deleteSubmission( del.dataset.id, del.closest( '.wpistic-formistic-row' ) );
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var add = e.target.closest( '.wpistic-formistic-note-add' );
		if ( ! add ) { return; }
		var box = add.closest( '.wpistic-formistic-note-form' );
		if ( ! box ) { return; }
		var id = box.getAttribute( 'data-submission' );
		var note = ( box.querySelector( '[name=wpistic_formistic_note_body]' ) || {} ).value || '';
		var tags = ( box.querySelector( '[name=wpistic_formistic_note_tags]' ) || {} ).value || '';
		if ( ! note.trim() ) { return; }
		add.disabled = true;
		post( 'wpistic_formistic_add_note', { submission_id: id, note: note, tags: tags } ).then( function ( res ) {
			add.disabled = false;
			if ( ! res || ! res.success ) { return; }
			currentDetail = currentDetail || {};
			$( '#wpistic-formistic-view-body' ).innerHTML = res.data.html || '';
		} ).catch( function () {
			add.disabled = false;
		} );
	} );

	document.addEventListener( 'click', function ( e ) {
		var replay = e.target.closest( '.wpistic-formistic-replay' );
		if ( ! replay ) { return; }
		var id = replay.getAttribute( 'data-submission' );
		var type = replay.getAttribute( 'data-type' ) || 'both';
		replay.disabled = true;
		post( 'wpistic_formistic_replay_submission', { submission_id: id, replay_type: type } ).then( function () {
			replay.disabled = false;
		} ).catch( function () {
			replay.disabled = false;
		} );
	} );

	// Reply button inside the View modal.
	var viewReplyBtn = $( '#wpistic-formistic-view-reply' );
	if ( viewReplyBtn ) {
		viewReplyBtn.addEventListener( 'click', function () {
			openReply( this.dataset.id, this.dataset.email, this.dataset.subject );
		} );
	}

	var sendBtn = $( '#wpistic-formistic-reply-send' );
	if ( sendBtn ) { sendBtn.addEventListener( 'click', sendReply ); }

	/* ---------- Form builder (CPT edit screen) ---------- */
	var fieldsContainer = $( '#wpistic-formistic-fields-editor-rows' );
	var addFieldBtn     = $( '#wpistic-formistic-fields-editor-add' );
	var fieldsTemplate  = $( '#wpistic-formistic-fields-editor-template' );

	function nextFieldIndex() {
		var rows = $all( '.wpistic-formistic-field-row', fieldsContainer );
		var max = -1;
		rows.forEach( function ( r ) {
			var i = parseInt( r.dataset.index, 10 );
			if ( ! isNaN( i ) && i > max ) { max = i; }
		} );
		return max + 1;
	}

	if ( addFieldBtn && fieldsContainer && fieldsTemplate ) {
		addFieldBtn.addEventListener( 'click', function () {
			var idx = nextFieldIndex();
			var html = fieldsTemplate.innerHTML.replace( /__INDEX__/g, String( idx ) );
			var wrap = document.createElement( 'div' );
			wrap.innerHTML = html.trim();
			var node = wrap.firstChild;
			fieldsContainer.appendChild( node );
			var firstInput = node.querySelector( 'input[type=text]' );
			if ( firstInput ) { firstInput.focus(); }
		} );

		fieldsContainer.addEventListener( 'click', function ( e ) {
			var rm = e.target.closest( '.wpistic-formistic-field-row__remove' );
			if ( ! rm ) { return; }
			var row = rm.closest( '.wpistic-formistic-field-row' );
			if ( row && window.confirm( 'Remove this field?' ) ) {
				row.parentNode.removeChild( row );
			}
		} );
	}

	/* ---------- Form builder: drag reorder + live preview + live style ---------- */
	var builder = $( '#wpistic-formistic-builder' );
	if ( builder && fieldsContainer ) {
		var previewEl = $( '#wpistic-formistic-preview' );

		function esc( s ) {
			return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
			} );
		}

		function styleVal( name, fallback ) {
			var el = document.querySelector( '[name="wpistic_formistic_settings[' + name + ']"]' );
			return el && el.value !== '' ? el.value : fallback;
		}

		function readFields() {
			return $all( '.wpistic-formistic-field-row', fieldsContainer ).map( function ( row ) {
				var i = row.dataset.index;
				function v( part ) {
					var el = row.querySelector( '[name="wpistic_formistic_fields[' + i + '][' + part + ']"]' );
					return el ? el.value : '';
				}
				var reqEl = row.querySelector( 'input[type=checkbox][name="wpistic_formistic_fields[' + i + '][required]"]' );
				return {
					label: v( 'label' ), type: v( 'type' ), placeholder: v( 'placeholder' ),
					options: v( 'options' ), required: reqEl ? reqEl.checked : false
				};
			} );
		}

		function previewField( f ) {
			var star = f.required ? ' <span style="color:#dc2626">*</span>' : '';
			var label = '<span class="wpf-pv-label">' + esc( f.label || 'Untitled' ) + star + '</span>';
			var ph = esc( f.placeholder );
			var opts = ( f.options || '' ).split( /\r?\n/ ).map( function ( o ) { return o.trim(); } ).filter( Boolean );
			var inner;
			switch ( f.type ) {
				case 'textarea': inner = '<textarea rows="3" placeholder="' + ph + '"></textarea>'; break;
				case 'select':
					inner = '<select><option>' + ( opts.length ? opts.map( esc ).join( '</option><option>' ) : '—' ) + '</option></select>'; break;
				case 'radio':
				case 'checkbox_group':
					var t = f.type === 'radio' ? 'radio' : 'checkbox';
					inner = '<div class="wpf-pv-opts">' + ( opts.length ? opts : [ 'Option' ] ).map( function ( o ) {
						return '<label><input type="' + t + '" disabled> ' + esc( o ) + '</label>';
					} ).join( '' ) + '</div>'; break;
				case 'checkbox':
				case 'consent':
					return '<div class="wpf-pv-field"><label class="wpf-pv-consent"><input type="checkbox" disabled> ' + esc( f.label || 'I agree' ) + star + '</label></div>';
				case 'file': inner = '<input type="file" disabled>'; break;
				case 'date': inner = '<input type="date" disabled>'; break;
				case 'hidden': return '';
				default: inner = '<input type="' + ( [ 'email', 'tel', 'url' ].indexOf( f.type ) > -1 ? f.type : 'text' ) + '" placeholder="' + ph + '">';
			}
			return '<div class="wpf-pv-field">' + label + inner + '</div>';
		}

		function renderPreview() {
			if ( ! previewEl ) { return; }
			var fields = readFields();
			var accent = styleVal( 'accent', '#2563eb' );
			var btnText = styleVal( 'button_text', '#ffffff' );
			var radius = styleVal( 'radius', '10' );
			var gap = styleVal( 'spacing', '16' );
			var width = styleVal( 'width', '640' );
			var layout = styleVal( 'layout', 'one' );
			var submitEl = document.querySelector( '[name="wpistic_formistic_settings[submit_label]"]' );
			var submit = submitEl && submitEl.value ? submitEl.value : 'Send Message';
			var titleEl = document.querySelector( '#title' );
			var title = titleEl && titleEl.value ? titleEl.value : 'Your Form';

			var css = '--wpf-accent:' + accent + ';--wpf-btn-text:' + btnText + ';--wpf-radius:' + parseInt( radius, 10 ) +
				'px;--wpf-gap:' + parseInt( gap, 10 ) + 'px;--wpf-width:' + parseInt( width, 10 ) + 'px;';
			var html = '<div class="wpf-pv-form wpf-pv-form--' + ( layout === 'two' ? 'two' : 'one' ) + '" style="' + css + '">' +
				'<h3 class="wpf-pv-title">' + esc( title ) + '</h3>' +
				fields.map( previewField ).join( '' ) +
				'<button type="button" class="wpf-pv-submit">' + esc( submit ) + '</button>' +
				'</div>';
			previewEl.innerHTML = html;
		}

		// Re-render on any edit in the builder or style/settings inputs.
		document.addEventListener( 'input', function ( e ) {
			if ( e.target.closest( '#wpistic-formistic-builder' ) ||
				( e.target.name && e.target.name.indexOf( 'wpistic_formistic_settings' ) === 0 ) ||
				e.target.id === 'title' ) {
				renderPreview();
			}
		} );
		document.addEventListener( 'change', function ( e ) {
			if ( e.target.closest( '#wpistic-formistic-builder' ) ||
				( e.target.name && e.target.name.indexOf( 'wpistic_formistic_settings' ) === 0 ) ) {
				renderPreview();
			}
		} );

		// Drag-to-reorder via the handle.
		var dragRow = null;
		fieldsContainer.addEventListener( 'mousedown', function ( e ) {
			var handle = e.target.closest( '.wpistic-formistic-field-row__drag' );
			if ( handle ) {
				var row = handle.closest( '.wpistic-formistic-field-row' );
				if ( row ) { row.setAttribute( 'draggable', 'true' ); }
			}
		} );
		fieldsContainer.addEventListener( 'dragstart', function ( e ) {
			dragRow = e.target.closest( '.wpistic-formistic-field-row' );
			if ( dragRow ) { dragRow.classList.add( 'is-dragging' ); }
		} );
		fieldsContainer.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			var over = e.target.closest( '.wpistic-formistic-field-row' );
			if ( ! over || ! dragRow || over === dragRow ) { return; }
			var rect = over.getBoundingClientRect();
			var after = ( e.clientY - rect.top ) > rect.height / 2;
			fieldsContainer.insertBefore( dragRow, after ? over.nextSibling : over );
		} );
		fieldsContainer.addEventListener( 'drop', function ( e ) { e.preventDefault(); } );
		fieldsContainer.addEventListener( 'dragend', function () {
			if ( dragRow ) {
				dragRow.classList.remove( 'is-dragging' );
				dragRow.setAttribute( 'draggable', 'false' );
			}
			dragRow = null;
			renderPreview();
		} );

		// Re-render after add/remove field.
		if ( addFieldBtn ) { addFieldBtn.addEventListener( 'click', function () { setTimeout( renderPreview, 0 ); } ); }
		fieldsContainer.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '.wpistic-formistic-field-row__remove' ) ) { setTimeout( renderPreview, 0 ); }
		} );

		renderPreview();
	}

	/* ---------- Addons screen: instant toggle ---------- */
	$all( '.wpistic-formistic-addon-toggle' ).forEach( function ( toggle ) {
		toggle.addEventListener( 'change', function () {
			var slug = toggle.dataset.slug;
			var card = toggle.closest( '.wpistic-formistic-addon-card' );
			var active = toggle.checked;
			toggle.disabled = true;
			post( 'wpistic_formistic_toggle_addon', { slug: slug, active: active ? '1' : '0' } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) { throw new Error(); }
					if ( card ) {
						card.classList.toggle( 'is-active', active );
						var status = card.querySelector( '.wpistic-formistic-addon-card__status' );
						if ( status ) { status.textContent = active ? 'Active' : 'Inactive'; }
					}
				} )
				.catch( function () { toggle.checked = ! active; } )
				.finally( function () { toggle.disabled = false; } );
		} );
	} );

	/* ---------- Bulk actions ---------- */
	var bulkForm = $( '#wpistic-formistic-bulk-form' );
	if ( bulkForm ) {
		var checkAll = $( '#wpistic-formistic-check-all' );
		if ( checkAll ) {
			checkAll.addEventListener( 'change', function () {
				$all( '.wpistic-formistic-check-row', bulkForm ).forEach( function ( cb ) { cb.checked = checkAll.checked; } );
			} );
		}
		bulkForm.addEventListener( 'submit', function ( e ) {
			var action = ( bulkForm.querySelector( '[name=bulk_action]' ) || {} ).value || '';
			var selected = $all( '.wpistic-formistic-check-row', bulkForm ).filter( function ( cb ) { return cb.checked; } );
			if ( ! action ) {
				e.preventDefault();
				window.alert( cfg.i18n.noBulkAction || 'Pick a bulk action.' );
				return;
			}
			if ( ! selected.length ) {
				e.preventDefault();
				window.alert( cfg.i18n.noBulkSelection || 'Select at least one row.' );
				return;
			}
			if ( action === 'delete' && ! window.confirm( cfg.i18n.confirmBulkDel || cfg.i18n.confirmDel ) ) {
				e.preventDefault();
			}
		} );
	}
}() );
