<?php /** @var array $d */
$maxUnits = max(1, ...array_map(fn($w) => (int)$w['units'], $d['warehouses'] ?: [['units' => 1]]));
?>
<header class="page-head">
  <div><h1>Tổng quan kho</h1><p class="muted">Số liệu tính từ sổ phát sinh trên các kho bạn được truy cập.</p></div>
  <div class="actions">
    <a class="btn" href="/transfers/new"><?= icon('truck') ?>Điều chuyển kho</a>
    <a class="btn btn-ghost" href="/vouchers/new?type=RECEIPT"><?= icon('in') ?>Lập phiếu nhập</a>
  </div>
</header>

<section class="kpis" aria-label="Chỉ số chính">
  <a class="kpi" href="/inventory"><span class="kpi-ic"><?= icon('layers') ?></span><b data-count="<?= (int)$d['units'] ?>"><?= n($d['units']) ?></b><span>Đơn vị đang tồn</span></a>
  <a class="kpi kpi-warn" href="/vouchers?status=DRAFT"><span class="kpi-ic"><?= icon('file') ?></span><b data-count="<?= (int)$d['drafts'] ?>"><?= n($d['drafts']) ?></b><span>Chứng từ chờ xác nhận</span></a>
  <a class="kpi kpi-ok" href="/vouchers?status=CONFIRMED"><span class="kpi-ic"><?= icon('check') ?></span><b data-count="<?= (int)$d['confirmed_today'] ?>"><?= n($d['confirmed_today']) ?></b><span>Đã xác nhận hôm nay</span></a>
  <a class="kpi kpi-info" href="/counts"><span class="kpi-ic"><?= icon('clipboard') ?></span><b data-count="<?= (int)$d['open_counts'] ?>"><?= n($d['open_counts']) ?></b><span>Đợt kiểm kê đang mở</span></a>
</section>

<div class="grid-2">
  <section class="card chart-card">
    <div class="card-head"><h2>Cơ cấu tồn theo kho</h2><span class="muted small">đơn vị đang tồn</span></div>
    <?php if (!$d['warehouses']): ?><p class="chart-empty">Chưa có dữ liệu để vẽ biểu đồ.</p><?php else: ?>
    <div class="chart-box donut"><canvas data-chart="doughnut" data-labels="<?= e(json_encode(array_column($d['warehouses'], 'code'))) ?>" data-values="<?= e(json_encode(array_map(fn($w) => (int)$w['units'], $d['warehouses']))) ?>" role="img" aria-label="Biểu đồ tròn cơ cấu tồn theo kho"></canvas></div>
    <?php endif; ?>
  </section>
  <section class="card chart-card">
    <div class="card-head"><h2>Số mã hàng &amp; phiếu chờ theo kho</h2><span class="muted small">so sánh giữa các kho</span></div>
    <?php if (!$d['warehouses']): ?><p class="chart-empty">Chưa có dữ liệu để vẽ biểu đồ.</p><?php else: ?>
    <div class="chart-box"><canvas data-chart="bar" data-labels="<?= e(json_encode(array_column($d['warehouses'], 'code'))) ?>" data-skus="<?= e(json_encode(array_map(fn($w) => (int)$w['skus'], $d['warehouses']))) ?>" data-drafts="<?= e(json_encode(array_map(fn($w) => (int)$w['drafts'], $d['warehouses']))) ?>" role="img" aria-label="Biểu đồ cột số mã hàng và phiếu chờ theo kho"></canvas></div>
    <?php endif; ?>
  </section>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Tồn theo kho</h2><a href="/warehouses">Tất cả kho →</a></div>
    <?php if (!$d['warehouses']): ?><p class="empty">Bạn chưa được cấp quyền ở kho nào. Liên hệ quản trị viên.</p><?php endif; ?>
    <ul class="wh-list">
    <?php foreach ($d['warehouses'] as $i => $w): ?>
      <li style="--i:<?= $i ?>">
        <div class="wh-top"><a href="/inventory?warehouse=<?= (int)$w['id'] ?>"><span class="wh-code"><?= e($w['code']) ?></span> <?= e($w['name']) ?></a>
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
      <li><span><b><?= e($r['sku']) ?></b> <span class="muted"><?= e($r['name']) ?></span><br><small class="muted">Định mức tối thiểu <?= n($r['reorder_level']) ?> <?= e($r['unit']) ?></small></span>
        <span class="tag tag-<?= (int)$r['qty'] <= 0 ? 'danger' : 'warn' ?>"><?= n($r['qty']) ?> <?= e($r['unit']) ?></span></li>
    <?php endforeach; ?>
    </ul>
  </section>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Chứng từ chờ xác nhận</h2><a href="/vouchers?status=DRAFT">Xem tất cả →</a></div>
    <?php if (!$d['pending']): ?><p class="empty">Không có chứng từ nào đang chờ.</p><?php endif; ?>
    <ul class="rows">
    <?php foreach ($d['pending'] as $v): ?>
      <li><span><a href="/vouchers/<?= (int)$v['id'] ?>"><b><?= e($v['code']) ?></b></a> <?= type_chip($v['type']) ?><br><small class="muted">Kho <?= e($v['wh_code']) ?> · <?= n($v['line_count']) ?> dòng · <?= e($v['creator_name']) ?> · <?= e(dt($v['created_at'])) ?></small></span>
        <?= status_tag('DRAFT') ?></li>
    <?php endforeach; ?>
    </ul>
  </section>

  <section class="card">
    <div class="card-head"><h2>Phát sinh gần đây</h2><a href="/history">Sổ phát sinh →</a></div>
    <?php if (!$d['recent']): ?><p class="empty">Chưa có phát sinh nào.</p><?php endif; ?>
    <ul class="rows">
    <?php foreach ($d['recent'] as $m): ?>
      <li><span><a href="/history?q=<?= urlencode($m['voucher_code']) ?>"><b><?= e($m['voucher_code']) ?></b></a> <small class="muted"><?= e($m['wh_code']) ?> · <?= e($m['sku']) ?></small><br><small class="muted"><?= e(dt($m['posted_at'])) ?></small></span>
        <b class="<?= $m['qty_change'] > 0 ? 'pos' : 'neg' ?>"><?= signed((int)$m['qty_change']) ?></b></li>
    <?php endforeach; ?>
    </ul>
  </section>
</div>
