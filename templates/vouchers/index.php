<?php /** @var array $rows @var array $counts */
$tabs = ['' => 'Tất cả', 'DRAFT' => 'Chờ xác nhận', 'CONFIRMED' => 'Đã xác nhận', 'CANCELLED' => 'Đã huỷ'];
$total = array_sum($counts);
$qs = fn(array $over) => http_build_query(array_filter(array_merge(['type' => $type, 'status' => $status, 'q' => $q, 'warehouse' => $wh], $over), fn($v) => $v !== null && $v !== ''));
?>
<header class="page-head">
  <div><h1>Phiếu nhập xuất kho</h1><p class="muted">Phiếu nhập, xuất, điều chuyển và điều chỉnh. Phiếu nhập xuất đã xác nhận không bị sửa/xoá — chỉ điều chỉnh bằng phiếu nhập xuất đảo.</p></div>
  <div class="actions"><?php if ($auth->allows($user, \StockFlow\Domain\Permission::LapPhieuNhap)): ?><a class="btn" href="/vouchers/new?type=RECEIPT"><?= icon('in') ?>Phiếu nhập</a><?php endif; ?><?php if ($auth->allows($user, \StockFlow\Domain\Permission::LapPhieuXuat)): ?><a class="btn btn-ghost" href="/vouchers/new?type=ISSUE"><?= icon('out') ?>Phiếu xuất</a><?php endif; ?><?php if ($auth->allows($user, \StockFlow\Domain\Permission::LapPhieuDieuChuyen)): ?><a class="btn btn-ghost" href="/transfers/new"><?= icon('truck') ?>Điều chuyển</a><?php endif; ?></div>
</header>
<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <?php foreach ($tabs as $k => $label): $n = $k === '' ? $total : ($counts[$k] ?? 0); ?>
  <a href="/vouchers?<?= e($qs(['status' => $k])) ?>"<?= ($status ?? '') === $k || ($k === '' && !$status) ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($n) ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <input type="hidden" name="status" value="<?= e($status ?? '') ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Mã phiếu nhập xuất hoặc đối tác…" aria-label="Tìm kiếm">
  <select name="type" aria-label="Loại"><option value="">Mọi loại</option><?php foreach (TYPE_LABELS as $k => [$l]): ?><option value="<?= $k ?>"<?= $k === $type ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <?php if ($user->isAdmin): ?><select name="warehouse" aria-label="Kho"><?= wh_options($warehouses, $wh, 'Mọi kho') ?></select>
  <?php elseif ($warehouses): ?><span class="tag tag-info"><?= e($warehouses[0]['ma'] . ' — ' . $warehouses[0]['ten']) ?></span><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã phiếu nhập xuất</th><th>Loại</th><th>Kho</th><th>Đối tác / lý do</th><th class="num">Số lượng</th><th>Trạng thái</th><th>Người lập</th><th>Thời gian</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $v): ?>
    <tr><td><a href="/vouchers/<?= (int)$v['id'] ?>"><b><?= e($v['ma']) ?></b></a></td><td><?= type_chip($v['loai']) ?></td><td class="nw"><span class="wh-code"><?= e($v['wh_code']) ?></span><?= $v['cp_code'] ? ' <span class="arrow">' . ($v['loai'] === 'TRANSFER_IN' ? '←' : '→') . '</span> <span class="wh-code">' . e($v['cp_code']) . '</span>' : '' ?></td>
      <td class="muted wrap"><?= e($v['doi_tac'] ?: ($v['ly_do'] ?: '—')) ?></td><td class="num"><b><?= n($v['total_qty']) ?></b> <small class="muted"><?= n($v['line_count']) ?> dòng</small></td><td><?= voucher_status_tag($v) ?></td>
      <td class="nw"><?= e($v['creator_name']) ?></td><td class="muted"><?= e(dt_short($v['ngay_tao'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">Không có phiếu nhập xuất phù hợp.</td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
