<?php
/**
 * DuaRTE — MCDA Dynamic Multi-Criteria Algorithm Configuration Panel
 *
 * Allows Admins and Field Supervisors to dynamically configure the
 * operational weights used in prioritizing pending requisitions.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/priority.php';

require_role(['admin', 'field_supervisor']);

$pdo = get_db();
$user = current_user();
$page_title = 'MCDA Algorithm Settings';

$presets = [
    'standard' => [
        'name'        => 'Standard Logistics (Default)',
        'description' => 'Balanced day-to-day allocation.',
        'scarcity'    => 0.45,
        'contention'  => 0.35,
        'reliability' => 0.20,
        'icon'        => '⚖️',
    ],
    'crisis_rush' => [
        'name'        => 'High-Demand / Crisis Response',
        'description' => 'Faster fulfillment in emergencies.',
        'scarcity'    => 0.60,
        'contention'  => 0.30,
        'reliability' => 0.10,
        'icon'        => '🚨',
    ],
    'asset_protection' => [
        'name'        => 'Asset Protection & Accountability',
        'description' => 'Prioritizes reliable borrowers.',
        'scarcity'    => 0.30,
        'contention'  => 0.30,
        'reliability' => 0.40,
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
        $chosen_preset = $_POST['preset'] ?? 'custom';

        if (isset($presets[$chosen_preset])) {
            $w_scarcity    = $presets[$chosen_preset]['scarcity'];
            $w_contention  = $presets[$chosen_preset]['contention'];
            $w_reliability = $presets[$chosen_preset]['reliability'];
            $active_preset = $chosen_preset;
        } else {
            $w_scarcity    = max(0.0, min(1.0, (float)($_POST['scarcity'] ?? 0.45)));
            $w_contention  = max(0.0, min(1.0, (float)($_POST['contention'] ?? 0.35)));
            $w_reliability = max(0.0, min(1.0, (float)($_POST['reliability'] ?? 0.20)));
            $active_preset = 'custom';
        }

        $sum = round($w_scarcity + $w_contention + $w_reliability, 2);
        if ($sum < 0.99 || $sum > 1.01) {
            $error_msg = "Total sum of weights must equal exactly 100%. Current sum: " . round($sum * 100) . "%.";
        } else {
            // Save to database
            $upd = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, description)
                VALUES (:k, :v, :d)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");

            $settings_to_save = [
                'mcda_weight_scarcity'    => [number_format($w_scarcity, 2, '.', ''), 'MCDA weight for inventory scarcity'],
                'mcda_weight_contention'  => [number_format($w_contention, 2, '.', ''), 'MCDA weight for demand contention'],
                'mcda_weight_reliability' => [number_format($w_reliability, 2, '.', ''), 'MCDA weight for requester reliability'],
                'mcda_active_preset'      => [$active_preset, 'Active MCDA preset'],
            ];

            foreach ($settings_to_save as $k => [$v, $d]) {
                $upd->execute(['k' => $k, 'v' => $v, 'd' => $d]);
            }

            audit_log(
                $pdo,
                $user['id'],
                'mcda_settings_update',
                "Updated MCDA weights: Preset='{$active_preset}', Scarcity=" . ($w_scarcity * 100) . "%, Contention=" . ($w_contention * 100) . "%, Reliability=" . ($w_reliability * 100) . "%"
            );

            $success_msg = "MCDA Algorithm weights updated successfully! Active Preset: " . strtoupper($active_preset) . ".";
        }
    }
}

// Fetch active weights
$current_weights = get_mcda_weights($pdo);
$curr_scarcity    = (float)$current_weights['scarcity'];
$curr_contention  = (float)$current_weights['contention'];
$curr_reliability = (float)$current_weights['reliability'];
$curr_preset      = $current_weights['preset'] ?? 'standard';

// Fetch sample pending requisitions to show live preview
$pending_stmt = $pdo->query("
    SELECT r.id, r.created_at, r.requester_id, r.purpose,
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
      <div style="font-size: 0.85rem; color: var(--ink-soft, #7A6A58); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Algorithm Governance</div>
      <h1 style="margin: 4px 0 0 0; font-size: 1.75rem; color: var(--ink, #1F1710);">Multi-Criteria Decision Analysis (MCDA) Settings</h1>
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
    <div class="alert alert-danger" style="background: #FFEBEE; border: 1px solid #EF9A9A; color: #B71C1C; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
      <span style="font-size: 1.2rem;">⚠️</span>
      <span><?= htmlspecialchars($error_msg) ?></span>
    </div>
  <?php endif; ?>

  <!-- Math Formulation Card -->
  <div style="background: var(--surface, #FFFFFF); border: 1px solid var(--line, #E5DFD7); border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
    <h3 style="margin: 0 0 10px 0; font-size: 1.1rem; color: var(--ink, #1F1710); display: flex; align-items: center; gap: 8px;">
      <span>📐</span> Mathematical Prioritization Formula
    </h3>
    <p style="margin: 0 0 14px 0; font-size: 0.9rem; color: var(--ink-soft, #7A6A58); line-height: 1.5;">
      DuaRTE replaces naive First-Come, First-Served (FIFO) ordering with a weighted composite multi-criteria model to eliminate unfair tool hoarding and prevent critical fleet downtime:
    </p>
    <div style="background: var(--paper, #F7F5F0); border: 1px solid var(--line-strong, #D3C9BC); border-radius: 8px; padding: 14px 18px; font-family: monospace; font-size: 1rem; color: var(--charcoal, #2B2118); overflow-x: auto;">
      <strong>Priority Score (0–100)</strong> = 100 × [ (<strong><?= $curr_scarcity ?></strong> × Scarcity) + (<strong><?= $curr_contention ?></strong> × Contention) + (<strong><?= $curr_reliability ?></strong> × Reliability) ]
    </div>
  </div>

  <!-- Configuration Form Grid -->
  <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">

    <!-- Preset Selection Card -->
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
              <div style="display: flex; gap: 8px; font-size: 0.75rem; font-weight: 600;">
                <span style="background: var(--blue-tint); color: var(--blue-info); padding: 3px 6px; border-radius: 4px;">Scarcity: <?= $p['scarcity'] * 100 ?>%</span>
                <span style="background: var(--amber-tint); color: var(--amber); padding: 3px 6px; border-radius: 4px;">Demand: <?= $p['contention'] * 100 ?>%</span>
                <span style="background: var(--green-tint); color: var(--green-ok); padding: 3px 6px; border-radius: 4px;">Trust: <?= $p['reliability'] * 100 ?>%</span>
              </div>
            </div>
          </form>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Custom Slider Form Card -->
    <div style="background: var(--surface, #FFFFFF); border: 1px solid var(--line, #E5DFD7); border-radius: 12px; padding: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
      <h3 style="margin: 0 0 16px 0; font-size: 1.1rem; color: var(--ink, #1F1710); display: flex; align-items: center; gap: 8px;">
        <span>🎛️</span> Custom Tuning Sliders
      </h3>

      <form method="POST" id="custom-weights-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_weights">
        <input type="hidden" name="preset" value="custom">

        <!-- Scarcity Slider -->
        <div style="margin-bottom: 18px;">
          <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
            <label style="font-weight: 600; font-size: 0.85rem; color: var(--ink, #1F1710);">
              📦 Scarcity (Stock Tightness)
            </label>
            <span id="scarcity-val" style="font-weight: bold; color: var(--blue-info); font-size: 0.9rem;"><?= round($curr_scarcity * 100) ?>%</span>
          </div>
          <input aria-label="Scarcity weight" type="range" id="scarcity-slider" name="scarcity" min="0" max="1" step="0.05" value="<?= $curr_scarcity ?>" style="width: 100%;">
          <div style="font-size: 0.75rem; color: var(--ink-soft, #7A6A58); margin-top: 3px;">
            Requested vs remaining stock.
          </div>
        </div>

        <!-- Contention Slider -->
        <div style="margin-bottom: 18px;">
          <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
            <label style="font-weight: 600; font-size: 0.85rem; color: var(--ink, #1F1710);">
              🔥 Contention (Competing Requesters)
            </label>
            <span id="contention-val" style="font-weight: bold; color: var(--amber); font-size: 0.9rem;"><?= round($curr_contention * 100) ?>%</span>
          </div>
          <input aria-label="Contention weight" type="range" id="contention-slider" name="contention" min="0" max="1" step="0.05" value="<?= $curr_contention ?>" style="width: 100%;">
          <div style="font-size: 0.75rem; color: var(--ink-soft, #7A6A58); margin-top: 3px;">
            Requesters competing for item.
          </div>
        </div>

        <!-- Reliability Slider -->
        <div style="margin-bottom: 20px;">
          <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
            <label style="font-weight: 600; font-size: 0.85rem; color: var(--ink, #1F1710);">
              ⭐ Reliability (Return Track Record)
            </label>
            <span id="reliability-val" style="font-weight: bold; color: var(--green-ok); font-size: 0.9rem;"><?= round($curr_reliability * 100) ?>%</span>
          </div>
          <input aria-label="Reliability weight" type="range" id="reliability-slider" name="reliability" min="0" max="1" step="0.05" value="<?= $curr_reliability ?>" style="width: 100%;">
          <div style="font-size: 0.75rem; color: var(--ink-soft, #7A6A58); margin-top: 3px;">
            Past overdue or missing returns.
          </div>
        </div>

        <!-- Total Sum Status Indicator -->
        <div style="padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; background: #F3F4F6;" id="sum-container">
          <span style="font-size: 0.85rem; font-weight: 600; color: var(--ink, #1F1710);">Total Weight Sum:</span>
          <span id="total-sum-badge" style="font-weight: bold; font-size: 0.9rem; padding: 2px 8px; border-radius: 6px; background: #D1FAE5; color: #065F46;">
            <?= round(($curr_scarcity + $curr_contention + $curr_reliability) * 100) ?>%
          </span>
        </div>

        <button type="submit" id="save-custom-btn" class="btn btn-primary" style="width: 100%; padding: 10px; font-weight: bold; font-size: 0.9rem;">
          Save Custom Weights
        </button>
      </form>
    </div>

  </div>

  <!-- Live Simulation / Preview Table -->
  <div style="background: var(--surface, #FFFFFF); border: 1px solid var(--line, #E5DFD7); border-radius: 12px; padding: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
      <div>
        <h3 style="margin: 0; font-size: 1.1rem; color: var(--ink, #1F1710);">Live Simulation: Pending Queue Priority Ranking</h3>
        <p style="margin: 4px 0 0 0; font-size: 0.8rem; color: var(--ink-soft, #7A6A58);">
          Real-time recalculated scores based on active weights (Preset: <?= strtoupper(htmlspecialchars($curr_preset)) ?>).
        </p>
      </div>
      <span style="font-size: 0.8rem; background: var(--paper, #F7F5F0); padding: 4px 10px; border-radius: 6px; border: 1px solid var(--line, #E5DFD7); font-weight: 600;">
        <?= count($preview_scored) ?> Pending Candidate(s)
      </span>
    </div>

    <?php if (!$preview_scored): ?>
      <p style="color: var(--ink-soft, #7A6A58); font-size: 0.9rem; margin: 20px 0; text-align: center;">
        No pending requests.
      </p>
    <?php else: ?>
      <div style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 720px;">
          <thead>
            <tr style="background: var(--paper, #F7F5F0); text-align: left; border-bottom: 2px solid var(--line, #E5DFD7); white-space: nowrap;">
              <th style="padding: 10px 12px; min-width: 60px;">Rank</th>
              <th style="padding: 10px 12px; min-width: 90px;">Req ID</th>
              <th style="padding: 10px 12px; min-width: 170px;">Requester &amp; Vehicle</th>
              <th style="padding: 10px 12px; min-width: 120px;">Stock (Scarcity)</th>
              <th style="padding: 10px 12px; min-width: 130px;">Demand (Contention)</th>
              <th style="padding: 10px 12px; min-width: 130px;">Trust (Reliability)</th>
              <th style="padding: 10px 12px; min-width: 120px; text-align: right;">Composite MCDA</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($preview_scored as $idx => $r): ?>
              <?php 
                $score = (float)$r['priority_score'];
                $badgeBg = ($score >= 70) ? 'var(--red-tint)' : (($score >= 40) ? 'var(--amber-tint)' : 'var(--green-tint)');
                $badgeColor = ($score >= 70) ? 'var(--red-danger)' : (($score >= 40) ? 'var(--amber)' : 'var(--green-ok)');
              ?>
              <tr style="border-bottom: 1px solid var(--line, #E5DFD7);">
                <td style="padding: 10px 12px; font-weight: bold; color: var(--ink-soft, #7A6A58);">#<?= $idx + 1 ?></td>
                <td style="padding: 10px 12px; font-weight: bold;">
                  <a href="<?= BASE_URL ?>/requisition/view.php?id=<?= $r['id'] ?>" style="color: var(--amber); text-decoration: none;">
                    REQ-<?= $r['id'] ?>
                  </a>
                </td>
                <td style="padding: 10px 12px;">
                  <div style="font-weight: 600; color: var(--ink, #1F1710);"><?= htmlspecialchars($r['requester_name']) ?></div>
                  <div style="font-size: 0.75rem; color: var(--ink-soft, #7A6A58);"><?= htmlspecialchars($r['plate_number'] ?? 'No vehicle') ?></div>
                </td>
                <td style="padding: 10px 12px; color: var(--blue-info);"><?= $r['priority_stock'] ?>%</td>
                <td style="padding: 10px 12px; color: var(--amber);"><?= $r['priority_demand'] ?>%</td>
                <td style="padding: 10px 12px; color: var(--green-ok);"><?= $r['priority_trust'] ?>%</td>
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
  const sSlider = document.getElementById('scarcity-slider');
  const cSlider = document.getElementById('contention-slider');
  const rSlider = document.getElementById('reliability-slider');

  const sVal = document.getElementById('scarcity-val');
  const cVal = document.getElementById('contention-val');
  const rVal = document.getElementById('reliability-val');

  const sumBadge = document.getElementById('total-sum-badge');
  const saveBtn  = document.getElementById('save-custom-btn');

  function updateSum() {
    const s = parseFloat(sSlider.value);
    const c = parseFloat(cSlider.value);
    const r = parseFloat(rSlider.value);

    sVal.textContent = Math.round(s * 100) + '%';
    cVal.textContent = Math.round(c * 100) + '%';
    rVal.textContent = Math.round(r * 100) + '%';

    const total = Math.round((s + c + r) * 100);
    sumBadge.textContent = total + '%';

    if (total === 100) {
      sumBadge.style.background = '#D1FAE5';
      sumBadge.style.color = '#065F46';
      saveBtn.disabled = false;
      saveBtn.style.opacity = '1';
    } else {
      sumBadge.style.background = '#FEE2E2';
      sumBadge.style.color = '#991B1B';
      sumBadge.textContent = total + '% (Must be 100%)';
      saveBtn.disabled = true;
      saveBtn.style.opacity = '0.5';
    }
  }

  sSlider.addEventListener('input', updateSum);
  cSlider.addEventListener('input', updateSum);
  rSlider.addEventListener('input', updateSum);
  updateSum();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
