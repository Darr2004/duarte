<?php
/**
 * DuaRTE — Overdue tool loan check.
 *
 * Run this on a schedule (e.g. hourly) so overdue alerts go out even
 * when nobody happens to be viewing inventory/loans.php:
 *
 *   0 * * * * php /path/to/duarte/cron/check_overdue.php >> /var/log/duarte-cron.log 2>&1
 *
 * Safe to run as often as you like — check_overdue_loans() only
 * notifies once per loan (see tool_loans.overdue_notified_at).
 */

// Belt-and-suspenders alongside cron/.htaccess: that file only blocks
// access under Apache, so this check stops the script from doing
// anything if it's ever reached over HTTP on a different web server.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script is for command-line/cron use only.');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/loans.php';

$pdo = get_db();
$count = check_overdue_loans($pdo);

echo date('Y-m-d H:i:s') . " — checked for overdue tool loans, {$count} newly flagged.\n";
