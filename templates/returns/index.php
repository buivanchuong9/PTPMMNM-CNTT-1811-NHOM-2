<?php /** @var array $rows @var array $counts @var string $status @var array $receiveWh */
use StockFlow\Service\SupplierReturnService as R;
$tone = ['CHO_CAP_LAI' => 'warn', 'DA_CAP_LAI' => 'ok', 'HUY' => 'all'];
?>
<header class="page-head">
  <div><h1>Trả hàng nhà cung cấp</h1></div>
</header>
<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <?php foreach (R::STATUS as $k => [$label]): ?>
  <a href="/returns?status=<?= e($k) ?>" data-tone="<?= $tone[$k] ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($counts[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>
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
