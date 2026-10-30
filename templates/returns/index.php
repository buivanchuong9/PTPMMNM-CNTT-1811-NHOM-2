<?php /** @var array $rows @var array $counts @var string $status @var array $receiveWh */
use StockFlow\Service\SupplierReturnService as R;
$tone = ['CHO_CAP_LAI' => 'warn', 'DA_CAP_LAI' => 'ok', 'HUY' => 'all'];
?>
<header class="page-head">
  <div><h1>Trả hàng nhà cung cấp</h1></div>
</header>
<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <?php foreach (R::STATUS as $k => [$label]): ?>
  <a href="/returns?status=<?= e($k) ?><?= $keep ? '&amp;' . e(http_build_query($keep)) : '' ?>" data-tone="<?= $tone[$k] ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($counts[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Phiếu trả, nhà cung cấp, phiếu nhập gốc…" aria-label="Tìm kiếm">
  <select name="supplier"<?= $f['supplier'] ? ' class="on"' : '' ?> aria-label="Nhà cung cấp"><?= filter_options($f['supOpts'], $f['supplier'], 'Mọi nhà cung cấp') ?></select>
<?php if (count($f['whOpts']) > 1): ?><select name="warehouse"<?= $f['warehouse'] ? ' class="on"' : '' ?> aria-label="Kho"><?= filter_options($f['whOpts'], $f['warehouse'], 'Mọi kho') ?></select><?php endif; ?>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
  <select name="sort"<?= $f['sort'] === 'old' ? ' class="on"' : '' ?> aria-label="Sắp xếp theo thời gian"><option value="">Mới nhất trước</option><option value="old"<?= $f['sort'] === 'old' ? ' selected' : '' ?>>Cũ nhất trước</option></select>
  <?php if (array_diff_key($keep, ['q' => 1])): ?><a class="btn btn-ghost" href="/returns?<?= e(http_build_query(array_filter(['status' => $status]))) ?>">Xoá lọc</a><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Phiếu trả</th><th>Nhà cung cấp</th><th>Phiếu nhập gốc</th><th class="num">Trả lại</th><th class="num">Đã cấp lại</th><th>Ngày</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $t): ?>
    <tr data-tone="<?= $tone[$t['trang_thai']] ?>">
      <td class="nw"><a href="/returns/<?= (int)$t['id'] ?>"><b><?= e($t['ma']) ?></b></a><br><small class="muted"><?= e($t['wh_code']) ?></small></td>
      <td><?= e($t['doi_tac']) ?></td>
      <td><a href="/vouchers/<?= (int)$t['phieu_nhap_xuat_id'] ?>"><?= e($t['receipt_code']) ?></a></td>
      <td class="num"><b><?= n($t['qty']) ?></b></td>
      <td class="num"><?= n($t['got']) ?></td>
      <td class="muted nw"><?= e(dt_short($t['ngay_tao'])) ?></td>
      <td class="nw"><?php if ($t['trang_thai'] === 'CHO_CAP_LAI' && in_array((int)$t['kho_id'], $receiveWh, true)): ?><a class="btn" href="/vouchers/new?type=RECEIPT&amp;replace=<?= (int)$t['id'] ?>"><?= icon('in') ?>Cấp lại</a><?php endif; ?></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">Chưa có phiếu trả hàng nào ở mục này.</td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
