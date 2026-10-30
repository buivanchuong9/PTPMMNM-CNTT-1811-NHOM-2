<?php /** @var array $totals @var array $byWh @var array $rows @var int $unpriced @var array $categories @var array $warehouses @var array $visible */
$vnd = fn($v) => n((int)$v) . ' đ';
?>
<header class="page-head">
  <div><h1>Vốn kho</h1></div>
</header>

<form class="filters" method="get">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Tìm SKU, tên hàng, danh mục…" aria-label="Tìm kiếm">
  <?php if (count($visible) > 1): ?><select name="warehouse" aria-label="Kho" data-live-reload><?= wh_options(array_values(array_filter($warehouses, fn($w) => in_array((int)$w['id'], $visible, true))), $wh, 'Tất cả kho') ?></select>
  <?php elseif ($myWarehouse): ?><span class="tag tag-info"><?= e($myWarehouse['ma'] . ' — ' . $myWarehouse['ten']) ?></span><?php endif; ?>
  <select name="category"<?= $category !== '' ? ' class="on"' : '' ?> aria-label="Danh mục" data-live-reload><option value="">Mọi danh mục</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $category === (string)$c['id'] ? ' selected' : '' ?>><?= e($c['ten']) ?></option><?php endforeach; ?><option value="none"<?= $category === 'none' ? ' selected' : '' ?>>Chưa phân loại</option></select>
  <select name="price"<?= $price ? ' class="on"' : '' ?> aria-label="Tình trạng giá"><option value="">Mọi tình trạng giá</option><option value="none"<?= $price === 'none' ? ' selected' : '' ?>>Chưa đủ giá gốc / giá bán</option><option value="ok"<?= $price === 'ok' ? ' selected' : '' ?>>Đã có đủ giá</option></select>
  <select name="sort"<?= $sort ? ' class="on"' : '' ?> aria-label="Sắp xếp"><?php foreach (['' => 'Vốn tồn: cao → thấp', 'qty' => 'Số lượng tồn: nhiều → ít', 'sell' => 'Giá trị bán: cao → thấp', 'margin' => 'Lãi dự kiến: cao → thấp', 'sku' => 'SKU: A → Z'] as $k => $label): ?><option value="<?= e($k) ?>"<?= $sort === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
  <?php if ($wh || $category !== '' || $price || $sort): ?><a class="btn btn-ghost" href="/valuation">Xoá lọc</a><?php endif; ?>
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
