<?php
// $active should be set by the including page to one of:
// 'home' | 'incidents' | 'daily' | 'medications' | 'wherewhen'
// Status/Export/Therapy moved under 'wherewhen' (Fulgrim, PLAN.md §18) —
// pages that used to set $active to 'status'/'export'/'therapy' now set
// $active = 'wherewhen' for THIS (top) nav, plus $subActive for
// partials_wherewhen_nav.php's own highlighting.
$active = $active ?? '';
function nav_class($key, $active) { return $key === $active ? 'nav-link active' : 'nav-link'; }

// Attention icon (Fulgrim, feature list §3.2 — "maybe an icon on the top
// right that blinks if things are missing"). Every including page has
// already required db.php and set $pdo, so the same reminder computation
// index.php uses for its full banner runs here too, just checked for
// non-emptiness — cheap enough at this app's scale not to bother with a
// separate lightweight query path.
require_once __DIR__ . '/attention.php';
$attentionNeeded = ($pdo instanceof PDO)
    ? (bool)(get_attention_items($pdo) || get_pending_event_count($pdo))
    : false;
?>
<a class="attention-icon<?= $attentionNeeded ? ' attention-icon-active' : '' ?>"
   href="index.php#attention" title="<?= $attentionNeeded ? 'Something needs your attention' : 'Nothing needs attention right now' ?>">🔔</a>
<nav class="mainnav">
  <a class="<?= nav_class('home', $active) ?> icon" href="index.php" title="Home" aria-label="Home">🏠</a>
  <a class="<?= nav_class('incidents', $active) ?> icon" href="incidents.php" title="Incidents" aria-label="Incidents">🚨</a>
  <a class="<?= nav_class('daily', $active) ?> icon" href="daily.php" title="Daily Log" aria-label="Daily Log">📋</a>
  <a class="<?= nav_class('medications', $active) ?> icon" href="medications.php" title="Medications" aria-label="Medications">💊</a>
  <a class="<?= nav_class('wherewhen', $active) ?> icon" href="wherewhen.php" title="wherewhen" aria-label="wherewhen"><img src="wherewhen-logo-32.png" width="32" height="32" alt=""></a>
  <a class="nav-link logout" href="logout.php">Log out</a>
</nav>
