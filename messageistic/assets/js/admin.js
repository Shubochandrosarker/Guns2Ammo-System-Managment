/* global messageisticAdmin, wp */
(function () {
    'use strict';

    const cfg = window.messageisticAdmin || {};

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.messageistic-test-connection');
        if (!button) {
            return;
        }
        event.preventDefault();

        const provider = button.getAttribute('data-provider');
        const out = document.querySelector('.messageistic-test-result[data-for="' + provider + '"]');
        if (out) {
            out.className = 'messageistic-test-result';
            out.textContent = cfg.i18n ? cfg.i18n.testing : 'Testing…';
        }
        button.disabled = true;

        wp.apiFetch({
            path: 'messageistic/v1/provider/test',
            method: 'POST',
            data: { key: provider }
        }).then(function () {
            if (out) {
                out.classList.add('is-ok');
                out.textContent = cfg.i18n ? cfg.i18n.testSuccess : 'Connection OK.';
            }
        }).catch(function (err) {
            if (out) {
                out.classList.add('is-fail');
                const tpl = cfg.i18n && cfg.i18n.testFailed ? cfg.i18n.testFailed : 'Connection failed: %s';
                out.textContent = tpl.replace('%s', err && err.message ? err.message : 'unknown error');
            }
        }).finally(function () {
            button.disabled = false;
        });
    });

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.messageistic-register-webhooks');
        if (!button) {
            return;
        }
        event.preventDefault();

        const provider = button.getAttribute('data-provider');
        const out = document.querySelector('.messageistic-test-result[data-for="' + provider + '"]');
        if (out) {
            out.className = 'messageistic-test-result';
            out.textContent = cfg.i18n && cfg.i18n.registering ? cfg.i18n.registering : 'Registering webhooks…';
        }
        button.disabled = true;

        wp.apiFetch({
            path: 'messageistic/v1/provider/' + provider + '/webhooks',
            method: 'POST'
        }).then(function (res) {
            if (out) {
                out.classList.add('is-ok');
                const count = res && res.webhooks ? Object.keys(res.webhooks).length : 0;
                const tpl = cfg.i18n && cfg.i18n.webhooksRegistered ? cfg.i18n.webhooksRegistered : '%d webhooks registered on the device.';
                out.textContent = tpl.replace('%d', String(count));
            }
        }).catch(function (err) {
            if (out) {
                out.classList.add('is-fail');
                const tpl = cfg.i18n && cfg.i18n.webhooksFailed ? cfg.i18n.webhooksFailed : 'Webhook registration failed: %s';
                out.textContent = tpl.replace('%s', err && err.message ? err.message : 'unknown error');
            }
        }).finally(function () {
            button.disabled = false;
        });
    });

    // Provider radio cards — toggle visual selection
    document.addEventListener('change', function (event) {
        if (event.target && event.target.matches('input[name="messageistic_active_provider"]')) {
            document.querySelectorAll('.messageistic-radio-card').forEach(function (el) {
                el.classList.remove('is-selected');
            });
            const card = event.target.closest('.messageistic-radio-card');
            if (card) {
                card.classList.add('is-selected');
            }
        }
    });

    /* ========================================================
     * Conversations inbox — AJAX reply, live polling, search
     * ======================================================== */
    const inbox = document.querySelector('.messageistic-inbox');
    if (!inbox) {
        return;
    }

    const STATUS_ICONS = { delivered: '✓✓', sent: '✓', failed: '⚠', queued: '⏱', pending: '⏱' };
    const panel = inbox.querySelector('.messageistic-inbox__panel');
    const thread = inbox.querySelector('.messageistic-inbox__thread');
    const compose = inbox.querySelector('.messageistic-inbox__compose');
    const contactId = panel ? parseInt(panel.getAttribute('data-contact-id') || '0', 10) : 0;

    function scrollThread() {
        if (thread) {
            thread.scrollTop = thread.scrollHeight;
        }
    }
    scrollThread();

    // Sidebar search — client-side filter over the loaded threads.
    const search = inbox.querySelector('.mi-inbox-search__input');
    if (search) {
        search.addEventListener('input', function () {
            const q = search.value.trim().toLowerCase();
            inbox.querySelectorAll('.mi-thread').forEach(function (a) {
                a.style.display = !q || (a.getAttribute('data-search') || '').indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }

    // Details toggle (workflow + notes).
    const detailsToggle = inbox.querySelector('.mi-inbox-details-toggle');
    const details = inbox.querySelector('.mi-inbox-details');
    if (detailsToggle && details) {
        detailsToggle.addEventListener('click', function () {
            const open = details.hidden;
            details.hidden = !open;
            detailsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    function formatTime(mysqlDate) {
        return typeof mysqlDate === 'string' && mysqlDate.length >= 16 ? mysqlDate.substring(11, 16) : '';
    }

    function statusMarkup(status) {
        return (STATUS_ICONS[status] || '') + ' ' + status;
    }

    function renderBubble(message) {
        const isOut = message.direction !== 'inbound';
        const div = document.createElement('div');
        div.className = 'messageistic-bubble ' + (isOut ? 'is-out' : 'is-in') + ' is-status-' + (message.status || 'queued');
        div.setAttribute('data-message-id', String(message.id));

        const body = document.createElement('div');
        body.className = 'messageistic-bubble__body';
        body.textContent = message.body || '';
        div.appendChild(body);

        const small = document.createElement('small');
        const time = document.createElement('span');
        time.className = 'messageistic-bubble__time';
        time.textContent = formatTime(message.created_at || '');
        small.appendChild(time);
        if (isOut) {
            small.appendChild(document.createTextNode(' · '));
            const st = document.createElement('span');
            st.className = 'messageistic-bubble__status';
            st.textContent = statusMarkup(message.status || 'queued');
            small.appendChild(st);
        }
        div.appendChild(small);
        return div;
    }

    function upsertMessages(messages) {
        if (!thread || !Array.isArray(messages)) {
            return;
        }
        let lastId = parseInt(thread.getAttribute('data-last-message-id') || '0', 10);
        let appended = false;
        messages.forEach(function (message) {
            const id = parseInt(message.id, 10);
            const existing = thread.querySelector('[data-message-id="' + id + '"]');
            if (existing) {
                // Refresh delivery status on bubbles we already show.
                if (message.direction !== 'inbound') {
                    existing.className = existing.className.replace(/is-status-\S+/, 'is-status-' + (message.status || 'queued'));
                    const st = existing.querySelector('.messageistic-bubble__status');
                    if (st) {
                        st.textContent = statusMarkup(message.status || 'queued');
                    }
                }
                return;
            }
            if (id > lastId || !thread.querySelector('[data-message-id]')) {
                thread.appendChild(renderBubble(message));
                appended = true;
            }
            if (id > lastId) {
                lastId = id;
            }
        });
        thread.setAttribute('data-last-message-id', String(lastId));
        if (appended) {
            const empty = thread.querySelector('.mi-inbox-empty');
            if (empty) {
                empty.remove();
            }
            scrollThread();
        }
    }

    // Live polling for new messages + delivery status flips.
    if (contactId && thread && window.wp && wp.apiFetch) {
        window.setInterval(function () {
            if (document.visibilityState !== 'visible') {
                return;
            }
            wp.apiFetch({ path: 'messageistic/v1/messages?contact_id=' + contactId + '&limit=50' }).then(function (messages) {
                upsertMessages(Array.isArray(messages) ? messages.slice().reverse() : []);
            }).catch(function () { /* transient — try again next tick */ });
        }, 12000);
    }

    // Compose: autosize, segment counter, Enter-to-send, AJAX submit.
    if (compose && window.wp && wp.apiFetch) {
        const textarea = compose.querySelector('textarea[name="body"]');
        const sendBtn = compose.querySelector('.mi-compose-send');
        const errorBox = compose.querySelector('.mi-compose-error');
        const counter = compose.querySelector('.mi-compose-counter');

        function autosize() {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 140) + 'px';
        }

        function updateCounter() {
            const text = textarea.value;
            if (!counter) {
                return;
            }
            if (!text.length) {
                counter.textContent = '';
                return;
            }
            const unicode = /[^\u0000-\u007F]/.test(text);
            const single = unicode ? 70 : 160;
            const multi = unicode ? 67 : 153;
            const segments = text.length <= single ? 1 : Math.ceil(text.length / multi);
            counter.textContent = text.length + ' chars · ' + segments + ' segment' + (segments > 1 ? 's' : '');
        }

        textarea.addEventListener('input', function () { autosize(); updateCounter(); });
        textarea.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                compose.requestSubmit ? compose.requestSubmit() : compose.submit();
            }
        });

        compose.addEventListener('submit', function (event) {
            event.preventDefault();
            const body = textarea.value.trim();
            if (!body) {
                return;
            }
            sendBtn.disabled = true;
            if (errorBox) {
                errorBox.hidden = true;
            }
            wp.apiFetch({
                path: 'messageistic/v1/messages',
                method: 'POST',
                data: {
                    contact_id: parseInt(compose.getAttribute('data-contact-id') || '0', 10),
                    body: body,
                    classification: 'customer_care',
                    idempotency_key: 'reply:' + contactId + ':' + Date.now() + ':' + Math.random().toString(36).slice(2, 10)
                }
            }).then(function (res) {
                textarea.value = '';
                autosize();
                updateCounter();
                const result = res && res.result ? res.result : {};
                upsertMessages([{
                    id: res && res.message_id ? res.message_id : Date.now(),
                    direction: 'outbound',
                    body: body,
                    status: result.status || 'queued',
                    created_at: new Date().toISOString().substring(0, 16).replace('T', ' ')
                }]);
            }).catch(function (err) {
                if (errorBox) {
                    errorBox.textContent = err && err.message ? err.message : 'Send failed.';
                    errorBox.hidden = false;
                } else {
                    window.alert(err && err.message ? err.message : 'Send failed.');
                }
            }).finally(function () {
                sendBtn.disabled = false;
                textarea.focus();
            });
        });

        autosize();
        updateCounter();
    }
}());
