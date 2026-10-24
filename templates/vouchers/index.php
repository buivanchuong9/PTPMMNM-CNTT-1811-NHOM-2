<?php /** @var array $rows @var array $counts */
$tones = ['' => 'all', 'DRAFT' => 'warn', 'CONFIRMED' => 'ok', 'CANCELLED' => 'bad'];
$tabs = ['' => 'Tất cả', 'DRAFT' => 'Chờ xác nhận', 'CONFIRMED' => 'Đã xác nhận', 'CANCELLED' => 'Đã huỷ'];
$total = array_sum($counts);
$qs = fn(array $over) => http_build_query(array_filter(array_merge(['type' => $type, 'status' => $status, 'q' => $q, 'warehouse' => $wh, 'category' => $catSel, 'item' => $item, 'range' => $rangeSel === 'custom' ? '' : $rangeSel, 'from' => $rangeSel === 'custom' ? $from : null, 'to' => $rangeSel === 'custom' ? $to : null, 'sort' => $sort === 'old' ? 'old' : null], $over), fn($v) => $v !== null && $v !== ''));
?>
<header class="page-head">
  <div><h1>Phiếu nhập xuất kho</h1></div>
  <div class="actions"><?php if ($auth->allows($user, \StockFlow\Domain\Permission::LapPhieuNhap)): ?><a class="btn" href="/vouchers/new?type=RECEIPT"><?= icon('in') ?>Phiếu nhập</a><?php endif; ?><?php if ($auth->allows($user, \StockFlow\Domain\Permission::LapPhieuXuat)): ?><a class="btn btn-ghost" href="/vouchers/new?type=ISSUE"><?= icon('out') ?>Phiếu xuất</a><?php endif; ?><?php if ($auth->allows($user, \StockFlow\Domain\Permission::LapPhieuDieuChuyen)): ?><a class="btn btn-ghost" href="/transfers/new"><?= icon('truck') ?>Điều chuyển</a><?php endif; ?></div>
</header>
<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <?php foreach ($tabs as $k => $label): $n = $k === '' ? $total : ($counts[$k] ?? 0); ?>
  <a href="/vouchers?<?= e($qs(['status' => $k])) ?>" data-tone="<?= $tones[$k] ?>"<?= ($status ?? '') === $k || ($k === '' && !$status) ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($n) ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <input type="hidden" name="status" value="<?= e($status ?? '') ?>">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Mã phiếu nhập xuất hoặc đối tác…" aria-label="Tìm kiếm">
  <select name="category"<?= $catSel !== '' ? ' class="on"' : '' ?> aria-label="Danh mục hàng hoá" data-live-reload><option value="">Mọi danh mục</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $catSel === (string)$c['id'] ? ' selected' : '' ?>><?= e($c['ten']) ?></option><?php endforeach; ?><option value="none"<?= $catSel === 'none' ? ' selected' : '' ?>>Chưa phân loại</option></select>
  <select name="item"<?= $item ? ' class="on"' : '' ?> aria-label="Mặt hàng" data-combo><option value="">Mọi mặt hàng</option><?php foreach ($itemOptions as $i): ?><option value="<?= (int)$i['id'] ?>"<?= $item === (int)$i['id'] ? ' selected' : '' ?>><?= e($i['ma_hang'] . ' — ' . $i['ten']) ?></option><?php endforeach; ?></select>
  <select name="type"<?= $type ? ' class="on"' : '' ?> aria-label="Loại"><option value="">Mọi loại</option><?php foreach (TYPE_LABELS as $k => [$l]): ?><option value="<?= $k ?>"<?= $k === $type ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <?php if ($user->isAdmin): ?><select name="warehouse"<?= $wh ? ' class="on"' : '' ?> aria-label="Kho"><?= wh_options($warehouses, $wh, 'Mọi kho') ?></select>
  <?php elseif ($warehouses): ?><span class="tag tag-info"><?= e($warehouses[0]['ma'] . ' — ' . $warehouses[0]['ten']) ?></span><?php endif; ?>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
  <select name="sort"<?= $sort === 'old' ? ' class="on"' : '' ?> aria-label="Sắp xếp theo thời gian"><option value="new"<?= $sort === 'new' ? ' selected' : '' ?>>Mới nhất trước</option><option value="old"<?= $sort === 'old' ? ' selected' : '' ?>>Cũ nhất trước</option></select>
  <?php if ($rangeSel || $sort === 'old'): ?><a class="btn btn-ghost" href="/vouchers?<?= e($qs(['range' => null, 'from' => null, 'to' => null, 'sort' => null])) ?>">Xoá lọc thời gian</a><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã phiếu nhập xuất</th><th>Loại</th><th>Kho</th><th>Đối tác / lý do</th><th>Hàng hoá</th><th>Trạng thái</th><th>Người lập</th><th>Thời gian</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $v): ?>
    <tr data-tone="<?= status_tone($v['trang_thai']) ?>"<?= is_new($changes, $v['id']) ? ' class="is-new"' : '' ?>><td><a href="/vouchers/<?= (int)$v['id'] ?>"><b><?= hl($v['ma'], $q) ?></b></a><?= new_tag($changes, $v['id']) ?></td><td><?= type_chip($v['loai']) ?></td><td class="nw"><span class="wh-code"><?= e($v['wh_code']) ?></span><?= $v['cp_code'] ? ' <span class="arrow">' . ($v['loai'] === 'TRANSFER_IN' ? '←' : '→') . '</span> <span class="wh-code">' . e($v['cp_code']) . '</span>' : '' ?></td>
      <td class="muted wrap"><?= hl($v['doi_tac'] ?: ($v['ly_do'] ?: '—'), $q) ?></td><td class="wrap"><?= $item || $catSel !== '' ? '<mark class="hl">' . e($v['first_item'] ?? '—') . '</mark>' : e($v['first_item'] ?? '—') ?><br><small class="muted"><?= (int)$v['line_count'] > 1 ? '+' . n((int)$v['line_count'] - 1) . ' mặt hàng khác' : ($v['loai'] === 'ADJUSTMENT' ? signed((int)$v['first_qty']) : n(abs((int)$v['first_qty']))) . ' ' . e($v['first_unit'] ?? '') ?></small></td><td><?= voucher_status_tag($v) ?></td>
      <td class="nw"><?= e($v['creator_name']) ?></td><td class="muted"><?= e(dt_short($v['ngay_tao'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">Không có phiếu nhập xuất phù hợp.</td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
