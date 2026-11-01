<?php /** @var array $rows @var string $status @var string $q @var array $counts */
use StockFlow\Service\CustomerReturnService as C;
$keep = keep_params(['status' => $status, 'q' => $q, 'customer' => $customer, 'range' => $rangeSel]);
$qs = fn(array $over) => http_build_query(array_filter(array_merge(['q' => $q, 'customer' => $customer, 'range' => $rangeSel === 'custom' ? '' : $rangeSel, 'from' => $rangeSel === 'custom' ? $from : null, 'to' => $rangeSel === 'custom' ? $to : null], $over), fn($v) => $v !== null && $v !== ''));   // chuyển tab vẫn giữ các bộ lọc khác
?>
<header class="page-head"><div><h1>Khách trả hàng</h1></div></header>
<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <a href="/customer-returns?<?= e($qs([])) ?>" data-tone="all"<?= $status === '' ? ' class="active" aria-current="page"' : '' ?>>Tất cả <span class="count"><?= n(array_sum($counts)) ?></span></a>
  <?php foreach (C::STATUS as $k => [$label, $tn]): ?><a href="/customer-returns?<?= e($qs(['status' => $k])) ?>" data-tone="<?= ['danger' => 'bad', 'muted' => 'all'][$tn] ?? $tn ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($counts[$k] ?? 0) ?></span></a><?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Mã phiếu, khách, phiếu xuất…" aria-label="Tìm kiếm">
  <select name="customer"<?= $customer !== '' ? ' class="on"' : '' ?> aria-label="Khách hàng" data-live-reload data-combo><?= filter_options(array_combine($customers, $customers), $customer, 'Mọi khách hàng') ?></select>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
  <?php if ($keep): ?><a class="btn btn-ghost" href="/customer-returns">Xoá lọc</a><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã phiếu</th><th>Khách</th><th>Phiếu xuất</th><th>Kho</th><th class="num">Số trả</th><th class="num">Trong đó lỗi</th><th>Trạng thái</th><th>Ngày lập</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $t): [$label, $tone] = C::STATUS[$t['trang_thai']]; ?>
    <tr><td><a href="/customer-returns/<?= (int)$t['id'] ?>"><b><?= e($t['ma']) ?></b></a></td><td><?= e($t['khach'] ?: '—') ?></td>
      <td><a href="/vouchers/<?= (int)$t['phieu_xuat_id'] ?>"><?= e($t['issue_code']) ?></a></td><td><span class="wh-code"><?= e($t['wh_code']) ?></span></td>
      <td class="num"><b><?= n($t['qty']) ?></b></td><td class="num <?= (int)$t['bad'] ? 'neg' : '' ?>"><?= n($t['bad']) ?></td>
      <td><span class="tag tag-<?= e($tone) ?>"><?= e($label) ?></span></td><td class="nw muted"><?= e(dt_short($t['ngay_tao'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty"><?= $keep ? 'Không có phiếu phù hợp bộ lọc.' : 'Chưa có phiếu khách trả hàng nào. Mở phiếu xuất đã xác nhận rồi bấm «Khách trả hàng».' ?></td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
