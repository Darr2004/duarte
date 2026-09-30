<?php
/**
 * DuaRTE — System Audit Log.
 *
 * log_audit_event() is the ONLY function that should ever write to
 * audit_logs, the same way record_stock_movement() (includes/stock.php)
 * is the only writer for stock_movements. Call it right at the point a
 * transaction actually completes (after the INSERT/UPDATE commits),
 * with the person who caused it and a plain-language description —
 * the admin audit log page (admin/audit_logs.php) is only as useful
 * as what gets recorded here.
 */

require_once __DIR__ . '/functions.php';

/**
 * @param PDO        $pdo
 * @param array|null $actor        current_user()-shaped array, or null for
 *                                  system/unauthenticated events (e.g. a
 *                                  failed login for a username that may not
 *                                  even exist).
 * @param string     $action       Short machine tag, e.g. 'login_success',
 *                                  'user_create', 'item_update',
 *                                  'requisition_approve', 'stock_in'.
 * @param string     $entity_type  What kind of thing this happened to,
 *                                  e.g. 'user', 'item', 'requisition'.
 * @param int|null   $entity_id    Primary key of that thing, if any.
 * @param string     $description  One-line, human-readable summary shown
 *                                  directly in the audit log table.
 */
function log_audit_event(
    PDO $pdo,
    ?array $actor,
    string $action,
    string $entity_type,
    ?int $entity_id,
    string $description
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs
            (actor_id, actor_name_snapshot, actor_role_snapshot, action, entity_type, entity_id, description, ip_address)
         VALUES (:actor_id, :actor_name, :actor_role, :action, :entity_type, :entity_id, :description, :ip)'
    );
    $stmt->execute([
        'actor_id'    => $actor['id'] ?? null,
        'actor_name'  => $actor['full_name'] ?? 'System',
        'actor_role'  => $actor['role'] ?? null,
        'action'      => $action,
        'entity_type' => $entity_type,
        'entity_id'   => $entity_id,
        'description' => $description,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/**
 * Human-readable label for an audit action tag, for display in the
 * admin audit log page and its filter dropdown.
 */
/**
 * Compact one-line form of an account-change audit description, for
 * dashboard panels. Display only — the stored description (shown in full
 * on the Audit Log page) is never modified.
 *
 *   System Administrator updated account "driverhelper3" (password reset).
 *     => driverhelper3 (password reset)
 *   Renamed account from "Aquino,M" to "Aquino,Mayeng".
 *     => Aquino,M → Aquino,Mayeng
 */
function audit_short_account_change(string $description, string $actor = ''): string
{
    $d = trim($description);
    // The actor is shown separately ("by ..."), so drop it from the sentence.
    if ($actor !== '' && str_starts_with($d, $actor . ' ')) {
        $d = substr($d, strlen($actor) + 1);
    }
    if (preg_match('/^Renamed account from "(.+)" to "(.+)"\.?$/u', $d, $m)) {
        return $m[1] . ' → ' . $m[2];
    }
    // The badge already says what happened (created / updated / ...).
    $d = preg_replace('/^(?:updated|created|deactivated|activated|reset|changed)\s+(?:the\s+)?account\s+/iu', '', $d);
    $d = preg_replace('/\(role changed from .+? to (.+)\)/u', '(role → $1)', $d);
    $d = str_replace('"', '', $d);
    return rtrim($d, ". ");
}

function audit_action_label(string $action): string
{
    return match ($action) {
        'login_success'         => 'Login',
        'login_failed'          => 'Failed login',
        'logout'                => 'Logout',
        'user_create'           => 'Account created',
        'user_update'           => 'Account updated',
        'user_activate'         => 'Account activated',
        'user_deactivate'       => 'Account deactivated',
        'user_password_change'  => 'Password changed',
        'user_avatar_update'    => 'Profile picture updated',
        'item_create'           => 'Item added',
        'item_update'           => 'Item updated',
        'category_create'       => 'Category added',
        'category_update'       => 'Category updated',
        'category_delete'       => 'Category deleted',
        'stock_in'              => 'Stock in',
        'stock_adjustment'      => 'Stock adjustment',
        'requisition_approve'   => 'Request approved',
        'requisition_decline'   => 'Request declined',
        'requisition_cancel'    => 'Request cancelled',
        'requisition_release'   => 'Request released',
        'requisition_self_decide_blocked' => 'Self-approval blocked',
        'requisition_urgent_flag'   => 'Request flagged urgent',
        'requisition_urgent_unflag' => 'Urgent flag removed',
        'tool_return'           => 'Tool returned',
        'item_request_create'   => 'Item request submitted',
        'item_request_fulfill'  => 'Item request fulfilled',
        'item_request_reject'   => 'Item request closed',
        'asset_register'        => 'Asset registered',
        'asset_checkout'        => 'Asset checked out',
        'asset_checkin'         => 'Asset checked in',
        'asset_transfer'        => 'Asset transferred',
        'asset_damage_report'   => 'Asset damage reported',
        'asset_maintenance_complete' => 'Asset maintenance completed',
        'asset_retire'          => 'Asset retired',
        'asset_audit'           => 'Asset audit completed',
        default                 => ucwords(str_replace('_', ' ', $action)),
    };
}

/**
 * CSS badge class for an audit action, grouped by how sensitive the
 * event is — kept separate from audit_action_label() so the page can
 * style a whole family of actions (e.g. every failed/destructive one)
 * the same way without a giant match statement in the view itself.
 */
function audit_action_class(string $action): string
{
    if (in_array($action, ['login_failed', 'user_deactivate', 'requisition_decline', 'category_delete', 'item_request_reject', 'requisition_self_decide_blocked', 'asset_damage_report'], true)) {
        return 'inactive';
    }
    if (in_array($action, ['login_success', 'user_activate', 'requisition_approve', 'requisition_release', 'stock_in', 'tool_return', 'item_request_fulfill', 'asset_register', 'asset_checkin', 'asset_maintenance_complete'], true)) {
        return 'active';
    }
    return 'role';
}
