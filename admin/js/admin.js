/**
 * Dragon Cron Manager - Admin JavaScript
 */
(function($) {
    'use strict';

    /**
     * Show toast notification
     */
    function showToast(message, type) {
        const $toast = $('<div class="dcm-toast dcm-toast-' + type + '">' + message + '</div>');
        $('body').append($toast);

        setTimeout(function() {
            $toast.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }

    /**
     * Run cron event
     */
    $(document).on('click', '.dcm-run-event', function() {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const hook = $row.data('hook');
        const args = $row.data('args');

        $btn.addClass('dcm-running');

        $.ajax({
            url: dcmAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dragoncronmanager_run_event',
                nonce: dcmAdmin.nonce,
                hook: hook,
                args: JSON.stringify(args)
            },
            success: function(response) {
                $btn.removeClass('dcm-running');

                if (response.success) {
                    showToast(response.data.message, 'success');
                    // Reload page after short delay to show updated stats/logs
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showToast(response.data.message || dcmAdmin.i18n.error, 'error');
                }
            },
            error: function() {
                $btn.removeClass('dcm-running');
                showToast(dcmAdmin.i18n.error, 'error');
            }
        });
    });

    /**
     * Test cron event (run without rescheduling)
     */
    $(document).on('click', '.dcm-test-event', function() {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const hook = $row.data('hook');
        const args = $row.data('args');

        $btn.addClass('dcm-running');

        $.ajax({
            url: dcmAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dragoncronmanager_test_event',
                nonce: dcmAdmin.nonce,
                hook: hook,
                args: JSON.stringify(args)
            },
            success: function(response) {
                $btn.removeClass('dcm-running');

                if (response.success) {
                    showToast(response.data.message, 'success');
                } else {
                    showToast(response.data.message || dcmAdmin.i18n.error, 'error');
                }
            },
            error: function() {
                $btn.removeClass('dcm-running');
                showToast(dcmAdmin.i18n.error, 'error');
            }
        });
    });

    /**
     * Trash cron event (move to trash from events page)
     */
    $(document).on('click', '.dcm-trash-event', function() {
        if (!confirm(dcmAdmin.i18n.confirmTrash)) {
            return;
        }

        const $btn = $(this);
        const $row = $btn.closest('tr');
        const hook = $row.data('hook');
        const key = $row.data('key');
        const timestamp = $row.data('timestamp');

        $btn.addClass('dcm-running');

        $.ajax({
            url: dcmAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dragoncronmanager_trash_event',
                nonce: dcmAdmin.nonce,
                hook: hook,
                key: key,
                timestamp: timestamp
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                    showToast(response.data.message, 'success');
                    // Reload after delay to update trash count
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $btn.removeClass('dcm-running');
                    showToast(response.data.message || dcmAdmin.i18n.error, 'error');
                }
            },
            error: function() {
                $btn.removeClass('dcm-running');
                showToast(dcmAdmin.i18n.error, 'error');
            }
        });
    });

    /**
     * Restore cron event from trash
     */
    $(document).on('click', '.dcm-restore-event', function() {
        if (!confirm(dcmAdmin.i18n.confirmRestore)) {
            return;
        }

        const $btn = $(this);
        const $row = $btn.closest('tr');
        const trashId = $row.data('trash-id');

        $btn.addClass('dcm-running');

        $.ajax({
            url: dcmAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dragoncronmanager_restore_event',
                nonce: dcmAdmin.nonce,
                trash_id: trashId
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                    showToast(response.data.message, 'success');
                    // Reload after delay to update counts
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $btn.removeClass('dcm-running');
                    showToast(response.data.message || dcmAdmin.i18n.error, 'error');
                }
            },
            error: function() {
                $btn.removeClass('dcm-running');
                showToast(dcmAdmin.i18n.error, 'error');
            }
        });
    });

    /**
     * Permanently delete cron event from trash
     */
    $(document).on('click', '.dcm-delete-event', function() {
        if (!confirm(dcmAdmin.i18n.confirmDelete)) {
            return;
        }

        const $btn = $(this);
        const $row = $btn.closest('tr');
        const trashId = $row.data('trash-id');

        $btn.addClass('dcm-running');

        $.ajax({
            url: dcmAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dragoncronmanager_delete_event',
                nonce: dcmAdmin.nonce,
                trash_id: trashId
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                    showToast(response.data.message, 'success');
                } else {
                    $btn.removeClass('dcm-running');
                    showToast(response.data.message || dcmAdmin.i18n.error, 'error');
                }
            },
            error: function() {
                $btn.removeClass('dcm-running');
                showToast(dcmAdmin.i18n.error, 'error');
            }
        });
    });

    /**
     * Empty all trash
     */
    $('#dcm-empty-trash').on('click', function() {
        if (!confirm(dcmAdmin.i18n.confirmEmptyTrash)) {
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true).text(dcmAdmin.i18n.running);

        $.ajax({
            url: dcmAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dragoncronmanager_empty_trash',
                nonce: dcmAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showToast(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    $btn.prop('disabled', false).text('Empty Trash');
                    showToast(response.data.message || dcmAdmin.i18n.error, 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Empty Trash');
                showToast(dcmAdmin.i18n.error, 'error');
            }
        });
    });

    /**
     * Clear logs
     */
    $('#dcm-clear-logs').on('click', function() {
        if (!confirm(dcmAdmin.i18n.confirmClear)) {
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true).text(dcmAdmin.i18n.running);

        $.ajax({
            url: dcmAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dragoncronmanager_clear_logs',
                nonce: dcmAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showToast(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    $btn.prop('disabled', false).text('Clear Logs');
                    showToast(response.data.message || dcmAdmin.i18n.error, 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Clear Logs');
                showToast(dcmAdmin.i18n.error, 'error');
            }
        });
    });

})(jQuery);
