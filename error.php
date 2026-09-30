<?php
/**
 * DuaRTE — Enterprise Error Page
 * Zero raw code, zero stack traces, zero internal path leakage.
 */
if (!defined('APP_NAME')) {
    define('APP_NAME', 'DuaRTE');
}
$base_url = defined('BASE_URL') ? BASE_URL : '/duarte';
?>
<!DOCTYPE html>
<html lang="tl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Paumanhin, May Naganap na Error · <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= $base_url ?>/assets/css/tokens.css">
  <link rel="stylesheet" href="<?= $base_url ?>/assets/css/base.css">
  <style>
    body {
      background: var(--paper, #F5F7FA);
      color: var(--ink, #161E26);
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      margin: 0;
      padding: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .error-card {
      background: var(--surface, #FFFFFF);
      border: 1px solid var(--line, #E1E6EB);
      border-radius: var(--radius-md, 12px);
      box-shadow: 0 8px 24px rgba(22, 30, 38, 0.08);
      max-width: 480px;
      width: 90%;
      padding: 2.5rem 2rem;
      text-align: center;
    }
    .error-logo {
      height: 48px;
      margin-bottom: 1.5rem;
      object-fit: contain;
    }
    .error-icon {
      width: 56px;
      height: 56px;
      border-radius: 50%;
      background: var(--red-tint, #FAF1EF);
      border: 1px solid var(--red-border, #EFC7C1);
      color: var(--red-danger, #94382C);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.75rem;
      margin: 0 auto 1.25rem;
    }
    .error-title {
      font-size: 1.3rem;
      font-weight: 700;
      color: var(--ink, #161E26);
      margin: 0 0 0.5rem;
    }
    .error-desc {
      font-size: 0.9rem;
      color: var(--ink-soft, #5A6876);
      line-height: 1.5;
      margin: 0 0 2rem;
    }
    .btn-group {
      display: flex;
      gap: 0.75rem;
      justify-content: center;
      flex-wrap: wrap;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0.65rem 1.25rem;
      font-size: 0.88rem;
      font-weight: 600;
      border-radius: var(--radius-sm, 8px);
      text-decoration: none;
      cursor: pointer;
      transition: background 0.15s ease, transform 0.1s ease;
      border: 1px solid transparent;
    }
    .btn-primary {
      background: var(--amber, #8C5A32);
      color: #FFFFFF;
    }
    .btn-primary:hover {
      background: var(--amber-dim, #6E4424);
    }
    .btn-outline {
      background: transparent;
      color: var(--ink, #161E26);
      border-color: var(--line, #E1E6EB);
    }
    .btn-outline:hover {
      background: var(--surface-subtle, #EDF1F5);
    }
  </style>
</head>
<body>
  <div class="error-card">
    <img src="<?= $base_url ?>/assets/images/logo.png" alt="<?= htmlspecialchars(APP_NAME) ?>" class="error-logo" onerror="this.style.display='none'">
    <div class="error-icon">⚠️</div>
    <h1 class="error-title">May Naganap na Hindi Inaasahang Error</h1>
    <p class="error-desc">
      May error. Pakisubukan muli.
    </p>
    <div class="btn-group">
      <a href="javascript:location.reload()" class="btn btn-outline">Subukan Muli</a>
      <a href="<?= $base_url ?>/index.php" class="btn btn-primary">Bumalik sa Dashboard</a>
    </div>
  </div>
</body>
</html>
