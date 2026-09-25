# Dragon Cron Manager

See, run, and debug everything WP-Cron is doing - with a doctor that tells you *why* it's stuck, not just that it is.

## The dashboard
**Tools → Cron Manager** lists every scheduled event: hook, schedule, next run, arguments. Per event:
- **Run** - execute now and reschedule the next run. The existing booking is not removed until the new one is confirmed in the schedule; if WordPress refuses the new booking, the existing one is left in place and the reason is shown. A one-time event is removed from the schedule when you run it (as WP-Cron does), so it does not run a second time; **Test** keeps it. Events WordPress core schedules itself are protected from the trash; plugin events are not, even when their names start with `wp_`.
- **Test** - execute without touching the schedule (safe for debugging).
- **Trash** - deleted events go to a 30-day trash and can be restored.

The **Execution Log** tab records when events ran, how long they took, and errors. Times are shown in your site's time zone. **Schedules** lists every registered interval.

### Add Event
The **Add Event** button on the dashboard schedules a brand-new cron event without touching code: give it a hook name, a schedule (single or recurring), a first-run time, and optional JSON arguments. The first-run time is read in your site's time zone (shown next to the field), not your browser's.

### Real-tick logging
The Run Log now records automatic WP-Cron runs as they happen - with duration and success/failure - not just manual **Run Now** and **Test** runs. A **Source** column marks each entry **Automatic** or **Manual**, and the cron doctor's "last activity" reflects real scheduled runs.

## The cron doctor
When something's overdue, press **Diagnose** in the health bar. It runs a live root-cause check:
- **DISABLE_WP_CRON set but tasks overdue** → your server cron isn't firing; the diagnosis includes the exact crontab line to add.
- **Site can't reach its own wp-cron.php** → you see the exact loopback error (basic auth, firewall, DNS…).
- **A crashed run holding the lock** → points you at the log to find the fatal task.
- **Everything works but the queue is starved** → the low-traffic-site case; the diagnosis confirms the site can reach its own wp-cron.php (it does not run the queue itself: overdue tasks run on the next visit, or use **Run** on one), and recommends a server cron.

## Data & privacy
The execution log lives in your database; a daily cleanup deletes entries older than 7 days, measured in your site's time zone (change it with `wp option update dragoncronmanager_log_retention_days <days>`). **Uninstalling keeps data by default** (opt-in delete: `wp option update dragoncronmanager_delete_data_on_uninstall 1`).
