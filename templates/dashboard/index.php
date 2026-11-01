<?php /** @var array $d */
use StockFlow\Domain\Permission as P;
$rangeLabels = ['7' => '7 ngày', '30' => '30 ngày', '90' => '90 ngày', 'month' => 'Tháng này'];
$scope = $user->isAdmin ? 'toàn hệ thống' : 'kho của bạn';
// kỳ dài (90 ngày) gộp theo tuần cho biểu đồ đỡ dày
$bucket = $days > 31 ? 7 : 1;
$trend = [];
foreach (array_chunk($period['series'], $bucket) as $chunk) {
    $a = substr($chunk[0]['ngay'], 8, 2) . '/' . substr($chunk[0]['ngay'], 5, 2);
    $trend[] = ['l' => $bucket > 1 ? $a . '–' . substr(end($chunk)['ngay'], 8, 2) . '/' . substr(end($chunk)['ngay'], 5, 2) : $a, 'nhap' => array_sum(array_column($chunk, 'nhap')), 'xuat' => array_sum(array_column($chunk, 'xuat'))];
}
$span = $range === 'month' ? 'tháng này' : $days . ' ngày qua';
$before = $range === 'month' ? 'cùng số ngày trước đó' : $days . ' ngày trước đó';
$delta = function (int $cur, int $prev) use ($before): string {
    if ($prev <= 0) {
        return '<small class="kpi-delta">' . ($cur > 0 ? $before . ' chưa có phát sinh' : '—') . '</small>';
    }
    $p = (int)round(($cur - $prev) / $prev * 100);
    return '<small class="kpi-delta ' . ($p >= 0 ? 'up' : 'down') . '">' . ($p >= 0 ? '▲ ' : '▼ ') . abs($p) . '% so với ' . e($before) . '</small>';
};
$donut = $user->isAdmin
    ? ['title' => 'Giá trị tồn theo kho', 'rows' => array_filter(array_column($value['rows'], 'value', 'code'), fn($v) => $v > 0)]
    : ['title' => 'Giá trị tồn theo danh mục', 'rows' => $value['cats']];
?>
<header class="page-head">
  <div><h1>Tổng quan kho</h1><p class="muted small">Số liệu <?= e($scope) ?> · <?= e($rangeLabels[$range]) ?> gần nhất</p></div>
  <div class="actions">
    <nav class="seg" aria-label="Khoảng thời gian"><?php foreach ($rangeLabels as $k => $lb): ?><a href="/?range=<?= e((string)$k) ?>"<?= $range === (string)$k ? ' class="on" aria-current="true"' : '' ?>><?= e($lb) ?></a><?php endforeach; ?></nav>
    <?php if ($auth->allows($user, P::LapPhieuDieuChuyen)): ?><a class="btn btn-act btn-act-xfer" href="/transfers/new"><?= icon('truck') ?>Điều chuyển kho</a><?php endif; ?>
    <?php if ($auth->allows($user, P::LapPhieuNhap)): ?><a class="btn btn-act btn-act-in" href="/vouchers/new?type=RECEIPT"><?= icon('in') ?>Lập phiếu nhập</a><?php endif; ?>
  </div>
</header>

<?php if (!empty($todo)): ?>
<nav class="todo-strip" aria-label="Việc cần làm"><b>Việc cần làm</b>
  <?php foreach ($todo as [$label, $count, $href]): ?><a href="<?= e($href) ?>"><span class="todo-n"><?= n($count) ?></span> <?= e($label) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>

<section class="kpis kpis-5" aria-label="Chỉ số chính">
  <a class="kpi kpi-info" href="<?= $user->isAdmin ? '/valuation' : '/inventory' ?>" title="<?= e(n($value['total'])) ?> đ theo giá gốc"><span class="kpi-ic"><?= icon('scale') ?></span><span class="kpi-label">Giá trị tồn kho</span><b><?= e(vnd_short($value['total'])) ?> <small>đ</small></b><small class="kpi-delta">theo giá gốc<?= $value['missing'] ? ' · ' . n($value['missing']) . ' dòng chưa có giá' : '' ?></small></a>
  <a class="kpi kpi-ok" href="/vouchers?type=RECEIPT" title="Tổng số lượng hàng đã nhập kho (đã xác nhận), cộng mọi mặt hàng — cái, hộp, cuộn… tính chung"><span class="kpi-ic"><?= icon('in') ?></span><span class="kpi-label">Hàng nhập kho · <?= e($span) ?></span><b><?= n($period['nhap']) ?> <small>đơn vị</small></b><small class="kpi-delta"><?= n($period['phieu_nhap']) ?> phiếu nhập · <?= n($period['hang_nhap']) ?> mặt hàng</small><?= $delta((int)$period['nhap'], (int)$period['prev_nhap']) ?></a>
  <a class="kpi kpi-warn" href="/vouchers?type=ISSUE" title="Tổng số lượng hàng đã xuất kho (đã xác nhận), cộng mọi mặt hàng — cái, hộp, cuộn… tính chung"><span class="kpi-ic"><?= icon('out') ?></span><span class="kpi-label">Hàng xuất kho · <?= e($span) ?></span><b><?= n($period['xuat']) ?> <small>đơn vị</small></b><small class="kpi-delta"><?= n($period['phieu_xuat']) ?> phiếu xuất · <?= n($period['hang_xuat']) ?> mặt hàng</small><?= $delta((int)$period['xuat'], (int)$period['prev_xuat']) ?></a>
  <a class="kpi" href="/vouchers?status=DRAFT"><span class="kpi-ic"><?= icon('truck') ?></span><span class="kpi-label">Hàng đang đi đường</span><b><?= n($period['transit']) ?> <small>đơn vị</small></b><small class="kpi-delta">đã xuất khỏi kho gửi, kho nhận chưa xác nhận</small></a>
  <a class="kpi kpi-warn" href="/vouchers?status=DRAFT"><span class="kpi-ic"><?= icon('file') ?></span><span class="kpi-label">Phiếu chờ xác nhận</span><b data-count="<?= (int)$d['drafts'] ?>"><?= n($d['drafts']) ?></b><small class="kpi-delta"><?= n($d['low_count']) ?> mặt hàng dưới định mức</small></a>
</section>

<div class="dash-row">
  <section class="card chart-card">
    <div class="card-head"><h2>Xu hướng nhập – xuất</h2><span class="muted small"><?= $bucket > 1 ? 'gộp theo tuần' : 'theo ngày' ?> · cột nhập, cột xuất, đường là chênh lệch</span></div>
    <div class="chart-box"><canvas data-chart="trend" data-labels="<?= e(json_encode(array_column($trend, 'l'))) ?>" data-nhap="<?= e(json_encode(array_column($trend, 'nhap'))) ?>" data-xuat="<?= e(json_encode(array_column($trend, 'xuat'))) ?>" role="img" aria-label="Biểu đồ cột nhập và xuất theo thời gian"></canvas></div>
  </section>
  <section class="card chart-card">
    <div class="card-head"><h2><?= e($donut['title']) ?></h2><span class="muted small">theo giá gốc</span></div>
    <?php if (!$donut['rows']): ?><p class="chart-empty">Chưa có hàng tồn có giá gốc.</p><?php else: ?>
    <div class="chart-box donut"><canvas data-chart="moneydonut" data-labels="<?= e(json_encode(array_keys($donut['rows']))) ?>" data-values="<?= e(json_encode(array_values($donut['rows']))) ?>" role="img" aria-label="<?= e($donut['title']) ?>"></canvas></div>
    <?php endif; ?>
  </section>
</div>

<div class="grid-2">
  <section class="card chart-card">
    <div class="card-head"><h2>Nhập – xuất theo danh mục</h2><span class="muted small"><?= e($rangeLabels[$range]) ?></span></div>
    <?php if (!$period['by_cat']): ?><p class="chart-empty">Chưa có phát sinh trong kỳ.</p><?php else: ?>
    <div class="chart-box"><canvas data-chart="catbar" data-labels="<?= e(json_encode(array_column($period['by_cat'], 'ten'))) ?>" data-nhap="<?= e(json_encode(array_column($period['by_cat'], 'nhap'))) ?>" data-xuat="<?= e(json_encode(array_column($period['by_cat'], 'xuat'))) ?>" role="img" aria-label="Biểu đồ thanh nhập và xuất theo danh mục"></canvas></div>
    <?php endif; ?>
  </section>
  <section class="card chart-card">
    <div class="card-head"><h2>Tồn so với định mức tối thiểu</h2><a href="/inventory?stock=low">Hàng sắp hết →</a></div>
    <?php if (!$period['min_stock']): ?><p class="chart-empty">Chưa đặt định mức tối thiểu cho mặt hàng nào.</p><?php else: ?>
    <div class="chart-box"><canvas data-chart="minstock" data-labels="<?= e(json_encode(array_column($period['min_stock'], 'ma_hang'))) ?>" data-names="<?= e(json_encode(array_column($period['min_stock'], 'ten'))) ?>" data-q="<?= e(json_encode(array_column($period['min_stock'], 'q'))) ?>" data-dm="<?= e(json_encode(array_column($period['min_stock'], 'dm'))) ?>" role="img" aria-label="Biểu đồ cột tồn hiện có so với định mức tối thiểu"></canvas></div>
    <p class="muted small chart-note">Cột đỏ: tồn ≤ định mức. Vạch ngang: định mức tối thiểu. Hiện 10 mặt hàng sát định mức nhất.</p>
    <?php endif; ?>
  </section>
</div>

<?php $nDraft = (int)$d['drafts']; $nLow = (int)$d['low_count']; ?>
<?php if (!$nDraft && !$nLow): ?>
<p class="all-clear"><?= icon('check') ?> Không có phiếu nào đang chờ xác nhận, không có mặt hàng nào dưới định mức.</p>
<?php else: ?>
<p class="all-clear warn"><?= icon('bell') ?>
  <?php if ($nDraft): ?><a href="/vouchers?status=DRAFT"><b><?= n($nDraft) ?> phiếu</b> đang chờ xác nhận</a><?php endif; ?>
  <?php if ($nDraft && $nLow): ?><span class="sep">·</span><?php endif; ?>
  <?php if ($nLow): ?><a href="/inventory?stock=low"><b><?= n($nLow) ?> mặt hàng</b> dưới định mức tối thiểu</a><?php endif; ?></p>
<?php endif; ?>

<?php if ($user->isAdmin): ?>
<section class="card">
  <div class="card-head"><h2>So sánh các kho</h2><a href="/warehouses">Tất cả kho →</a></div>
  <?php if (!$d['warehouses']): ?><p class="empty">Chưa có kho nào.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Kho</th><th class="num">Nhập</th><th class="num">Xuất</th><th class="num">Giá trị tồn</th><th class="num">Phiếu chờ</th></tr></thead>
    <tbody>
    <?php $vals = array_column($value['rows'], 'value', 'id'); foreach ($d['warehouses'] as $w): $f = $period['by_wh'][(int)$w['id']] ?? ['nhap' => 0, 'xuat' => 0]; ?>
      <tr><td><a href="/inventory?warehouse=<?= (int)$w['id'] ?>"><span class="wh-code"><?= e($w['ma']) ?></span></a> <small class="muted"><?= e($w['ten']) ?></small></td>
        <td class="num"><?= n($f['nhap']) ?></td><td class="num"><?= n($f['xuat']) ?></td><td class="num" title="<?= e(n($vals[(int)$w['id']] ?? 0)) ?> đ"><?= e(vnd_short((int)($vals[(int)$w['id']] ?? 0))) ?></td>
        <td class="num"><?= (int)$w['drafts'] ? '<b class="warn-text">' . n($w['drafts']) . '</b>' : '<span class="muted">0</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>
