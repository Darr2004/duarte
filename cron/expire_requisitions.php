<?php
/**
 * DuaRTE — Stale requisition expiry.
 *
 * A requisition is only valid on the day it was submitted. This is
 * already enforced on every page load (see includes/header.php), so
 * this script is optional — but running it on a schedule keeps the
 * status current even on the rare page nobody visits right after
 * midnight:
 *
 *   5 0 * * * php /path/to/duarte/cron/expire_requisitions.php >> /var/log/duarte-cron.log 2>&1
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
require_once __DIR__ . '/../includes/functions.php';

$pdo = get_db();
$count = expire_stale_requisitions($pdo);

echo date('Y-m-d H:i:s') . " — expired {$count} stale requisition(s) from previous days.\n";
