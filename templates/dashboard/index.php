<?php /** @var array $d */
$maxUnits = max(1, ...array_map(fn($w) => (int)$w['units'], $d['warehouses'] ?: [['units' => 1]]));
?>
<header class="page-head">
  <div><h1>Tổng quan kho</h1><p class="muted"><?= $user->isAdmin ? 'Toàn cảnh các kho: tồn bao nhiêu, nhập xuất ra sao — tính từ sổ phát sinh. Tài khoản quản trị chỉ xem và quản lý.' : 'Số liệu tính từ sổ phát sinh trên kho bạn được truy cập.' ?></p></div>
  <div class="actions">
    <?php if ($auth->allows($user, \StockFlow\Domain\Permission::LapPhieuDieuChuyen)): ?><a class="btn" href="/transfers/new"><?= icon('truck') ?>Điều chuyển kho</a><?php endif; ?>
    <?php if ($auth->allows($user, \StockFlow\Domain\Permission::LapPhieuNhap)): ?><a class="btn btn-ghost" href="/vouchers/new?type=RECEIPT"><?= icon('in') ?>Lập phiếu nhập</a><?php endif; ?>
  </div>
</header>

<section class="kpis" aria-label="Chỉ số chính">
  <a class="kpi" href="/inventory"><span class="kpi-ic"><?= icon('layers') ?></span><b data-count="<?= (int)$d['units'] ?>"><?= n($d['units']) ?></b><span>Đơn vị đang tồn</span></a>
  <a class="kpi kpi-warn" href="/vouchers?status=DRAFT"><span class="kpi-ic"><?= icon('file') ?></span><b data-count="<?= (int)$d['drafts'] ?>"><?= n($d['drafts']) ?></b><span>Phiếu nhập xuất chờ xác nhận</span></a>
  <a class="kpi kpi-ok" href="/vouchers?status=CONFIRMED"><span class="kpi-ic"><?= icon('check') ?></span><b data-count="<?= (int)$d['confirmed_today'] ?>"><?= n($d['confirmed_today']) ?></b><span>Đã xác nhận hôm nay</span></a>
</section>

<div class="grid-2">
  <section class="card chart-card">
    <div class="card-head"><h2>Cơ cấu tồn theo kho</h2><span class="muted small">đơn vị đang tồn</span></div>
    <?php if (!$d['warehouses']): ?><p class="chart-empty">Chưa có dữ liệu để vẽ biểu đồ.</p><?php else: ?>
    <div class="chart-box donut"><canvas data-chart="doughnut" data-labels="<?= e(json_encode(array_column($d['warehouses'], 'ma'))) ?>" data-values="<?= e(json_encode(array_map(fn($w) => (int)$w['units'], $d['warehouses']))) ?>" role="img" aria-label="Biểu đồ tròn cơ cấu tồn theo kho"></canvas></div>
    <?php endif; ?>
  </section>
  <section class="card chart-card">
    <div class="card-head"><h2>Nhập – xuất theo kho</h2><span class="muted small">30 ngày gần nhất (số lượng)</span></div>
    <?php if (!$d['warehouses']): ?><p class="chart-empty">Chưa có dữ liệu để vẽ biểu đồ.</p><?php else: ?>
    <div class="chart-box"><canvas data-chart="flowbar" data-labels="<?= e(json_encode(array_column($d['warehouses'], 'ma'))) ?>"
      data-vao="<?= e(json_encode(array_map(fn($w) => (int)($d['flow'][$w['id']]['vao'] ?? 0), $d['warehouses']))) ?>"
      data-ra="<?= e(json_encode(array_map(fn($w) => (int)($d['flow'][$w['id']]['ra'] ?? 0), $d['warehouses']))) ?>" role="img" aria-label="Biểu đồ cột tổng nhập và xuất theo kho"></canvas></div>
    <?php endif; ?>
  </section>
</div>

<section class="card flush">
  <div class="card-head pad"><h2>Từng kho: tồn &amp; xuất nhập</h2><span class="muted small">30 ngày gần nhất · tính từ sổ phát sinh</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Kho</th><th class="num">Tồn hiện tại</th><th class="num">Nhập kho</th><th class="num">Xuất kho</th><th class="num">Chuyển đi</th><th class="num">Chuyển đến</th><th class="num">Điều chỉnh</th><th class="num">Tổng vào</th><th class="num">Tổng ra</th><th class="num">Phiếu chờ</th></tr></thead>
    <tbody>
    <?php $tot = ['units' => 0, 'nhap' => 0, 'xuat' => 0, 'chuyen_di' => 0, 'chuyen_den' => 0, 'dieu_chinh' => 0, 'vao' => 0, 'ra' => 0, 'drafts' => 0];
    foreach ($d['warehouses'] as $w): $f = $d['flow'][$w['id']] ?? ['nhap' => 0, 'xuat' => 0, 'chuyen_di' => 0, 'chuyen_den' => 0, 'dieu_chinh' => 0, 'vao' => 0, 'ra' => 0];
      $tot['units'] += (int)$w['units']; $tot['drafts'] += (int)$w['drafts'];
      foreach (['nhap', 'xuat', 'chuyen_di', 'chuyen_den', 'dieu_chinh', 'vao', 'ra'] as $k) { $tot[$k] += $f[$k]; } ?>
      <tr>
        <td><a href="/inventory?warehouse=<?= (int)$w['id'] ?>"><span class="wh-code"><?= e($w['ma']) ?></span> <b><?= e($w['ten']) ?></b></a></td>
        <td class="num"><b><?= n($w['units']) ?></b></td>
        <td class="num pos"><?= n($f['nhap']) ?></td><td class="num neg"><?= n($f['xuat']) ?></td>
        <td class="num"><?= n($f['chuyen_di']) ?></td><td class="num"><?= n($f['chuyen_den']) ?></td>
        <td class="num"><?= $f['dieu_chinh'] ? signed((int)$f['dieu_chinh']) : '<span class="muted">0</span>' ?></td>
        <td class="num pos"><b><?= n($f['vao']) ?></b></td><td class="num neg"><b><?= n($f['ra']) ?></b></td>
        <td class="num"><?= (int)$w['drafts'] ? '<span class="tag tag-warn">' . n($w['drafts']) . '</span>' : '<span class="muted">0</span>' ?></td>
      </tr>
    <?php endforeach; if (!$d['warehouses']): ?><tr><td colspan="10" class="empty">Chưa có kho nào.</td></tr>
    <?php else: ?>
      <tr class="total-row"><td><b>Tổng cộng</b></td><td class="num"><b><?= n($tot['units']) ?></b></td><td class="num"><?= n($tot['nhap']) ?></td><td class="num"><?= n($tot['xuat']) ?></td>
        <td class="num"><?= n($tot['chuyen_di']) ?></td><td class="num"><?= n($tot['chuyen_den']) ?></td><td class="num"><?= $tot['dieu_chinh'] ? signed((int)$tot['dieu_chinh']) : '0' ?></td>
        <td class="num"><b><?= n($tot['vao']) ?></b></td><td class="num"><b><?= n($tot['ra']) ?></b></td><td class="num"><?= n($tot['drafts']) ?></td></tr>
    <?php endif; ?>
    </tbody></table></div>
  <p class="muted small flow-note">Nhập kho / Xuất kho: phiếu nhập, phiếu xuất ngoài hệ thống · Chuyển đi / đến: điều chuyển giữa các kho · Tổng vào = nhập + chuyển đến · Tổng ra = xuất + chuyển đi.</p>
</section>

<section class="card chart-card">
  <div class="card-head"><h2>Diễn biến 14 ngày</h2><span class="muted small">tổng vào / tổng ra mỗi ngày, mọi kho bạn xem</span></div>
  <div class="chart-box"><canvas data-chart="dailyline" data-labels="<?= e(json_encode(array_map(fn($r) => substr($r['ngay'], 8, 2) . '/' . substr($r['ngay'], 5, 2), $d['daily']))) ?>"
    data-vao="<?= e(json_encode(array_column($d['daily'], 'vao'))) ?>" data-ra="<?= e(json_encode(array_column($d['daily'], 'ra'))) ?>" role="img" aria-label="Biểu đồ đường tổng vào và ra theo ngày"></canvas></div>
</section>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Tồn theo kho</h2><a href="/warehouses">Tất cả kho →</a></div>
    <?php if (!$d['warehouses']): ?><p class="empty">Bạn chưa được cấp quyền ở kho nào. Liên hệ quản trị viên.</p><?php endif; ?>
    <ul class="wh-list">
    <?php foreach ($d['warehouses'] as $i => $w): ?>
      <li style="--i:<?= $i ?>">
        <div class="wh-top"><a href="/inventory?warehouse=<?= (int)$w['id'] ?>"><span class="wh-code"><?= e($w['ma']) ?></span> <?= e($w['ten']) ?></a>
          <span class="muted"><?= n($w['units']) ?> đv · <?= n($w['skus']) ?> mã<?= (int)$w['drafts'] ? ' · <b class="warn-text">' . n($w['drafts']) . ' phiếu chờ</b>' : '' ?></span></div>
        <div class="bar"><i style="--w:<?= round($w['units'] / $maxUnits * 100, 1) ?>%"></i></div>
      </li>
    <?php endforeach; ?>
    </ul>
  </section>

  <section class="card">
    <div class="card-head"><h2>Cảnh báo sắp hết hàng</h2><span class="muted small">tổng các kho bạn xem</span></div>
    <?php if (!$d['low']): ?><p class="empty">Không có mặt hàng nào dưới định mức.</p><?php endif; ?>
    <ul class="rows">
    <?php foreach ($d['low'] as $r): ?>
      <li><span><b><?= e($r['ma_hang']) ?></b> <span class="muted"><?= e($r['ten']) ?></span><br><small class="muted">Định mức tối thiểu <?= n($r['dinh_muc_toi_thieu']) ?> <?= e($r['unit']) ?></small></span>
        <span class="tag tag-<?= (int)$r['so_luong'] <= 0 ? 'danger' : 'warn' ?>"><?= n($r['so_luong']) ?> <?= e($r['unit']) ?></span></li>
    <?php endforeach; ?>
    </ul>
  </section>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Phiếu nhập xuất chờ xác nhận</h2><a href="/vouchers?status=DRAFT">Xem tất cả →</a></div>
    <?php if (!$d['pending']): ?><p class="empty">Không có phiếu nhập xuất nào đang chờ.</p><?php endif; ?>
    <ul class="rows">
    <?php foreach ($d['pending'] as $v): ?>
      <li><span><a href="/vouchers/<?= (int)$v['id'] ?>"><b><?= e($v['ma']) ?></b></a> <?= type_chip($v['loai']) ?><br><small class="muted">Kho <?= e($v['wh_code']) ?> · <?= n($v['line_count']) ?> dòng · <?= e($v['creator_name']) ?> · <?= e(dt($v['ngay_tao'])) ?></small></span>
        <?= $v['loai'] === 'TRANSFER_IN' ? '<span class="tag tag-info">Chờ nhận hàng</span>' : status_tag('DRAFT') ?></li>
    <?php endforeach; ?>
    </ul>
  </section>

  <section class="card">
    <div class="card-head"><h2>Phát sinh gần đây</h2><a href="/history">Sổ phát sinh →</a></div>
    <?php if (!$d['recent']): ?><p class="empty">Chưa có phát sinh nào.</p><?php endif; ?>
    <ul class="rows">
    <?php foreach ($d['recent'] as $m): ?>
      <li><span><a href="/history?q=<?= urlencode($m['voucher_code']) ?>"><b><?= e($m['voucher_code']) ?></b></a> <small class="muted"><?= e($m['wh_code']) ?> · <?= e($m['ma_hang']) ?></small><br><small class="muted"><?= e(dt($m['ngay_ghi_so'])) ?></small></span>
        <b class="<?= $m['bien_dong'] > 0 ? 'pos' : 'neg' ?>"><?= signed((int)$m['bien_dong']) ?></b></li>
    <?php endforeach; ?>
    </ul>
  </section>
</div>
