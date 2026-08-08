/**
 * Verifyistic Admin JS
 * WordPressistic
 */
(function ($) {
    'use strict';

    var VFY = {

        init: function () {
            this.initTabs();
            this.initSettingsTabs();
            this.initColorPickers();
            this.initMediaUploader();
            this.initRangeSliders();
            this.initToggleSync();
            this.initWebhookTest();
            this.initLogActions();
            this.initExportCsv();
            this.initToast();
            this.initSaveForm();
        },

        // ── Page Tabs (nav) ──────────────────────────────────────
        initTabs: function () {
            $('.vfya-nav a').on('click', function (e) {
                // Let WP handle navigation normally — tabs are full page loads
            });
        },

        // ── Settings Inner Tabs ──────────────────────────────────
        initSettingsTabs: function () {
            var $tabs   = $('.vfya-stab');
            var $panels = $('.vfya-tab-panel');

            $tabs.on('click', function () {
                var target = $(this).data('tab');
                $tabs.removeClass('active');
                $panels.removeClass('active');
                $(this).addClass('active');
                $('#vfya-panel-' + target).addClass('active');
                // Store active tab
                try { sessionStorage.setItem('vfya_active_tab', target); } catch(e) {}
            });

            // Restore active tab
            try {
                var stored = sessionStorage.getItem('vfya_active_tab');
                if (stored) {
                    $('[data-tab="' + stored + '"]').trigger('click');
                }
            } catch(e) {}
        },

        // ── Color Pickers ────────────────────────────────────────
        initColorPickers: function () {
            $('.vfya-color-picker').wpColorPicker({
                change: function (event, ui) {
                    $(this).trigger('input');
                },
                clear: function () {
                    $(this).trigger('input');
                }
            });
        },

        // ── Media Uploader ───────────────────────────────────────
        initMediaUploader: function () {
            var frame;

            $(document).on('click', '.vfya-upload-logo-btn', function (e) {
                e.preventDefault();
                var $btn     = $(this);
                var $preview = $btn.closest('.vfya-logo-field').find('.vfya-logo-preview');
                var $input   = $btn.closest('.vfya-logo-field').find('.vfya-logo-input');
                var $img     = $preview.find('img');

                if (frame) { frame.open(); return; }

                frame = wp.media({
                    title:    verifyisticAdmin.strings.selectImage,
                    button:   { text: verifyisticAdmin.strings.useImage },
                    multiple: false,
                    library:  { type: 'image' }
                });

                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $input.val(attachment.url);
                    if ($img.length) {
                        $img.attr('src', attachment.url).show();
                    } else {
                        $preview.append('<img src="' + attachment.url + '" style="max-height:60px;max-width:200px;">');
                    }
                    $preview.show();
                });

                frame.open();
            });

            $(document).on('click', '.vfya-remove-logo-btn', function (e) {
                e.preventDefault();
                var $field = $(this).closest('.vfya-logo-field');
                $field.find('.vfya-logo-input').val('');
                $field.find('.vfya-logo-preview img').attr('src', '').hide();
            });
        },

        // ── Range Sliders ────────────────────────────────────────
        initRangeSliders: function () {
            $(document).on('input', '.vfya-range', function () {
                var val   = $(this).val();
                var $wrap = $(this).closest('.vfya-range-wrap');
                $wrap.find('.vfya-range-value').text(val + '%');
            });
        },

        // ── Toggle Sync: ID Verification ─────────────────────────
        initToggleSync: function () {
            var $idToggle = $('#verifyistic_id_verification');
            var $modeField = $('#vfya-mode-field');

            function syncMode() {
                if ($idToggle.is(':checked')) {
                    $modeField.addClass('vfya-mode-disabled');
                    $modeField.find('select').val('id_face').prop('disabled', true);
                } else {
                    $modeField.removeClass('vfya-mode-disabled');
                    $modeField.find('select').prop('disabled', false);
                }
            }

            $idToggle.on('change', syncMode);
            syncMode();
        },

        // ── Webhook Test ─────────────────────────────────────────
        initWebhookTest: function () {
            $(document).on('click', '#vfya-test-webhook', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var url  = $('#verifyistic_webhook_url').val();

                if (!url) {
                    VFY.showToast(verifyisticAdmin.strings.error, 'error');
                    return;
                }

                $btn.prop('disabled', true).html('<span class="vfya-spinner"></span> Sending…');

                $.post(verifyisticAdmin.ajaxUrl, {
                    action:      'verifyistic_test_webhook',
                    nonce:       verifyisticAdmin.nonce,
                    webhook_url: url
                }, function (res) {
                    $btn.prop('disabled', false).text('Test Webhook');
                    if (res.success) {
                        VFY.showToast(verifyisticAdmin.strings.webhookSuccess, 'success');
                    } else {
                        VFY.showToast(verifyisticAdmin.strings.webhookFail, 'error');
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).text('Test Webhook');
                    VFY.showToast(verifyisticAdmin.strings.webhookFail, 'error');
                });
            });
        },

        // ── Log Actions ──────────────────────────────────────────
        initLogActions: function () {
            // Delete single log
            $(document).on('click', '.vfya-delete-log', function (e) {
                e.preventDefault();
                if (!confirm(verifyisticAdmin.strings.confirmDelete)) return;
                var $btn = $(this);
                var id   = $btn.data('id');

                $.post(verifyisticAdmin.ajaxUrl, {
                    action:  'verifyistic_delete_log',
                    nonce:   verifyisticAdmin.nonce,
                    log_id:  id
                }, function (res) {
                    if (res.success) {
                        $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
                        VFY.showToast('Log deleted.', 'success');
                    }
                });
            });

            // Clear all logs
            $(document).on('click', '#vfya-clear-logs', function (e) {
                e.preventDefault();
                if (!confirm(verifyisticAdmin.strings.confirmClear)) return;
                var $btn = $(this);
                $btn.prop('disabled', true);

                $.post(verifyisticAdmin.ajaxUrl, {
                    action: 'verifyistic_clear_logs',
                    nonce:  verifyisticAdmin.nonce
                }, function (res) {
                    $btn.prop('disabled', false);
                    if (res.success) {
                        $('#vfya-logs-tbody tr').fadeOut(400, function () { $(this).remove(); });
                        $('#vfya-logs-tbody').html('<tr><td colspan="9" class="vfya-table-empty"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>No verification logs found.</td></tr>');
                        VFY.showToast('All logs cleared.', 'success');
                    }
                });
            });
        },

        // ── Export CSV ───────────────────────────────────────────
        initExportCsv: function () {
            $(document).on('click', '#vfya-export-csv', function (e) {
                e.preventDefault();
                var $btn = $(this);
                $btn.prop('disabled', true).html('<span class="vfya-spinner"></span> Exporting…');

                $.post(verifyisticAdmin.ajaxUrl, {
                    action: 'verifyistic_export_csv',
                    nonce:  verifyisticAdmin.nonce
                }, function (res) {
                    $btn.prop('disabled', false).html('Export CSV');
                    if (res.success && res.data.csv) {
                        var blob = new Blob([res.data.csv], { type: 'text/csv' });
                        var url  = URL.createObjectURL(blob);
                        var a    = document.createElement('a');
                        a.href  = url;
                        a.download = 'verifyistic-logs-' + new Date().toISOString().split('T')[0] + '.csv';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                        VFY.showToast('CSV exported!', 'success');
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).html('Export CSV');
                    VFY.showToast(verifyisticAdmin.strings.error, 'error');
                });
            });
        },

        // ── Save Form ────────────────────────────────────────────
        initSaveForm: function () {
            // Standard form submit — show toast on redirect
            if (window.location.search.indexOf('settings-updated=true') > -1 || window.location.search.indexOf('updated=1') > -1) {
                VFY.showToast(verifyisticAdmin.strings.saved, 'success');
            }
        },

        // ── Toast ────────────────────────────────────────────────
        initToast: function () {
            if (!$('#vfya-toast').length) {
                $('body').append(
                    '<div id="vfya-toast" role="alert">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>' +
                    '<span id="vfya-toast-msg"></span>' +
                    '</div>'
                );
            }
        },

        showToast: function (msg, type) {
            var $toast = $('#vfya-toast');
            $toast.removeClass('success error show').addClass(type);
            $('#vfya-toast-msg').text(msg);
            $toast.addClass('show');
            clearTimeout(VFY._toastTimer);
            VFY._toastTimer = setTimeout(function () {
                $toast.removeClass('show');
            }, 3500);
        }
    };

    $(document).ready(function () {
        VFY.init();
    });

}(jQuery));
