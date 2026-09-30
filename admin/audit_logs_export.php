<?php
/**
 * DuaRTE — Audit Log CSV export. Applies the same filters as
 * admin/audit_logs.php (passed through as the same query params) but
 * with no page cap, so an admin can pull the full filtered history
 * for an external review or incident investigation.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$pdo = get_db();

$search        = trim($_GET['q'] ?? '');
$action_filter = $_GET['action'] ?? '';
$entity_filter = $_GET['entity'] ?? '';
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

$where = ' WHERE 1=1';
$params = [];

if ($search !== '') {
    // Each LIKE needs its own placeholder — PDO throws "Invalid parameter
    // number" if the same named placeholder appears more than once.
    $where .= ' AND (actor_name_snapshot LIKE :q1 OR description LIKE :q2 OR ip_address LIKE :q3)';
    $params['q1'] = '%' . $search . '%';
    $params['q2'] = '%' . $search . '%';
    $params['q3'] = '%' . $search . '%';
}
if ($action_filter !== '') {
    $where .= ' AND action = :action';
    $params['action'] = $action_filter;
}
if ($entity_filter !== '') {
    $where .= ' AND entity_type = :entity';
    $params['entity'] = $entity_filter;
}
if ($from !== '') {
    $where .= ' AND created_at >= :from';
    $params['from'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where .= ' AND created_at <= :to';
    $params['to'] = $to . ' 23:59:59';
}

$stmt = $pdo->prepare("SELECT * FROM audit_logs" . $where . " ORDER BY created_at ASC, id ASC");
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="duarte-audit-log-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Date & Time', 'Action', 'Actor', 'Actor Role', 'Entity Type', 'Entity ID', 'Description', 'IP Address']);
foreach ($stmt as $row) {
    fputcsv($out, csv_row([
        $row['created_at'],
        $row['action'],
        $row['actor_name_snapshot'],
        $row['actor_role_snapshot'],
        $row['entity_type'],
        $row['entity_id'],
        $row['description'],
        $row['ip_address'],
    ]));
}
fclose($out);
