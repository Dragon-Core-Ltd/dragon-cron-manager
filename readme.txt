=== Dragon Cron Manager ===
Contributors: dragoncore
Tags: cron, scheduled tasks, wp-cron, debug, developer
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

View, manage, and debug WordPress cron jobs with ease. Features trash bin recovery, test mode, and execution logging.

== Description ==

Dragon Cron Manager gives you complete visibility and control over WordPress scheduled tasks (WP-Cron).

**Key Features:**

* **Cron Dashboard** - See all scheduled events with hook names, schedules, and next run times
* **Run Now** - Execute any cron job and automatically reschedule the next run
* **Test Mode** - Run a cron without changing its schedule (perfect for debugging)
* **Trash Bin** - Deleted crons go to trash and can be restored for 30 days
* **Execution Log** - Track when crons ran, how long they took, and any errors
* **Health Check** - Detect issues like disabled WP-Cron or overdue events
* **Schedules Overview** - See all registered cron intervals

**What makes it different:**

* **Trash bin recovery** - Accidentally deleted a cron? Restore it with one click. Other plugins delete permanently.
* **Test mode** - Run a cron without rescheduling. Great for debugging without disrupting schedules.
* **Clear tooltips** - Every action button explains what it does on hover.
* **Modern UI** - Clean, intuitive interface that matches WordPress admin styling.

**Perfect for:**

* Debugging cron-related issues
* Monitoring plugin scheduled tasks
* Safely cleaning up orphaned cron jobs
* Testing cron handlers during development
* Recovering accidentally deleted cron events

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/dragon-cron-manager/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Tools → Cron Manager to view your scheduled tasks

== Frequently Asked Questions ==

= Is WP-Cron the same as system cron? =

No. WP-Cron is WordPress's built-in task scheduler that runs on page loads. For high-traffic or mission-critical sites, it's recommended to disable WP-Cron and use a real system cron instead.

= What's the difference between Run and Test? =

**Run** executes the cron and reschedules the next run based on now + interval. For example, an hourly cron run at 2:30pm will next run at 3:30pm.

**Test** executes the cron but leaves the schedule unchanged. The original next run time stays the same. This is useful for debugging without affecting the normal schedule.

= Can I recover a deleted cron event? =

Yes! Unlike other cron plugins, Dragon Cron Manager has a Trash Bin. Deleted crons are kept for 30 days and can be restored with one click. After 30 days, they are automatically purged.

= Can I break my site by deleting cron events? =

Core WordPress cron events are protected - you cannot trash them. Plugin crons can be trashed safely and restored if needed.

= Does the execution log slow down my site? =

No. Logging is lightweight and old entries are automatically cleaned up based on your retention settings.

= What does "overdue" mean? =

A cron is overdue when its scheduled time has passed but it hasn't run yet. This usually happens because WP-Cron only runs when someone visits your site. In development environments with no traffic, crons can pile up.

== Screenshots ==

1. Main dashboard showing all scheduled cron events with Run, Test, and Trash buttons
2. Trash bin with restore and permanent delete options
3. Execution history log showing duration and status
4. Health check status bar

== Changelog ==

= 1.0.2 =
* Fix: settings and trashed-cron recovery data are now carried safely on a deactivate then reactivate update.

= 1.0.1 =
* Renamed all option, hook, function and constant prefixes to the unique `dragoncronmanager_` / `DRAGONCRONMANAGER_` prefix. Existing settings and cleanup schedules are migrated automatically on update; the log table is unaffected.

= 1.0.0 =
* Initial release
* View all scheduled cron events
* Run cron jobs manually with automatic rescheduling
* Test mode - run without rescheduling
* Trash bin with 30-day recovery
* Restore cron events from trash
* Permanent delete from trash
* Empty trash (bulk delete)
* Execution history logging with duration and error tracking
* WP-Cron health check (overdue detection)
* Registered schedules overview
* Core cron protection (cannot trash WordPress system crons)
* Automatic trash cleanup after 30 days
* Tooltips explaining each action

== Upgrade Notice ==

= 1.0.0 =
Initial release.
