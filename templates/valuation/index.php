<?php /** @var array $totals @var array $byWh @var array $rows @var array $sales @var array $salesTotal @var int $unpriced @var array $categories @var array $warehouses @var array $visible */
$vnd = fn($v) => n((int)$v) . ' đ';
$pct = fn($a, $b) => $b > 0 ? round($a / $b * 100, 1) . '%' : '—';
$profit = fn(array $s) => (int)$s['doanh_thu'] - (int)$s['gia_von'];
?>
<header class="page-head">
  <div><h1>Giá trị tồn &amp; doanh thu</h1></div>
</header>

<form class="filters" method="get">
  <?php if (count($visible) > 1): ?><select name="warehouse" aria-label="Kho" data-live-reload><?= wh_options(array_values(array_filter($warehouses, fn($w) => in_array((int)$w['id'], $visible, true))), $wh, 'Tất cả kho') ?></select>
  <?php elseif ($myWarehouse): ?><span class="tag tag-info"><?= e($myWarehouse['ma'] . ' — ' . $myWarehouse['ten']) ?></span><?php endif; ?>
  <select name="category" aria-label="Danh mục" data-live-reload><option value="">Mọi danh mục</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $category === (string)$c['id'] ? ' selected' : '' ?>><?= e($c['ten']) ?></option><?php endforeach; ?><option value="none"<?= $category === 'none' ? ' selected' : '' ?>>Chưa phân loại</option></select>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
</form>

<section class="cards-3" style="margin-bottom:16px" aria-label="Tổng hợp tồn kho">
  <div class="card pad"><p class="muted small">Mặt hàng còn tồn</p><p class="stat"><b><?= n($totals['items']) ?></b></p><p class="muted small">tổng <?= n($totals['qty']) ?> đơn vị</p></div>
  <div class="card pad"><p class="muted small">Giá trị tồn theo giá gốc</p><p class="stat"><b class="money"><?= $vnd($totals['cost']) ?></b></p><p class="muted small">số lượng × giá gốc</p></div>
  <div class="card pad"><p class="muted small">Giá trị tồn theo giá bán</p><p class="stat"><b class="money"><?= $vnd($totals['sell']) ?></b></p><p class="muted small">lãi gộp tiềm năng <b class="<?= $totals['sell'] - $totals['cost'] >= 0 ? 'pos' : 'neg' ?>"><?= $vnd($totals['sell'] - $totals['cost']) ?></b> (chưa thuế)</p></div>
</section>
<?php if ($unpriced): ?><p class="muted small" style="margin:-4px 0 12px"><?= icon('alert') ?> <b><?= n($unpriced) ?></b> mặt hàng chưa có giá gốc hoặc giá bán nên giá trị đang thấp hơn thực tế — quản trị đặt giá gốc, quản lý kho đặt giá bán ở trang Hàng hoá.</p><?php endif; ?>

<section class="card flush" style="margin-bottom:16px">
  <div class="card-head pad"><h2>Tồn kho theo từng kho</h2></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Kho</th><th class="num">Mặt hàng</th><th class="num">Tổng số lượng</th><th class="num">Giá trị theo giá gốc</th><th class="num">Giá trị theo giá bán</th><th class="num">Lãi gộp tiềm năng</th></tr></thead>
    <tbody>
    <?php foreach ($byWh as $w): ?>
      <tr><td><span class="wh-code"><?= e($w['code']) ?></span> <?= e($w['name']) ?></td><td class="num"><?= n($w['items']) ?></td><td class="num"><?= n($w['qty']) ?></td>
        <td class="num money"><?= $vnd($w['cost']) ?></td><td class="num money"><?= $vnd($w['sell']) ?></td><td class="num money <?= $w['sell'] - $w['cost'] >= 0 ? 'pos' : 'neg' ?>"><?= $vnd($w['sell'] - $w['cost']) ?></td></tr>
    <?php endforeach; if (!$byWh): ?><tr><td colspan="6" class="empty">Không có tồn phù hợp.</td></tr><?php endif; ?>
    </tbody>
    <?php if (count($byWh) > 1): ?><tfoot><tr><th>Tổng cộng</th><th class="num"><?= n($totals['items']) ?></th><th class="num"><?= n($totals['qty']) ?></th><th class="num money"><?= $vnd($totals['cost']) ?></th><th class="num money"><?= $vnd($totals['sell']) ?></th><th class="num money"><?= $vnd($totals['sell'] - $totals['cost']) ?></th></tr></tfoot><?php endif; ?>
  </table></div>
</section>

<section class="card flush" style="margin-bottom:16px">
  <div class="card-head pad"><h2>Doanh thu bán hàng</h2><span class="muted small">phiếu xuất đã xác nhận<?= $rangeSel ? '' : ' · mọi thời gian' ?> · giá lấy tại lúc lập phiếu</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Kho</th><th class="num">Phiếu xuất</th><th class="num">Số lượng</th><th class="num">Doanh thu (chưa thuế)</th><th class="num">Thuế GTGT</th><th class="num">Tổng thanh toán</th><th class="num">Giá gốc hàng bán</th><th class="num">Lãi gộp</th><th class="num">Biên lãi</th></tr></thead>
    <tbody>
    <?php foreach ($sales as $s): ?>
      <tr><td><span class="wh-code"><?= e($s['wh_code']) ?></span> <?= e($s['wh_name']) ?></td><td class="num"><?= n($s['phieu']) ?></td><td class="num"><?= n($s['so_luong']) ?></td>
        <td class="num money"><?= $vnd($s['doanh_thu']) ?></td><td class="num money"><?= $vnd($s['thue']) ?></td><td class="num money"><b><?= $vnd((int)$s['doanh_thu'] + (int)$s['thue']) ?></b></td>
        <td class="num money"><?= $vnd($s['gia_von']) ?></td><td class="num money <?= $profit($s) >= 0 ? 'pos' : 'neg' ?>"><?= $vnd($profit($s)) ?></td><td class="num"><?= $pct($profit($s), (int)$s['doanh_thu']) ?></td></tr>
    <?php endforeach; if (!$sales): ?><tr><td colspan="9" class="empty">Chưa có phiếu xuất nào được xác nhận trong khoảng này.</td></tr><?php endif; ?>
    </tbody>
    <?php if (count($sales) > 1): ?><tfoot><tr><th>Tổng cộng</th><th class="num"><?= n($salesTotal['phieu']) ?></th><th class="num"><?= n($salesTotal['so_luong']) ?></th><th class="num money"><?= $vnd($salesTotal['doanh_thu']) ?></th><th class="num money"><?= $vnd($salesTotal['thue']) ?></th>
      <th class="num money"><?= $vnd($salesTotal['doanh_thu'] + $salesTotal['thue']) ?></th><th class="num money"><?= $vnd($salesTotal['gia_von']) ?></th><th class="num money"><?= $vnd($salesTotal['doanh_thu'] - $salesTotal['gia_von']) ?></th><th class="num"><?= $pct($salesTotal['doanh_thu'] - $salesTotal['gia_von'], $salesTotal['doanh_thu']) ?></th></tr></tfoot><?php endif; ?>
  </table></div>
</section>

<section class="card flush" data-live="results">
  <div class="card-head pad"><h2>Giá trị theo từng mặt hàng</h2><span class="muted small">sắp theo giá trị tồn giảm dần</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>SKU</th><th>Tên hàng</th><th>Danh mục</th><th class="num">Tồn</th><th class="num">Giá gốc</th><th class="num">Giá bán (theo kho)</th><th class="num">Thuế</th><th class="num">Giá trị gốc</th><th class="num">Giá trị bán</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i): ?>
      <tr><td><b><?= e($i['sku']) ?></b></td><td><?= e($i['name']) ?></td><td><?= $i['category'] ? '<span class="tag tag-info">' . e($i['category']) . '</span>' : '<span class="muted">—</span>' ?></td>
        <td class="num"><b><?= n($i['qty']) ?></b> <small class="muted"><?= e($i['unit']) ?></small></td>
        <td class="num money"><?= $i['cost_price'] > 0 ? $vnd($i['cost_price']) : '<span class="muted">—</span>' ?></td>
        <td class="num money"><?= $i['sell_price'] > 0 ? $vnd($i['sell_price']) : '<span class="tag tag-warn">Chưa có giá</span>' ?></td><td class="num"><?= (int)$i['vat'] ?>%</td>
        <td class="num money"><?= $vnd($i['cost']) ?></td><td class="num money"><?= $vnd($i['sell']) ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty">Không có mặt hàng phù hợp.</td></tr><?php endif; ?>
    </tbody>
  </table></div><?= pager($pg) ?>
</section>
