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
$groups['Tài khoản'] = ['settings' => ['/settings', 'settings', 'Cài đặt', []]];
foreach ($groups as $label => $entries) {
    $groups[$label] = array_filter($entries, fn($e) => $e[3] === [] || array_filter($e[3], fn($perm) => $auth->allows($user, $perm)));
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
<meta name="spa-layout" content="app">
<title><?= e($title ? $title . ' • ' : '') ?><?= e(APP_NAME) ?></title>
<script src="/assets/js/theme.js"></script>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" href="/assets/img/favicon.png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<link rel="preload" href="/assets/vendor/fonts/bvp-vietnamese-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/theme.css">
<link rel="stylesheet" href="/assets/css/ui.css">
<link rel="stylesheet" href="/assets/css/dark.css">
<link rel="stylesheet" href="/assets/css/dialog.css">
</head>
<body class="app-shell<?= !empty($compact) ? ' compact' : '' ?>">
<a class="skip-link" href="#main">Đến nội dung chính</a>
<header class="topbar">
  <button class="menu-toggle" type="button" aria-label="Mở menu" data-menu-toggle><?= icon('layers') ?></button>
  <a class="brand" href="/"><img src="/assets/img/logo-mark.png" alt="" width="40" height="40"><span><?= app_brand() ?> <small><?= e(APP_TAGLINE) ?></small></span></a>
  <form class="topsearch" action="/history" method="get" role="search">
    <?= icon('search') ?><input type="search" name="q" placeholder="Tìm mã phiếu nhập xuất, SKU, số lô…" aria-label="Tìm kiếm nhanh">
  </form>
  <div class="topuser">
    <button class="icon-btn theme-toggle" type="button" data-theme-toggle aria-label="Đổi giao diện sáng / tối" title="Đổi giao diện sáng / tối"><?= str_replace('class="icon"', 'class="icon ic-sun"', icon('sun')) ?><?= str_replace('class="icon"', 'class="icon ic-moon"', icon('moon')) ?></button>
    <a class="avatar" href="/settings" title="Cài đặt tài khoản" aria-label="Cài đặt tài khoản"><?php if ($user->avatar): ?><img src="<?= e($user->avatarUrl()) ?>" alt=""><?php else: ?><?= e($user->initials()) ?><?php endif; ?></a>
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
    <p class="side-foot"><?= e(APP_NAME) ?> · MIT<br>Dữ liệu demo — không dùng thông tin thật.</p>
  </aside>
  <div class="main-wrap">
  <main id="main" tabindex="-1">
    <?php if ($flash): $hasErr = (bool)array_filter($flash, fn($f) => $f[0] === 'err'); ?>
    <noscript><?php foreach ($flash as [$kind, $msg]): ?><div class="flash flash-<?= e($kind) ?>"><?= icon($kind === 'err' ? 'alert' : 'check') ?><span><?= e($msg) ?></span></div><?php endforeach; ?></noscript>
    <?php endif; ?>
    <?= $content ?>
  </main>
  </div>
</div>
<?php if ($flash): ?>
<dialog id="notice-dlg" class="dlg dlg-notice <?= $hasErr ? 'is-err' : 'is-ok' ?>" aria-labelledby="notice-t" data-auto="<?= $hasErr ? 0 : 3200 ?>">
  <div class="nt-icon" aria-hidden="true">
    <?php if ($hasErr): ?><svg viewBox="0 0 24 24"><path d="M12 7v6M12 17v.5"/></svg>
    <?php else: ?><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg><?php endif; ?>
  </div>
  <h2 id="notice-t"><?= $hasErr ? 'Không thực hiện được' : 'Thành công' ?></h2>
  <div class="nt-msg" role="<?= $hasErr ? 'alert' : 'status' ?>"><?php foreach ($flash as [$kind, $msg]): ?><p><?= e($msg) ?></p><?php endforeach; ?></div>
  <div class="actions nt-actions"><button type="button" data-dialog-close autofocus>Đã hiểu</button></div>
  <?php if (!$hasErr): ?><i class="nt-bar" aria-hidden="true"></i><?php endif; ?>
</dialog>
<?php endif; ?>
<script src="/assets/vendor/chart.umd.min.js" defer></script>
<script src="/assets/js/dialog-fx.js" defer></script>
<script src="/assets/js/app.js" defer></script>
<script src="/assets/js/address.js" defer></script>
</body>
</html>
