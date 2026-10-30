<?php
use StockFlow\Domain\Permission as P;
/** @var \StockFlow\Domain\User $user */
// Menu theo quyền: chức năng bị thu hồi với vai trò của tài khoản thì không hiện (chức năng vẫn còn trong hệ thống).
$groups = [
    'Tác nghiệp' => [
        'dashboard' => ['/', 'home', 'Tổng quan', [P::XemTongQuan]],
        // mục có con (phần tử thứ 5): bấm mở trang cha, bấm mũi tên ▾ để xổ / gập các mục con; mục con lọc theo quyền riêng
        // mục cha chỉ xổ / gập các mục con (href '#'), không phải một trang
        'vouchers-group' => ['#', 'file', 'Quản lý phiếu', [P::XemPhieu], [
            'vouchers' => ['/vouchers', 'file', 'Quản lý phiếu', [P::XemPhieu]],
            'returns' => ['/returns', 'truck', 'Trả hàng nhà cung cấp', [P::XemPhieu]],
            'requests' => ['/requests', 'truck', 'Yêu cầu chuyển hàng', [P::XemPhieu]],
            'counts' => ['/counts', 'clipboard', 'Kiểm kê', [P::XemKiemKe, P::KiemKeThucHien, P::KiemKeDuyet]],
        ]],
        'warranty' => ['/warranty', 'shield', 'Bảo hành', [P::XemBaoHanh]],
        'disposals' => ['/disposals', 'box', 'Kho huỷ', [P::XemKhoHuy]],
    ],
    'Tra cứu' => [
        'inventory-group' => ['#', 'box', 'Hàng hoá', [P::XemTonKho], [
            'inventory' => ['/inventory', 'box', 'Danh sách hàng hoá', [P::XemTonKho]],
            'reconcile' => ['/reconcile', 'scale', 'Đối chiếu tồn', [P::XemDoiChieu]],
            'proposals' => ['/proposals', 'box', 'Đề xuất hàng mới', [P::GanMaVach, P::DuyetDeXuatHang]],
        ]],
        'history' => ['/history', 'clock', 'Lịch sử phát sinh', [P::XemLichSu]],
        'valuation-group' => ['#', 'scale', 'Vốn kho', [P::XemBaoCao], [
            'valuation' => ['/valuation', 'scale', 'Tổng quan vốn kho', [P::XemBaoCao]],
            'money-in' => ['/money/in', 'in', 'Tiền nhập', [P::XemBaoCao]],
            'money-out' => ['/money/out', 'out', 'Tiền xuất', [P::XemBaoCao]],
            'money-profit' => ['/money/profit', 'chart', 'Tiền lãi', [P::XemBaoCao]],
        ]],
    ],
    'Danh mục' => [
        'warehouses-group' => ['#', 'warehouse', 'Kho & nhân sự', [P::XemDanhMuc], [
            'warehouses' => ['/warehouses', 'warehouse', 'Danh sách kho & nhân sự', [P::XemDanhMuc]],
            'partners' => ['/partners', 'users', 'Đối tác & nhà cung cấp', [P::XemDanhMuc]],
            'fleet' => ['/fleet', 'truck', 'Đội xe & tài xế', [P::XemDoiXe]],
        ]],
    ],
    'Quản trị hệ thống' => [
        'users' => ['/users', 'users', 'Tài khoản', [P::QuanLyTaiKhoan]],
        'roles' => ['/roles', 'shield', 'Phân quyền chức năng', [P::PhanQuyen]],
    ],
];
$groups['Báo cáo & tài khoản'] = ['reports' => ['/reports', 'chart', 'Báo cáo', [P::XemBaoCao]], 'settings' => ['/settings', 'settings', 'Cài đặt', []]];
$canSee = fn(array $e) => $e[3] === [] || array_filter($e[3], fn($perm) => $auth->allows($user, $perm));
foreach ($groups as $label => $entries) {
    $kept = [];
    foreach ($entries as $key => $e) {
        $kids = array_filter($e[4] ?? [], $canSee);
        if ($canSee($e)) {
            $e[4] = $kids;
            $kept[$key] = $e;
        } else {
            $kept += $kids;   // không xem được mục cha thì các mục con đứng riêng
        }
    }
    $groups[$label] = $kept;
    if (!$groups[$label]) {
        unset($groups[$label]);
    }
}
if (isset($groups['Báo cáo & tài khoản']) && !isset($groups['Báo cáo & tài khoản']['reports'])) {   // không xem được Báo cáo ⇒ nhóm chỉ còn Cài đặt
    $groups['Tài khoản'] = $groups['Báo cáo & tài khoản'];
    unset($groups['Báo cáo & tài khoản']);
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
<script src="<?= asset_url('/assets/js/theme.js') ?>"></script>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" href="/assets/img/favicon.png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<link rel="preload" href="/assets/vendor/fonts/bvp-vietnamese-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= asset_url('/assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/theme.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/ui.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/dark.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/dialog.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/scan.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/erp.css') ?>">
</head>
<body class="app-shell<?= !empty($compact) ? ' compact' : '' ?>">
<a class="skip-link" href="#main">Đến nội dung chính</a>
<header class="topbar">
  <button class="menu-toggle" type="button" aria-label="Mở menu" data-menu-toggle><?= icon('layers') ?></button>
  <a class="brand" href="/" aria-label="<?= e(strip_tags(app_brand())) ?> — Tổng quan" title="Tổng quan"><img src="/assets/img/logo-mark.png" alt="" width="40" height="40"></a>
  <form class="topsearch" action="/history" method="get" role="search">
    <?= icon('search') ?><input type="search" name="q" maxlength="50" data-search-clean placeholder="Tìm mã phiếu, SKU, số lô…" aria-label="Tìm kiếm nhanh">
  </form>
  <div class="topuser">
    <?php $canScan = $auth->allows($user, P::XemTonKho) || $auth->allows($user, P::XemDanhMuc); if ($canScan): ?><button class="icon-btn" type="button" data-scan-open title="Quét mã vạch" aria-label="Quét mã vạch"><?= icon('scan') ?></button><?php endif; ?>
    <?php $nTasks = $notifications['tasks'] ?? []; $nAct = $notifications['activity'] ?? ['new' => 0, 'list' => []];
          $notifTotal = array_sum(array_column($nTasks, 'count')) + (int)$nAct['new']; ?>
    <div class="notif" data-notif>
      <button class="icon-btn notif-btn" type="button" data-notif-toggle aria-haspopup="true" aria-expanded="false" aria-label="Thông báo" title="Thông báo"><?= icon('bell') ?><b class="notif-count" data-notif-count<?= $notifTotal ? '' : ' hidden' ?>><?= $notifTotal > 99 ? '99+' : $notifTotal ?></b></button>
      <div class="notif-pop" data-notif-pop hidden>
        <div class="notif-head"><p>Thông báo</p><button class="notif-readall" type="button" data-notif-readall<?= $nAct['new'] ? '' : ' disabled' ?>>Đánh dấu tất cả là đã đọc</button></div>
        <div class="notif-cols">
          <section class="notif-col notif-act">
            <p class="notif-col-head">Hoạt động mới<em data-notif-n="act"<?= $nAct['new'] ? '' : ' hidden' ?>><?= n($nAct['new']) ?></em></p>
            <ul class="notif-list notif-activity" data-notif-activity>
              <?php foreach ($nAct['list'] as $ac): ?>
              <li><a href="<?= e($ac['href']) ?>" class="notif-item<?= $ac['fresh'] ? ' fresh' : '' ?>"><i class="notif-dot" aria-hidden="true"></i><span><b><?= e($ac['who']) ?></b><small><?= e($ac['text']) ?></small></span><em class="when"><?= e($ac['at']) ?></em></a></li>
              <?php endforeach; ?>
            </ul>
            <p class="notif-empty" data-notif-empty="act"<?= $nAct['list'] ? ' hidden' : '' ?>>Chưa có hoạt động nào</p>
          </section>
          <section class="notif-col notif-tasks">
            <p class="notif-col-head">Việc cần làm<em data-notif-n="tasks"<?= $nTasks ? '' : ' hidden' ?>><?= n(array_sum(array_column($nTasks, 'count'))) ?></em></p>
            <ul class="notif-list" data-notif-list>
              <?php foreach ($nTasks as $nt): ?>
              <li><a href="<?= e($nt['href']) ?>" class="notif-item tone-<?= e($nt['tone']) ?>"><i class="notif-dot" aria-hidden="true"></i><span><b><?= e($nt['title']) ?></b><small><?= e($nt['note']) ?></small></span><em><?= n($nt['count']) ?></em></a></li>
              <?php endforeach; ?>
            </ul>
            <p class="notif-empty" data-notif-empty="tasks"<?= $nTasks ? ' hidden' : '' ?>>Không có việc nào đang chờ bạn</p>
          </section>
        </div>
      </div>
    </div>
    <button class="icon-btn theme-toggle" type="button" data-theme-toggle aria-label="Đổi giao diện sáng / tối" title="Đổi giao diện sáng / tối"><?= str_replace('class="icon"', 'class="icon ic-sun"', icon('sun')) ?><?= str_replace('class="icon"', 'class="icon ic-moon"', icon('moon')) ?></button>
    <?php $avatarInner = $user->avatar ? '<img src="' . e($user->avatarUrl()) . '" alt="">' : e($user->initials()); ?>
    <div class="acct" data-acct>
      <button class="acct-btn" type="button" data-acct-toggle aria-haspopup="true" aria-expanded="false" aria-label="Tài khoản" title="<?= e($user->fullName) ?>"><span class="avatar"><?= $avatarInner ?></span><svg class="acct-caret" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 4.5 6 7.5 9 4.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
      <div class="acct-pop" data-acct-pop hidden>
        <div class="acct-head"><span class="avatar"><?= $avatarInner ?></span><span><b><?= e($user->fullName) ?></b><small>@<?= e($user->username) ?></small></span></div>
        <dl class="acct-info">
          <dt>Vai trò</dt><dd><em class="role-badge role-<?= e(strtolower($user->role->value)) ?>"><?= e($user->role->label()) ?></em></dd>
          <dt>Kho</dt><dd><?= e($myWarehouse ? $whLabel : 'Tất cả các kho') ?></dd>
          <?php if ($myWarehouse && $myWarehouse['dia_chi']): ?><dt>Địa chỉ</dt><dd><?= e($myWarehouse['dia_chi']) ?></dd><?php endif; ?>
        </dl>
        <a class="acct-link" href="/settings"><?= icon('settings') ?>Cài đặt tài khoản</a>
        <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="acct-link acct-out"><?= icon('logout') ?>Đăng xuất</button></form>
      </div>
    </div>
  </div>
</header>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <?php $navBadges ??= []; $navNew ??= [];
    $navLink = function (string $key, string $href, string $ic, string $text, string $tail = '') use ($active, $navBadges, $navNew): string {   // một mục menu + số nhỏ (cần xử lý / thay đổi mới)
        $pa = (int)($navBadges[$key] ?? 0);
        $pn = (int)($navNew[$key] ?? 0);
        $pills = $pa || $pn ? '<span class="nav-pills">' . ($pn ? '<b class="nav-new" title="' . n($pn) . ' thay đổi mới">' . ($pn > 99 ? '+99' : '+' . $pn) . '</b>' : '') . ($pa ? '<b class="nav-badge" title="Cần xử lý">' . n($pa) . '</b>' : '') . '</span>' : '';
        return '<a href="' . e($href) . '" data-nav="' . e($key) . '"' . ($href === '#' ? ' data-nav-toggle' : '') . ($key === $active ? ' class="active" aria-current="page"' : '') . '>' . icon($ic) . '<span>' . e($text) . '</span>' . $pills . $tail . '</a>';
    }; ?>
    <nav aria-label="Điều hướng chính">
      <?php foreach ($groups as $label => $items): ?>
      <p class="nav-group"><?= e($label) ?></p>
        <?php foreach ($items as $key => $entry): [$href, $ic, $text] = $entry; $kids = $entry[4] ?? []; ?>
        <?php if ($kids): ?><div class="nav-tree<?= $key === $active || isset($kids[$active]) ? ' open' : '' ?>" data-nav-tree><?php endif; ?>
      <?= $navLink($key, $href, $ic, $text, $kids ? '<i class="nav-caret" data-nav-caret aria-hidden="true"></i>' : '') ?>
        <?php if ($kids): ?><div class="nav-sub"><?php foreach ($kids as $ck => [$chref, $cic, $ctext]): ?><?= $navLink($ck, $chref, $cic, $ctext) ?><?php endforeach; ?></div></div><?php endif; ?>
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
<?php if ($canScan) { require __DIR__ . '/../partials/scan_dialog.php'; } ?>
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
<?php if (!empty($old)): ?><div id="old-input" hidden data-action="<?= e($old['action']) ?>" data-old="<?= e(json_encode($old['data'], JSON_UNESCAPED_UNICODE)) ?>"></div><?php endif; ?>
<script src="/assets/vendor/chart.umd.min.js" defer></script>
<script src="<?= asset_url('/assets/js/dialog-fx.js') ?>" defer></script>
<script src="<?= asset_url('/assets/js/app.js') ?>" defer></script>
<script src="<?= asset_url('/assets/js/address.js') ?>" defer></script>
<script src="<?= asset_url('/assets/js/route-map.js') ?>" defer></script>
<script src="<?= asset_url('/assets/js/convoy.js') ?>" defer></script>
<script src="<?= asset_url('/assets/js/crew.js') ?>" defer></script>
<script src="<?= asset_url('/assets/js/delivery-km.js') ?>" defer></script>
<script src="<?= asset_url('/assets/js/trips.js') ?>" defer></script>
<script src="<?= asset_url('/assets/js/logistics.js') ?>" defer></script>
<?php if ($canScan): ?><script src="<?= asset_url('/assets/js/scan.js') ?>" defer></script><?php endif; ?>
</body>
</html>
