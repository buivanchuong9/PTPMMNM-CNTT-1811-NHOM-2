<?php /** @var array $rows @var array $total @var int $items @var ?array $best @var array $warehouses @var array $visible */
$vnd = fn($v) => n((int)$v) . ' đ';
$pct = fn($a, $b) => $b > 0 ? round($a / $b * 100, 1) . '%' : '—';
?>
<header class="page-head">
  <div><h1>Tiền lãi</h1></div>
</header>

<form class="filters" method="get">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="SKU hoặc tên hàng…" aria-label="Tìm kiếm">
  <?php if (count($visible) > 1): ?><select name="warehouse" aria-label="Kho" data-live-reload><?= wh_options(array_values(array_filter($warehouses, fn($w) => in_array((int)$w['id'], $visible, true))), $wh, 'Tất cả kho') ?></select>
  <?php elseif ($myWarehouse): ?><span class="tag tag-info"><?= e($myWarehouse['ma'] . ' — ' . $myWarehouse['ten']) ?></span><?php endif; ?>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
</form>

<section class="cards-3" style="margin-bottom:16px" aria-label="Tổng hợp lãi">
  <div class="card pad"><p class="muted small">Tổng tiền lãi</p><p class="stat"><b class="money <?= $total['lai'] >= 0 ? 'pos' : 'neg' ?>"><?= $vnd($total['lai']) ?></b></p><p class="muted small">biên lãi <?= $pct($total['lai'], $total['tien']) ?> · <?= n($items) ?> mặt hàng đã bán</p></div>
  <div class="card pad"><p class="muted small">Tiền bán (chưa thuế)</p><p class="stat"><b class="money"><?= $vnd($total['tien']) ?></b></p><p class="muted small">vốn hàng đã bán <?= $vnd($total['gia_von']) ?></p></div>
  <div class="card pad"><p class="muted small">Lãi nhiều nhất</p><p class="stat"><b><?= $best ? e($best['ma_hang']) : '—' ?></b></p><p class="muted small"><?= $best ? e($best['ten']) . ' · ' . $vnd($best['lai']) : 'chưa có phiếu xuất có giá' ?></p></div>
</section>

<section class="card flush" data-live="results">
  <div class="card-head pad"><h2>Lãi theo từng mặt hàng</h2><span class="muted small">phiếu xuất đã xác nhận<?= $rangeSel ? '' : ' · mọi thời gian' ?> · lãi = tiền bán − vốn (giá gốc lúc xuất) · lãi nhiều trước</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>SKU</th><th>Tên hàng</th><th class="num">Phiếu xuất</th><th class="num">Đã bán</th><th class="num">Tiền bán</th><th class="num">Vốn</th><th class="num">Tiền lãi</th><th class="num">Biên lãi</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i): $lai = (int)$i['lai']; ?>
      <tr><td><b><?= e($i['ma_hang']) ?></b></td><td><?= e($i['ten']) ?></td><td class="num"><?= n($i['phieu']) ?></td>
        <td class="num"><b><?= n($i['so_luong']) ?></b> <small class="muted"><?= e($i['unit']) ?></small></td>
        <td class="num money"><?= $vnd($i['tien']) ?></td><td class="num money"><?= $vnd($i['gia_von']) ?></td>
        <td class="num money <?= $lai >= 0 ? 'pos' : 'neg' ?>"><b><?= $vnd($lai) ?></b></td><td class="num"><?= $pct($lai, (int)$i['tien']) ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">Chưa có phiếu xuất nào (có ghi giá) được xác nhận trong khoảng này.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?><tfoot><tr><th colspan="3">Tổng cộng <?= n($items) ?> mặt hàng</th><th class="num"><?= n($total['so_luong']) ?></th><th class="num money"><?= $vnd($total['tien']) ?></th><th class="num money"><?= $vnd($total['gia_von']) ?></th><th class="num money"><?= $vnd($total['lai']) ?></th><th class="num"><?= $pct($total['lai'], $total['tien']) ?></th></tr></tfoot><?php endif; ?>
  </table></div><?= pager($pg) ?>
</section>
