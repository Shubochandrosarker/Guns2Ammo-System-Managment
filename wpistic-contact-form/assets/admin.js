/* WPistic Contact Form — admin interactions
 * View submission, reply via smooth modal, delete. */
( function () {
	'use strict';

	var cfg = window.WPISTIC_CF || {};

	function $( sel, ctx ) { return ( ctx || document ).querySelector( sel ); }
	function $all( sel, ctx ) { return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) ); }

	var viewModal  = $( '#WPISTIC_CF-modal-view' );
	var replyModal = $( '#WPISTIC_CF-modal-reply' );
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
		$( '#WPISTIC_CF-view-body' ).innerHTML = '<div class="WPISTIC_CF-loading">' + ( cfg.i18n.loading || 'Loading…' ) + '</div>';
		openModal( viewModal );

		post( 'WPISTIC_CF_get_submission', { id: id } ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				$( '#WPISTIC_CF-view-body' ).innerHTML = '<div class="WPISTIC_CF-loading">' + ( ( res && res.data && res.data.message ) || cfg.i18n.error ) + '</div>';
				return;
			}
			var d = res.data;
			currentDetail = d;
			$( '#WPISTIC_CF-view-body' ).innerHTML = d.html;
			$( '#WPISTIC_CF-view-title' ).textContent = d.form || cfg.i18n.detailsTitle || 'Submission Details';

			var replyBtn = $( '#WPISTIC_CF-view-reply' );
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
			$( '#WPISTIC_CF-view-body' ).innerHTML = '<div class="WPISTIC_CF-loading">' + cfg.i18n.error + '</div>';
		} );
	}

	/* ---------- Templates ---------- */
	var templatesLoaded = false;
	function loadTemplatesOnce() {
		if ( templatesLoaded ) { return; }
		templatesLoaded = true;
		post( 'WPISTIC_CF_list_templates', {} ).then( function ( res ) {
			if ( ! res || ! res.success || ! res.data || ! res.data.templates ) { return; }
			var sel = $( '#WPISTIC_CF-reply-template' );
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
		var form = $( '#WPISTIC_CF-reply-form' );
		form.querySelector( '[name=submission_id]' ).value = id;
		form.querySelector( '[name=to]' ).value = email;
		form.querySelector( '[name=subject]' ).value = subject || '';
		form.querySelector( '[name=body]' ).value = '';
		form.querySelector( '[name=cc]' ).value = '';
		form.querySelector( '[name=bcc]' ).value = '';
		$( '#WPISTIC_CF-reply-html' ).checked = false;
		// Reset the CC/BCC reveal.
		$( '#WPISTIC_CF-reply-extras' ).hidden = true;
		var toggle = $( '#WPISTIC_CF-reply-toggle-extras' );
		if ( toggle ) { toggle.textContent = cfg.i18n.showExtras || 'Show CC / BCC'; }
		// Reset the template picker to placeholder.
		var tpl = $( '#WPISTIC_CF-reply-template' );
		if ( tpl ) { tpl.value = ''; }

		var status = $( '#WPISTIC_CF-reply-status' );
		status.hidden = true;
		status.className = 'WPISTIC_CF-reply-status';
		$( '#WPISTIC_CF-reply-send' ).disabled = false;

		loadTemplatesOnce();
		openModal( replyModal );
		setTimeout( function () { form.querySelector( '[name=body]' ).focus(); }, 120 );
	}

	/* CC/BCC reveal toggle. */
	document.addEventListener( 'click', function ( e ) {
		var t = e.target.closest( '#WPISTIC_CF-reply-toggle-extras' );
		if ( ! t ) { return; }
		var box = $( '#WPISTIC_CF-reply-extras' );
		box.hidden = ! box.hidden;
		t.textContent = box.hidden
			? ( cfg.i18n.showExtras || 'Show CC / BCC' )
			: ( cfg.i18n.hideExtras || 'Hide CC / BCC' );
	} );

	/* Quote original button. */
	document.addEventListener( 'click', function ( e ) {
		var t = e.target.closest( '#WPISTIC_CF-reply-quote' );
		if ( ! t ) { return; }
		var ta     = $( '#WPISTIC_CF-reply-form [name=body]' );
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
		var t = e.target.closest( '#WPISTIC_CF-reply-template' );
		if ( ! t ) { return; }
		var opt = t.options[ t.selectedIndex ];
		if ( ! opt || ! opt.value ) { return; }
		var ta = $( '#WPISTIC_CF-reply-form [name=body]' );
		var subjInput = $( '#WPISTIC_CF-reply-form [name=subject]' );
		var newBody    = applyPlaceholders( opt.dataset.body || '' );
		var newSubject = applyPlaceholders( opt.dataset.subject || '' );
		ta.value = newBody;
		if ( newSubject ) { subjInput.value = newSubject; }
		// Reset to placeholder so picking the same template again still works.
		t.value = '';
	} );

	function sendReply() {
		var form   = $( '#WPISTIC_CF-reply-form' );
		var sendBtn = $( '#WPISTIC_CF-reply-send' );
		var status = $( '#WPISTIC_CF-reply-status' );
		var id      = form.querySelector( '[name=submission_id]' ).value;
		var subject = form.querySelector( '[name=subject]' ).value.trim();
		var bodyTxt = form.querySelector( '[name=body]' ).value.trim();

		if ( ! subject || ! bodyTxt ) {
			status.hidden = false;
			status.className = 'WPISTIC_CF-reply-status WPISTIC_CF-reply-status--err';
			status.textContent = cfg.i18n.error;
			return;
		}

		sendBtn.disabled = true;
		sendBtn.textContent = cfg.i18n.sending;
		status.hidden = true;

		var cc      = ( form.querySelector( '[name=cc]' )  || {} ).value || '';
		var bcc     = ( form.querySelector( '[name=bcc]' ) || {} ).value || '';
		var html    = $( '#WPISTIC_CF-reply-html' ).checked ? '1' : '';

		post( 'WPISTIC_CF_send_reply', { submission_id: id, subject: subject, body: bodyTxt, cc: cc, bcc: bcc, html_mode: html } ).then( function ( res ) {
			status.hidden = false;
			if ( res && res.success ) {
				status.className = 'WPISTIC_CF-reply-status WPISTIC_CF-reply-status--ok';
				status.textContent = res.data.message || cfg.i18n.sent;
				markRow( id, 'replied' );
				setTimeout( closeAll, 1100 );
			} else {
				status.className = 'WPISTIC_CF-reply-status WPISTIC_CF-reply-status--err';
				status.textContent = ( res && res.data && res.data.message ) || cfg.i18n.error;
				sendBtn.disabled = false;
			}
			sendBtn.textContent = cfg.i18n.sendReply || 'Send Reply';
		} ).catch( function () {
			status.hidden = false;
			status.className = 'WPISTIC_CF-reply-status WPISTIC_CF-reply-status--err';
			status.textContent = cfg.i18n.error;
			sendBtn.disabled = false;
			sendBtn.textContent = cfg.i18n.sendReply || 'Send Reply';
		} );
	}

	/* ---------- Delete ---------- */
	function deleteSubmission( id, row ) {
		if ( ! window.confirm( cfg.i18n.confirmDel ) ) { return; }
		post( 'WPISTIC_CF_delete', { id: id } ).then( function ( res ) {
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
		var row = $( '.WPISTIC_CF-row[data-id="' + id + '"]' );
		if ( ! row ) { return; }
		row.classList.remove( 'WPISTIC_CF-row--new', 'WPISTIC_CF-row--read', 'WPISTIC_CF-row--replied' );
		row.classList.add( 'WPISTIC_CF-row--' + status );
		var pill = row.querySelector( '.WPISTIC_CF-pill' );
		if ( pill ) {
			var labels = {
				replied: cfg.i18n.statusReplied || 'Replied',
				read:    cfg.i18n.statusRead    || 'Viewed',
				new:     cfg.i18n.statusNew     || 'New'
			};
			pill.className = 'WPISTIC_CF-pill WPISTIC_CF-pill--' + status;
			pill.textContent = labels[ status ] || status;
		}
	}

	/* ---------- Event delegation ---------- */
	document.addEventListener( 'click', function ( e ) {
		var view  = e.target.closest( '.WPISTIC_CF-btn--view' );
		var reply = e.target.closest( '.WPISTIC_CF-btn--reply' );
		var del   = e.target.closest( '.WPISTIC_CF-btn--del' );

		if ( view ) {
			viewSubmission( view.dataset.id );
		} else if ( reply && ! reply.disabled ) {
			var row = reply.closest( '.WPISTIC_CF-row' );
			var email = row ? ( row.querySelector( '.WPISTIC_CF-from-email' ) || {} ).textContent : '';
			var form  = row ? ( row.querySelector( '.WPISTIC_CF-formtag' ) || {} ).textContent : '';
			openReply( reply.dataset.id, ( email || '' ).trim(), 'Re: ' + ( form || '' ).trim() );
		} else if ( del ) {
			deleteSubmission( del.dataset.id, del.closest( '.WPISTIC_CF-row' ) );
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var add = e.target.closest( '.WPISTIC_CF-note-add' );
		if ( ! add ) { return; }
		var box = add.closest( '.WPISTIC_CF-note-form' );
		if ( ! box ) { return; }
		var id = box.getAttribute( 'data-submission' );
		var note = ( box.querySelector( '[name=WPISTIC_CF_note_body]' ) || {} ).value || '';
		var tags = ( box.querySelector( '[name=WPISTIC_CF_note_tags]' ) || {} ).value || '';
		if ( ! note.trim() ) { return; }
		add.disabled = true;
		post( 'WPISTIC_CF_add_note', { submission_id: id, note: note, tags: tags } ).then( function ( res ) {
			add.disabled = false;
			if ( ! res || ! res.success ) { return; }
			currentDetail = currentDetail || {};
			$( '#WPISTIC_CF-view-body' ).innerHTML = res.data.html || '';
		} ).catch( function () {
			add.disabled = false;
		} );
	} );

	document.addEventListener( 'click', function ( e ) {
		var replay = e.target.closest( '.WPISTIC_CF-replay' );
		if ( ! replay ) { return; }
		var id = replay.getAttribute( 'data-submission' );
		var type = replay.getAttribute( 'data-type' ) || 'both';
		replay.disabled = true;
		post( 'WPISTIC_CF_replay_submission', { submission_id: id, replay_type: type } ).then( function () {
			replay.disabled = false;
		} ).catch( function () {
			replay.disabled = false;
		} );
	} );

	// Reply button inside the View modal.
	var viewReplyBtn = $( '#WPISTIC_CF-view-reply' );
	if ( viewReplyBtn ) {
		viewReplyBtn.addEventListener( 'click', function () {
			openReply( this.dataset.id, this.dataset.email, this.dataset.subject );
		} );
	}

	var sendBtn = $( '#WPISTIC_CF-reply-send' );
	if ( sendBtn ) { sendBtn.addEventListener( 'click', sendReply ); }

	/* ---------- Form builder (CPT edit screen) ---------- */
	var fieldsContainer = $( '#WPISTIC_CF-fields-editor-rows' );
	var addFieldBtn     = $( '#WPISTIC_CF-fields-editor-add' );
	var fieldsTemplate  = $( '#WPISTIC_CF-fields-editor-template' );

	function nextFieldIndex() {
		var rows = $all( '.WPISTIC_CF-field-row', fieldsContainer );
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
			var rm = e.target.closest( '.WPISTIC_CF-field-row__remove' );
			if ( ! rm ) { return; }
			var row = rm.closest( '.WPISTIC_CF-field-row' );
			if ( row && window.confirm( 'Remove this field?' ) ) {
				row.parentNode.removeChild( row );
			}
		} );
	}

	/* ---------- Bulk actions ---------- */
	var bulkForm = $( '#WPISTIC_CF-bulk-form' );
	if ( bulkForm ) {
		var checkAll = $( '#WPISTIC_CF-check-all' );
		if ( checkAll ) {
			checkAll.addEventListener( 'change', function () {
				$all( '.WPISTIC_CF-check-row', bulkForm ).forEach( function ( cb ) { cb.checked = checkAll.checked; } );
			} );
		}
		bulkForm.addEventListener( 'submit', function ( e ) {
			var action = ( bulkForm.querySelector( '[name=bulk_action]' ) || {} ).value || '';
			var selected = $all( '.WPISTIC_CF-check-row', bulkForm ).filter( function ( cb ) { return cb.checked; } );
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
