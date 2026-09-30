<?php
/**
 * DuaRTE — Multi-criteria priority scoring for pending requisitions.
 *
 * requisition/pending.php used to list requests in pure "oldest first"
 * order. That's fine until two people want the same scarce item at
 * once — then submission order alone can hand out the last jack to
 * whoever happened to click first, ahead of someone whose need is
 * more urgent or who has never once returned something late.
 *
 * This scores each pending requisition on three criteria:
 *
 *   - Stock (Scarcity: 45%) — how tight supply is for what it's asking for,
 *                      i.e. requested qty vs. what's actually left
 *                      once other pending/approved requests are
 *                      accounted for (reserved_stock_for()).
 *   - Demand (Contention: 35%) — how many other requesters currently
 *                      have a pending requisition for the same item.
 *   - Trust (Reliability: 20%) — the requester's track record with
 *                      borrowed tools (late returns and missing loans).
 *
 * A Field Supervisor can also manually mark a requisition
 * `manual_urgent` with a required, logged reason (requisition/view.php).
 *
 * Each is normalized to 0–1 and combined with weights into one 0–100
 * score.
 */

require_once __DIR__ . '/loans.php';

const PRIORITY_WEIGHT_SCARCITY    = 0.45;
const PRIORITY_WEIGHT_CONTENTION  = 0.35;
const PRIORITY_WEIGHT_RELIABILITY = 0.20;

// A tool that's still missing counts this many times worse than one
// late return that eventually came back — see get_missing_loan_counts().
const PRIORITY_MISSING_LOAN_WEIGHT = 2;

/**
 * Retrieve dynamic MCDA weights from system_settings, or fallback to default constants.
 */
function get_mcda_weights(PDO $pdo): array
{
    static $cached_weights = null;
    if ($cached_weights !== null) {
        return $cached_weights;
    }

    $weights = [
        'scarcity'    => PRIORITY_WEIGHT_SCARCITY,
        'contention'  => PRIORITY_WEIGHT_CONTENTION,
        'reliability' => PRIORITY_WEIGHT_RELIABILITY,
        'preset'      => 'standard',
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'mcda_%'");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($rows) {
            if (isset($rows['mcda_weight_scarcity']) && is_numeric($rows['mcda_weight_scarcity'])) {
                $weights['scarcity'] = max(0.0, min(1.0, (float)$rows['mcda_weight_scarcity']));
            }
            if (isset($rows['mcda_weight_contention']) && is_numeric($rows['mcda_weight_contention'])) {
                $weights['contention'] = max(0.0, min(1.0, (float)$rows['mcda_weight_contention']));
            }
            if (isset($rows['mcda_weight_reliability']) && is_numeric($rows['mcda_weight_reliability'])) {
                $weights['reliability'] = max(0.0, min(1.0, (float)$rows['mcda_weight_reliability']));
            }
            if (isset($rows['mcda_active_preset'])) {
                $weights['preset'] = $rows['mcda_active_preset'];
            }

            // Normalize weights dynamically so their sum strictly equals 1.0 (100%)
            $total_w = $weights['scarcity'] + $weights['contention'] + $weights['reliability'];
            if ($total_w > 0) {
                $weights['scarcity']    = round($weights['scarcity'] / $total_w, 4);
                $weights['contention']  = round($weights['contention'] / $total_w, 4);
                $weights['reliability'] = round($weights['reliability'] / $total_w, 4);
            }
        }
    } catch (Throwable $e) {
        // Table does not exist or query failed: use standard defaults
    }

    $cached_weights = $weights;
    return $weights;
}

/**
 * Scores every currently-pending requisition and returns them ranked
 * highest-priority first. Each returned row keeps its original
 * columns plus: score (0-100), and the per-criterion 0-1 values that
 * fed into it, so the UI can show *why* something ranked where it did
 * instead of just a bare number.
 *
 * @param array $requisitions rows from requisitions (must include id, created_at, requester_id)
 */
function score_pending_requisitions(PDO $pdo, array $requisitions): array
{
    if (!$requisitions) {
        return [];
    }

    $mcda_weights  = get_mcda_weights($pdo);
    $w_scarcity    = $mcda_weights['scarcity'];
    $w_contention  = $mcda_weights['contention'];
    $w_reliability = $mcda_weights['reliability'];

    $late_counts = get_late_return_counts($pdo);
    $missing_counts = get_missing_loan_counts($pdo);
    $ids = array_column($requisitions, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Line items for every candidate requisition in one query, rather
    // than one query per requisition. variant_selected is pulled too:
    // items with variant_label (fuses, PPE sizes, ...) keep separate
    // stock and reservations *per variant* (see available_stock_for()
    // in includes/functions.php) — scoring by item_id alone would mix
    // unrelated variants' stock together.
    $stmt = $pdo->prepare(
        "SELECT requisition_id, item_id, variant_selected, quantity_requested
         FROM requisition_items
         WHERE requisition_id IN ($placeholders) AND item_id IS NOT NULL"
    );
    $stmt->execute($ids);
    $lines_by_req = [];
    foreach ($stmt->fetchAll() as $row) {
        $lines_by_req[$row['requisition_id']][] = $row;
    }

    // Key a line by item + variant so different variants of the same
    // item never share a stock/contention bucket. Matches the null-vs-
    // value split reserved_stock_for() already uses.
    $line_key = fn(array $line): string => $line['item_id'] . '::' . ($line['variant_selected'] ?? '');

    // Distinct items touched by any candidate, so stock levels and
    // contention counts can be looked up once per item, not once per line.
    $item_ids = array_values(array_unique(array_filter(array_merge(
        ...array_map(fn($ls) => array_column($ls, 'item_id'), $lines_by_req ?: [[]])
    ))));

    $on_hand_by_item   = [];  // item_id => items.quantity_on_hand (cross-variant total; also the
                               // right number for items with no variant_label at all)
    $on_hand_by_key    = [];  // "item_id::variant_value" => that variant's own quantity_on_hand
    $reserved_by_key   = [];  // "item_id::variant_selected" (variant_selected may be '') => reserved qty, everyone
    $reserved_by_key_requester = []; // same key => [requester_id => that requester's own reserved qty]
    $pending_requesters_by_key = []; // same key => count of DISTINCT REQUESTERS with a pending requisition wanting it
    if ($item_ids) {
        $ph = implode(',', array_fill(0, count($item_ids), '?'));

        $stmt = $pdo->prepare("SELECT id, quantity_on_hand FROM items WHERE id IN ($ph)");
        $stmt->execute($item_ids);
        foreach ($stmt->fetchAll() as $row) {
            $on_hand_by_item[$row['id']] = (int)$row['quantity_on_hand'];
        }

        // Per-variant stock, for lines whose item has variant_label set.
        // Falls back to on_hand_by_item below for lines with no variant.
        $stmt = $pdo->prepare("SELECT item_id, variant_value, quantity_on_hand FROM item_variants WHERE item_id IN ($ph)");
        $stmt->execute($item_ids);
        foreach ($stmt->fetchAll() as $row) {
            $on_hand_by_key[$row['item_id'] . '::' . $row['variant_value']] = (int)$row['quantity_on_hand'];
        }

        // Reserved qty, batched in one query and grouped by variant AND
        // requester (mirrors reserved_stock_for()'s IS NULL / = :variant
        // split, plus a per-requester breakdown). The per-requester
        // breakdown lets the scoring loop below exclude a requester's
        // OWN total reservation for an item — across every pending/
        // approved requisition they have for it, not just the one line
        // being scored. Without that, a requester who splits one need
        // into several duplicate requisitions for the same item only
        // had their current line's own qty excluded, so their other
        // duplicates counted as "competing" reservations and inflated
        // their own scarcity score — the more duplicates, the higher
        // it climbed.
        $stmt = $pdo->prepare(
            "SELECT ri.item_id, ri.variant_selected, r.requester_id, SUM(ri.quantity_requested) reserved
             FROM requisition_items ri
             JOIN requisitions r ON r.id = ri.requisition_id
             WHERE ri.item_id IN ($ph) AND r.status IN ('pending', 'approved')
             GROUP BY ri.item_id, ri.variant_selected, r.requester_id"
        );
        $stmt->execute($item_ids);
        $reserved_by_key_requester = []; // key => [requester_id => reserved]
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['item_id'] . '::' . ($row['variant_selected'] ?? '');
            $reserved_by_key[$key] = ($reserved_by_key[$key] ?? 0) + (int)$row['reserved'];
            $reserved_by_key_requester[$key][(int)$row['requester_id']] = (int)$row['reserved'];
        }

        // Contention, grouped by variant too — a request for a 10A
        // fuse doesn't compete with one for a 30A fuse even though
        // both are the same item_id. Counts DISTINCT REQUESTERS, not
        // distinct requisitions — otherwise one requester filing
        // several duplicate requisitions for the same scarce item
        // inflated the contention score for their own requests (and
        // everyone else's) just by existing, regardless of whether any
        // other person actually wanted the item.
        $stmt = $pdo->prepare(
            "SELECT ri.item_id, ri.variant_selected, COUNT(DISTINCT r.requester_id) c
             FROM requisition_items ri
             JOIN requisitions r ON r.id = ri.requisition_id
             WHERE ri.item_id IN ($ph) AND r.status = 'pending'
             GROUP BY ri.item_id, ri.variant_selected"
        );
        $stmt->execute($item_ids);
        foreach ($stmt->fetchAll() as $row) {
            $pending_requesters_by_key[$row['item_id'] . '::' . ($row['variant_selected'] ?? '')] = (int)$row['c'];
        }
    }

    $scored = [];
    foreach ($requisitions as $r) {
        $lines = $lines_by_req[$r['id']] ?? [];

        // --- Stock (Scarcity): Robust line-weighted calculation
        // Blends 70% quantity-weighted average with 30% peak item scarcity.
        // Prevents a requester from artificially gaming the MCDA score by
        // appending 1 unit of a scarce item to a massive bulk order of common goods.
        $scarcity_peak = 0.0;
        $scarcity_weighted_sum = 0.0;
        $total_qty = 0;

        foreach ($lines as $line) {
            $key = $line_key($line);
            // A variant's own stock if this line picked one and the
            // item actually has per-variant rows; otherwise the
            // item's own (shared) total.
            $on_hand = $on_hand_by_key[$key] ?? $on_hand_by_item[$line['item_id']] ?? 0;
            $reserved_total = $reserved_by_key[$key] ?? 0; // includes this requester's own reservations
            $reserved_own   = $reserved_by_key_requester[$key][$r['requester_id']] ?? 0;
            $raw_qty = max(1, (int)$line['quantity_requested']);

            // Cap the quantity used for scoring at total on-hand: a
            // request for more than the entire stock isn't scored any
            // scarcer than a request for all of it.
            $capped_qty = min($raw_qty, max(1, $on_hand));

            $reserved_others = max(0, $reserved_total - $reserved_own);
            $remaining_before_this = max(0, $on_hand - $reserved_others);
            $ratio = $remaining_before_this > 0
                ? min(1.0, $capped_qty / $remaining_before_this)
                : 1.0;

            $scarcity_peak = max($scarcity_peak, $ratio);
            $scarcity_weighted_sum += ($ratio * $raw_qty);
            $total_qty += $raw_qty;
        }

        $scarcity_avg = ($total_qty > 0) ? ($scarcity_weighted_sum / $total_qty) : $scarcity_peak;
        $scarcity = min(1.0, (0.70 * $scarcity_avg) + (0.30 * $scarcity_peak));

        // --- Demand (Contention): Robust line-weighted calculation
        // Blends 70% quantity-weighted average contention with 30% peak contention.
        $contention_peak = 0.0;
        $contention_weighted_sum = 0.0;
        foreach ($lines as $line) {
            $count = $pending_requesters_by_key[$line_key($line)] ?? 1;
            $contenders = max(0, $count - 1); // exclude this requester
            $c_ratio = min(1.0, $contenders / 3);

            $raw_qty = max(1, (int)$line['quantity_requested']);
            $contention_peak = max($contention_peak, $c_ratio);
            $contention_weighted_sum += ($c_ratio * $raw_qty);
        }
        $contention_avg = ($total_qty > 0) ? ($contention_weighted_sum / $total_qty) : $contention_peak;
        $contention = min(1.0, (0.70 * $contention_avg) + (0.30 * $contention_peak));

        // --- Trust (Reliability): fewer past late returns and fewer currently-
        // missing loans = higher score.
        $late = $late_counts[$r['requester_id']] ?? 0;
        $missing = $missing_counts[$r['requester_id']] ?? 0;
        $penalty = $late + (PRIORITY_MISSING_LOAN_WEIGHT * $missing);
        $reliability = 1 / (1 + ($penalty / 3));

        $score = 100 * (
            $w_scarcity    * $scarcity +
            $w_contention  * $contention +
            $w_reliability * $reliability
        );

        $scored[] = $r + [
            'priority_score'       => round($score, 1),
            'priority_stock'       => round($scarcity * 100),
            'priority_demand'      => round($contention * 100),
            'priority_trust'       => round($reliability * 100),
            'mcda_weights'         => [
                'scarcity'    => round($w_scarcity * 100),
                'contention'  => round($w_contention * 100),
                'reliability' => round($w_reliability * 100),
                'preset'      => $mcda_weights['preset'] ?? 'standard',
            ],
            // Aliases kept for backward compatibility if referenced
            'priority_scarcity'    => round($scarcity * 100),
            'priority_contention'  => round($contention * 100),
            'priority_reliability' => round($reliability * 100),
        ];
    }

    usort($scored, function (array $a, array $b): int {
        // Manually-flagged urgent requests always rank first — a human
        // judgment call the algorithm can't weigh against a score.
        $a_urgent = !empty($a['manual_urgent']);
        $b_urgent = !empty($b['manual_urgent']);
        if ($a_urgent !== $b_urgent) {
            return $a_urgent ? -1 : 1;
        }
        $score_cmp = $b['priority_score'] <=> $a['priority_score'];
        if ($score_cmp !== 0) {
            return $score_cmp;
        }
        // Tie-breaker: oldest request first (FIFO)
        return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
    });

    return $scored;
}

/** Badge tone for a 0-100 priority score, matching existing badge classes. */
function priority_score_class(float $score): string
{
    if ($score >= 66) {
        return 'inactive'; // reuses the existing "red/urgent" badge tone
    }
    if ($score >= 33) {
        return 'role'; // existing "amber/neutral" tone
    }
    return 'active'; // existing "green/low-pressure" tone
}
