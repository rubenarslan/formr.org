// Behaviour for the superadmin User Management page. Loaded via a plain
// <script src> (CSP 'self', no nonce needed) instead of an inline block — so
// it doesn't depend on the per-request CSP nonce reaching the template, and
// the logic lives outside the view. No-ops unless #user-management-page is
// present, so it's harmless if ever loaded elsewhere. The ajax endpoint is
// read from the page's data attribute rather than injected into JS.
(function () {
    var root = document.getElementById('user-management-page');
    if (!root) { return; }
    var saAjaxUrl = root.getAttribute('data-sa-ajax-url');

    // The .api-btn / .del-btn / .verify-email-btn / .add-email-btn actions on
    // this page are bound in the admin bundle (common/js/run_users.js), which
    // reads the endpoint from a GLOBAL `saAjaxUrl`. That global used to be set
    // by the inline <script> that lived here; externalizing this file for CSP
    // dropped it, so those four superadmin actions threw "saAjaxUrl is not
    // defined" on click and silently did nothing (only Reset 2FA, handled
    // below from the local var, was migrated). Re-expose it for run_users.js.
    window.saAjaxUrl = saAjaxUrl;

    jQuery(function ($) {
        $('.reset-2fa-btn').click(function () {
            var userId = $(this).data('user');
            var userEmail = $(this).data('email');

            var template = $('#tpl-reset-2fa').html();
            template = template.replace(/%{user}/g, userEmail);

            var $modal = $(template);
            $modal.modal('show');

            $modal.find('.reset-2fa-confirm').click(function () {
                var adminCode = $modal.find('input[name="admin_2fa_code"]').val();

                $.post(saAjaxUrl, {
                    reset_2fa: true,
                    user_id: userId,
                    user_email: userEmail,
                    admin_2fa_code: adminCode
                }, function (response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        alert(response.message || 'Failed to reset 2FA');
                    }
                });
            });
        });
    });
})();
