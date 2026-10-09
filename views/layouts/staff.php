<?php
$user = current_user();
$perms = $user ? array_keys(\CodeAlpha\Services\RbacService::permissionsFor((int)$user['id'])) : [];
function staff_can(string $p): bool { return can($p); }
$isSuper = $user ? \CodeAlpha\Services\RbacService::isSuperAdmin((int)$user['id']) : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title ?? 'Staff') ?> — <?= e(config('app.name','CodeAlpha')) ?> Staff</title>
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/staff.css">
</head>
<body class="staff-layout">
<header class="topbar staff-topbar">
  <a href="/staff" class="brand"><?= e(config('app.name','CodeAlpha')) ?> Staff</a>
  <nav class="topnav">
    <a href="/community">Back to app</a>
    <span class="muted"><?= e($user['email'] ?? '') ?></span>
    <a href="/staff/logout">Log out</a>
  </nav>
</header>
<div class="staff-shell">
  <aside class="staff-sidebar">
    <nav>
      <?php if ($isSuper || staff_can('dashboard.super_admin')): ?>
      <div class="sidebar-group"><strong>Super Admin</strong>
        <a href="/staff/super">Overview</a>
        <?php if (staff_can('staff.manage')): ?><a href="/staff/super/staff">Staff accounts</a><?php endif; ?>
        <?php if (staff_can('roles.manage')): ?><a href="/staff/super/roles">Roles & permissions</a><?php endif; ?>
        <?php if (staff_can('features.manage')): ?><a href="/staff/super/features">Feature management</a><?php endif; ?>
        <?php if (staff_can('settings.manage')): ?><a href="/staff/super/settings">System settings</a><?php endif; ?>
        <?php if (staff_can('payments.configure')): ?><a href="/staff/super/gateways">Gateways</a><a href="/staff/super/plans">Plans</a><?php endif; ?>
        <?php if (staff_can('campaigns.manage')): ?><a href="/staff/super/campaigns">Campaigns</a><?php endif; ?>
        <?php if (staff_can('advertisements.manage')): ?><a href="/staff/super/advertisements">Adverts</a><?php endif; ?>
        <?php if (staff_can('audit.view')): ?><a href="/staff/super/audit">Audit log</a><?php endif; ?>
        <?php if (staff_can('analytics.view')): ?><a href="/staff/super/analytics">Analytics</a><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if (staff_can('dashboard.admin')): ?>
      <div class="sidebar-group"><strong>Admin</strong>
        <a href="/staff/admin">Dashboard</a>
        <?php if (staff_can('users.view')): ?><a href="/staff/admin/users">Users</a><?php endif; ?>
        <?php if (staff_can('verification.review')): ?><a href="/staff/admin/verification">Verification</a><?php endif; ?>
        <?php if (staff_can('moderation.review')): ?><a href="/staff/admin/moderation">Moderation</a><?php endif; ?>
        <?php if (staff_can('community.manage')): ?><a href="/staff/admin/community">Community</a><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if (staff_can('dashboard.finance')): ?>
      <div class="sidebar-group"><strong>Finance</strong>
        <a href="/staff/finance">Overview</a>
        <a href="/staff/finance/transactions">Transactions</a>
        <a href="/staff/finance/subscriptions">Subscriptions</a>
        <a href="/staff/finance/reports">Reports</a>
      </div>
      <?php endif; ?>
      <?php if (staff_can('dashboard.support')): ?>
      <div class="sidebar-group"><strong>Support</strong>
        <a href="/staff/support">Dashboard</a>
        <a href="/staff/support/tickets">Tickets</a>
        <?php if (staff_can('support.appeals')): ?><a href="/staff/support/appeals">Appeals</a><?php endif; ?>
        <a href="/staff/support/reports">Reports</a>
      </div>
      <?php endif; ?>
    </nav>
  </aside>
  <main class="staff-main">
    <?php if ($msg = flash('success')): ?><div class="flash flash-success"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($msg = flash('error')): ?><div class="flash flash-error"><?= e($msg) ?></div><?php endif; ?>
    <?= $content ?? '' ?>
  </main>
</div>
<script>window.CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;</script>
<script src="/assets/js/app.js"></script>
</body>
</html>
