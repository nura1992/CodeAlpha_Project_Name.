<?php
$user = current_user();
$features = $features ?? \CodeAlpha\Core\FeatureFlags::clientMap();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="theme-color" content="#1877f2">
<title><?= e($title ?? config('app.name','CodeAlpha')) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body data-features='<?= e(json_encode($features)) ?>'>
<header class="topbar">
  <a href="/community" class="brand"><?= e(config('app.name','CodeAlpha')) ?></a>
  <nav class="topnav desktop-nav">
    <?php if (feature_enabled('discovery')): ?><a href="/discover" class="<?= str_contains($_SERVER['REQUEST_URI']??'','/discover')?'active':'' ?>">Discover</a><?php endif; ?>
    <?php if (feature_enabled('discovery')): ?><a href="/matches">Matches</a><?php endif; ?>
    <?php if (feature_enabled('messaging')): ?><a href="/chat">Messages</a><?php endif; ?>
    <a href="/calls">Calls</a>
    <?php if (feature_enabled('community')): ?><a href="/community" class="<?= str_contains($_SERVER['REQUEST_URI']??'','/community')?'active':'' ?>">Community</a><?php endif; ?>
    <a href="/notifications" id="notif-bell">🔔 <span class="badge" id="notif-count" hidden>0</span></a>
    <a href="/profile" class="avatar-link"><?= $user ? e($user['display_name'] ?? 'Profile') : 'Profile' ?></a>
    <a href="/settings">Settings</a>
    <?php if (is_staff_user()): ?><a href="/staff" class="staff-link">Staff</a><?php endif; ?>
    <a href="/logout">Log out</a>
  </nav>
  <button class="hamburger" id="hamburger" aria-label="Menu">☰</button>
</header>
<nav class="mobile-nav" id="mobile-nav" hidden>
  <?php if (feature_enabled('discovery')): ?><a href="/discover">Discover</a><?php endif; ?>
  <a href="/matches">Matches</a>
  <a href="/chat">Messages</a>
  <a href="/calls">Calls</a>
  <?php if (feature_enabled('community')): ?><a href="/community">Community</a><?php endif; ?>
  <a href="/notifications">Notifications</a>
  <a href="/profile">Profile</a>
  <a href="/settings">Settings</a>
  <?php if (feature_enabled('payments')): ?><a href="/subscribe">Subscription</a><?php endif; ?>
  <a href="/support">Support</a>
  <a href="/logout">Log out</a>
</nav>

<?php if ($user && ($user['verification_status'] ?? '') !== 'approved'): ?>
<div class="banner banner-warning">
  <?php if (($user['verification_status'] ?? '') === 'pending'): ?>
    Your profile is under review. You can use community while you wait.
  <?php elseif (($user['verification_status'] ?? '') === 'rejected'): ?>
    Verification was not approved — <a href="/verify">resubmit</a> to enter discovery.
  <?php else: ?>
    Complete <a href="/verify">verification</a> to enter discovery. Community is available now.
  <?php endif; ?>
  <?php if (!feature_enabled('profile_verification')): ?><span class="muted">(verification module is currently disabled by the administrator)</span><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($msg = flash('success')): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="flash flash-error"><?= e($msg) ?></div><?php endif; ?>

<main class="app-main">
  <div class="container"><?= $content ?? '' ?></div>
</main>

<nav class="bottom-nav">
  <?php if (feature_enabled('discovery')): ?><a href="/discover">♡ Discover</a><?php endif; ?>
  <a href="/matches">♥ Matches</a>
  <?php if (feature_enabled('messaging')): ?><a href="/chat">💬 Chat</a><?php endif; ?>
  <?php if (feature_enabled('community')): ?><a href="/community">👥 Community</a><?php endif; ?>
  <a href="/profile">👤 Profile</a>
</nav>

<div id="toast-container"></div>

<script>window.CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content; window.APP_URL = <?= json_encode(config('app.url')) ?>; window.CURRENT_USER = <?= json_encode($user ? ['id'=>(int)$user['id'],'uuid'=>$user['uuid']] : null) ?>;</script>
<script src="/assets/js/app.js"></script>
</body>
</html>
