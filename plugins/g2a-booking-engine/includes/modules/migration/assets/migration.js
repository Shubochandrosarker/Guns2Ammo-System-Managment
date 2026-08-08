/* G2AB Migration Tool — admin JS
 * Vanilla JS, no jQuery dependency. Polls AJAX for progress.
 * v1.1.0
 */
(function () {
	'use strict';

	if ( typeof window.G2ABMigration === 'undefined' ) return;
	const M = window.G2ABMigration;

	const $ = (sel, root) => (root || document).querySelector(sel);
	const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

	function ajax(action, data) {
		const body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', M.nonce);
		Object.entries(data || {}).forEach(([k, v]) => body.append(k, v));

		return fetch(M.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' })
			.then(r => r.json())
			.then(json => {
				if (!json.success) throw new Error(json.data && json.data.message ? json.data.message : 'Request failed.');
				return json.data;
			});
	}

	function setProgress(card, data) {
		const fill = $('.g2ab-mig-progress-fill', card);
		const stats = $$('.g2ab-mig-stat strong', card);
		const status = $('.g2ab-mig-status-line', card);
		const log = $('.g2ab-mig-log', card);

		if (fill) fill.style.width = (data.progress || 0) + '%';
		if (stats[0]) stats[0].textContent = data.imported || 0;
		if (stats[1]) stats[1].textContent = data.skipped  || 0;
		if (stats[2]) stats[2].textContent = data.failed   || 0;
		if (status) {
			let t = '';
			if (data.completed) t = data.status === 'completed' ? M.i18n.completed : M.i18n.failed;
			else t = M.i18n.running + ' (' + (data.imported + data.skipped + data.failed) + '/' + data.total + ')';
			status.textContent = t;
		}
		if (log && data.log) log.textContent = data.log;
	}

	function pollUntilDone(runId, card, onDone) {
		let stopped = false;
		const tick = () => {
			if (stopped) return;
			ajax('g2ab_migration_status', { run_id: runId })
				.then(data => {
					setProgress(card, data);
					if (data.completed) {
						stopped = true;
						setTimeout(() => onDone && onDone(data), 600);
					} else {
						setTimeout(tick, 1500);
					}
				})
				.catch(err => {
					console.error('[G2AB Migration]', err);
					setTimeout(tick, 3000);
				});
		};
		tick();
		return () => { stopped = true; };
	}

	// =============================================================
	// Step 3 — Dry run preview
	// =============================================================
	const previewCard = $('#g2ab-mig-preview');
	if (previewCard) {
		const adapter = previewCard.dataset.adapter;
		const startBtn = $('#g2ab-mig-start-dry');
		const liveBtn  = $('#g2ab-mig-start-live');
		const progress = $('#g2ab-mig-preview-progress');
		const cta      = $('#g2ab-mig-preview-cta');
		const status   = $('.g2ab-mig-status-line', previewCard);

		let lastRunId = null;

		startBtn.addEventListener('click', () => {
			startBtn.disabled = true;
			startBtn.textContent = M.i18n.starting;
			progress.style.display = 'block';

			ajax('g2ab_migration_start', { adapter, mode: 'dry' })
				.then(({ run_id }) => {
					lastRunId = run_id;
					pollUntilDone(run_id, previewCard, () => {
						cta.style.display = 'flex';
						startBtn.style.display = 'none';
					});
				})
				.catch(err => {
					status.textContent = '✗ ' + err.message;
					status.style.color = '#c62828';
					startBtn.disabled = false;
					startBtn.textContent = 'Retry dry run';
				});
		});

		if (liveBtn) {
			liveBtn.addEventListener('click', () => {
				liveBtn.disabled = true;
				liveBtn.textContent = M.i18n.starting;
				ajax('g2ab_migration_start', { adapter, mode: 'live' })
					.then(({ run_id }) => {
						window.location.href = M.pageUrl + '&step=4&run=' + run_id + '&adapter=' + encodeURIComponent(adapter);
					})
					.catch(err => {
						alert('✗ ' + err.message);
						liveBtn.disabled = false;
						liveBtn.textContent = 'Run live migration →';
					});
			});
		}
	}

	// =============================================================
	// Step 4 — Live progress
	// =============================================================
	const liveCard = $('#g2ab-mig-live');
	if (liveCard) {
		const runId = parseInt(liveCard.dataset.run, 10);
		if (runId) {
			pollUntilDone(runId, liveCard, () => {
				// Redirect to step 5 when done.
				window.location.href = M.pageUrl + '&step=5&run=' + runId;
			});
		}
	}

	// =============================================================
	// Rollback button (Step 5)
	// =============================================================
	$$('.g2ab-mig-rollback-btn').forEach(btn => {
		btn.addEventListener('click', () => {
			if (!confirm(M.i18n.confirmRollback)) return;
			btn.disabled = true;
			btn.textContent = '…';
			ajax('g2ab_migration_rollback', { run_id: btn.dataset.run })
				.then(() => {
					alert(M.i18n.rollbackOk);
					window.location.reload();
				})
				.catch(err => {
					alert('✗ ' + err.message);
					btn.disabled = false;
					btn.textContent = 'Rollback this run';
				});
		});
	});

})();
