/**
 * Dragon Cron Manager - Admin JavaScript
 */
(function($) {
    'use strict';

    /**
     * Show toast notification
     */
    function showToast(message, type) {
        // Server messages are plain text; set them with .text(), never as HTML.
        const $toast = $('<div>').addClass('dcm-toast dcm-toast-' + type).text(message);
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
                    $btn.prop('disabled', false).text(dcmAdmin.i18n.emptyTrash);
                    showToast(response.data.message || dcmAdmin.i18n.error, 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(dcmAdmin.i18n.emptyTrash);
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
                    $btn.prop('disabled', false).text(dcmAdmin.i18n.clearLogs);
                    showToast(response.data.message || dcmAdmin.i18n.error, 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(dcmAdmin.i18n.clearLogs);
                showToast(dcmAdmin.i18n.error, 'error');
            }
        });
    });

    /**
     * Cron doctor: run the diagnosis and render findings.
     * Findings are rendered with .text() — server strings only, no HTML.
     */
    $(document).on('click', '.dcm-diagnose-btn', function() {
        const $btn = $(this);
        const $panel = $('.dcm-doctor-results');
        const $findings = $panel.find('.dcm-doctor-findings');

        $btn.prop('disabled', true).text(dcmAdmin.i18n.diagnosing);

        $.ajax({
            url: dcmAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'dragoncronmanager_diagnose',
                nonce: dcmAdmin.nonce
            },
            success: function(response) {
                if (!response.success) {
                    showToast((response.data && response.data.message) || dcmAdmin.i18n.error, 'error');
                    return;
                }
                $findings.empty();
                $panel.find('.dcm-doctor-last-activity').text(response.data.last_activity || '');
                (response.data.findings || []).forEach(function(f) {
                    const $card = $('<div>').addClass('dcm-doctor-finding dcm-doctor-' + f.severity);
                    $('<strong>').text(f.title).appendTo($card);
                    $('<p>').text(f.detail).appendTo($card);
                    if (f.fix) {
                        $('<p>').addClass('description').text(f.fix).appendTo($card);
                    }
                    $findings.append($card);
                });
                $panel.prop('hidden', false);
            },
            error: function() {
                showToast(dcmAdmin.i18n.error, 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).text(dcmAdmin.i18n.diagnose);
            }
        });
    });

    /**
     * Toggle the Add Event form.
     */
    $(document).on('click', '#dcm-add-event-toggle', function() {
        const $form = $('#dcm-add-event-form');
        const isHidden = $form.prop('hidden');
        $form.prop('hidden', !isHidden);
        $(this).attr('aria-expanded', isHidden ? 'true' : 'false');
    });

    /**
     * Submit a new scheduled event.
     */
    $(document).on('click', '#dcm-ae-submit', function() {
        const $btn = $(this);
        const hook = ($('#dcm-ae-hook').val() || '').trim();
        const schedule = $('#dcm-ae-schedule').val() || '';
        const timeVal = $('#dcm-ae-time').val() || '';
        const args = ($('#dcm-ae-args').val() || '').trim() || '[]';

        if (!hook) {
            showToast(dcmAdmin.i18n.enterHook, 'error');
            return;
        }

        // Convert the browser-local datetime into an absolute unix timestamp so
        // the server schedules the correct moment regardless of its timezone.
        let timestamp = '';
        if (timeVal) {
            const parsed = new Date(timeVal).getTime();
            if (!isNaN(parsed)) {
                timestamp = Math.floor(parsed / 1000);
            }
        }

        $btn.prop('disabled', true);

        $.ajax({
            url: dcmAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dragoncronmanager_add_event',
                nonce: dcmAdmin.nonce,
                hook: hook,
                schedule: schedule,
                timestamp: timestamp,
                args: args
            },
            success: function(response) {
                if (response.success) {
                    showToast(response.data.message, 'success');
                    location.reload();
                } else {
                    showToast((response.data && response.data.message) || dcmAdmin.i18n.error, 'error');
                }
            },
            error: function() {
                showToast(dcmAdmin.i18n.error, 'error');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

})(jQuery);
