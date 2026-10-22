<?php
use StockFlow\Domain\Permission as P;
/** @var \StockFlow\Domain\User $user */
// Menu theo quyền: chức năng bị thu hồi với vai trò của tài khoản thì không hiện (chức năng vẫn còn trong hệ thống).
$groups = [
    'Tác nghiệp' => [
        'dashboard' => ['/', 'home', 'Tổng quan', [P::XemTongQuan]],
        'new-receipt' => ['/vouchers/new?type=RECEIPT', 'in', 'Lập phiếu nhập', [P::LapPhieuNhap]],
        'new-issue' => ['/vouchers/new?type=ISSUE', 'out', 'Lập phiếu xuất', [P::LapPhieuXuat]],
        'new-transfer' => ['/transfers/new', 'truck', 'Điều chuyển kho', [P::LapPhieuDieuChuyen]],
        'vouchers' => ['/vouchers', 'file', 'Phiếu nhập xuất', [P::XemPhieu]],
        'counts' => ['/counts', 'clipboard', 'Kiểm kê', [P::XemKiemKe, P::KiemKeThucHien, P::KiemKeDuyet]],
    ],
    'Tra cứu & báo cáo' => [
        'inventory' => ['/inventory', 'box', 'Hàng hoá', [P::XemTonKho]],
        'history' => ['/history', 'clock', 'Lịch sử phát sinh', [P::XemLichSu]],
        'reconcile' => ['/reconcile', 'scale', 'Đối chiếu tồn', [P::XemDoiChieu]],
        'reports' => ['/reports', 'chart', 'Báo cáo', [P::XemBaoCao]],
    ],
    'Danh mục' => [
        'warehouses' => ['/warehouses', 'warehouse', 'Kho & nhân sự', [P::XemDanhMuc]],
    ],
    'Quản trị hệ thống' => [
        'users' => ['/users', 'users', 'Tài khoản', [P::QuanLyTaiKhoan]],
        'roles' => ['/roles', 'shield', 'Phân quyền chức năng', [P::PhanQuyen]],
    ],
];
foreach ($groups as $label => $entries) {
    $groups[$label] = array_filter($entries, fn($e) => array_filter($e[3], fn($perm) => $auth->allows($user, $perm)));
    if (!$groups[$label]) {
        unset($groups[$label]);
    }
}
// Dòng mô tả dưới tên: vai trò + kho được gắn (tài khoản cấp cao thấy mọi kho)
$whLabel = $myWarehouse ? $myWarehouse['ma'] . ' — ' . $myWarehouse['ten'] : 'Tất cả các kho';
$roleSummary = $user->role->label() . ' · ' . $whLabel;
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ? $title . ' • ' : '') ?>StockFlow WMS</title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="preload" href="/assets/vendor/fonts/bvp-vietnamese-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/theme.css">
</head>
<body>
<a class="skip-link" href="#main">Đến nội dung chính</a>
<header class="topbar">
  <button class="menu-toggle" type="button" aria-label="Mở menu" data-menu-toggle><?= icon('layers') ?></button>
  <a class="brand" href="/"><img src="/assets/img/logo-mark.svg" alt="" width="32" height="32"><span>Stock<b>Flow</b> <small>WMS</small></span></a>
  <form class="topsearch" action="/history" method="get" role="search">
    <?= icon('search') ?><input type="search" name="q" placeholder="Tìm mã phiếu nhập xuất, SKU, số lô…" aria-label="Tìm kiếm nhanh">
  </form>
  <div class="topuser">
    <span class="avatar" aria-hidden="true"><?= e($user->initials()) ?></span>
    <span class="who" title="<?= e($myWarehouse ? 'Địa chỉ kho: ' . ($myWarehouse['dia_chi'] ?: '—') : 'Tài khoản cấp cao: thấy và thao tác ở mọi kho') ?>"><b><?= e($user->fullName) ?> <em class="role-badge role-<?= e(strtolower($user->role->value)) ?>"><?= e($user->role->label()) ?></em></b><small><?= e($myWarehouse ? $whLabel : 'Tất cả các kho') ?></small></span>
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
<script src="/assets/vendor/chart.umd.min.js" defer></script>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
