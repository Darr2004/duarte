<?php
/**
 * DuaRTE — Practical Multi-Criteria Decision Analysis (MCDA) Priority Scoring
 *
 * Operational Trucking Decision Matrix:
 *   1. Sira ng Sasakyan / Urgency (Vehicle Defect Urgency: 45%)
 *      - Gaano kalala ang sira ng truck batay sa Complaints Checklist
 *        (Emergency breakdown / tirik tulad ng starter relay, makina, preno
 *        vs. routine preventive maintenance / oil change).
 *   2. Schedule ng Biyahe (Trip / Dispatch Schedule: 35%)
 *      - Status ng biyahe: nasiraan sa kalsada / on-trip, nakatakdang
 *        aalis na delivery dispatch, o nakatambay sa garahe.
 *   3. Rekord ng Driver (Driver Accountability / Trust: 20%)
 *      - Kasaysayan ng driver sa maayos at maagap na pagsasauli ng
 *        hiniram na tools mula sa bodega (walang overdue o nawawalang gamit).
 *
 * Automatically combines criteria into a 0–100 composite score to rank
 * pending requisitions in real-time.
 */

require_once __DIR__ . '/loans.php';

const PRIORITY_WEIGHT_URGENCY     = 0.45; // Sira ng Sasakyan / Breakdown Urgency
const PRIORITY_WEIGHT_TRIP        = 0.35; // Schedule ng Biyahe / Dispatch Schedule
const PRIORITY_WEIGHT_TRUST       = 0.20; // Rekord ng Driver / Accountability

// Aliases for full backward compatibility
const PRIORITY_WEIGHT_SCARCITY    = PRIORITY_WEIGHT_URGENCY;
const PRIORITY_WEIGHT_CONTENTION  = PRIORITY_WEIGHT_TRIP;
const PRIORITY_WEIGHT_RELIABILITY = PRIORITY_WEIGHT_TRUST;

const PRIORITY_MISSING_LOAN_WEIGHT = 2;

/**
 * Retrieve dynamic MCDA weights from system_settings, or fallback to defaults.
 */
function get_mcda_weights(PDO $pdo): array
{
    static $cached_weights = null;
    if ($cached_weights !== null) {
        return $cached_weights;
    }

    $weights = [
        'urgency'     => PRIORITY_WEIGHT_URGENCY,
        'trip'        => PRIORITY_WEIGHT_TRIP,
        'trust'       => PRIORITY_WEIGHT_TRUST,
        'scarcity'    => PRIORITY_WEIGHT_URGENCY,
        'contention'  => PRIORITY_WEIGHT_TRIP,
        'reliability' => PRIORITY_WEIGHT_TRUST,
        'preset'      => 'standard',
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'mcda_%'");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($rows) {
            $w_u = $rows['mcda_weight_urgency'] ?? $rows['mcda_weight_scarcity'] ?? null;
            if ($w_u !== null && is_numeric($w_u)) {
                $weights['urgency']  = max(0.0, min(1.0, (float)$w_u));
                $weights['scarcity'] = $weights['urgency'];
            }

            $w_t = $rows['mcda_weight_trip'] ?? $rows['mcda_weight_contention'] ?? null;
            if ($w_t !== null && is_numeric($w_t)) {
                $weights['trip']       = max(0.0, min(1.0, (float)$w_t));
                $weights['contention'] = $weights['trip'];
            }

            $w_tr = $rows['mcda_weight_trust'] ?? $rows['mcda_weight_reliability'] ?? null;
            if ($w_tr !== null && is_numeric($w_tr)) {
                $weights['trust']       = max(0.0, min(1.0, (float)$w_tr));
                $weights['reliability'] = $weights['trust'];
            }

            if (isset($rows['mcda_active_preset'])) {
                $weights['preset'] = $rows['mcda_active_preset'];
            }

            // Normalize weights dynamically so their sum strictly equals 1.0 (100%)
            $total_w = $weights['urgency'] + $weights['trip'] + $weights['trust'];
            if ($total_w > 0) {
                $weights['urgency']     = round($weights['urgency'] / $total_w, 4);
                $weights['trip']        = round($weights['trip'] / $total_w, 4);
                $weights['trust']       = round($weights['trust'] / $total_w, 4);
                $weights['scarcity']    = $weights['urgency'];
                $weights['contention']  = $weights['trip'];
                $weights['reliability'] = $weights['trust'];
            }
        }
    } catch (Throwable $e) {
        // Table does not exist or query failed: use standard defaults
    }

    $cached_weights = $weights;
    return $weights;
}

/**
 * Retrieve dynamic Complaint Urgency Matrix from system_settings, or fallback to standard defaults.
 */
function get_complaint_urgency_matrix(PDO $pdo): array
{
    static $cached_matrix = null;
    if ($cached_matrix !== null) {
        return $cached_matrix;
    }

    $defaults = [
        'emergency_keywords' => 'starter relay, startic realy, ayaw mag-start, preno, brake failure, air leak, overheat, overheating, tirik, makina, bagsak makina',
        'medium_keywords'    => 'alternator, battery drain, low battery, pudpod gulong, flat tire, suspension, pang-ilalim, maingay na makina, tagas langis, tagas',
        'routine_keywords'   => 'change oil, regular pms, preventive maintenance, basag salamin, sidemirror, wiper, pundi ilaw, busina, body repair',
        'score_emergency'    => 1.0,
        'score_medium'       => 0.75,
        'score_routine'      => 0.40,
        'score_baseline'     => 0.30,
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'mcda_complaint_%'");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($rows) {
            if (!empty($rows['mcda_complaint_emergency'])) $defaults['emergency_keywords'] = $rows['mcda_complaint_emergency'];
            if (!empty($rows['mcda_complaint_medium']))    $defaults['medium_keywords']    = $rows['mcda_complaint_medium'];
            if (!empty($rows['mcda_complaint_routine']))   $defaults['routine_keywords']   = $rows['mcda_complaint_routine'];
            if (isset($rows['mcda_complaint_score_emergency'])) $defaults['score_emergency'] = (float)$rows['mcda_complaint_score_emergency'];
            if (isset($rows['mcda_complaint_score_medium']))    $defaults['score_medium']    = (float)$rows['mcda_complaint_score_medium'];
            if (isset($rows['mcda_complaint_score_routine']))   $defaults['score_routine']   = (float)$rows['mcda_complaint_score_routine'];
            if (isset($rows['mcda_complaint_score_baseline']))  $defaults['score_baseline']  = (float)$rows['mcda_complaint_score_baseline'];
        }
    } catch (Throwable $e) {}

    $cached_matrix = $defaults;
    return $defaults;
}

/**
 * Classify a vehicle complaint text/level into an urgency tier and score
 * based on the Admin's configured criteria.
 */
function classify_complaint_urgency(string $text, string $level, array $matrix): array
{
    $text_lower  = strtolower(trim($text));
    $level_lower = strtolower(trim($level));

    // Keyword arrays
    $em_keys = array_filter(array_map('trim', explode(',', strtolower($matrix['emergency_keywords']))));
    $med_keys = array_filter(array_map('trim', explode(',', strtolower($matrix['medium_keywords']))));
    $rt_keys = array_filter(array_map('trim', explode(',', strtolower($matrix['routine_keywords']))));

    // 1. Explicit 'high' / 'emergency' / 'breakdown' level or match with Emergency keywords
    if (in_array($level_lower, ['high', 'emergency', 'breakdown', 'critical'], true)) {
        return ['tier' => 'emergency', 'score' => (float)$matrix['score_emergency'], 'label' => 'Emergency / Mataas'];
    }
    foreach ($em_keys as $k) {
        if ($k !== '' && strpos($text_lower, $k) !== false) {
            return ['tier' => 'emergency', 'score' => (float)$matrix['score_emergency'], 'label' => 'Emergency / Mataas'];
        }
    }

    // 2. Explicit 'medium' level or match with Medium keywords
    if ($level_lower === 'medium') {
        return ['tier' => 'medium', 'score' => (float)$matrix['score_medium'], 'label' => 'Katamtaman'];
    }
    foreach ($med_keys as $k) {
        if ($k !== '' && strpos($text_lower, $k) !== false) {
            return ['tier' => 'medium', 'score' => (float)$matrix['score_medium'], 'label' => 'Katamtaman'];
        }
    }

    // 3. Explicit 'low' / 'routine' level or match with Routine keywords
    if (in_array($level_lower, ['low', 'routine'], true)) {
        return ['tier' => 'routine', 'score' => (float)$matrix['score_routine'], 'label' => 'Routine / Mababa'];
    }
    foreach ($rt_keys as $k) {
        if ($k !== '' && strpos($text_lower, $k) !== false) {
            return ['tier' => 'routine', 'score' => (float)$matrix['score_routine'], 'label' => 'Routine / Mababa'];
        }
    }

    // Default if non-empty complaint has no matching keyword
    if ($text_lower !== '') {
        return ['tier' => 'medium', 'score' => (float)$matrix['score_medium'], 'label' => 'Katamtaman'];
    }

    return ['tier' => 'baseline', 'score' => (float)$matrix['score_baseline'], 'label' => 'Normal Routine'];
}

/**
 * Scores every currently-pending requisition and returns them ranked highest-priority first.
 *
 * @param array $requisitions rows from requisitions (must include id, requester_id)
 */
function score_pending_requisitions(PDO $pdo, array $requisitions): array
{
    if (!$requisitions) {
        return [];
    }

    $mcda_weights = get_mcda_weights($pdo);
    $w_urgency    = $mcda_weights['urgency'];
    $w_trip       = $mcda_weights['trip'];
    $w_trust      = $mcda_weights['trust'];

    $late_counts    = get_late_return_counts($pdo);
    $missing_counts = get_missing_loan_counts($pdo);

    // Collect truck IDs to fetch truck info and complaints in bulk
    $truck_ids = array_values(array_unique(array_filter(array_column($requisitions, 'truck_id'))));
    $trucks_map = [];
    $truck_complaints_map = [];

    if ($truck_ids) {
        $ph_trucks = implode(',', array_fill(0, count($truck_ids), '?'));
        
        // Fetch truck status
        $t_stmt = $pdo->prepare("SELECT id, plate_number, model, status FROM trucks WHERE id IN ($ph_trucks)");
        $t_stmt->execute($truck_ids);
        foreach ($t_stmt->fetchAll() as $t) {
            $trucks_map[$t['id']] = $t;
        }

        // Fetch active complaints (from truck_complaints table if exists)
        try {
            $c_stmt = $pdo->prepare(
                "SELECT truck_id, urgency_level, complaint_text 
                 FROM truck_complaints 
                 WHERE truck_id IN ($ph_trucks) AND status != 'resolved'"
            );
            $c_stmt->execute($truck_ids);
            foreach ($c_stmt->fetchAll() as $comp) {
                $truck_complaints_map[$comp['truck_id']][] = $comp;
            }
        } catch (Throwable $e) {
            // Table might not exist in old environments
        }
    }

    $scored = [];
    $complaint_matrix = get_complaint_urgency_matrix($pdo);

    foreach ($requisitions as $r) {
        $truck_id = $r['truck_id'] ?? null;
        $truck = $truck_id ? ($trucks_map[$truck_id] ?? null) : null;
        $complaints = $truck_id ? ($truck_complaints_map[$truck_id] ?? []) : [];

        // -------------------------------------------------------------
        // Criterion 1: Sira ng Sasakyan / Urgency (0.0 to 1.0)
        // -------------------------------------------------------------
        $urgency = (float)$complaint_matrix['score_baseline']; // Baseline for routine requests

        if (!empty($r['manual_urgent'])) {
            $urgency = (float)$complaint_matrix['score_emergency'];
        }

        // Evaluate requisition's own purpose / complaint against Admin's Matrix
        if (!empty($r['purpose'])) {
            $p_res = classify_complaint_urgency($r['purpose'], '', $complaint_matrix);
            if ($p_res['score'] > $urgency) {
                $urgency = $p_res['score'];
            }
        }

        // Evaluate active complaints on the assigned truck via Admin's Matrix
        if ($complaints) {
            $max_urgency = (float)$complaint_matrix['score_baseline'];
            foreach ($complaints as $c) {
                $c_res = classify_complaint_urgency($c['complaint_text'] ?? '', $c['urgency_level'] ?? '', $complaint_matrix);
                if ($c_res['score'] > $max_urgency) {
                    $max_urgency = $c_res['score'];
                }
            }
            $urgency = max($urgency, $max_urgency);
        }

        // Flagged as maintenance repair
        if (!empty($r['is_maintenance_request'])) {
            $urgency = max($urgency, (float)$complaint_matrix['score_medium']);
        }

        // Truck currently in shop
        if ($truck && $truck['status'] === 'under_maintenance') {
            $urgency = max($urgency, 0.65);
        }

        // -------------------------------------------------------------
        // Criterion 2: Schedule ng Biyahe / Trip Priority (0.0 to 1.0)
        // -------------------------------------------------------------
        $trip = 0.35; // Baseline if no truck assigned
        if ($truck) {
            if ($truck['status'] === 'on_trip') {
                $trip = 1.0; // Stranded on road delivery trip (Top emergency)
            } elseif ($truck['status'] === 'available') {
                $trip = 0.65; // Ready for immediate fleet dispatch
            } elseif ($truck['status'] === 'under_maintenance') {
                $trip = 0.50; // In shop waiting to re-enter service
            }
        }

        // -------------------------------------------------------------
        // Criterion 3: Rekord ng Driver / Trust (0.0 to 1.0)
        // -------------------------------------------------------------
        $late = $late_counts[$r['requester_id']] ?? 0;
        $missing = $missing_counts[$r['requester_id']] ?? 0;
        $penalty = $late + (PRIORITY_MISSING_LOAN_WEIGHT * $missing);
        $trust = 1 / (1 + ($penalty / 3));

        // -------------------------------------------------------------
        // Composite MCDA Weighted Score (0 to 100)
        // -------------------------------------------------------------
        $score = 100 * (
            $w_urgency * $urgency +
            $w_trip    * $trip +
            $w_trust   * $trust
        );

        $scored[] = $r + [
            'priority_score'       => round($score, 1),
            'priority_urgency'     => round($urgency * 100),
            'priority_trip'        => round($trip * 100),
            'priority_trust'       => round($trust * 100),
            // Backward compatibility aliases
            'priority_stock'       => round($urgency * 100),
            'priority_demand'      => round($trip * 100),
            'priority_scarcity'    => round($urgency * 100),
            'priority_contention'  => round($trip * 100),
            'priority_reliability' => round($trust * 100),
            'mcda_weights'         => [
                'urgency'     => round($w_urgency * 100),
                'trip'        => round($w_trip * 100),
                'trust'       => round($w_trust * 100),
                'scarcity'    => round($w_urgency * 100),
                'contention'  => round($w_trip * 100),
                'reliability' => round($w_trust * 100),
                'preset'      => $mcda_weights['preset'] ?? 'standard',
            ],
        ];
    }

    usort($scored, function (array $a, array $b): int {
        // Manually-flagged urgent requests always rank first
        $a_urgent = !empty($a['manual_urgent']);
        $b_urgent = !empty($b['manual_urgent']);
        if ($a_urgent !== $b_urgent) {
            return $a_urgent ? -1 : 1;
        }
        $score_cmp = $b['priority_score'] <=> $a['priority_score'];
        if ($score_cmp !== 0) {
            return $score_cmp;
        }
        // Tie-breaker: oldest request first
        return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
    });

    return $scored;
}

/**
 * Returns a CSS modifier class for a priority score badge.
 */
function priority_score_class(float $score): string
{
    if ($score >= 70) {
        return 'badge-danger';
    }
    if ($score >= 40) {
        return 'badge-warning';
    }
    return 'badge-success';
}
