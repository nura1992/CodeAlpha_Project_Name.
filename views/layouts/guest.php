<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title ?? config('app.name','CodeAlpha')) ?> — <?= e(config('app.name','CodeAlpha')) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="guest">
<header class="topbar guest-topbar">
  <a href="/" class="brand"><?= e(config('app.name','CodeAlpha')) ?></a>
  <nav class="topnav">
    <a href="/login">Log in</a>
    <a href="/register" class="btn btn-primary">Create account</a>
  </nav>
</header>
<?php if ($msg = flash('success')): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="flash flash-error"><?= e($msg) ?></div><?php endif; ?>
<main class="guest-main"><?= $content ?? '' ?></main>
<footer class="footer">
  <a href="/terms">Terms</a> · <a href="/privacy">Privacy</a> · <a href="/guidelines">Guidelines</a>
  <span class="muted"> · <?= e(config('app.name','CodeAlpha')) ?> — verification confirms platform checks, not identity or intentions.</span>
</footer>
<script>window.CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content;</script>
<script src="/assets/js/app.js"></script>
</body>
</html>
