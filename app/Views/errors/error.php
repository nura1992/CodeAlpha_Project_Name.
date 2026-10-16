<div class="center">
  <div class="error-code"><?= (int) ($status ?? 500) ?></div>
  <h1 class="auth-title"><?= e($title ?? 'Error') ?></h1>
  <p class="auth-sub"><?= e($message ?? '') ?></p>
  <a class="btn btn-primary" href="/dashboard">Go to dashboard</a>
  <div class="auth-links"><a href="/">Home</a></div>
</div>
