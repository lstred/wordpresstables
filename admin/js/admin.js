/* global jQuery, tumAdmin */
(function ($) {
    'use strict';

    var TUMAdmin = {

        init: function () {
            this.bindUserApproval();
            this.bindTableDelete();
            this.bindUserFilter();
            this.bindCopyShortcode();
        },

        // ── User approval ─────────────────────────────────────────────────────
        bindUserApproval: function () {
            $(document).on('click', '.tum-approve-btn', function () {
                var $btn    = $(this);
                var userId  = $btn.data('user-id');
                TUMAdmin.setApproval($btn, userId, 'tum_admin_approve_user');
            });

            $(document).on('click', '.tum-unapprove-btn', function () {
                var $btn   = $(this);
                var userId = $btn.data('user-id');
                if ( ! confirm(tumAdmin.strings.confirmUnapprove) ) return;
                TUMAdmin.setApproval($btn, userId, 'tum_admin_unapprove_user');
            });
        },

        setApproval: function ($btn, userId, action) {
            $btn.prop('disabled', true).text(tumAdmin.strings.saving);

            $.post(tumAdmin.ajaxUrl, {
                action:  action,
                user_id: userId,
                nonce:   tumAdmin.nonce
            })
            .done(function (resp) {
                if (resp.success) {
                    var $row    = $btn.closest('tr');
                    var $status = $row.find('.tum-status-cell');

                    if (action === 'tum_admin_approve_user') {
                        $status.html('<span class="tum-badge tum-badge-approved">Approved</span>');
                        $btn.removeClass('button-primary tum-approve-btn')
                            .addClass('tum-unapprove-btn')
                            .text('Revoke Access')
                            .prop('disabled', false);
                    } else {
                        $status.html('<span class="tum-badge tum-badge-pending">Not Approved</span>');
                        $btn.addClass('button-primary tum-approve-btn')
                            .removeClass('tum-unapprove-btn')
                            .text('Approve')
                            .prop('disabled', false);
                    }
                } else {
                    alert(resp.data.message || tumAdmin.strings.error);
                    $btn.prop('disabled', false).text('Action');
                }
            })
            .fail(function () {
                alert(tumAdmin.strings.error);
                $btn.prop('disabled', false);
            });
        },

        // ── Table delete (admin) ──────────────────────────────────────────────
        bindTableDelete: function () {
            $(document).on('click', '.tum-admin-delete-table', function () {
                var $btn    = $(this);
                var tableId = $btn.data('table-id');
                var title   = $btn.data('title');

                if ( ! confirm(tumAdmin.strings.confirmDelete + '\n\n"' + title + '"') ) return;

                $btn.prop('disabled', true).text('Deleting…');

                $.post(tumAdmin.ajaxUrl, {
                    action:   'tum_admin_delete_table',
                    table_id: tableId,
                    nonce:    tumAdmin.nonce
                })
                .done(function (resp) {
                    if (resp.success) {
                        $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
                    } else {
                        alert(resp.data.message || tumAdmin.strings.error);
                        $btn.prop('disabled', false).text('Delete');
                    }
                })
                .fail(function () {
                    alert(tumAdmin.strings.error);
                    $btn.prop('disabled', false).text('Delete');
                });
            });
        },

        // ── User filter ───────────────────────────────────────────────────────
        bindUserFilter: function () {
            $('#tum-user-filter').on('input', function () {
                var term = $(this).val().toLowerCase();
                $('#tum-users-table tbody tr').each(function () {
                    var search = $(this).data('search') || '';
                    $(this).toggle(search.indexOf(term) > -1);
                });
            });
        },

        // ── Copy shortcode ────────────────────────────────────────────────────
        bindCopyShortcode: function () {
            $(document).on('click', '.tum-copy-shortcode', function () {
                var text = $(this).data('copy');
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function () {
                        /* brief visual feedback */
                    });
                } else {
                    var el = document.createElement('textarea');
                    el.value = text;
                    document.body.appendChild(el);
                    el.select();
                    document.execCommand('copy');
                    document.body.removeChild(el);
                }
                var $btn = $(this);
                $btn.text('Copied!');
                setTimeout(function () { $btn.text('Copy'); }, 2000);
            });
        }
    };

    $(function () { TUMAdmin.init(); });

}(jQuery));
