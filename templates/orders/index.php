<?php /** @var array $rows @var string $status @var string $q @var array $counts @var bool $canMake */
use StockFlow\Service\OrderService as O;
$keep = keep_params(['status' => $status, 'q' => $q, 'supplier' => $supplier, 'range' => $rangeSel]);
$qs = fn(array $over) => http_build_query(array_filter(array_merge(['q' => $q, 'supplier' => $supplier, 'range' => $rangeSel === 'custom' ? '' : $rangeSel, 'from' => $rangeSel === 'custom' ? $from : null, 'to' => $rangeSel === 'custom' ? $to : null], $over), fn($v) => $v !== null && $v !== ''));   // chuyển tab vẫn giữ các bộ lọc khác
?>
<header class="page-head">
  <div><h1>Đặt hàng nhà cung cấp</h1></div>
  <?php if ($canMake): ?><div class="actions"><a class="btn" href="/orders/new"><?= icon('plus') ?>Lập đơn đặt hàng</a></div><?php endif; ?>
</header>
<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <a href="/orders?<?= e($qs([])) ?>"<?= $status === '' ? ' class="active" aria-current="page"' : '' ?>>Tất cả <span class="count"><?= n(array_sum($counts)) ?></span></a>
  <?php foreach (['NHAP', 'DA_GUI', 'NHAN_MOT_PHAN', 'DA_NHAN'] as $k): ?><a href="/orders?<?= e($qs(['status' => $k])) ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e(O::STATUS[$k][0]) ?> <span class="count"><?= n($counts[$k] ?? 0) ?></span></a><?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Mã đơn, nhà cung cấp…" aria-label="Tìm kiếm">
  <select name="supplier"<?= $supplier !== '' ? ' class="on"' : '' ?> aria-label="Nhà cung cấp" data-live-reload><?= filter_options(array_combine($suppliers, $suppliers), $supplier, 'Mọi nhà cung cấp') ?></select>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
  <?php if ($keep): ?><a class="btn btn-ghost" href="/orders">Xoá lọc</a><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã đơn</th><th>Nhà cung cấp</th><th>Kho</th><th class="num">Mặt hàng</th><th class="num">Đã nhận / đặt</th><th>Dự kiến</th><th>Trạng thái</th><th>Ngày lập</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $o): [$label, $tone] = O::STATUS[$o['trang_thai']]; ?>
    <tr><td><a href="/orders/<?= (int)$o['id'] ?>"><b><?= e($o['ma']) ?></b></a></td><td><?= e($o['doi_tac']) ?></td><td><span class="wh-code"><?= e($o['wh_code']) ?></span></td>
      <td class="num"><?= n($o['line_count']) ?></td><td class="num"><b><?= n($o['got']) ?></b> / <?= n($o['qty']) ?></td>
      <td class="nw"><?= $o['ngay_du_kien'] ? e(dt($o['ngay_du_kien'], false)) : '<span class="muted">—</span>' ?></td>
      <td><span class="tag tag-<?= e($tone) ?>"><?= e($label) ?></span></td><td class="nw muted"><?= e(dt_short($o['ngay_tao'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty"><?= $keep ? 'Không có đơn phù hợp bộ lọc.' : 'Chưa có đơn đặt hàng nào.' ?></td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
