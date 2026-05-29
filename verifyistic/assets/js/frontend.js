/**
 * Verifyistic Frontend JS
 * WordPressistic
 */
(function ($) {
    'use strict';

    var VFY = {

        data: window.verifyisticData || {},
        cookieName: 'verifyistic_verified',

        init: function () {
            // Check if already verified via cookie
            if (this.getCookie(this.cookieName)) {
                return; // Already verified
            }

            this.bindEvents();
            this.showOverlay();
        },

        // ── Show Overlay ─────────────────────────────────────────
        showOverlay: function () {
            $('#verifyistic-overlay').fadeIn(200);
            $('body').addClass('verifyistic-locked');
        },

        // ── Hide Overlay ─────────────────────────────────────────
        hideOverlay: function (delay) {
            delay = delay || 0;
            setTimeout(function () {
                $('#verifyistic-overlay').fadeOut(300, function () {
                    $(this).remove();
                    $('body').removeClass('verifyistic-locked');
                });
            }, delay);
        },

        // ── Bind Events ──────────────────────────────────────────
        bindEvents: function () {
            var self = this;

            // DOB / ID form submit
            $(document).on('submit', '#vfy-verify-form', function (e) {
                e.preventDefault();
                self.handleFormSubmit($(this));
            });

            // Yes button
            $(document).on('click', '#vfy-btn-yes', function (e) {
                e.preventDefault();
                self.handleYesNo('yes');
            });

            // No button
            $(document).on('click', '#vfy-btn-no', function (e) {
                e.preventDefault();
                self.handleYesNo('no');
            });

            // File upload preview
            $(document).on('change', '.vfy-file-input', function () {
                var file   = this.files[0];
                var $wrap  = $(this).closest('.vfy-upload-area');
                var $prev  = $wrap.find('.vfy-upload-preview');
                if (file) {
                    $prev.text(file.name);
                }
            });

            // Prevent click-through on popup card
            $(document).on('click', '#verifyistic-popup', function (e) {
                e.stopPropagation();
            });

            // Prevent right-click on overlay
            $(document).on('contextmenu', '#verifyistic-overlay', function (e) {
                e.preventDefault();
            });

            // Disable keyboard escape
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape' && $('#verifyistic-overlay').length) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            });
        },

        // ── Handle DOB / ID Form Submit ──────────────────────────
        handleFormSubmit: function ($form) {
            var self  = this;
            var $btn  = $form.find('.vfy-btn-submit');
            var $err  = $form.find('.vfy-error');
            var mode  = this.data.mode || 'dob';

            // Client-side validation
            if (!this.validateForm($form, mode)) return;

            // Loading state
            this.setButtonLoading($btn, true);
            $err.removeClass('show');

            var formData = new FormData( $form[0] );
            formData.append('action',   'verifyistic_verify');
            formData.append('nonce',    this.data.nonce);
            formData.append('mode',     mode);
            formData.append('page_url', window.location.href);
            // Anti-bot guard fields (shared, live outside the <form>).
            formData.append('vfy_hp',    $('#vfy-hp').val() || '');
            formData.append('vfy_token', $('#vfy-form-token').val() || '');

            $.ajax({
                url:         this.data.ajaxUrl,
                type:        'POST',
                data:        formData,
                processData: false,
                contentType: false,
                success: function (res) {
                    self.setButtonLoading($btn, false);
                    if (res.success) {
                        self.onVerified(res.data, $form);
                    } else {
                        self.showError($err, res.data.message || self.data.strings.ageError);
                    }
                },
                error: function () {
                    self.setButtonLoading($btn, false);
                    self.showError($err, 'Network error. Please try again.');
                }
            });
        },

        // ── Handle Yes/No Buttons ────────────────────────────────
        handleYesNo: function (choice) {
            var self = this;
            var $btn = choice === 'yes' ? $('#vfy-btn-yes') : $('#vfy-btn-no');

            this.setButtonLoading($btn, true);

            if (choice === 'no') {
                // Fire decline AJAX
                $.post(this.data.ajaxUrl, {
                    action:   'verifyistic_decline',
                    nonce:    this.data.nonce,
                    page_url: window.location.href
                }, function (res) {
                    self.setButtonLoading($btn, false);
                    if (res.success && res.data.redirect) {
                        window.location.href = res.data.redirect;
                    } else {
                        // No redirect — go back
                        window.history.back();
                    }
                }).fail(function () {
                    window.history.back();
                });
                return;
            }

            // YES: verify
            $.post(this.data.ajaxUrl, {
                action:    'verifyistic_verify',
                nonce:     this.data.nonce,
                mode:      'yes_no',
                page_url:  window.location.href,
                vfy_hp:    $('#vfy-hp').val() || '',
                vfy_token: $('#vfy-form-token').val() || ''
            }, function (res) {
                self.setButtonLoading($btn, false);
                if (res.success) {
                    self.onVerified(res.data, null);
                }
            }).fail(function () {
                self.setButtonLoading($btn, false);
                // Optimistic — let through on network failure
                self.onVerified({}, null);
            });
        },

        // ── On Verified ──────────────────────────────────────────
        onVerified: function (data, $form) {
            // Set cookie
            var remember = $form ? $form.find('.vfy-remember-checkbox').is(':checked') : true;
            var days     = remember ? (parseInt(this.data.cookieDays) || 30) : 0;
            this.setCookie(this.cookieName, data.token || '1', days);

            // Show success state
            if ($form) {
                $form.hide();
                $form.closest('.vfy-inner').find('.vfy-success-state').addClass('show');
            } else {
                // Yes/No — just show success briefly
                $('#vfy-yn-content').hide();
                $('#vfy-success-yn').addClass('show');
            }

            // Hide overlay after delay
            this.hideOverlay(1600);
        },

        // ── Validate Form ────────────────────────────────────────
        validateForm: function ($form, mode) {
            var $err = $form.find('.vfy-error');

            if (mode === 'dob') {
                var dob = $form.find('[name="dob"]').val();
                if (!dob) {
                    this.showError($err, this.data.strings.dobRequired);
                    return false;
                }
                // Age check client-side
                var age = this.calculateAge(dob);
                if (age < 0) {
                    this.showError($err, this.data.strings.dobInvalid);
                    return false;
                }
                if (age < parseInt(this.data.minAge)) {
                    this.showError($err, this.data.strings.ageError);
                    return false;
                }
            }

            if (mode === 'id_face') {
                var idFile     = $form.find('[name="id_file"]')[0];
                var selfieFile = $form.find('[name="selfie_file"]')[0];
                if (!idFile || !idFile.files.length) {
                    this.showError($err, this.data.strings.idRequired);
                    return false;
                }
                if (!selfieFile || !selfieFile.files.length) {
                    this.showError($err, this.data.strings.selfieReq);
                    return false;
                }
            }

            return true;
        },

        // ── Show Error ───────────────────────────────────────────
        showError: function ($el, msg) {
            $el.find('.vfy-error-text').text(msg);
            $el.addClass('show');
            $el[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        },

        // ── Button Loading State ─────────────────────────────────
        setButtonLoading: function ($btn, loading) {
            if (loading) {
                $btn.data('orig', $btn.html())
                    .prop('disabled', true)
                    .html('<span class="vfy-btn-spinner"></span> ' + (this.data.strings.verifying || 'Verifying…'));
            } else {
                $btn.prop('disabled', false).html($btn.data('orig') || $btn.html());
            }
        },

        // ── Calculate Age ────────────────────────────────────────
        calculateAge: function (dob) {
            var parts = dob.split('-');
            if (parts.length < 3) return -1;
            var birth = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
            if (isNaN(birth.getTime())) return -1;
            var today = new Date();
            var age   = today.getFullYear() - birth.getFullYear();
            var m     = today.getMonth() - birth.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) { age--; }
            return age;
        },

        // ── Cookie Helpers ───────────────────────────────────────
        setCookie: function (name, value, days) {
            var expires = '';
            if (days) {
                var date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = '; expires=' + date.toUTCString();
            }
            document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
        },

        getCookie: function (name) {
            var nameEQ = name + '=';
            var ca     = document.cookie.split(';');
            for (var i = 0; i < ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0) === ' ') { c = c.substring(1, c.length); }
                if (c.indexOf(nameEQ) === 0) {
                    return decodeURIComponent(c.substring(nameEQ.length, c.length));
                }
            }
            return null;
        }
    };

    $(document).ready(function () {
        VFY.init();
    });

}(jQuery));
