<?php
$flash = flash_get();
$user = current_user();

function active_page_class(string $page, string $current): string {
    return $page === $current ? ' is-active' : '';
}

function role_label(?string $role): string {
    return match ($role) {
        'super_admin' => 'Super admin',
        'admin' => 'Admin control',
        default => 'Field guard',
    };
}

function role_nav_items(?string $role): array {
    if ($role === 'super_admin') {
        return [
            ['id' => 'platform-overview', 'label' => 'Overview', 'icon' => '○', 'page' => 'super-admin', 'section' => 'platform-overview'],
            ['id' => 'organizations', 'label' => 'Organizations', 'icon' => '▦', 'page' => 'super-admin', 'section' => 'organizations'],
            ['id' => 'new-organization', 'label' => 'Add Company', 'icon' => '+', 'page' => 'super-admin', 'section' => 'new-organization'],
            ['id' => 'organization-admins', 'label' => 'Admins', 'icon' => '⟡', 'page' => 'super-admin', 'section' => 'organization-admins'],
        ];
    }

    if ($role === 'admin') {
        return [
            ['id' => 'admin-overview', 'label' => 'Overview', 'icon' => '○', 'page' => 'admin', 'section' => 'admin-overview'],
            ['id' => 'admin-master', 'label' => 'Master', 'icon' => '▦', 'page' => 'admin', 'section' => 'admin-master'],
            ['id' => 'admin-map', 'label' => 'Live Map', 'icon' => '⌖', 'page' => 'admin', 'section' => 'admin-map'],
            ['id' => 'admin-duty-site-management', 'label' => 'Sites & Zones', 'icon' => '?', 'page' => 'admin', 'section' => 'admin-duty-site-management'],
            ['id' => 'admin-guard-detail', 'label' => 'Guards', 'icon' => '🛡', 'page' => 'admin', 'section' => 'admin-guard-detail'],
            ['id' => 'admin-create-guard', 'label' => 'Guard Setup', 'icon' => '+', 'page' => 'admin', 'section' => 'admin-create-guard'],
        ];
    }

    return [
        ['id' => 'guard-overview', 'label' => 'Overview', 'icon' => '○', 'page' => 'dashboard', 'section' => 'guard-overview'],
        ['id' => 'guard-attendance', 'label' => 'Shift', 'icon' => '⟡', 'page' => 'dashboard', 'section' => 'guard-attendance'],
        ['id' => 'guard-map', 'label' => 'Route', 'icon' => '⌖', 'page' => 'dashboard', 'section' => 'guard-map'],
        ['id' => 'guard-history-page', 'label' => 'History', 'icon' => '☰', 'page' => 'dashboard', 'section' => 'guard-history-page'],
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h(config('app_name')) ?></title>
  <link rel="icon" href="../public/brand-shield.svg">
  <link rel="stylesheet" href="../public/styles.css?v=9">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
</head>
<body>
<script>
(function () {
  const storedTheme = localStorage.getItem('secure360-theme');
  if (storedTheme === 'dark') document.documentElement.classList.add('theme-dark');
})();
function toggleTheme() {
  const root = document.documentElement;
  const next = root.classList.toggle('theme-dark') ? 'dark' : 'light';
  localStorage.setItem('secure360-theme', next);
}
</script>
<?php if ($page === 'landing'): ?>
  <?php include __DIR__ . '/pages/landing.php'; ?>
<?php elseif ($page === 'auth'): ?>
  <?php include __DIR__ . '/pages/auth.php'; ?>
<?php elseif (in_array($page, ['dashboard', 'admin', 'super-admin'], true)): ?>
  <div class="app-shell">
    <aside class="app-sidebar">
      <div class="sidebar-brand">
        <img src="../public/brand-shield.svg" alt="Infipre Security">
        <div>
          <strong>Infipre Security</strong>
          <span>Security operations console</span>
        </div>
      </div>

      <div class="session-card">
        <p class="eyebrow">Session</p>
        <div class="session-user">
          <div class="avatar"><?= strtoupper(substr((string)($user['full_name'] ?? 'IS'), 0, 2)) ?></div>
          <div>
            <strong><?= h($user['full_name'] ?? '') ?></strong>
            <span><?= h($user['shift_label'] ?? role_label($user['role'] ?? null)) ?></span>
          </div>
        </div>
      </div>

      <nav class="sidebar-nav">
        <?php foreach (role_nav_items($user['role'] ?? null) as $item): ?>
          <a class="nav-item<?= (($section ?? '') === ($item['section'] ?? '') || (!$section && $page === ($item['page'] ?? ''))) ? ' is-active' : '' ?>" href="?page=<?= h($item['page'] ?? $page) ?>&section=<?= h($item['section'] ?? $item['id']) ?>">
            <span class="nav-icon"><?= h($item['icon']) ?></span>
            <span><?= h($item['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="sidebar-note">
        <strong>Always-on visibility</strong>
        <p>Live shifts, selfies, and route sync.</p>
      </div>

      <form method="post" class="logout-form">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn btn-outline btn-full">Sign Out</button>
      </form>
    </aside>

    <main class="app-main">
      <header class="app-header card">
        <div>
          <p class="eyebrow">Secure workspace</p>
          <h1><?= h($page === 'super-admin' ? 'Platform Control' : ($page === 'admin' ? 'Operations Control' : 'Guard Command Hub')) ?></h1>
          <span><?= h($page === 'super-admin' ? 'Workspaces, admins, and limits.' : ($page === 'admin' ? 'Roster, attendance, and guard operations.' : 'Selfie, shift, and live route.')) ?></span>
        </div>
        <div class="header-actions">
          <button class="btn btn-outline btn-sm" type="button" onclick="toggleTheme()">Dark mode</button>
          <div class="profile-chip">
            <div class="profile-chip__avatar"><?= strtoupper(substr((string)($user['full_name'] ?? 'IS'), 0, 2)) ?></div>
            <div>
              <strong><?= h(role_label($user['role'] ?? null)) ?></strong>
              <span><?= h($user['email'] ?? '') ?></span>
            </div>
          </div>
        </div>
      </header>

      <?php if (!empty($flash)): ?>
        <div class="toast-stack" aria-live="polite" aria-atomic="true">
          <div class="flash toast <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        </div>
      <?php endif; ?>

      <?php
      if ($page === 'dashboard') {
          include __DIR__ . '/pages/dashboard.php';
      } elseif ($page === 'admin') {
          include __DIR__ . '/pages/admin.php';
      } elseif ($page === 'super-admin') {
          include __DIR__ . '/pages/super-admin.php';
      }
      ?>
    </main>
  </div>
<?php endif; ?>
</body>
</html>




