# Dragon Cron Manager

See, run, and debug everything WP-Cron is doing — with a doctor that tells you *why* it's stuck, not just that it is.

## The dashboard
**Tools → Cron Manager** lists every scheduled event: hook, schedule, next run, arguments. Per event:
- **Run** — execute now and reschedule the next run.
- **Test** — execute without touching the schedule (safe for debugging).
- **Trash** — deleted events go to a 30-day trash and can be restored.

The **Execution Log** tab records when events ran, how long they took, and errors. **Schedules** lists every registered interval.

## The cron doctor
When something's overdue, press **Diagnose** in the health bar. It runs a live root-cause check:
- **DISABLE_WP_CRON set but tasks overdue** → your server cron isn't firing; the diagnosis includes the exact crontab line to add.
- **Site can't reach its own wp-cron.php** → you see the exact loopback error (basic auth, firewall, DNS…).
- **A crashed run holding the lock** → points you at the log to find the fatal task.
- **Everything works but the queue is starved** → the low-traffic-site case; the diagnosis itself kicks the queue, and recommends a server cron.

## Data & privacy
The execution log lives in your database with automatic pruning. **Uninstalling keeps data by default** (opt-in delete: `wp option update dragoncronmanager_delete_data_on_uninstall 1`).
