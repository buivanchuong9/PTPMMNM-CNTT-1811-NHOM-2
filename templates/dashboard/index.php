<?php /** @var array $d */
use StockFlow\Domain\Permission as P;
$maxUnits = max(1, ...array_map(fn($w) => (int)$w['units'], $d['warehouses'] ?: [['units' => 1]]));
$dailyLabels = array_map(fn($r) => substr($r['ngay'], 8, 2) . '/' . substr($r['ngay'], 5, 2), $d['daily']);
?>
<header class="page-head">
  <div><h1>Tổng quan kho</h1></div>
  <div class="actions">
    <?php if ($auth->allows($user, P::LapPhieuDieuChuyen)): ?><a class="btn" href="/transfers/new"><?= icon('truck') ?>Điều chuyển kho</a><?php endif; ?>
    <?php if ($auth->allows($user, P::LapPhieuNhap)): ?><a class="btn btn-ghost" href="/vouchers/new?type=RECEIPT"><?= icon('in') ?>Lập phiếu nhập</a><?php endif; ?>
  </div>
</header>

<section class="kpis kpis-strip" aria-label="Chỉ số chính">
  <a class="kpi" href="/inventory"><span class="kpi-ic"><?= icon('layers') ?></span><b data-count="<?= (int)$d['units'] ?>"><?= n($d['units']) ?></b><span>Đơn vị đang tồn</span></a>
  <a class="kpi kpi-warn" href="/vouchers?status=DRAFT"><span class="kpi-ic"><?= icon('file') ?></span><b data-count="<?= (int)$d['drafts'] ?>"><?= n($d['drafts']) ?></b><span>Phiếu chờ xác nhận</span></a>
  <a class="kpi kpi-ok" href="/vouchers?status=CONFIRMED"><span class="kpi-ic"><?= icon('check') ?></span><b data-count="<?= (int)$d['confirmed_today'] ?>"><?= n($d['confirmed_today']) ?></b><span>Đã xác nhận hôm nay</span></a>
  <?php if ($value): ?>
  <a class="kpi kpi-info" href="/valuation" title="<?= e(n($value['total'])) ?> đ theo giá gốc"><span class="kpi-ic"><?= icon('scale') ?></span><b><?= e(vnd_short($value['total'])) ?></b><span>Giá trị tồn kho (giá gốc, mọi kho)</span></a>
  <?php else: ?>
  <a class="kpi kpi-warn" href="/inventory?stock=low"><span class="kpi-ic"><?= icon('box') ?></span><b data-count="<?= (int)$d['low_count'] ?>"><?= n($d['low_count']) ?></b><span>Mặt hàng sắp hết</span></a>
  <?php endif; ?>
</section>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Phiếu chờ xác nhận</h2><a href="/vouchers?status=DRAFT">Xem tất cả →</a></div>
    <?php if (!$d['pending']): ?><p class="empty">Không có phiếu nào đang chờ.</p><?php endif; ?>
    <ul class="rows">
    <?php foreach ($d['pending'] as $v): ?>
      <li><span><a href="/vouchers/<?= (int)$v['id'] ?>"><b><?= e($v['ma']) ?></b></a> <?= type_chip($v['loai']) ?><br><small class="muted"><?= e($v['wh_code']) ?> · <?= e($v['creator_name']) ?> · <?= e(dt($v['ngay_tao'])) ?></small></span>
        <?= $v['loai'] === 'TRANSFER_IN' ? '<span class="tag tag-info">Chờ nhận hàng</span>' : status_tag('DRAFT') ?></li>
    <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($value): ?>
  <section class="card">
    <div class="card-head"><h2>Giá trị tồn theo kho</h2><a href="/valuation">Chi tiết →</a></div>
    <?php if (!$value['rows']): ?><p class="empty">Chưa có kho nào.</p><?php endif; ?>
    <ul class="rows">
    <?php foreach ($value['rows'] as $r): ?>
      <li><span><b><?= e($r['code']) ?></b> <span class="muted"><?= e($r['name']) ?></span><br><small class="muted"><?= $value['total'] > 0 ? round($r['value'] / $value['total'] * 100) : 0 ?>% tổng giá trị tồn</small></span>
        <span class="tag tag-info" title="<?= e(n($r['value'])) ?> đ"><?= e(vnd_short($r['value'])) ?> đ</span></li>
    <?php endforeach; ?>
    </ul>
    <?php if ($value['missing']): ?><p class="muted small pad"><?= n($value['missing']) ?> dòng tồn chưa có giá gốc nên chưa tính vào tổng.</p><?php endif; ?>
  </section>
  <?php else: ?>
  <section class="card">
    <div class="card-head"><h2>Sắp hết hàng</h2><a href="/inventory?stock=low">Xem tất cả →</a></div>
    <?php if (!$d['low']): ?><p class="empty">Không có mặt hàng nào dưới định mức.</p><?php endif; ?>
    <ul class="rows">
    <?php foreach ($d['low'] as $r): ?>
      <li><span><b><?= e($r['ma_hang']) ?></b> <span class="muted"><?= e($r['ten']) ?></span><br><small class="muted">Tối thiểu <?= n($r['dinh_muc_toi_thieu']) ?> <?= e($r['unit']) ?></small></span>
        <span class="tag tag-<?= (int)$r['so_luong'] <= 0 ? 'danger' : 'warn' ?>"><?= n($r['so_luong']) ?> <?= e($r['unit']) ?></span></li>
    <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
</div>

<?php if ($user->isAdmin): ?>
<div class="grid-2">
  <section class="card chart-card">
    <div class="card-head"><h2>Diễn biến 14 ngày</h2><span class="muted small">vào / ra mỗi ngày</span></div>
    <div class="chart-box"><canvas data-chart="dailyline" data-labels="<?= e(json_encode($dailyLabels)) ?>"
      data-vao="<?= e(json_encode(array_column($d['daily'], 'vao'))) ?>" data-ra="<?= e(json_encode(array_column($d['daily'], 'ra'))) ?>" role="img" aria-label="Biểu đồ đường tổng vào và ra theo ngày"></canvas></div>
  </section>
  <section class="card chart-card">
    <div class="card-head"><h2>Nhập – xuất theo kho</h2><a href="/reports">Chi tiết →</a></div>
    <?php if (!$d['warehouses']): ?><p class="chart-empty">Chưa có dữ liệu.</p><?php else: ?>
    <div class="chart-box"><canvas data-chart="flowbar" data-labels="<?= e(json_encode(array_column($d['warehouses'], 'ma'))) ?>"
      data-vao="<?= e(json_encode(array_map(fn($w) => (int)($d['flow'][$w['id']]['vao'] ?? 0), $d['warehouses']))) ?>"
      data-ra="<?= e(json_encode(array_map(fn($w) => (int)($d['flow'][$w['id']]['ra'] ?? 0), $d['warehouses']))) ?>" role="img" aria-label="Biểu đồ cột tổng nhập và xuất theo kho"></canvas></div>
    <?php endif; ?>
  </section>
</div>
<?php else: ?>
<section class="card chart-card">
  <div class="card-head"><h2>Diễn biến 14 ngày</h2><span class="muted small">vào / ra mỗi ngày</span></div>
  <div class="chart-box"><canvas data-chart="dailyline" data-labels="<?= e(json_encode($dailyLabels)) ?>"
    data-vao="<?= e(json_encode(array_column($d['daily'], 'vao'))) ?>" data-ra="<?= e(json_encode(array_column($d['daily'], 'ra'))) ?>" role="img" aria-label="Biểu đồ đường tổng vào và ra theo ngày"></canvas></div>
</section>
<?php endif; ?>

<div class="grid-2">
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

<?php if ($user->isAdmin): ?>
  <section class="card">
    <div class="card-head"><h2>Tồn theo kho</h2><a href="/warehouses">Tất cả kho →</a></div>
    <?php if (!$d['warehouses']): ?><p class="empty">Chưa có kho nào.</p><?php endif; ?>
    <ul class="wh-list">
    <?php foreach ($d['warehouses'] as $i => $w): ?>
      <li style="--i:<?= $i ?>">
        <div class="wh-top"><a href="/inventory?warehouse=<?= (int)$w['id'] ?>"><span class="wh-code"><?= e($w['ma']) ?></span> <?= e($w['ten']) ?></a>
          <span class="muted"><?= n($w['units']) ?> đv<?= (int)$w['drafts'] ? ' · <b class="warn-text">' . n($w['drafts']) . ' phiếu chờ</b>' : '' ?></span></div>
        <div class="bar"><i style="--w:<?= round($w['units'] / $maxUnits * 100, 1) ?>%"></i></div>
      </li>
    <?php endforeach; ?>
    </ul>
  </section>
<?php else: $topMax = max(1, ...array_map(fn($t) => (int)$t['so_luong'], $d['top'] ?: [['so_luong' => 1]])); ?>
  <section class="card">
    <div class="card-head"><h2>Tồn nhiều nhất</h2><a href="/inventory">Hàng hoá →</a></div>
    <?php if (!$d['top']): ?><p class="empty">Kho chưa có hàng tồn.</p><?php endif; ?>
    <ul class="wh-list">
    <?php foreach ($d['top'] as $i => $t): ?>
      <li style="--i:<?= $i ?>">
        <div class="wh-top"><span><b><?= e($t['ma_hang']) ?></b> <?= e($t['ten']) ?></span><span class="muted"><?= n($t['so_luong']) ?> <?= e($t['unit']) ?></span></div>
        <div class="bar"><i style="--w:<?= round($t['so_luong'] / $topMax * 100, 1) ?>%"></i></div>
      </li>
    <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>
</div>
