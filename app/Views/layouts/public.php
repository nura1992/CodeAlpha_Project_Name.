<?php /** Public marketing layout (landing + directory). */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(($title ?? '') . ' · ' . config('app.brand') . ' ' . config('app.name')) ?></title>
<link rel="icon" href="/assets/images/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body class="public-body">
  <header class="public-header">
    <a href="/" class="public-brand">
      <img src="/assets/images/logo.svg" alt="" width="32" height="32">
      <span><strong><?= e(config('app.brand')) ?></strong><small><?= e(config('app.name')) ?></small></span>
    </a>
    <nav class="public-nav">
      <a href="/directory">Find care</a>
      <a href="/login">Sign in</a>
      <a href="/register" class="btn btn-primary btn-sm">Create account</a>
    </nav>
  </header>
  <?= $content ?>
  <footer class="public-footer">
    <span><?= e(config('app.brand')) ?> — MedCore Phase 1 demo. All records shown are synthetic.</span>
  </footer>
</body>
</html>
