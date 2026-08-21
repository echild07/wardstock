<?php
// Sub-nav for everything living under the top-level "Where When" tab
// (Fulgrim, PLAN.md §18) — Analysis / Status / Export / Therapy.
// $subActive should be set by the including page to one of:
// 'analysis' | 'status' | 'export' | 'therapy' | 'settings'
// The including page is also expected to have already set $active =
// 'wherewhen' and included partials_nav.php (the top nav), before this.
$subActive = $subActive ?? '';
function wherewhen_nav_class($key, $subActive) { return $key === $subActive ? 'nav-link active' : 'nav-link'; }
?>
<nav class="mainnav subnav">
  <a class="<?= wherewhen_nav_class('analysis', $subActive) ?> icon" href="analysis.php" title="Analysis" aria-label="Analysis">📊</a>
  <a class="<?= wherewhen_nav_class('status', $subActive) ?> icon" href="status.php" title="Status" aria-label="Status">💓</a>
  <a class="<?= wherewhen_nav_class('export', $subActive) ?> icon" href="export.php" title="Export" aria-label="Export">📤</a>
  <a class="<?= wherewhen_nav_class('therapy', $subActive) ?> icon" href="therapy.php" title="Therapy" aria-label="Therapy">🛋️</a>
  <a class="<?= wherewhen_nav_class('settings', $subActive) ?> icon" href="settings.php" title="Settings" aria-label="Settings">⚙️</a>
</nav>
