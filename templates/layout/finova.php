<?php
/** Khung trang Finova: sidebar trái + header + vùng nội dung. Dùng riêng cho các trang /finova/*; không ảnh hưởng khung StockFlow. */
require_once __DIR__ . '/../finova/partials/icons.php';

$nav = [
    'Menu' => [
        ['dashboard', 'Dashboard', 'dashboard', '/'],
        ['orders', 'Orders', 'orders', null],
        ['products', 'Products', 'package', null],
        ['customers', 'Customers', 'users', null],
        ['report', 'Report', 'report', null],
    ],
    'Tools' => [
        ['store', 'My Store', 'store', null],
        ['integration', 'Integration', 'plug', null],
        ['discount', 'Discount', 'percent', null],
        ['invoice', 'Invoice', 'receipt', null],
    ],
    'Support' => [
        ['settings', 'Settings', 'settings', null],
        ['help', 'Help', 'help', null],
    ],
];
$active = $active ?? 'orders';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ? $title . ' • ' : '') ?>Finova</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" href="/assets/img/favicon.png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<link rel="preload" href="/assets/vendor/fonts/bvp-vietnamese-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/css/finova.css">
<link rel="stylesheet" href="/assets/css/dialog.css">
<script src="/assets/js/theme.js"></script>
</head>
<body>
<?= fi_sprite() ?>
<a class="fv-skip" href="#main">Skip to content</a>
<div class="fv-app">
  <div class="fv-shell" id="fv-shell">
    <aside class="fv-side" id="fv-side" aria-label="Sidebar">
      <div class="fv-side-head">
        <a class="fv-brand" href="/finova/orders" aria-label="Finova">
          <svg class="fv-logo" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="16" fill="#D12F3F"/><path d="M16 6.5 24 11v10l-8 4.5L8 21V11z" fill="#fff"/><path d="m16 12.2 3.6 2.1v4.2L16 20.6l-3.6-2.1v-4.2z" fill="#D12F3F"/></svg>
          <span>Finova</span>
        </a>
        <button class="fv-icon-btn fv-drawer-close" type="button" data-drawer-close aria-label="Close menu"><?= fi('x') ?></button>
      </div>

      <nav class="fv-nav" aria-label="Main">
        <?php foreach ($nav as $group => $items): $gid = 'grp-' . strtolower($group); ?>
        <div class="fv-group">
          <button class="fv-group-head" type="button" data-group-toggle aria-expanded="true" aria-controls="<?= e($gid) ?>"><span><?= e($group) ?></span><?= fi('chevron-down') ?></button>
          <ul class="fv-group-list" id="<?= e($gid) ?>">
            <?php foreach ($items as [$key, $label, $icon, $href]): $on = $key === $active; ?>
            <li>
              <?php if ($href !== null): ?>
              <a class="fv-link" href="<?= e($href) ?>" title="<?= e($label) ?>"><?= fi($icon) ?><span><?= e($label) ?></span></a>
              <?php elseif ($on): ?>
              <a class="fv-link is-active" href="/finova/orders" aria-current="page" title="<?= e($label) ?>"><?= fi($icon) ?><span><?= e($label) ?></span></a>
              <?php else: ?>
              <button class="fv-link" type="button" data-soon="<?= e($label) ?>" title="<?= e($label) ?>"><?= fi($icon) ?><span><?= e($label) ?></span></button>
              <?php endif; ?>
            </li>
            <?php endforeach; ?>
            <?php if ($group === 'Support'): ?>
            <li>
              <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <button class="fv-link fv-link-danger" type="submit" title="Logout"><?= fi('logout') ?><span>Logout</span></button>
              </form>
            </li>
            <?php endif; ?>
          </ul>
        </div>
        <?php endforeach; ?>
      </nav>
    </aside>
    <div class="fv-overlay" data-drawer-close hidden></div>

    <div class="fv-main">
      <header class="fv-top">
        <button class="fv-icon-btn fv-menu-btn" type="button" data-drawer-open aria-label="Open menu" aria-controls="fv-side"><?= fi('menu') ?></button>
        <form class="fv-search" id="fv-global-form" role="search" action="/finova/orders" method="get">
          <?= fi('search') ?>
          <input id="fv-global-search" type="search" placeholder="Search orders, products, or customers..." aria-label="Search orders, products, or customers" autocomplete="off">
        </form>

        <div class="fv-top-actions">
          <div class="fv-seg" role="group" aria-label="Color theme">
            <button type="button" data-theme-set="dark" aria-label="Dark mode" title="Dark mode"><?= fi('moon') ?></button>
            <button type="button" data-theme-set="light" aria-label="Light mode" title="Light mode"><?= fi('sun') ?></button>
          </div>
          <div class="fv-seg fv-seg-solo">
            <div class="fv-pop-wrap">
              <button class="fv-round" type="button" data-pop="pop-msg" aria-haspopup="true" aria-expanded="false" aria-label="Messages" title="Messages"><?= fi('message') ?></button>
              <div class="fv-pop" id="pop-msg" hidden role="region" aria-label="Messages"></div>
            </div>
            <div class="fv-pop-wrap">
              <button class="fv-round fv-bell" type="button" data-pop="pop-notif" aria-haspopup="true" aria-expanded="false" aria-label="Notifications" title="Notifications"><?= fi('bell') ?><i class="fv-dot" aria-hidden="true"></i></button>
              <div class="fv-pop" id="pop-notif" hidden role="region" aria-label="Notifications"></div>
            </div>
          </div>
          <div class="fv-pop-wrap">
            <button class="fv-user" type="button" data-pop="pop-user" aria-haspopup="true" aria-expanded="false">
              <span class="fv-avatar" aria-hidden="true">GH</span>
              <span class="fv-user-text"><b>Guy Hawkins</b><small>Admin</small></span>
              <?= fi('chevron-down') ?>
            </button>
            <div class="fv-pop fv-pop-menu" id="pop-user" hidden role="menu" aria-label="Account">
              <button type="button" role="menuitem" data-soon="Profile"><?= fi('user') ?>Profile</button>
              <button type="button" role="menuitem" data-soon="Settings"><?= fi('settings') ?>Settings</button>
              <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" role="menuitem" class="is-danger"><?= fi('logout') ?>Logout</button></form>
            </div>
          </div>
        </div>
      </header>

      <main class="fv-panel" id="main" tabindex="-1">
        <?= $content ?>
      </main>
    </div>
  </div>
</div>
<div class="fv-toasts" id="fv-toasts" aria-live="polite" aria-atomic="false"></div>
<script src="/assets/js/dialog-fx.js" defer></script>
<script type="module" src="/assets/js/finova/orders-page.js"></script>
</body>
</html>
