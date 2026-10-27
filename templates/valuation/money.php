<?php /** @var string $dir 'in' | 'out' @var array $rows @var array $total @var array $warehouses @var array $visible */
$isIn = $dir === 'in';
$vnd = fn($v) => n((int)$v) . ' đ';
$pct = fn($a, $b) => $b > 0 ? round($a / $b * 100, 1) . '%' : '—';
$profit = $total['tien'] - $total['gia_von'];
?>
<header class="page-head">
  <div><h1><?= $isIn ? 'Tiền nhập' : 'Tiền xuất' ?></h1></div>
</header>

<form class="filters" method="get">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Mã phiếu hoặc <?= $isIn ? 'nhà cung cấp' : 'khách hàng' ?>…" aria-label="Tìm kiếm">
  <?php if (count($visible) > 1): ?><select name="warehouse" aria-label="Kho" data-live-reload><?= wh_options(array_values(array_filter($warehouses, fn($w) => in_array((int)$w['id'], $visible, true))), $wh, 'Tất cả kho') ?></select>
  <?php elseif ($myWarehouse): ?><span class="tag tag-info"><?= e($myWarehouse['ma'] . ' — ' . $myWarehouse['ten']) ?></span><?php endif; ?>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
</form>

<section class="cards-3" style="margin-bottom:16px" aria-label="Tổng hợp">
  <div class="card pad"><p class="muted small"><?= $isIn ? 'Tổng tiền nhập' : 'Tổng tiền bán thu về' ?></p><p class="stat"><b class="money <?= $isIn ? 'neg' : 'pos' ?>"><?= $vnd($total['tien'] + $total['thue']) ?></b></p><p class="muted small"><?= n($total['phieu']) ?> phiếu <?= $isIn ? 'nhập' : 'xuất' ?> · gồm thuế</p></div>
  <div class="card pad"><p class="muted small">Tiền hàng chưa thuế</p><p class="stat"><b class="money"><?= $vnd($total['tien']) ?></b></p><p class="muted small">thuế GTGT <?= $vnd($total['thue']) ?></p></div>
  <?php if (!$isIn): ?>
  <div class="card pad"><p class="muted small">Lãi gộp</p><p class="stat"><b class="money <?= $profit >= 0 ? 'pos' : 'neg' ?>"><?= $vnd($profit) ?></b></p><p class="muted small">biên lãi <?= $pct($profit, $total['tien']) ?> · tiền hàng − giá gốc</p></div>
  <?php endif; ?>
</section>

<section class="card flush" data-live="results">
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Mã phiếu</th><th>Ngày xác nhận</th><th>Kho</th><th><?= $isIn ? 'Nhà cung cấp' : 'Khách hàng' ?></th><th class="num">Số lượng</th><th class="num">Tiền hàng</th><th class="num">Thuế</th><th class="num">Tổng tiền</th><?php if (!$isIn): ?><th class="num">Lãi gộp</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $v): $priced = (int)$v['tien'] > 0; $g = (int)$v['tien'] - (int)$v['gia_von']; ?>
      <tr><td><a href="/vouchers/<?= (int)$v['id'] ?>"><b><?= e($v['ma']) ?></b></a></td><td class="nw"><?= e(dt($v['ngay_xac_nhan'])) ?></td><td><span class="wh-code" title="<?= e($v['wh_name']) ?>"><?= e($v['wh_code']) ?></span></td>
        <td><?= $v['doi_tac'] !== '' ? e($v['doi_tac']) : '<span class="muted">—</span>' ?></td><td class="num"><?= n($v['so_luong']) ?></td>
        <?php if ($priced): ?>
        <td class="num money"><?= $vnd($v['tien']) ?></td><td class="num money"><?= $vnd($v['thue']) ?></td><td class="num money"><b><?= $vnd((int)$v['tien'] + (int)$v['thue']) ?></b></td>
        <?php if (!$isIn): ?><td class="num money <?= $g >= 0 ? 'pos' : 'neg' ?>"><?= $vnd($g) ?></td><?php endif; ?>
        <?php else: ?>
        <td class="num muted" colspan="<?= $isIn ? 3 : 4 ?>" title="Phiếu lập trước khi có giá nên không ghi đơn giá">Chưa ghi giá</td>
        <?php endif; ?></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="<?= $isIn ? 8 : 9 ?>" class="empty">Chưa có phiếu <?= $isIn ? 'nhập' : 'xuất' ?> nào được xác nhận trong khoảng này.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?><tfoot><tr><th colspan="5">Tổng cộng <?= n($total['phieu']) ?> phiếu</th><th class="num money"><?= $vnd($total['tien']) ?></th><th class="num money"><?= $vnd($total['thue']) ?></th><th class="num money"><?= $vnd($total['tien'] + $total['thue']) ?></th><?php if (!$isIn): ?><th class="num money"><?= $vnd($profit) ?></th><?php endif; ?></tr></tfoot><?php endif; ?>
  </table></div><?= pager($pg) ?>
</section>
