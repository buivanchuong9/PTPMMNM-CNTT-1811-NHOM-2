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
        'partners' => ['/partners', 'users', 'Đối tác & nhà cung cấp', [P::XemDanhMuc]],
    ],
    'Quản trị hệ thống' => [
        'users' => ['/users', 'users', 'Tài khoản', [P::QuanLyTaiKhoan]],
        'fleet' => ['/fleet', 'truck', 'Đội xe & tài xế', [P::QuanLyDoiXe]],
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
<?php if (!empty($welcome)): ?>
<div class="welcome" id="welcome" aria-hidden="true" title="Bấm để bỏ qua">
  <p class="welcome-text"><b><?= e($user->fullName) ?></b> đang vào kho…</p>
  <i class="welcome-sun"></i><i class="welcome-cloud c1"></i><i class="welcome-cloud c2"></i><i class="welcome-cloud c3"></i><i class="welcome-bird b1"></i><i class="welcome-bird b2"></i>
  <div class="welcome-city welcome-hills"><div class="welcome-belt"><svg class="welcome-tile" viewBox="0 0 1600 200" width="1600" height="200">
      <path class="hill1" d="M0 200V112C110 112 230 56 380 96S620 72 780 92 1040 38 1200 82 1500 112 1600 112V200Z"/>
      <path class="hill2" d="M0 200V146C160 146 300 104 470 132S760 112 920 128 1240 96 1420 128 1540 146 1600 146V200Z"/>
    </svg><svg class="welcome-tile" viewBox="0 0 1600 200" width="1600" height="200">
      <path class="hill1" d="M0 200V112C110 112 230 56 380 96S620 72 780 92 1040 38 1200 82 1500 112 1600 112V200Z"/>
      <path class="hill2" d="M0 200V146C160 146 300 104 470 132S760 112 920 128 1240 96 1420 128 1540 146 1600 146V200Z"/>
    </svg><svg class="welcome-tile" viewBox="0 0 1600 200" width="1600" height="200">
      <path class="hill1" d="M0 200V112C110 112 230 56 380 96S620 72 780 92 1040 38 1200 82 1500 112 1600 112V200Z"/>
      <path class="hill2" d="M0 200V146C160 146 300 104 470 132S760 112 920 128 1240 96 1420 128 1540 146 1600 146V200Z"/>
    </svg></div></div>
  <div class="welcome-city welcome-far"><div class="welcome-belt"><svg class="welcome-tile" viewBox="0 0 1600 160" width="1600" height="160">
      <g class="sil"><polygon points="36,76 170,40 304,76"/><rect x="36" y="76" width="268" height="84"/></g>
      <rect x="104" y="84" width="132" height="22" rx="3" class="sign"/><text x="170" y="100.5" text-anchor="middle" class="sign-t">KHO</text>
      <g class="door"><rect x="62" y="116" width="54" height="44"/><rect x="144" y="116" width="54" height="44"/><rect x="226" y="116" width="54" height="44"/></g>
      <g class="sil2"><rect x="352" y="50" width="48" height="110" rx="24"/><rect x="408" y="72" width="48" height="88" rx="24"/></g>
      <g class="band"><rect x="352" y="84" width="48" height="3"/><rect x="352" y="116" width="48" height="3"/><rect x="408" y="104" width="48" height="3"/></g>
      <rect x="520" y="14" width="92" height="146" class="sil"/><rect x="532" y="32" width="11" height="12" class="lit"/><rect x="552" y="32" width="11" height="12" class="win"/><rect x="572" y="32" width="11" height="12" class="win"/><rect x="592" y="32" width="11" height="12" class="lit"/><rect x="532" y="56" width="11" height="12" class="win"/><rect x="552" y="56" width="11" height="12" class="win"/><rect x="572" y="56" width="11" height="12" class="lit"/><rect x="592" y="56" width="11" height="12" class="win"/><rect x="532" y="80" width="11" height="12" class="win"/><rect x="552" y="80" width="11" height="12" class="lit"/><rect x="572" y="80" width="11" height="12" class="win"/><rect x="592" y="80" width="11" height="12" class="win"/><rect x="532" y="104" width="11" height="12" class="lit"/><rect x="552" y="104" width="11" height="12" class="win"/><rect x="572" y="104" width="11" height="12" class="win"/><rect x="592" y="104" width="11" height="12" class="lit"/>
      <g class="ct1"><rect x="690" y="132" width="62" height="28"/></g><g class="ct2"><rect x="756" y="132" width="62" height="28"/><rect x="722" y="104" width="62" height="28"/></g><g class="ct3"><rect x="822" y="132" width="62" height="28"/><rect x="790" y="104" width="62" height="28"/><rect x="756" y="76" width="62" height="28"/></g>
      <g class="sil2"><polygon points="930,80 1070,46 1210,80"/><rect x="930" y="80" width="280" height="80"/></g>
      <g class="door"><rect x="958" y="116" width="48" height="44"/><rect x="1020" y="116" width="48" height="44"/><rect x="1082" y="116" width="48" height="44"/><rect x="1144" y="116" width="48" height="44"/></g>
      <rect x="1240" y="30" width="86" height="130" class="sil"/><rect x="1262" y="46" width="10" height="11" class="lit"/><rect x="1282" y="46" width="10" height="11" class="win"/><rect x="1302" y="46" width="10" height="11" class="lit"/><rect x="1262" y="70" width="10" height="11" class="win"/><rect x="1282" y="70" width="10" height="11" class="lit"/><rect x="1302" y="70" width="10" height="11" class="win"/><rect x="1262" y="94" width="10" height="11" class="lit"/><rect x="1282" y="94" width="10" height="11" class="win"/><rect x="1302" y="94" width="10" height="11" class="lit"/>
      <rect x="1352" y="68" width="72" height="92" class="sil2"/>
      <path d="M1470 160V34h8v126zM1470 38h96v6h-96zM1552 38v28" class="crane"/>
    </svg><svg class="welcome-tile" viewBox="0 0 1600 160" width="1600" height="160">
      <g class="sil"><polygon points="36,76 170,40 304,76"/><rect x="36" y="76" width="268" height="84"/></g>
      <rect x="104" y="84" width="132" height="22" rx="3" class="sign"/><text x="170" y="100.5" text-anchor="middle" class="sign-t">KHO</text>
      <g class="door"><rect x="62" y="116" width="54" height="44"/><rect x="144" y="116" width="54" height="44"/><rect x="226" y="116" width="54" height="44"/></g>
      <g class="sil2"><rect x="352" y="50" width="48" height="110" rx="24"/><rect x="408" y="72" width="48" height="88" rx="24"/></g>
      <g class="band"><rect x="352" y="84" width="48" height="3"/><rect x="352" y="116" width="48" height="3"/><rect x="408" y="104" width="48" height="3"/></g>
      <rect x="520" y="14" width="92" height="146" class="sil"/><rect x="532" y="32" width="11" height="12" class="lit"/><rect x="552" y="32" width="11" height="12" class="win"/><rect x="572" y="32" width="11" height="12" class="win"/><rect x="592" y="32" width="11" height="12" class="lit"/><rect x="532" y="56" width="11" height="12" class="win"/><rect x="552" y="56" width="11" height="12" class="win"/><rect x="572" y="56" width="11" height="12" class="lit"/><rect x="592" y="56" width="11" height="12" class="win"/><rect x="532" y="80" width="11" height="12" class="win"/><rect x="552" y="80" width="11" height="12" class="lit"/><rect x="572" y="80" width="11" height="12" class="win"/><rect x="592" y="80" width="11" height="12" class="win"/><rect x="532" y="104" width="11" height="12" class="lit"/><rect x="552" y="104" width="11" height="12" class="win"/><rect x="572" y="104" width="11" height="12" class="win"/><rect x="592" y="104" width="11" height="12" class="lit"/>
      <g class="ct1"><rect x="690" y="132" width="62" height="28"/></g><g class="ct2"><rect x="756" y="132" width="62" height="28"/><rect x="722" y="104" width="62" height="28"/></g><g class="ct3"><rect x="822" y="132" width="62" height="28"/><rect x="790" y="104" width="62" height="28"/><rect x="756" y="76" width="62" height="28"/></g>
      <g class="sil2"><polygon points="930,80 1070,46 1210,80"/><rect x="930" y="80" width="280" height="80"/></g>
      <g class="door"><rect x="958" y="116" width="48" height="44"/><rect x="1020" y="116" width="48" height="44"/><rect x="1082" y="116" width="48" height="44"/><rect x="1144" y="116" width="48" height="44"/></g>
      <rect x="1240" y="30" width="86" height="130" class="sil"/><rect x="1262" y="46" width="10" height="11" class="lit"/><rect x="1282" y="46" width="10" height="11" class="win"/><rect x="1302" y="46" width="10" height="11" class="lit"/><rect x="1262" y="70" width="10" height="11" class="win"/><rect x="1282" y="70" width="10" height="11" class="lit"/><rect x="1302" y="70" width="10" height="11" class="win"/><rect x="1262" y="94" width="10" height="11" class="lit"/><rect x="1282" y="94" width="10" height="11" class="win"/><rect x="1302" y="94" width="10" height="11" class="lit"/>
      <rect x="1352" y="68" width="72" height="92" class="sil2"/>
      <path d="M1470 160V34h8v126zM1470 38h96v6h-96zM1552 38v28" class="crane"/>
    </svg><svg class="welcome-tile" viewBox="0 0 1600 160" width="1600" height="160">
      <g class="sil"><polygon points="36,76 170,40 304,76"/><rect x="36" y="76" width="268" height="84"/></g>
      <rect x="104" y="84" width="132" height="22" rx="3" class="sign"/><text x="170" y="100.5" text-anchor="middle" class="sign-t">KHO</text>
      <g class="door"><rect x="62" y="116" width="54" height="44"/><rect x="144" y="116" width="54" height="44"/><rect x="226" y="116" width="54" height="44"/></g>
      <g class="sil2"><rect x="352" y="50" width="48" height="110" rx="24"/><rect x="408" y="72" width="48" height="88" rx="24"/></g>
      <g class="band"><rect x="352" y="84" width="48" height="3"/><rect x="352" y="116" width="48" height="3"/><rect x="408" y="104" width="48" height="3"/></g>
      <rect x="520" y="14" width="92" height="146" class="sil"/><rect x="532" y="32" width="11" height="12" class="lit"/><rect x="552" y="32" width="11" height="12" class="win"/><rect x="572" y="32" width="11" height="12" class="win"/><rect x="592" y="32" width="11" height="12" class="lit"/><rect x="532" y="56" width="11" height="12" class="win"/><rect x="552" y="56" width="11" height="12" class="win"/><rect x="572" y="56" width="11" height="12" class="lit"/><rect x="592" y="56" width="11" height="12" class="win"/><rect x="532" y="80" width="11" height="12" class="win"/><rect x="552" y="80" width="11" height="12" class="lit"/><rect x="572" y="80" width="11" height="12" class="win"/><rect x="592" y="80" width="11" height="12" class="win"/><rect x="532" y="104" width="11" height="12" class="lit"/><rect x="552" y="104" width="11" height="12" class="win"/><rect x="572" y="104" width="11" height="12" class="win"/><rect x="592" y="104" width="11" height="12" class="lit"/>
      <g class="ct1"><rect x="690" y="132" width="62" height="28"/></g><g class="ct2"><rect x="756" y="132" width="62" height="28"/><rect x="722" y="104" width="62" height="28"/></g><g class="ct3"><rect x="822" y="132" width="62" height="28"/><rect x="790" y="104" width="62" height="28"/><rect x="756" y="76" width="62" height="28"/></g>
      <g class="sil2"><polygon points="930,80 1070,46 1210,80"/><rect x="930" y="80" width="280" height="80"/></g>
      <g class="door"><rect x="958" y="116" width="48" height="44"/><rect x="1020" y="116" width="48" height="44"/><rect x="1082" y="116" width="48" height="44"/><rect x="1144" y="116" width="48" height="44"/></g>
      <rect x="1240" y="30" width="86" height="130" class="sil"/><rect x="1262" y="46" width="10" height="11" class="lit"/><rect x="1282" y="46" width="10" height="11" class="win"/><rect x="1302" y="46" width="10" height="11" class="lit"/><rect x="1262" y="70" width="10" height="11" class="win"/><rect x="1282" y="70" width="10" height="11" class="lit"/><rect x="1302" y="70" width="10" height="11" class="win"/><rect x="1262" y="94" width="10" height="11" class="lit"/><rect x="1282" y="94" width="10" height="11" class="win"/><rect x="1302" y="94" width="10" height="11" class="lit"/>
      <rect x="1352" y="68" width="72" height="92" class="sil2"/>
      <path d="M1470 160V34h8v126zM1470 38h96v6h-96zM1552 38v28" class="crane"/>
    </svg></div></div>
  <div class="welcome-haze"></div>
  <div class="welcome-ground"></div>
  <div class="welcome-city welcome-near"><div class="welcome-belt"><svg class="welcome-tile" viewBox="0 0 1600 120" width="1600" height="120"><g transform="translate(300 120)"><ellipse cx="0" cy="-10" rx="30" ry="16" class="tree2"/><ellipse cx="-14" cy="-14" rx="18" ry="12" class="tree"/><ellipse cx="14" cy="-14" rx="18" ry="12" class="tree"/></g><g transform="translate(700 120)"><ellipse cx="0" cy="-10" rx="30" ry="16" class="tree2"/><ellipse cx="-14" cy="-14" rx="18" ry="12" class="tree"/><ellipse cx="14" cy="-14" rx="18" ry="12" class="tree"/></g><g transform="translate(1110 120)"><ellipse cx="0" cy="-10" rx="30" ry="16" class="tree2"/><ellipse cx="-14" cy="-14" rx="18" ry="12" class="tree"/><ellipse cx="14" cy="-14" rx="18" ry="12" class="tree"/></g><g transform="translate(1520 120)"><ellipse cx="0" cy="-10" rx="30" ry="16" class="tree2"/><ellipse cx="-14" cy="-14" rx="18" ry="12" class="tree"/><ellipse cx="14" cy="-14" rx="18" ry="12" class="tree"/></g><g transform="translate(110 120) scale(1.0)"><rect x="-4" y="-46" width="8" height="46" class="trunk"/><circle cx="0" cy="-64" r="30" class="tree2"/><circle cx="-14" cy="-52" r="22" class="tree"/><circle cx="16" cy="-54" r="24" class="tree"/><circle cx="2" cy="-78" r="20" class="tree"/></g><g transform="translate(500 120) scale(0.9)"><rect x="-4" y="-46" width="8" height="46" class="trunk"/><circle cx="0" cy="-64" r="30" class="tree2"/><circle cx="-14" cy="-52" r="22" class="tree"/><circle cx="16" cy="-54" r="24" class="tree"/><circle cx="2" cy="-78" r="20" class="tree"/></g><g transform="translate(910 120) scale(1.05)"><rect x="-4" y="-46" width="8" height="46" class="trunk"/><circle cx="0" cy="-64" r="30" class="tree2"/><circle cx="-14" cy="-52" r="22" class="tree"/><circle cx="16" cy="-54" r="24" class="tree"/><circle cx="2" cy="-78" r="20" class="tree"/></g><g transform="translate(1330 120) scale(0.95)"><rect x="-4" y="-46" width="8" height="46" class="trunk"/><circle cx="0" cy="-64" r="30" class="tree2"/><circle cx="-14" cy="-52" r="22" class="tree"/><circle cx="16" cy="-54" r="24" class="tree"/><circle cx="2" cy="-78" r="20" class="tree"/></g><g transform="translate(220 120)"><rect x="-2.5" y="-96" width="5" height="96" class="pole"/><rect x="-2.5" y="-96" width="30" height="5" class="pole"/><circle cx="28" cy="-88" r="14" class="glow"/><circle cx="28" cy="-90" r="6" fill="#ffd35a"/></g><g transform="translate(640 120)"><rect x="-2.5" y="-96" width="5" height="96" class="pole"/><rect x="-2.5" y="-96" width="30" height="5" class="pole"/><circle cx="28" cy="-88" r="14" class="glow"/><circle cx="28" cy="-90" r="6" fill="#ffd35a"/></g><g transform="translate(1060 120)"><rect x="-2.5" y="-96" width="5" height="96" class="pole"/><rect x="-2.5" y="-96" width="30" height="5" class="pole"/><circle cx="28" cy="-88" r="14" class="glow"/><circle cx="28" cy="-90" r="6" fill="#ffd35a"/></g><g transform="translate(1480 120)"><rect x="-2.5" y="-96" width="5" height="96" class="pole"/><rect x="-2.5" y="-96" width="30" height="5" class="pole"/><circle cx="28" cy="-88" r="14" class="glow"/><circle cx="28" cy="-90" r="6" fill="#ffd35a"/></g><g class="cone"><polygon points="560,120 574,84 588,120"/><rect x="556" y="116" width="36" height="5"/></g><g class="cone"><polygon points="1210,120 1224,84 1238,120"/><rect x="1206" y="116" width="36" height="5"/></g><g class="pallet"><rect x="800" y="86" width="44" height="34"/><rect x="848" y="98" width="32" height="22"/><rect x="812" y="62" width="32" height="24"/></g><g><rect x="1385" y="40" width="4" height="80" class="pole"/><rect x="1362" y="30" width="50" height="38" rx="6" class="roadsign"/><path d="M1376 56l12-16 12 16h-8v8h-8v-8z" fill="#fff"/></g></svg><svg class="welcome-tile" viewBox="0 0 1600 120" width="1600" height="120"><g transform="translate(300 120)"><ellipse cx="0" cy="-10" rx="30" ry="16" class="tree2"/><ellipse cx="-14" cy="-14" rx="18" ry="12" class="tree"/><ellipse cx="14" cy="-14" rx="18" ry="12" class="tree"/></g><g transform="translate(700 120)"><ellipse cx="0" cy="-10" rx="30" ry="16" class="tree2"/><ellipse cx="-14" cy="-14" rx="18" ry="12" class="tree"/><ellipse cx="14" cy="-14" rx="18" ry="12" class="tree"/></g><g transform="translate(1110 120)"><ellipse cx="0" cy="-10" rx="30" ry="16" class="tree2"/><ellipse cx="-14" cy="-14" rx="18" ry="12" class="tree"/><ellipse cx="14" cy="-14" rx="18" ry="12" class="tree"/></g><g transform="translate(1520 120)"><ellipse cx="0" cy="-10" rx="30" ry="16" class="tree2"/><ellipse cx="-14" cy="-14" rx="18" ry="12" class="tree"/><ellipse cx="14" cy="-14" rx="18" ry="12" class="tree"/></g><g transform="translate(110 120) scale(1.0)"><rect x="-4" y="-46" width="8" height="46" class="trunk"/><circle cx="0" cy="-64" r="30" class="tree2"/><circle cx="-14" cy="-52" r="22" class="tree"/><circle cx="16" cy="-54" r="24" class="tree"/><circle cx="2" cy="-78" r="20" class="tree"/></g><g transform="translate(500 120) scale(0.9)"><rect x="-4" y="-46" width="8" height="46" class="trunk"/><circle cx="0" cy="-64" r="30" class="tree2"/><circle cx="-14" cy="-52" r="22" class="tree"/><circle cx="16" cy="-54" r="24" class="tree"/><circle cx="2" cy="-78" r="20" class="tree"/></g><g transform="translate(910 120) scale(1.05)"><rect x="-4" y="-46" width="8" height="46" class="trunk"/><circle cx="0" cy="-64" r="30" class="tree2"/><circle cx="-14" cy="-52" r="22" class="tree"/><circle cx="16" cy="-54" r="24" class="tree"/><circle cx="2" cy="-78" r="20" class="tree"/></g><g transform="translate(1330 120) scale(0.95)"><rect x="-4" y="-46" width="8" height="46" class="trunk"/><circle cx="0" cy="-64" r="30" class="tree2"/><circle cx="-14" cy="-52" r="22" class="tree"/><circle cx="16" cy="-54" r="24" class="tree"/><circle cx="2" cy="-78" r="20" class="tree"/></g><g transform="translate(220 120)"><rect x="-2.5" y="-96" width="5" height="96" class="pole"/><rect x="-2.5" y="-96" width="30" height="5" class="pole"/><circle cx="28" cy="-88" r="14" class="glow"/><circle cx="28" cy="-90" r="6" fill="#ffd35a"/></g><g transform="translate(640 120)"><rect x="-2.5" y="-96" width="5" height="96" class="pole"/><rect x="-2.5" y="-96" width="30" height="5" class="pole"/><circle cx="28" cy="-88" r="14" class="glow"/><circle cx="28" cy="-90" r="6" fill="#ffd35a"/></g><g transform="translate(1060 120)"><rect x="-2.5" y="-96" width="5" height="96" class="pole"/><rect x="-2.5" y="-96" width="30" height="5" class="pole"/><circle cx="28" cy="-88" r="14" class="glow"/><circle cx="28" cy="-90" r="6" fill="#ffd35a"/></g><g transform="translate(1480 120)"><rect x="-2.5" y="-96" width="5" height="96" class="pole"/><rect x="-2.5" y="-96" width="30" height="5" class="pole"/><circle cx="28" cy="-88" r="14" class="glow"/><circle cx="28" cy="-90" r="6" fill="#ffd35a"/></g><g class="cone"><polygon points="560,120 574,84 588,120"/><rect x="556" y="116" width="36" height="5"/></g><g class="cone"><polygon points="1210,120 1224,84 1238,120"/><rect x="1206" y="116" width="36" height="5"/></g><g class="pallet"><rect x="800" y="86" width="44" height="34"/><rect x="848" y="98" width="32" height="22"/><rect x="812" y="62" width="32" height="24"/></g><g><rect x="1385" y="40" width="4" height="80" class="pole"/><rect x="1362" y="30" width="50" height="38" rx="6" class="roadsign"/><path d="M1376 56l12-16 12 16h-8v8h-8v-8z" fill="#fff"/></g></svg><svg class="welcome-tile" viewBox="0 0 1600 120" width="1600" height="120"><g transform="translate(300 120)"><ellipse cx="0" cy="-10" rx="30" ry="16" class="tree2"/><ellipse cx="-14" cy="-14" rx="18" ry="12" class="tree"/><ellipse cx="14" cy="-14" rx="18" ry="12" class="tree"/></g><g transform="translate(700 120)"><ellipse cx="0" cy="-10" rx="30" ry="16" class="tree2"/><ellipse cx="-14" cy="-14" rx="18" ry="12" class="tree"/><ellipse cx="14" cy="-14" rx="18" ry="12" class="tree"/></g><g transform="translate(1110 120)"><ellipse cx="0" cy="-10" rx="30" ry="16" class="tree2"/><ellipse cx="-14" cy="-14" rx="18" ry="12" class="tree"/><ellipse cx="14" cy="-14" rx="18" ry="12" class="tree"/></g><g transform="translate(1520 120)"><ellipse cx="0" cy="-10" rx="30" ry="16" class="tree2"/><ellipse cx="-14" cy="-14" rx="18" ry="12" class="tree"/><ellipse cx="14" cy="-14" rx="18" ry="12" class="tree"/></g><g transform="translate(110 120) scale(1.0)"><rect x="-4" y="-46" width="8" height="46" class="trunk"/><circle cx="0" cy="-64" r="30" class="tree2"/><circle cx="-14" cy="-52" r="22" class="tree"/><circle cx="16" cy="-54" r="24" class="tree"/><circle cx="2" cy="-78" r="20" class="tree"/></g><g transform="translate(500 120) scale(0.9)"><rect x="-4" y="-46" width="8" height="46" class="trunk"/><circle cx="0" cy="-64" r="30" class="tree2"/><circle cx="-14" cy="-52" r="22" class="tree"/><circle cx="16" cy="-54" r="24" class="tree"/><circle cx="2" cy="-78" r="20" class="tree"/></g><g transform="translate(910 120) scale(1.05)"><rect x="-4" y="-46" width="8" height="46" class="trunk"/><circle cx="0" cy="-64" r="30" class="tree2"/><circle cx="-14" cy="-52" r="22" class="tree"/><circle cx="16" cy="-54" r="24" class="tree"/><circle cx="2" cy="-78" r="20" class="tree"/></g><g transform="translate(1330 120) scale(0.95)"><rect x="-4" y="-46" width="8" height="46" class="trunk"/><circle cx="0" cy="-64" r="30" class="tree2"/><circle cx="-14" cy="-52" r="22" class="tree"/><circle cx="16" cy="-54" r="24" class="tree"/><circle cx="2" cy="-78" r="20" class="tree"/></g><g transform="translate(220 120)"><rect x="-2.5" y="-96" width="5" height="96" class="pole"/><rect x="-2.5" y="-96" width="30" height="5" class="pole"/><circle cx="28" cy="-88" r="14" class="glow"/><circle cx="28" cy="-90" r="6" fill="#ffd35a"/></g><g transform="translate(640 120)"><rect x="-2.5" y="-96" width="5" height="96" class="pole"/><rect x="-2.5" y="-96" width="30" height="5" class="pole"/><circle cx="28" cy="-88" r="14" class="glow"/><circle cx="28" cy="-90" r="6" fill="#ffd35a"/></g><g transform="translate(1060 120)"><rect x="-2.5" y="-96" width="5" height="96" class="pole"/><rect x="-2.5" y="-96" width="30" height="5" class="pole"/><circle cx="28" cy="-88" r="14" class="glow"/><circle cx="28" cy="-90" r="6" fill="#ffd35a"/></g><g transform="translate(1480 120)"><rect x="-2.5" y="-96" width="5" height="96" class="pole"/><rect x="-2.5" y="-96" width="30" height="5" class="pole"/><circle cx="28" cy="-88" r="14" class="glow"/><circle cx="28" cy="-90" r="6" fill="#ffd35a"/></g><g class="cone"><polygon points="560,120 574,84 588,120"/><rect x="556" y="116" width="36" height="5"/></g><g class="cone"><polygon points="1210,120 1224,84 1238,120"/><rect x="1206" y="116" width="36" height="5"/></g><g class="pallet"><rect x="800" y="86" width="44" height="34"/><rect x="848" y="98" width="32" height="22"/><rect x="812" y="62" width="32" height="24"/></g><g><rect x="1385" y="40" width="4" height="80" class="pole"/><rect x="1362" y="30" width="50" height="38" rx="6" class="roadsign"/><path d="M1376 56l12-16 12 16h-8v8h-8v-8z" fill="#fff"/></g></svg></div></div>
  <div class="welcome-truck">
    <i class="welcome-streak s1"></i><i class="welcome-streak s2"></i><i class="welcome-streak s3"></i>
    <svg viewBox="0 0 360 150" fill="none" overflow="visible">
      <defs>
        <linearGradient id="w-body" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="color-mix(in srgb, var(--brand) 78%, #fff)"/><stop offset=".12" stop-color="var(--brand)"/><stop offset="1" stop-color="color-mix(in srgb, var(--brand) 78%, #000)"/></linearGradient>
        <linearGradient id="w-cab" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="color-mix(in srgb, var(--brand) 70%, #fff)"/><stop offset=".15" stop-color="var(--brand)"/><stop offset="1" stop-color="color-mix(in srgb, var(--brand) 72%, #000)"/></linearGradient>
        <linearGradient id="w-glass" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="#d9edff"/><stop offset=".55" stop-color="#7fb2e8"/><stop offset="1" stop-color="#5b92cf"/></linearGradient>
        <linearGradient id="w-bump" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#f0f2f7"/><stop offset="1" stop-color="#8b93a3"/></linearGradient>
        <linearGradient id="w-beam" x1="0" x2="1" y1="0" y2="0"><stop offset="0" stop-color="#fff4c2" stop-opacity=".75"/><stop offset="1" stop-color="#fff4c2" stop-opacity="0"/></linearGradient>
        <radialGradient id="w-rim"><stop offset="0" stop-color="#f3f5f9"/><stop offset=".6" stop-color="#b3bccb"/><stop offset="1" stop-color="#4b5163"/></radialGradient>
      </defs>
      <ellipse cx="180" cy="141" rx="176" ry="6" fill="#000" opacity=".28"/>
      <polygon points="348,86 470,66 470,128 348,102" fill="url(#w-beam)"/>
      <rect x="226" y="8" width="5" height="48" rx="2" fill="#3a3f4c"/><rect x="224" y="6" width="9" height="5" rx="2" fill="#555c6b"/>
      <rect x="14" y="104" width="276" height="12" rx="3" fill="#20242d"/>
      <rect x="8" y="14" width="216" height="92" rx="7" fill="url(#w-body)"/>
      <rect x="8" y="14" width="216" height="9" rx="6" fill="#fff" opacity=".24"/>
      <g stroke="#000" stroke-opacity=".15" stroke-width="1.5"><path d="M61 20V98"/><path d="M114 20V98"/><path d="M167 20V98"/></g>
      <rect x="8" y="68" width="216" height="9" fill="#fff" opacity=".93"/>
      <rect x="8" y="98" width="216" height="8" fill="#000" opacity=".22"/>
      <text x="116" y="47" text-anchor="middle" class="welcome-logo">CHƯƠNG ĐẠI DƯƠNG</text>
      <text x="116" y="60" text-anchor="middle" class="welcome-sub">KHO · VẬN CHUYỂN</text>
      <rect x="4" y="82" width="6" height="14" rx="2" fill="#ff4b4b"/>
      <rect x="150" y="112" width="46" height="15" rx="7" fill="#aab1bf"/><rect x="150" y="112" width="46" height="5" rx="2.5" fill="#fff" opacity=".35"/>
      <path d="M228 38H282Q292 38 298 49L314 80V106H228Z" fill="url(#w-cab)"/>
      <path d="M228 38H282Q292 38 298 49L301 55H228Z" fill="#fff" opacity=".2"/>
      <path d="M240 48H278L293 76H240Z" fill="url(#w-glass)"/><path d="M246 50H260L250 74H240V56Z" fill="#fff" opacity=".35"/>
      <rect x="234" y="44" width="62" height="60" rx="5" fill="none" stroke="#000" stroke-opacity=".2" stroke-width="1.4"/>
      <rect x="244" y="84" width="16" height="3.6" rx="1.8" fill="#eef1f6"/>
      <rect x="222" y="54" width="6" height="18" rx="2" fill="#262a35"/>
      <path d="M314 80H338Q346 80 348 88V106H314Z" fill="url(#w-cab)"/><path d="M314 80H338Q346 80 348 88V92H314Z" fill="#000" opacity=".12"/>
      <rect x="326" y="90" width="12" height="14" rx="2" fill="#2b2f3a"/><g stroke="#69718a" stroke-width="1.4"><path d="M326 94h12M326 98h12M326 102h12"/></g>
      <circle cx="343" cy="92" r="9" fill="#ffe28a" opacity=".35"/><rect x="338" y="86" width="9" height="11" rx="3.5" fill="#ffe9a3"/>
      <rect x="312" y="104" width="40" height="10" rx="3.5" fill="url(#w-bump)"/>
      <rect x="34" y="104" width="30" height="11" fill="#12151b" opacity="0"/>
      <path d="M32 118a28 28 0 0 1 56 0z" fill="#12151b"/><path d="M90 118a28 28 0 0 1 56 0z" fill="#12151b"/><path d="M264 118a28 28 0 0 1 56 0z" fill="#12151b"/>
      <rect x="22" y="112" width="7" height="22" rx="2" fill="#12151b"/>
      <g class="welcome-wheel"><circle cx="60" cy="118" r="22" fill="#171a21"/><circle cx="60" cy="118" r="22" fill="none" stroke="rgba(255,255,255,.1)" stroke-width="1.5"/><circle cx="60" cy="118" r="14" fill="url(#w-rim)"/><g class="welcome-spin" stroke="#69718a" stroke-width="2.4" stroke-linecap="round"><path d="M60 118 L72.00 118.00" /><path d="M60 118 L66.00 128.39" /><path d="M60 118 L54.00 128.39" /><path d="M60 118 L48.00 118.00" /><path d="M60 118 L54.00 107.61" /><path d="M60 118 L66.00 107.61" /></g><circle cx="60" cy="118" r="4.6" fill="#e9edf4"/><circle cx="60" cy="118" r="1.8" fill="#6b7280"/></g><g class="welcome-wheel"><circle cx="118" cy="118" r="22" fill="#171a21"/><circle cx="118" cy="118" r="22" fill="none" stroke="rgba(255,255,255,.1)" stroke-width="1.5"/><circle cx="118" cy="118" r="14" fill="url(#w-rim)"/><g class="welcome-spin" stroke="#69718a" stroke-width="2.4" stroke-linecap="round"><path d="M118 118 L130.00 118.00" /><path d="M118 118 L124.00 128.39" /><path d="M118 118 L112.00 128.39" /><path d="M118 118 L106.00 118.00" /><path d="M118 118 L112.00 107.61" /><path d="M118 118 L124.00 107.61" /></g><circle cx="118" cy="118" r="4.6" fill="#e9edf4"/><circle cx="118" cy="118" r="1.8" fill="#6b7280"/></g><g class="welcome-wheel"><circle cx="292" cy="118" r="22" fill="#171a21"/><circle cx="292" cy="118" r="22" fill="none" stroke="rgba(255,255,255,.1)" stroke-width="1.5"/><circle cx="292" cy="118" r="14" fill="url(#w-rim)"/><g class="welcome-spin" stroke="#69718a" stroke-width="2.4" stroke-linecap="round"><path d="M292 118 L304.00 118.00" /><path d="M292 118 L298.00 128.39" /><path d="M292 118 L286.00 128.39" /><path d="M292 118 L280.00 118.00" /><path d="M292 118 L286.00 107.61" /><path d="M292 118 L298.00 107.61" /></g><circle cx="292" cy="118" r="4.6" fill="#e9edf4"/><circle cx="292" cy="118" r="1.8" fill="#6b7280"/></g>
    </svg>
    <i class="welcome-puff p1"></i><i class="welcome-puff p2"></i><i class="welcome-puff p3"></i>
  </div>
  <div class="welcome-progress"><i></i></div>
</div>
<?php endif; ?>
<header class="topbar">
  <button class="menu-toggle" type="button" aria-label="Mở menu" data-menu-toggle><?= icon('layers') ?></button>
  <a class="brand" href="/"><img src="/assets/img/logo-mark.png" alt="" width="40" height="40"><span><?= app_brand() ?> <small><?= e(APP_TAGLINE) ?></small></span></a>
  <form class="topsearch" action="/history" method="get" role="search">
    <?= icon('search') ?><input type="search" name="q" maxlength="50" data-search-clean placeholder="Tìm mã phiếu nhập xuất, SKU, số lô…" aria-label="Tìm kiếm nhanh">
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
