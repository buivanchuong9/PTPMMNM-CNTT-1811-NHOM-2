<?php /** @var array $rows @var array $counts */
$tabs = ['' => 'Tất cả', 'DRAFT' => 'Chờ xác nhận', 'CONFIRMED' => 'Đã xác nhận', 'CANCELLED' => 'Đã huỷ'];
$total = array_sum($counts);
$qs = fn(array $over) => http_build_query(array_filter(array_merge(['type' => $type, 'status' => $status, 'q' => $q, 'warehouse' => $wh], $over), fn($v) => $v !== null && $v !== ''));
?>
<header class="page-head">
  <div><h1>Chứng từ kho</h1><p class="muted">Phiếu nhập, xuất, điều chuyển và điều chỉnh. Chứng từ đã xác nhận không bị sửa/xoá — chỉ điều chỉnh bằng chứng từ đảo.</p></div>
  <div class="actions"><a class="btn" href="/vouchers/new?type=RECEIPT"><?= icon('in') ?>Phiếu nhập</a><a class="btn btn-ghost" href="/vouchers/new?type=ISSUE"><?= icon('out') ?>Phiếu xuất</a><a class="btn btn-ghost" href="/transfers/new"><?= icon('truck') ?>Điều chuyển</a></div>
</header>
<nav class="tabs" aria-label="Trạng thái">
  <?php foreach ($tabs as $k => $label): $n = $k === '' ? $total : ($counts[$k] ?? 0); ?>
  <a href="/vouchers?<?= e($qs(['status' => $k])) ?>"<?= ($status ?? '') === $k || ($k === '' && !$status) ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($n) ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <input type="hidden" name="status" value="<?= e($status ?? '') ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Mã chứng từ hoặc đối tác…" aria-label="Tìm kiếm">
  <select name="type" aria-label="Loại"><option value="">Mọi loại</option><?php foreach (TYPE_LABELS as $k => [$l]): ?><option value="<?= $k ?>"<?= $k === $type ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="warehouse" aria-label="Kho"><?= wh_options($warehouses, $wh, 'Mọi kho') ?></select>
  <button type="submit">Lọc</button>
</form>
<section class="card flush"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã chứng từ</th><th>Loại</th><th>Kho</th><th>Đối tác / lý do</th><th class="num">Số lượng</th><th>Trạng thái</th><th>Người lập</th><th>Thời gian</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $v): ?>
    <tr><td><a href="/vouchers/<?= (int)$v['id'] ?>"><b><?= e($v['code']) ?></b></a></td><td><?= type_chip($v['type']) ?></td><td class="nw"><span class="wh-code"><?= e($v['wh_code']) ?></span><?= $v['cp_code'] ? ' <span class="arrow">' . ($v['type'] === 'TRANSFER_IN' ? '←' : '→') . '</span> <span class="wh-code">' . e($v['cp_code']) . '</span>' : '' ?></td>
      <td class="muted wrap"><?= e($v['partner'] ?: ($v['reason'] ?: '—')) ?></td><td class="num"><b><?= n($v['total_qty']) ?></b> <small class="muted"><?= n($v['line_count']) ?> dòng</small></td><td><?= status_tag($v['status']) ?></td>
      <td class="nw"><?= e($v['creator_name']) ?></td><td class="muted"><?= e(dt_short($v['created_at'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">Không có chứng từ phù hợp.</td></tr><?php endif; ?>
  </tbody></table></div></section>
