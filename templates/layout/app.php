<?php
/** @var \StockFlow\Domain\User $user */
$groups = [
    'Tác nghiệp' => [
        'dashboard' => ['/', 'home', 'Tổng quan'],
        'new-receipt' => ['/vouchers/new?type=RECEIPT', 'in', 'Lập phiếu nhập'],
        'new-issue' => ['/vouchers/new?type=ISSUE', 'out', 'Lập phiếu xuất'],
        'new-transfer' => ['/transfers/new', 'truck', 'Điều chuyển kho'],
        'vouchers' => ['/vouchers', 'file', 'Chứng từ'],
        'counts' => ['/counts', 'clipboard', 'Kiểm kê'],
    ],
    'Tra cứu & báo cáo' => [
        'inventory' => ['/inventory', 'layers', 'Tồn kho'],
        'history' => ['/history', 'clock', 'Lịch sử phát sinh'],
        'reconcile' => ['/reconcile', 'scale', 'Đối chiếu tồn'],
        'reports' => ['/reports', 'chart', 'Báo cáo'],
    ],
    'Danh mục' => [
        'warehouses' => ['/warehouses', 'warehouse', 'Kho'],
        'items' => ['/items', 'box', 'Mặt hàng & lô'],
    ] + ($user->isAdmin ? ['users' => ['/users', 'users', 'Tài khoản & quyền']] : []),
];
$roleNames = [];
foreach ($auth->rolesOf($user) as $rs) {
    foreach ($rs as $r) {
        $roleNames[\StockFlow\Domain\Role::from($r)->label()] = true;
    }
}
$roleSummary = $user->isAdmin ? 'Quản trị viên' : implode(' · ', array_keys($roleNames));
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ? $title . ' • ' : '') ?>StockFlow WMS</title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main">Đến nội dung chính</a>
<header class="topbar">
  <button class="menu-toggle" type="button" aria-label="Mở menu" data-menu-toggle><?= icon('layers') ?></button>
  <a class="brand" href="/"><img src="/assets/img/logo-mark.svg" alt="" width="32" height="32"><span>Stock<b>Flow</b> <small>WMS</small></span></a>
  <form class="topsearch" action="/history" method="get" role="search">
    <?= icon('search') ?><input type="search" name="q" placeholder="Tìm mã chứng từ, SKU, số lô…" aria-label="Tìm kiếm nhanh">
  </form>
  <div class="topuser">
    <span class="avatar" aria-hidden="true"><?= e($user->initials()) ?></span>
    <span class="who"><b><?= e($user->fullName) ?></b><small><?= e($roleSummary ?: 'Chưa được cấp quyền kho') ?></small></span>
    <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="icon-btn" title="Đăng xuất" aria-label="Đăng xuất"><?= icon('logout') ?></button></form>
  </div>
</header>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <nav aria-label="Điều hướng chính">
      <?php foreach ($groups as $label => $items): ?>
      <p class="nav-group"><?= e($label) ?></p>
        <?php foreach ($items as $key => [$href, $ic, $text]): ?>
      <a href="<?= e($href) ?>"<?= $key === $active ? ' class="active" aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e($text) ?></span></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <p class="side-foot">StockFlow · MIT<br>Dữ liệu demo — không dùng thông tin thật.</p>
  </aside>
  <main id="main" tabindex="-1">
    <?php foreach ($flash as [$kind, $msg]): ?>
    <div class="flash flash-<?= e($kind) ?>" role="<?= $kind === 'err' ? 'alert' : 'status' ?>"><?= icon($kind === 'err' ? 'alert' : 'check') ?><span><?= e($msg) ?></span></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
