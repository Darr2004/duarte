<?php
/**
 * DuaRTE — MCDA Dynamic Multi-Criteria Algorithm Configuration Panel
 *
 * Operational Trucking Decision Matrix:
 *   1. Sira ng Sasakyan (Vehicle Defect Urgency)
 *   2. Schedule ng Biyahe (Trip / Dispatch Schedule)
 *   3. Rekord ng Driver (Driver Accountability / Trust)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/priority.php';

require_role(['admin']);

$pdo = get_db();
$user = current_user();
$page_title = 'MCDA Priority Settings';

$presets = [
    'standard' => [
        'name'        => 'Standard Trucking Operations',
        'description' => 'Balanced weighting across vehicle defect urgency, trip schedule, and driver accountability.',
        'urgency'     => 0.45,
        'trip'        => 0.35,
        'trust'       => 0.20,
        'icon'        => '⚖️',
    ],
    'crisis_rush' => [
        'name'        => 'Emergency Breakdown Priority',
        'description' => 'Prioritizes severe vehicle breakdowns to get immobilized trucks back on schedule.',
        'urgency'     => 0.60,
        'trip'        => 0.30,
        'trust'       => 0.10,
        'icon'        => '🚨',
    ],
    'asset_protection' => [
        'name'        => 'Driver Accountability Focus',
        'description' => 'Prioritizes drivers with proven track records of safe operations and prompt tool returns.',
        'urgency'     => 0.30,
        'trip'        => 0.30,
        'trust'       => 0.40,
        'icon'        => '🛡️',
    ],
];

$success_msg = null;
$error_msg   = null;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid or expired CSRF security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? 'save_weights';

        if ($action === 'save_complaint_matrix') {
            $em_kw   = trim($_POST['emergency_keywords'] ?? '');
            $med_kw  = trim($_POST['medium_keywords'] ?? '');
            $rt_kw   = trim($_POST['routine_keywords'] ?? '');

            $sc_em   = max(0.5, min(1.0, (float)($_POST['score_emergency'] ?? 100) / 100));
            $sc_med  = max(0.2, min(0.9, (float)($_POST['score_medium'] ?? 75) / 100));
            $sc_rt   = max(0.1, min(0.6, (float)($_POST['score_routine'] ?? 40) / 100));
            $sc_base = max(0.0, min(0.5, (float)($_POST['score_baseline'] ?? 30) / 100));

            $upd = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, description)
                VALUES (:k, :v, :d)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");

            $complaint_settings = [
                'mcda_complaint_emergency'       => [$em_kw, 'Emergency defect keywords (100% urgency)'],
                'mcda_complaint_medium'          => [$med_kw, 'Medium defect keywords (75% urgency)'],
                'mcda_complaint_routine'         => [$rt_kw, 'Routine maintenance keywords (40% urgency)'],
                'mcda_complaint_score_emergency' => [number_format($sc_em, 2, '.', ''), 'Score for Emergency defects'],
                'mcda_complaint_score_medium'    => [number_format($sc_med, 2, '.', ''), 'Score for Medium defects'],
                'mcda_complaint_score_routine'   => [number_format($sc_rt, 2, '.', ''), 'Score for Routine defects'],
                'mcda_complaint_score_baseline'  => [number_format($sc_base, 2, '.', ''), 'Baseline score when no defect is reported'],
            ];

            foreach ($complaint_settings as $k => [$v, $d]) {
                $upd->execute(['k' => $k, 'v' => $v, 'd' => $d]);
            }

            log_audit_event(
                $pdo,
                $user,
                'mcda_complaint_matrix_update',
                'system_setting',
                null,
                "Updated MCDA Complaints Urgency Matrix rules and keyword criteria."
            );

            $success_msg = "Complaints Urgency Matrix saved successfully.";
        } elseif ($action === 'save_weights') {
            $chosen_preset = $_POST['preset'] ?? 'custom';

            if (isset($presets[$chosen_preset])) {
                $w_urgency = $presets[$chosen_preset]['urgency'];
                $w_trip    = $presets[$chosen_preset]['trip'];
                $w_trust   = $presets[$chosen_preset]['trust'];
                $active_preset = $chosen_preset;
            } else {
                $w_urgency = max(0.0, min(1.0, (float)($_POST['urgency'] ?? 0.45)));
                $w_trip    = max(0.0, min(1.0, (float)($_POST['trip'] ?? 0.35)));
                $w_trust   = max(0.0, min(1.0, (float)($_POST['trust'] ?? 0.20)));
                $active_preset = 'custom';
            }

            $sum = round($w_urgency + $w_trip + $w_trust, 2);
            if ($sum < 0.99 || $sum > 1.01) {
                $error_msg = "Total weight must equal 100%. Current sum: " . round($sum * 100) . "%.";
            } else {
                // Save to database
                $upd = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, description)
                    VALUES (:k, :v, :d)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ");

                $settings_to_save = [
                    'mcda_weight_urgency'     => [number_format($w_urgency, 2, '.', ''), 'MCDA weight for vehicle defect urgency'],
                    'mcda_weight_trip'        => [number_format($w_trip, 2, '.', ''), 'MCDA weight for trip dispatch schedule'],
                    'mcda_weight_trust'       => [number_format($w_trust, 2, '.', ''), 'MCDA weight for driver accountability'],
                    'mcda_active_preset'      => [$active_preset, 'Active MCDA preset'],
                    // Aliases for backward compatibility
                    'mcda_weight_scarcity'    => [number_format($w_urgency, 2, '.', ''), 'MCDA weight alias for urgency'],
                    'mcda_weight_contention'  => [number_format($w_trip, 2, '.', ''), 'MCDA weight alias for trip'],
                    'mcda_weight_reliability' => [number_format($w_trust, 2, '.', ''), 'MCDA weight alias for trust'],
                ];

                foreach ($settings_to_save as $k => [$v, $d]) {
                    $upd->execute(['k' => $k, 'v' => $v, 'd' => $d]);
                }

                log_audit_event(
                    $pdo,
                    $user,
                    'mcda_settings_update',
                    'system_setting',
                    null,
                    "Updated MCDA weights: Preset='{$active_preset}', Urgency=" . ($w_urgency * 100) . "%, Trip=" . ($w_trip * 100) . "%, Trust=" . ($w_trust * 100) . "%"
                );

                $success_msg = "MCDA weights updated successfully. Active Preset: " . strtoupper($active_preset) . ".";
            }
        }
    }
}

// Fetch active weights
$current_weights = get_mcda_weights($pdo);
$curr_urgency = (float)$current_weights['urgency'];
$curr_trip    = (float)$current_weights['trip'];
$curr_trust   = (float)$current_weights['trust'];
$curr_preset  = $current_weights['preset'] ?? 'standard';

// Fetch active complaints urgency matrix
$curr_matrix = get_complaint_urgency_matrix($pdo);

// Fetch sample pending requisitions to show live preview
$pending_stmt = $pdo->query("
    SELECT r.id, r.created_at, r.requester_id, r.purpose, r.truck_id, r.is_maintenance_request, r.manual_urgent,
           u.full_name AS requester_name,
           t.plate_number
    FROM requisitions r
    JOIN users u ON u.id = r.requester_id
    LEFT JOIN trucks t ON t.id = r.truck_id
    WHERE r.status = 'pending'
    ORDER BY r.created_at ASC
    LIMIT 10
");
$pending_reqs = $pending_stmt->fetchAll();
$preview_scored = $pending_reqs ? score_pending_requisitions($pdo, $pending_reqs) : [];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-wrapper" style="max-width: 1100px; margin: 0 auto; padding: 20px 16px;">

  <!-- Page Header -->
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
      <div style="font-size: 0.82rem; color: var(--ink-soft, #7A6A58); font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;">Priority Algorithm Governance</div>
      <h1 style="margin: 4px 0 0 0; font-size: 1.75rem; color: var(--ink, #1F1710);">Multi-Criteria Decision Analysis (MCDA) Settings</h1>
      <p style="margin: 4px 0 0 0; font-size: 0.85rem; color: var(--ink-soft, #7A6A58);">
        Configure prioritization weights across vehicle defect urgency, trip schedule, and driver accountability.
      </p>
    </div>
    <div style="display: flex; gap: 8px;">
      <a href="<?= BASE_URL ?>/requisition/pending.php" class="btn btn-secondary" style="font-size: 0.85rem;">← Back to Pending Approvals</a>
    </div>
  </div>

  <?php if ($success_msg): ?>
    <div class="alert alert-success" style="background: #E8F5E9; border: 1px solid #A5D6A7; color: #1B5E20; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
      <span style="font-size: 1.2rem;">✓</span>
      <span><?= htmlspecialchars($success_msg) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($error_msg): ?>
    <div class="alert alert-danger" style="background: #FFEBEE; border: 1px solid #FFCDD2; color: #C62828; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
      <span style="font-size: 1.2rem;">⚠</span>
      <span><?= htmlspecialchars($error_msg) ?></span>
    </div>
  <?php endif; ?>

  <!-- Settings Grid (Presets on Left, Sliders on Right) -->
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 24px; margin-bottom: 30px;">

    <!-- Preset Profiles Card -->
    <div style="background: var(--surface, #FFFFFF); border: 1px solid var(--line, #E5DFD7); border-radius: 12px; padding: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
      <h3 style="margin: 0 0 16px 0; font-size: 1.1rem; color: var(--ink, #1F1710); display: flex; align-items: center; gap: 8px;">
        <span>⚡</span> Operational Presets
      </h3>

      <div style="display: flex; flex-direction: column; gap: 12px;">
        <?php foreach ($presets as $k => $p): ?>
          <?php $isActive = ($curr_preset === $k); ?>
          <form method="POST" style="margin: 0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_weights">
            <input type="hidden" name="preset" value="<?= htmlspecialchars($k) ?>">

            <div style="border: 2px solid <?= $isActive ? 'var(--amber, #D97706)' : 'var(--line, #E5DFD7)' ?>; background: <?= $isActive ? '#FFFBEB' : '#FAFAFA' ?>; border-radius: 10px; padding: 14px; transition: all 0.2s;">
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                <span style="font-weight: bold; font-size: 0.95rem; color: var(--ink, #1F1710);">
                  <?= $p['icon'] ?> <?= htmlspecialchars($p['name']) ?>
                </span>
                <?php if ($isActive): ?>
                  <span style="background: var(--amber, #D97706); color: white; font-size: 0.75rem; font-weight: bold; padding: 2px 8px; border-radius: 12px;">ACTIVE</span>
                <?php else: ?>
                  <button type="submit" class="btn btn-secondary" style="font-size: 0.75rem; padding: 4px 10px;">Activate</button>
                <?php endif; ?>
              </div>
              <p style="margin: 0 0 10px 0; font-size: 0.8rem; color: var(--ink-soft, #7A6A58); line-height: 1.4;">
                <?= htmlspecialchars($p['description']) ?>
              </p>
              <div style="display: flex; gap: 8px; font-size: 0.75rem; font-weight: 600; flex-wrap: wrap;">
                <span style="background: var(--red-tint, #FEE2E2); color: var(--red-danger, #DC2626); padding: 3px 6px; border-radius: 4px;">Defect: <?= $p['urgency'] * 100 ?>%</span>
                <span style="background: var(--blue-tint, #DBEAFE); color: var(--blue-info, #2563EB); padding: 3px 6px; border-radius: 4px;">Trip: <?= $p['trip'] * 100 ?>%</span>
                <span style="background: var(--green-tint, #D1FAE5); color: var(--green-ok, #059669); padding: 3px 6px; border-radius: 4px;">Driver: <?= $p['trust'] * 100 ?>%</span>
              </div>
            </div>
          </form>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Custom Slider Form Card -->
    <div style="background: var(--surface, #FFFFFF); border: 1px solid var(--line, #E5DFD7); border-radius: 12px; padding: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
      <h3 style="margin: 0 0 16px 0; font-size: 1.1rem; color: var(--ink, #1F1710); display: flex; align-items: center; gap: 8px;">
        <span>🎛️</span> Custom Weight Sliders
      </h3>

      <form method="POST" id="custom-weights-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_weights">
        <input type="hidden" name="preset" value="custom">

        <!-- Urgency Slider -->
        <div style="margin-bottom: 16px;">
          <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
            <label style="font-weight: 600; font-size: 0.85rem; color: var(--ink, #1F1710);">
              🚨 Vehicle Defect Urgency
            </label>
            <span id="urgency-val" style="font-weight: bold; color: var(--red-danger, #DC2626); font-size: 0.9rem;"><?= round($curr_urgency * 100) ?>%</span>
          </div>
          <input aria-label="Urgency weight" type="range" id="urgency-slider" name="urgency" min="0" max="1" step="0.05" value="<?= $curr_urgency ?>" style="width: 100%;">
        </div>

        <!-- Trip Slider -->
        <div style="margin-bottom: 16px;">
          <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
            <label style="font-weight: 600; font-size: 0.85rem; color: var(--ink, #1F1710);">
              🚛 Trip &amp; Dispatch Schedule
            </label>
            <span id="trip-val" style="font-weight: bold; color: var(--blue-info, #2563EB); font-size: 0.9rem;"><?= round($curr_trip * 100) ?>%</span>
          </div>
          <input aria-label="Trip weight" type="range" id="trip-slider" name="trip" min="0" max="1" step="0.05" value="<?= $curr_trip ?>" style="width: 100%;">
        </div>

        <!-- Trust Slider -->
        <div style="margin-bottom: 20px;">
          <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
            <label style="font-weight: 600; font-size: 0.85rem; color: var(--ink, #1F1710);">
              ⭐ Driver Accountability &amp; Trust
            </label>
            <span id="trust-val" style="font-weight: bold; color: var(--green-ok, #059669); font-size: 0.9rem;"><?= round($curr_trust * 100) ?>%</span>
          </div>
          <input aria-label="Trust weight" type="range" id="trust-slider" name="trust" min="0" max="1" step="0.05" value="<?= $curr_trust ?>" style="width: 100%;">
        </div>

        <!-- Total Sum Status Indicator -->
        <div style="padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; background: #F3F4F6;" id="sum-container">
          <span style="font-size: 0.85rem; font-weight: 600; color: var(--ink, #1F1710);">Total Weight Sum:</span>
          <span id="total-sum-badge" style="font-weight: bold; font-size: 0.9rem; padding: 2px 8px; border-radius: 6px; background: #D1FAE5; color: #065F46;">
            <?= round(($curr_urgency + $curr_trip + $curr_trust) * 100) ?>%
          </span>
        </div>

        <button type="submit" id="save-custom-btn" class="btn btn-primary" style="width: 100%; padding: 10px; font-weight: bold; font-size: 0.9rem;">
          Save Custom Weights
        </button>
      </form>
    </div>

  </div>

  <!-- Complaints Urgency Matrix Card (Executive System Designer UI) -->
  <div style="background: var(--surface, #FFFFFF); border: 1px solid var(--line, #E5DFD7); border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
    
    <!-- Section Header -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; flex-wrap: wrap; gap: 12px;">
      <div>
        <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; color: var(--amber, #8C5A32); margin-bottom: 4px;">
          MCDA Urgency Engine
        </div>
        <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: var(--ink, #1F1710); display: flex; align-items: center; gap: 8px;">
          <span>🛠️</span> Vehicle Complaints Urgency Matrix
        </h3>
        <p style="margin: 4px 0 0 0; font-size: 0.85rem; color: var(--ink-soft, #7A6A58); max-width: 680px;">
          Assign vehicle defect keywords into priority tiers using interactive tags and the catalog below.
        </p>
      </div>
      <div style="display: flex; gap: 8px; align-items: center;">
        <button type="button" class="btn btn-outline" id="btn-reset-matrix" style="font-size: 0.8rem; padding: 6px 12px;">
          🔄 Reset Defaults
        </button>
        <span style="font-size: 0.78rem; background: var(--amber-tint, #FAF4EB); color: var(--amber-dim, #6E4424); border: 1px solid var(--amber-border, #EDDCBE); padding: 5px 12px; border-radius: 6px; font-weight: 700;">
          Basis: Driver Complaint
        </span>
      </div>
    </div>

    <form method="POST" id="complaints-matrix-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_complaint_matrix">
      
      <!-- Hidden Comma-Separated Values for DB Synchronization -->
      <input type="hidden" name="emergency_keywords" id="kw-emergency" value="<?= htmlspecialchars((string)$curr_matrix['emergency_keywords']) ?>">
      <input type="hidden" name="medium_keywords" id="kw-medium" value="<?= htmlspecialchars((string)$curr_matrix['medium_keywords']) ?>">
      <input type="hidden" name="routine_keywords" id="kw-routine" value="<?= htmlspecialchars((string)$curr_matrix['routine_keywords']) ?>">

      <!-- 3 Tier Cards with Interactive Chip Boards -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(310px, 1fr)); gap: 16px; margin-bottom: 20px;">
        
        <!-- 1. Emergency Tier Board -->
        <div style="background: var(--red-tint, #FAF1EF); border: 1px solid var(--red-border, #EFC7C1); border-radius: 12px; padding: 16px; display: flex; flex-direction: column;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <div style="font-weight: 700; font-size: 0.95rem; color: var(--red-danger, #94382C); display: flex; align-items: center; gap: 6px;">
              <span>🔴</span> Emergency / High
            </div>
            <div style="display: flex; align-items: center; gap: 4px;">
              <input type="number" name="score_emergency" id="score-em" value="<?= round((float)$curr_matrix['score_emergency'] * 100) ?>" min="50" max="100" style="width: 52px; padding: 2px 4px; font-size: 0.8rem; font-weight: bold; border: 1px solid var(--red-border, #EFC7C1); border-radius: 4px; text-align: center; color: var(--red-danger, #94382C); background: #fff;">
              <span style="font-size: 0.8rem; font-weight: bold; color: var(--red-danger, #94382C);">%</span>
            </div>
          </div>

          <!-- Interactive Chips Container -->
          <div id="chips-container-emergency" style="display: flex; flex-wrap: wrap; gap: 6px; min-height: 90px; align-content: flex-start; background: #FFFFFF; border: 1px solid var(--red-border, #EFC7C1); border-radius: 8px; padding: 10px; margin-bottom: 10px;">
            <!-- Rendered by JS -->
          </div>

          <!-- Quick Add Tag Input -->
          <div style="display: flex; gap: 6px; margin-top: auto;">
            <input type="text" id="add-input-emergency" placeholder="+ Add defect keyword..." style="flex: 1; font-size: 0.8rem; padding: 7px 10px; border-radius: 6px; border: 1px solid var(--red-border, #EFC7C1); background: #fff;">
            <button type="button" class="btn" onclick="addChipFromInput('emergency')" style="padding: 6px 12px; font-size: 0.8rem; background: var(--red-danger, #94382C); color: #fff; border: none; font-weight: bold; border-radius: 6px;">
              + Add
            </button>
          </div>
        </div>

        <!-- 2. Medium Tier Board -->
        <div style="background: var(--amber-tint, #FAF4EB); border: 1px solid var(--amber-border, #EDDCBE); border-radius: 12px; padding: 16px; display: flex; flex-direction: column;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <div style="font-weight: 700; font-size: 0.95rem; color: var(--amber-dim, #6E4424); display: flex; align-items: center; gap: 6px;">
              <span>🟡</span> Medium
            </div>
            <div style="display: flex; align-items: center; gap: 4px;">
              <input type="number" name="score_medium" id="score-med" value="<?= round((float)$curr_matrix['score_medium'] * 100) ?>" min="20" max="90" style="width: 52px; padding: 2px 4px; font-size: 0.8rem; font-weight: bold; border: 1px solid var(--amber-border, #EDDCBE); border-radius: 4px; text-align: center; color: var(--amber-dim, #6E4424); background: #fff;">
              <span style="font-size: 0.8rem; font-weight: bold; color: var(--amber-dim, #6E4424);">%</span>
            </div>
          </div>

          <!-- Interactive Chips Container -->
          <div id="chips-container-medium" style="display: flex; flex-wrap: wrap; gap: 6px; min-height: 90px; align-content: flex-start; background: #FFFFFF; border: 1px solid var(--amber-border, #EDDCBE); border-radius: 8px; padding: 10px; margin-bottom: 10px;">
            <!-- Rendered by JS -->
          </div>

          <!-- Quick Add Tag Input -->
          <div style="display: flex; gap: 6px; margin-top: auto;">
            <input type="text" id="add-input-medium" placeholder="+ Add defect keyword..." style="flex: 1; font-size: 0.8rem; padding: 7px 10px; border-radius: 6px; border: 1px solid var(--amber-border, #EDDCBE); background: #fff;">
            <button type="button" class="btn" onclick="addChipFromInput('medium')" style="padding: 6px 12px; font-size: 0.8rem; background: var(--amber, #8C5A32); color: #fff; border: none; font-weight: bold; border-radius: 6px;">
              + Add
            </button>
          </div>
        </div>

        <!-- 3. Routine Tier Board -->
        <div style="background: var(--green-tint, #EEF6F2); border: 1px solid var(--green-border, #C2E0CE); border-radius: 12px; padding: 16px; display: flex; flex-direction: column;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <div style="font-weight: 700; font-size: 0.95rem; color: var(--green-ok, #2D6A4F); display: flex; align-items: center; gap: 6px;">
              <span>🟢</span> Routine / Low
            </div>
            <div style="display: flex; align-items: center; gap: 4px;">
              <input type="number" name="score_routine" id="score-rt" value="<?= round((float)$curr_matrix['score_routine'] * 100) ?>" min="10" max="60" style="width: 52px; padding: 2px 4px; font-size: 0.8rem; font-weight: bold; border: 1px solid var(--green-border, #C2E0CE); border-radius: 4px; text-align: center; color: var(--green-ok, #2D6A4F); background: #fff;">
              <span style="font-size: 0.8rem; font-weight: bold; color: var(--green-ok, #2D6A4F);">%</span>
            </div>
          </div>

          <!-- Interactive Chips Container -->
          <div id="chips-container-routine" style="display: flex; flex-wrap: wrap; gap: 6px; min-height: 90px; align-content: flex-start; background: #FFFFFF; border: 1px solid var(--green-border, #C2E0CE); border-radius: 8px; padding: 10px; margin-bottom: 10px;">
            <!-- Rendered by JS -->
          </div>

          <!-- Quick Add Tag Input -->
          <div style="display: flex; gap: 6px; margin-top: auto;">
            <input type="text" id="add-input-routine" placeholder="+ Add defect keyword..." style="flex: 1; font-size: 0.8rem; padding: 7px 10px; border-radius: 6px; border: 1px solid var(--green-border, #C2E0CE); background: #fff;">
            <button type="button" class="btn" onclick="addChipFromInput('routine')" style="padding: 6px 12px; font-size: 0.8rem; background: var(--green-ok, #2D6A4F); color: #fff; border: none; font-weight: bold; border-radius: 6px;">
              + Add
            </button>
          </div>
        </div>

      </div>

      <!-- Master Fleet Defect Quick Picker Accordion -->
      <div style="background: var(--paper, #F5F7FA); border: 1px solid var(--line, #E1E6EB); border-radius: 10px; padding: 14px 18px; margin-bottom: 18px;">
        <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;" onclick="toggleMasterCatalog()">
          <div style="font-weight: 700; font-size: 0.9rem; color: var(--ink, #161E26); display: flex; align-items: center; gap: 8px;">
            <span>📋</span> Master Fleet Defect Catalog
          </div>
          <span id="catalog-toggle-icon" style="font-size: 0.85rem; font-weight: bold; color: var(--amber, #8C5A32);">
            ▼ Open Catalog
          </span>
        </div>

        <div id="master-catalog-body" style="display: none; margin-top: 14px; border-top: 1px solid var(--line); padding-top: 12px;">
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px;">
            
            <!-- Electrical Group -->
            <div style="background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 10px;">
              <div style="font-weight: 700; font-size: 0.82rem; color: var(--amber-dim); margin-bottom: 8px;">
                ⚡ Electrical System
              </div>
              <div style="display: flex; flex-direction: column; gap: 6px;" id="catalog-group-electrical"></div>
            </div>

            <!-- Engine Group -->
            <div style="background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 10px;">
              <div style="font-weight: 700; font-size: 0.82rem; color: var(--red-danger); margin-bottom: 8px;">
                🚗 Engine &amp; Cooling
              </div>
              <div style="display: flex; flex-direction: column; gap: 6px;" id="catalog-group-engine"></div>
            </div>

            <!-- Brakes & Chassis Group -->
            <div style="background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 10px;">
              <div style="font-weight: 700; font-size: 0.82rem; color: var(--blue-info); margin-bottom: 8px;">
                🛑 Brakes, Tires &amp; Chassis
              </div>
              <div style="display: flex; flex-direction: column; gap: 6px;" id="catalog-group-chassis"></div>
            </div>

            <!-- Body & Glass Group -->
            <div style="background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 10px;">
              <div style="font-weight: 700; font-size: 0.82rem; color: var(--green-ok); margin-bottom: 8px;">
                🪟 Body, Glass &amp; Maintenance
              </div>
              <div style="display: flex; flex-direction: column; gap: 6px;" id="catalog-group-body"></div>
            </div>

          </div>
        </div>
      </div>

      <!-- Live Complaint Tester / Simulator -->
      <div style="background: #FFFFFF; border: 1px dashed var(--line-strong, #CBD5E1); border-radius: 10px; padding: 14px 18px; margin-bottom: 20px;">
        <div style="font-weight: 700; font-size: 0.88rem; color: var(--ink, #161E26); margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
          <span>🧪</span> Live Tester: Automatic Complaint Classifier
        </div>
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
          <input type="text" id="test-complaint-input" placeholder="Test a complaint (e.g. starter relay won't start, change oil, flat tire)..." style="flex: 1; min-width: 280px; padding: 9px 14px; font-size: 0.85rem; border: 1px solid var(--line); border-radius: 6px;">
          <div id="test-result-badge" style="padding: 8px 16px; border-radius: 6px; font-weight: 700; font-size: 0.85rem; background: var(--surfaceSubtle, #EDF1F5); color: var(--ink-soft); min-width: 220px; text-align: center;">
            Enter a complaint on the left to test...
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div style="display: flex; justify-content: flex-end; gap: 10px;">
        <button type="submit" class="btn btn-primary" style="padding: 11px 28px; font-weight: 700; font-size: 0.92rem; background: var(--amber, #8C5A32); border-color: var(--amber, #8C5A32); box-shadow: 0 2px 6px rgba(140, 90, 50, 0.25);">
          💾 Save Complaints Matrix
        </button>
      </div>
    </form>
  </div>

  <style>
    .matrix-chip {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 8px 4px 10px;
      border-radius: 16px;
      font-size: 0.78rem;
      font-weight: 600;
      transition: all 0.15s ease;
      box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }
    .matrix-chip-em {
      background: #FAF1EF;
      color: #94382C;
      border: 1px solid #EFC7C1;
    }
    .matrix-chip-med {
      background: #FAF4EB;
      color: #6E4424;
      border: 1px solid #EDDCBE;
    }
    .matrix-chip-rt {
      background: #EEF6F2;
      color: #2D6A4F;
      border: 1px solid #C2E0CE;
    }
    .chip-action-btn {
      background: none;
      border: none;
      cursor: pointer;
      padding: 1px 3px;
      font-size: 0.8rem;
      border-radius: 50%;
      opacity: 0.7;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: opacity 0.1s;
    }
    .chip-action-btn:hover {
      opacity: 1;
      background: rgba(0,0,0,0.08);
    }
    .catalog-item-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 4px 0;
      border-bottom: 1px dashed #F1F5F9;
      font-size: 0.78rem;
    }
    .catalog-item-row:last-child {
      border-bottom: none;
    }
    .catalog-pill-btn {
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 0.7rem;
      font-weight: 700;
      border: 1px solid transparent;
      cursor: pointer;
      background: #F1F5F9;
      color: #64748B;
      transition: all 0.1s;
    }
    .catalog-pill-btn.active-em {
      background: #FAF1EF;
      color: #94382C;
      border-color: #EFC7C1;
    }
    .catalog-pill-btn.active-med {
      background: #FAF4EB;
      color: #6E4424;
      border-color: #EDDCBE;
    }
    .catalog-pill-btn.active-rt {
      background: #EEF6F2;
      color: #2D6A4F;
      border-color: #C2E0CE;
    }
  </style>

  <!-- Live Simulation / Preview Table -->
  <div style="background: var(--surface, #FFFFFF); border: 1px solid var(--line, #E5DFD7); border-radius: 12px; padding: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
      <div>
        <h3 style="margin: 0; font-size: 1.1rem; color: var(--ink, #1F1710);">Live Simulation: Priority Queue Ranking</h3>
        <p style="margin: 4px 0 0 0; font-size: 0.8rem; color: var(--ink-soft, #7A6A58);">
          Real-time priority scores based on active weights (Active: <?= strtoupper(htmlspecialchars($curr_preset)) ?>).
        </p>
      </div>
      <span style="font-size: 0.8rem; background: var(--paper, #F7F5F0); padding: 4px 10px; border-radius: 6px; border: 1px solid var(--line, #E5DFD7); font-weight: 600;">
        <?= count($preview_scored) ?> Pending Candidate(s)
      </span>
    </div>

    <?php if (!$preview_scored): ?>
      <p style="color: var(--ink-soft, #7A6A58); font-size: 0.9rem; margin: 20px 0; text-align: center;">
        No pending requests found.
      </p>
    <?php else: ?>
      <div style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 720px;">
          <thead>
            <tr style="background: var(--paper, #F7F5F0); text-align: left; border-bottom: 2px solid var(--line, #E5DFD7); white-space: nowrap;">
              <th style="padding: 10px 12px; min-width: 60px;">Rank</th>
              <th style="padding: 10px 12px; min-width: 90px;">Req ID</th>
              <th style="padding: 10px 12px; min-width: 170px;">Requester &amp; Vehicle</th>
              <th style="padding: 10px 12px; min-width: 120px;">Defect Urgency</th>
              <th style="padding: 10px 12px; min-width: 130px;">Trip Schedule</th>
              <th style="padding: 10px 12px; min-width: 130px;">Driver Trust</th>
              <th style="padding: 10px 12px; min-width: 120px; text-align: right;">MCDA Score</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($preview_scored as $idx => $r): ?>
              <?php 
                $score = (float)$r['priority_score'];
                $badgeBg = ($score >= 70) ? 'var(--red-tint, #FEE2E2)' : (($score >= 40) ? 'var(--amber-tint, #FEF3C7)' : 'var(--green-tint, #D1FAE5)');
                $badgeColor = ($score >= 70) ? 'var(--red-danger, #DC2626)' : (($score >= 40) ? 'var(--amber, #D97706)' : 'var(--green-ok, #059669)');
              ?>
              <tr style="border-bottom: 1px solid var(--line, #E5DFD7);">
                <td style="padding: 10px 12px; font-weight: bold; color: var(--ink-soft, #7A6A58);">#<?= $idx + 1 ?></td>
                <td style="padding: 10px 12px; font-weight: bold;">
                  <a href="<?= BASE_URL ?>/requisition/view.php?id=<?= $r['id'] ?>" style="color: var(--amber, #D97706); text-decoration: none;">
                    REQ-<?= $r['id'] ?>
                  </a>
                </td>
                <td style="padding: 10px 12px;">
                  <div style="font-weight: 600; color: var(--ink, #1F1710);"><?= htmlspecialchars($r['requester_name']) ?></div>
                  <div style="font-size: 0.75rem; color: var(--ink-soft, #7A6A58);"><?= htmlspecialchars($r['plate_number'] ?? 'No vehicle') ?></div>
                </td>
                <td style="padding: 10px 12px; color: var(--red-danger, #DC2626); font-weight: 600;"><?= $r['priority_urgency'] ?>%</td>
                <td style="padding: 10px 12px; color: var(--blue-info, #2563EB); font-weight: 600;"><?= $r['priority_trip'] ?>%</td>
                <td style="padding: 10px 12px; color: var(--green-ok, #059669); font-weight: 600;"><?= $r['priority_trust'] ?>%</td>
                <td style="padding: 10px 12px; text-align: right;">
                  <span style="background: <?= $badgeBg ?>; color: <?= $badgeColor ?>; font-weight: bold; padding: 3px 8px; border-radius: 6px; font-size: 0.85rem;">
                    <?= number_format($score, 1) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const uSlider = document.getElementById('urgency-slider');
  const tSlider = document.getElementById('trip-slider');
  const trSlider = document.getElementById('trust-slider');

  const uVal = document.getElementById('urgency-val');
  const tVal = document.getElementById('trip-val');
  const trVal = document.getElementById('trust-val');

  const sumBadge = document.getElementById('total-sum-badge');
  const saveBtn  = document.getElementById('save-custom-btn');

  function updateSum() {
    const u = parseFloat(uSlider.value);
    const t = parseFloat(tSlider.value);
    const tr = parseFloat(trSlider.value);

    uVal.textContent = Math.round(u * 100) + '%';
    tVal.textContent = Math.round(t * 100) + '%';
    trVal.textContent = Math.round(tr * 100) + '%';

    const total = Math.round((u + t + tr) * 100);
    sumBadge.textContent = total + '%';

    if (total === 100) {
      sumBadge.style.background = '#D1FAE5';
      sumBadge.style.color = '#065F46';
      saveBtn.disabled = false;
      saveBtn.style.opacity = '1';
    } else {
      sumBadge.style.background = '#FEE2E2';
      sumBadge.style.color = '#991B1B';
      sumBadge.textContent = total + '% (Must equal 100%)';
      saveBtn.disabled = true;
      saveBtn.style.opacity = '0.5';
    }
  }

  uSlider.addEventListener('input', updateSum);
  tSlider.addEventListener('input', updateSum);
  trSlider.addEventListener('input', updateSum);
  updateSum();

  // ==========================================
  // COMPLAINTS URGENCY MATRIX - EXECUTIVE UI
  // ==========================================
  const kwEmInput = document.getElementById('kw-emergency');
  const kwMedInput = document.getElementById('kw-medium');
  const kwRtInput = document.getElementById('kw-routine');

  const containerEm = document.getElementById('chips-container-emergency');
  const containerMed = document.getElementById('chips-container-medium');
  const containerRt = document.getElementById('chips-container-routine');

  const defectCatalog = {
    electrical: [
      'starter relay', 'startic realy', 'ayaw mag-start', 'alternator', 'battery drain',
      'low battery', 'pundi ilaw', 'headlight', 'tail light', 'busina', 'wiper motor'
    ],
    engine: [
      'makina', 'bagsak makina', 'overheat', 'overheating', 'tirik',
      'tagas langis', 'tagas coolant', 'radiator leak', 'maingay na makina', 'usok puti/itim', 'fuel pump'
    ],
    chassis: [
      'preno', 'brake failure', 'air leak', 'pudpod gulong', 'flat tire',
      'suspension', 'pang-ilalim', 'manibela / steering', 'bearing', 'clutch slide'
    ],
    body: [
      'change oil', 'regular pms', 'preventive maintenance', 'basag salamin', 'sidemirror',
      'wiper blade', 'wiper', 'body repair', 'pintura', 'sira pinto / lock'
    ]
  };

  function getKeywords(tier) {
    let input = (tier === 'emergency') ? kwEmInput : (tier === 'medium' ? kwMedInput : kwRtInput);
    if (!input || !input.value) return [];
    return input.value.split(',').map(s => s.trim()).filter(Boolean);
  }

  function setKeywords(tier, arr) {
    let input = (tier === 'emergency') ? kwEmInput : (tier === 'medium' ? kwMedInput : kwRtInput);
    if (input) {
      input.value = arr.join(', ');
    }
  }

  function renderChips() {
    const tiers = ['emergency', 'medium', 'routine'];
    tiers.forEach(tier => {
      const container = (tier === 'emergency') ? containerEm : (tier === 'medium' ? containerMed : containerRt);
      if (!container) return;
      const kws = getKeywords(tier);
      container.innerHTML = '';
      if (kws.length === 0) {
        container.innerHTML = '<span style="font-size:0.75rem; color:#94A3B8; font-style:italic; padding: 4px;">No tags assigned...</span>';
        return;
      }
      kws.forEach((kw, idx) => {
        const chip = document.createElement('div');
        chip.className = 'matrix-chip ' + (tier === 'emergency' ? 'matrix-chip-em' : (tier === 'medium' ? 'matrix-chip-med' : 'matrix-chip-rt'));
        chip.innerHTML = `
          <span>${escapeHtml(kw)}</span>
          <button type="button" class="chip-action-btn" title="Remove tag" onclick="removeChip('${tier}', ${idx})">&times;</button>
        `;
        container.appendChild(chip);
      });
    });
    renderMasterCatalog();
    runComplaintTest();
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  window.removeChip = function(tier, idx) {
    let kws = getKeywords(tier);
    kws.splice(idx, 1);
    setKeywords(tier, kws);
    renderChips();
  };

  window.addChipFromInput = function(tier) {
    const input = document.getElementById('add-input-' + tier);
    if (!input) return;
    const val = input.value.trim().toLowerCase();
    if (!val) return;

    // Remove from other tiers first to avoid conflicts
    ['emergency', 'medium', 'routine'].forEach(t => {
      let kws = getKeywords(t);
      kws = kws.filter(k => k.toLowerCase() !== val);
      setKeywords(t, kws);
    });

    // Add to target tier
    let targetKws = getKeywords(tier);
    if (!targetKws.map(k => k.toLowerCase()).includes(val)) {
      targetKws.push(val);
      setKeywords(tier, targetKws);
    }
    input.value = '';
    renderChips();
  };

  // Keyboard shortcut: Press Enter inside quick add inputs
  ['emergency', 'medium', 'routine'].forEach(tier => {
    const inp = document.getElementById('add-input-' + tier);
    if (inp) {
      inp.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          window.addChipFromInput(tier);
        }
      });
    }
  });

  window.setDefectTier = function(defectText, targetTier) {
    const val = defectText.trim().toLowerCase();
    // Remove from all tiers
    ['emergency', 'medium', 'routine'].forEach(t => {
      let kws = getKeywords(t);
      kws = kws.filter(k => k.toLowerCase() !== val);
      setKeywords(t, kws);
    });
    // Add to target tier
    let targetKws = getKeywords(targetTier);
    if (!targetKws.map(k => k.toLowerCase()).includes(val)) {
      targetKws.push(val);
      setKeywords(targetTier, targetKws);
    }
    renderChips();
  };

  window.removeDefect = function(defectText) {
    const val = defectText.trim().toLowerCase();
    ['emergency', 'medium', 'routine'].forEach(t => {
      let kws = getKeywords(t);
      kws = kws.filter(k => k.toLowerCase() !== val);
      setKeywords(t, kws);
    });
    renderChips();
  };

  function findDefectTier(defectText) {
    const val = defectText.trim().toLowerCase();
    for (const t of ['emergency', 'medium', 'routine']) {
      const kws = getKeywords(t).map(k => k.toLowerCase());
      if (kws.includes(val)) return t;
    }
    return null;
  }

  function renderMasterCatalog() {
    for (const [cat, items] of Object.entries(defectCatalog)) {
      const container = document.getElementById('catalog-group-' + cat);
      if (!container) continue;
      container.innerHTML = '';
      items.forEach(item => {
        const activeTier = findDefectTier(item);
        const row = document.createElement('div');
        row.className = 'catalog-item-row';
        
        const isEm = activeTier === 'emergency';
        const isMed = activeTier === 'medium';
        const isRt = activeTier === 'routine';

        row.innerHTML = `
          <span style="font-weight: 500; color: #1E293B; text-transform: capitalize;">${escapeHtml(item)}</span>
          <div style="display: flex; gap: 4px; align-items: center;">
            <button type="button" class="catalog-pill-btn ${isEm ? 'active-em' : ''}" onclick="setDefectTier('${item.replace(/'/g, "\\'")}', 'emergency')" title="Set to Emergency (100%)">🔴</button>
            <button type="button" class="catalog-pill-btn ${isMed ? 'active-med' : ''}" onclick="setDefectTier('${item.replace(/'/g, "\\'")}', 'medium')" title="Set to Medium (75%)">🟡</button>
            <button type="button" class="catalog-pill-btn ${isRt ? 'active-rt' : ''}" onclick="setDefectTier('${item.replace(/'/g, "\\'")}', 'routine')" title="Set to Routine (40%)">🟢</button>
            ${activeTier ? `<button type="button" class="catalog-pill-btn" onclick="removeDefect('${item.replace(/'/g, "\\'")}')" title="Remove">&times;</button>` : ''}
          </div>
        `;
        container.appendChild(row);
      });
    }
  }

  window.toggleMasterCatalog = function() {
    const body = document.getElementById('master-catalog-body');
    const icon = document.getElementById('catalog-toggle-icon');
    if (!body || !icon) return;
    if (body.style.display === 'none' || !body.style.display) {
      body.style.display = 'block';
      icon.textContent = '▲ Close Catalog';
    } else {
      body.style.display = 'none';
      icon.textContent = '▼ Open Catalog';
    }
  };

  // Factory Reset
  const resetBtn = document.getElementById('btn-reset-matrix');
  if (resetBtn) {
    resetBtn.addEventListener('click', function() {
      if (confirm('Reset the complaints matrix to system recommended defaults?')) {
        kwEmInput.value = 'starter relay, startic realy, ayaw mag-start, preno, brake failure, air leak, overheat, overheating, tirik, makina, bagsak makina';
        kwMedInput.value = 'alternator, battery drain, low battery, pudpod gulong, flat tire, suspension, pang-ilalim, maingay na makina, tagas langis, tagas';
        kwRtInput.value = 'change oil, regular pms, preventive maintenance, basag salamin, sidemirror, wiper, pundi ilaw, busina, body repair';
        document.getElementById('score-em').value = '100';
        document.getElementById('score-med').value = '75';
        document.getElementById('score-rt').value = '40';
        renderChips();
      }
    });
  }

  // Live Complaint Tester
  const testInput = document.getElementById('test-complaint-input');
  const testBadge = document.getElementById('test-result-badge');

  function runComplaintTest() {
    if (!testInput || !testBadge) return;
    const txt = testInput.value.trim().toLowerCase();
    if (!txt) {
      testBadge.textContent = 'Enter a complaint on the left to test...';
      testBadge.style.background = '#EDF1F5';
      testBadge.style.color = '#5A6876';
      return;
    }

    const emKw = getKeywords('emergency').map(s => s.toLowerCase());
    const medKw = getKeywords('medium').map(s => s.toLowerCase());
    const rtKw = getKeywords('routine').map(s => s.toLowerCase());

    const emScore = document.getElementById('score-em').value || '100';
    const medScore = document.getElementById('score-med').value || '75';
    const rtScore = document.getElementById('score-rt').value || '40';

    // 1. Check emergency
    for (const k of emKw) {
      if (txt.includes(k)) {
        testBadge.textContent = '🔴 Emergency (' + emScore + '% Urgency) — Matched: "' + k + '"';
        testBadge.style.background = '#FAF1EF';
        testBadge.style.color = '#94382C';
        return;
      }
    }

    // 2. Check medium
    for (const k of medKw) {
      if (txt.includes(k)) {
        testBadge.textContent = '🟡 Medium (' + medScore + '% Urgency) — Matched: "' + k + '"';
        testBadge.style.background = '#FAF4EB';
        testBadge.style.color = '#6E4424';
        return;
      }
    }

    // 3. Check routine
    for (const k of rtKw) {
      if (txt.includes(k)) {
        testBadge.textContent = '🟢 Routine (' + rtScore + '% Urgency) — Matched: "' + k + '"';
        testBadge.style.background = '#EEF6F2';
        testBadge.style.color = '#2D6A4F';
        return;
      }
    }

    testBadge.textContent = '🟡 Medium (' + medScore + '% Urgency) — Default (no keyword match)';
    testBadge.style.background = '#FAF4EB';
    testBadge.style.color = '#6E4424';
  }

  if (testInput) {
    testInput.addEventListener('input', runComplaintTest);
  }

  // Initial render
  renderChips();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
