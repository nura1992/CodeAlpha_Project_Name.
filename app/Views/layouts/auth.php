<?php /** Centered card layout for auth + error pages. */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
<title><?= e(($title ?? '') . ' · ' . config('app.name')) ?></title>
<link rel="icon" href="/assets/images/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body class="auth-body">
  <div class="auth-card">
    <div class="auth-brand">
      <a href="/" class="auth-brand-link">
        <img src="/assets/images/logo.svg" alt="" width="40" height="40">
        <span>
          <strong><?= e(config('app.brand')) ?></strong>
          <small><?= e(config('app.name')) ?></small>
        </span>
      </a>
    </div>
    <?= partial('partials/flash', ['messages' => $flashMessages ?? []]) ?>
    <?= $content ?>
  </div>
  <p class="auth-foot"><a href="/">&larr; Back to home</a> · <a href="/directory">Find care</a></p>
</body>
</html>
