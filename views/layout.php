<?php
/** @var string $content */
$u = Auth::user();
$here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$workspaceName = $u ? (Settings::get('company_name') ?: 'My workspace') : '';

// [file, label, icon path, also-active-on]
$nav = [
    ['index.php',        'Dashboard',     'M3 13h8V3H3zm10 8h8V11h-8zM3 21h8v-6H3zm10-18v6h8V3z', []],
    ['leads.php',        'Contacts',      'M16 11a4 4 0 1 0-8 0 4 4 0 0 0 8 0zM4 21a8 8 0 0 1 16 0', ['lead.php', 'import.php', 'enroll.php']],
    ['contacts.php',     'Find contacts', 'M11 19a8 8 0 1 1 5.3-2L21 21.7', []],
    ['campaigns.php',    'Campaigns',     'M4 4h16v12H5.2L4 17.2zM8 9h8M8 12h5', ['campaign.php']],
    ['templates.php',    'Email designs', 'M4 5h16v14H4zM4 9h16M9 9v10', ['composer.php']],
    ['appointments.php', 'Appointments',  'M7 3v3m10-3v3M4 8h16M5 5h14v15H5z', []],
    ['settings.php',     'Settings',      'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm7.4-3a7.4 7.4 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7.6 7.6 0 0 0-2-1.2L14.5 3h-5l-.4 2.6a7.6 7.6 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1a7.6 7.6 0 0 0 2 1.2l.4 2.6h5l.4-2.6a7.6 7.6 0 0 0 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2z', []],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? '') !== '' ? $title . ' · scrap3r' : 'scrap3r') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(asset_url('assets/app.css')) ?>">
</head>
<body class="<?= $u ? 'has-shell' : 'is-bare' ?>">
<?php if ($u): ?>
<input type="checkbox" id="nav-toggle" class="nav-toggle" aria-hidden="true">
<aside class="sidebar">
  <div class="sidebar-brand">
    <a href="<?= e(app_url('index.php')) ?>" class="logo"><span class="logo-mark">s3</span>scrap3r</a>
    <div class="workspace" title="Workspace"><?= e($workspaceName) ?></div>
  </div>
  <nav class="sidebar-nav">
    <?php foreach ($nav as [$file, $label, $icon, $also]):
      $active = $here === $file || in_array($here, $also, true); ?>
      <a href="<?= e(app_url($file)) ?>" class="<?= $active ? 'is-active' : '' ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $icon ?>"/></svg><?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-user">
    <div class="avatar"><?= e(strtoupper(substr((string) $u['name'], 0, 1))) ?></div>
    <div class="who">
      <strong><?= e($u['name']) ?></strong>
      <a href="<?= e(app_url('logout.php')) ?>">Sign out</a>
    </div>
  </div>
</aside>
<label for="nav-toggle" class="nav-scrim" aria-hidden="true"></label>
<header class="mobilebar">
  <label for="nav-toggle" class="burger" aria-label="Menu"><span></span><span></span><span></span></label>
  <span class="logo"><span class="logo-mark">s3</span>scrap3r</span>
</header>
<?php endif; ?>

<main class="<?= $u ? 'content' : 'bare' ?>">
<?php if ($f = flash()): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= nl2br(e($f['message'])) ?></div>
<?php endif; ?>
<?= $content ?>
</main>
</body>
</html>
