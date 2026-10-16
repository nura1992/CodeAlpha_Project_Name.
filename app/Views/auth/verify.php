<?php if (!empty($ok)): ?>
  <h1 class="auth-title">Email verified</h1>
  <p class="auth-sub">Your email address has been verified. You can now sign in.</p>
  <a class="btn btn-primary btn-block" href="/login">Sign in</a>
<?php else: ?>
  <h1 class="auth-title">Verification failed</h1>
  <p class="auth-sub">This verification link is invalid or has already been used.</p>
  <a class="btn btn-secondary btn-block" href="/login">Back to sign in</a>
<?php endif; ?>
