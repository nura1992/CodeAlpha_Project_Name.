<?php
/** Main authenticated layout: sidebar + topbar + content. */
$nav = [
    ['section' => 'Overview'],
    ['href' => '/dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'show' => true],
    ['href' => '/notifications', 'label' => 'Notifications', 'icon' => 'bell', 'show' => true, 'badge' => $unreadNotifications ?? 0],
    ['section' => 'MediLink — Care', 'show' => \MedCore\Core\Authorization::can((int) ($currentUser['id'] ?? 0), 'appointments.view') || \MedCore\Core\Authorization::can((int) ($currentUser['id'] ?? 0), 'providers.view')],
    ['href' => '/appointments', 'label' => 'Appointments', 'icon' => 'calendar', 'perm' => 'appointments.view'],
    ['href' => '/providers', 'label' => 'Care providers', 'icon' => 'stethoscope', 'perm' => 'providers.view'],
    ['section' => 'MediID — Records', 'show' => \MedCore\Core\Authorization::can((int) ($currentUser['id'] ?? 0), 'patients.view') || \MedCore\Core\Authorization::hasRole((int) ($currentUser['id'] ?? 0), 'patient')],
    ['href' => '/my/record', 'label' => 'My health record', 'icon' => 'shield', 'show' => \MedCore\Core\Authorization::hasRole((int) ($currentUser['id'] ?? 0), 'patient')],
    ['href' => '/patients', 'label' => 'Patients', 'icon' => 'users', 'perm' => 'patients.view'],
    ['section' => 'Administration', 'show' => \MedCore\Core\Authorization::can((int) ($currentUser['id'] ?? 0), 'users.view') || \MedCore\Core\Authorization::can((int) ($currentUser['id'] ?? 0), 'organizations.view')],
    ['href' => '/admin/organizations', 'label' => 'Organizations', 'icon' => 'building', 'perm' => 'organizations.view'],
    ['href' => '/admin/users', 'label' => 'Users', 'icon' => 'user-cog', 'perm' => 'users.view'],
    ['href' => '/admin/roles', 'label' => 'Roles & permissions', 'icon' => 'key', 'perm' => 'roles.view'],
    ['href' => '/admin/audit', 'label' => 'Audit log', 'icon' => 'list', 'perm' => 'audits.view'],
    ['href' => '/admin/settings', 'label' => 'Platform settings', 'icon' => 'settings', 'perm' => 'settings.manage'],
];
$uid = (int) ($currentUser['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e($csrfToken) ?>">
<title><?= e(($title ?? 'Home') . ' · ' . config('app.name')) ?></title>
<link rel="icon" href="/assets/images/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body data-path="<?= e($currentPath) ?>">
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <img src="/assets/images/logo.svg" alt="" class="brand-mark" width="30" height="30">
      <div class="brand-text">
        <span class="brand-name"><?= e(config('app.brand')) ?></span>
        <span class="brand-sub"><?= e(config('app.name')) ?></span>
      </div>
    </div>
    <nav class="side-nav" aria-label="Primary">
      <?php foreach ($nav as $item):
        if (array_key_exists('show', $item) && !$item['show']) continue;
        if (isset($item['perm']) && !\MedCore\Core\Authorization::can($uid, $item['perm'])) continue;
        if (isset($item['section'])): ?>
          <div class="nav-section"><?= e($item['section']) ?></div>
        <?php else:
          $active = $currentPath === $item['href'] || (strlen($item['href']) > 2 && str_starts_with($currentPath, $item['href'] . '/')) || ($item['href'] === '/appointments' && $currentPath === '/my/appointments');
          ?>
          <a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= e($item['href']) ?>">
            <?= partial('partials/icon', ['name' => $item['icon']]) ?>
            <span><?= e($item['label']) ?></span>
            <?php if (!empty($item['badge'])): ?><span class="nav-badge"><?= (int) $item['badge'] ?></span><?php endif; ?>
          </a>
        <?php endif; endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <span class="eco-tag">MedCore Phase 1 · Core Platform</span>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn" id="sidebarToggle" aria-label="Toggle menu"><?= partial('partials/icon', ['name' => 'menu']) ?></button>
      <div class="topbar-title"><?= e($title ?? '') ?></div>
      <div class="topbar-actions">
        <a class="icon-btn" href="/notifications" aria-label="Notifications"><?= partial('partials/icon', ['name' => 'bell']) ?>
          <?php if (($unreadNotifications ?? 0) > 0): ?><span class="dot"></span><?php endif; ?>
        </a>
        <details class="user-menu">
          <summary>
            <span class="avatar"><?= e(strtoupper(mb_substr($currentUser['name'] ?? 'U', 0, 1))) ?></span>
            <span class="user-menu-name"><?= e($currentUser['name'] ?? '') ?></span>
          </summary>
          <div class="menu">
            <div class="menu-head">
              <strong><?= e($currentUser['name'] ?? '') ?></strong>
              <span><?= e($currentUser['email'] ?? '') ?></span>
            </div>
            <a href="/profile"><?= partial('partials/icon', ['name' => 'user']) ?> My profile</a>
            <form method="post" action="/logout"><?= csrf_field() ?>
              <button type="submit" class="menu-btn danger"><?= partial('partials/icon', ['name' => 'logout']) ?> Sign out</button>
            </form>
          </div>
        </details>
      </div>
    </header>

    <main class="content" id="content">
      <?= partial('partials/flash', ['messages' => $flashMessages ?? []]) ?>
      <?= $content ?>
    </main>
    <footer class="footer">
      <span><?= e(config('app.brand')) ?> · <?= e(config('app.name')) ?> — synthetic demo data only</span>
      <span> &copy; <?= date('Y') ?></span>
    </footer>
  </div>
</div>
<script src="<?= asset('js/app.js') ?>" defer></script>
</body>
</html>
