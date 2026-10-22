<?php /** @var array $rows @var array $statusCounts @var array $summary @var array $allWarehouses */
$tabs = ['' => 'Tất cả', 'OPEN' => 'Đang kiểm đếm', 'REVIEW' => 'Chờ duyệt', 'CLOSED' => 'Đã đóng', 'CANCELLED' => 'Đã huỷ'];
$total = array_sum($statusCounts);
$qs = fn(array $over) => http_build_query(array_filter(array_merge(['status' => $status, 'warehouse' => $wh], $over), fn($v) => $v !== null && $v !== ''));
?>
<header class="page-head">
  <div><h1>Kiểm kê</h1><p class="muted">Đếm thực tế → nộp kèm lý do chênh lệch → quản lý kho duyệt → hệ thống lập phiếu điều chỉnh và cập nhật tồn.<?= $user->isAdmin ? ' Tài khoản cấp cao xem lịch sử kiểm kê của từng kho.' : '' ?></p></div>
  <?php if ($warehouses): ?><div class="actions"><button type="button" data-dialog-open="count-dlg"><?= icon('clipboard') ?>Mở đợt kiểm kê</button></div><?php endif; ?>
</header>

<?php if ($user->isAdmin && $summary): ?>
<section class="cards-3" style="margin-bottom:16px" aria-label="Lịch sử kiểm kê theo kho">
  <?php foreach ($summary as $s): ?>
  <a class="card pad" href="/counts?<?= e($qs(['warehouse' => (int)$s['id'], 'status' => ''])) ?>" style="display:block;<?= (int)$wh === (int)$s['id'] ? 'outline:2px solid var(--brand)' : '' ?>">
    <div><span class="wh-code"><?= e($s['ma']) ?></span> <b><?= e($s['ten']) ?></b></div>
    <p style="margin:6px 0 0"><b><?= n($s['total']) ?></b> đợt · <span class="tag tag-info"><?= n($s['open_n']) ?> đang đếm</span> <span class="tag tag-warn"><?= n($s['review_n']) ?> chờ duyệt</span> <span class="tag tag-ok"><?= n($s['closed_n']) ?> đã đóng</span></p>
    <small class="muted"><?= $s['last_closed'] ? 'Chốt gần nhất: ' . e(dt($s['last_closed'])) : 'Chưa chốt đợt nào' ?></small>
  </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <?php foreach ($tabs as $k => $label): $c = $k === '' ? $total : ($statusCounts[$k] ?? 0); ?>
  <a href="/counts?<?= e($qs(['status' => $k])) ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($c) ?></span></a>
  <?php endforeach; ?>
</nav>
<?php if ($user->isAdmin): ?>
<form class="filters" method="get">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <select name="warehouse" aria-label="Kho"><?= wh_options($allWarehouses, $wh, 'Mọi kho') ?></select>
</form>
<?php endif; ?>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã đợt</th><th>Kho</th><th>Trạng thái</th><th>Tiến độ đếm</th><th class="num">Dòng lệch</th><th class="num">Lệch ròng</th><th>Người lập / duyệt</th><th>Phiếu điều chỉnh</th><th>Mở lúc</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): $pct = $c['line_total'] ? round($c['counted'] / $c['line_total'] * 100) : 0; $net = (int)$c['net_variance']; ?>
    <tr><td><a href="/counts/<?= (int)$c['id'] ?>"><b><?= e($c['ma']) ?></b></a></td><td><span class="wh-code"><?= e($c['wh_code']) ?></span></td><td><?= status_tag($c['trang_thai']) ?></td>
      <td><div class="bar sm"><i style="--w:<?= $pct ?>%"></i></div><small class="muted"><?= n($c['counted']) ?>/<?= n($c['line_total']) ?></small></td>
      <td class="num"><?= (int)$c['variances'] > 0 ? '<span class="tag tag-warn">' . n($c['variances']) . '</span>' : '<span class="muted">0</span>' ?></td>
      <td class="num <?= $net > 0 ? 'pos' : ($net < 0 ? 'neg' : '') ?>"><?= $c['counted'] ? signed($net) : '—' ?></td>
      <td class="nw"><?= e($c['creator_name']) ?><?= $c['approver_name'] ? ' <span class="arrow">→</span> ' . e($c['approver_name']) : '' ?></td>
      <td><?= $c['phieu_nhap_xuat_dieu_chinh_id'] ? '<a href="/vouchers/' . (int)$c['phieu_nhap_xuat_dieu_chinh_id'] . '">Xem phiếu</a>' : '<span class="muted">—</span>' ?></td><td class="muted"><?= e(dt($c['ngay_tao'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty">Chưa có đợt kiểm kê.</td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>

<?php if ($warehouses): ?>
<dialog id="count-dlg" class="dlg" aria-labelledby="count-dlg-t">
  <form method="post" action="/counts" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="count-dlg-t">Mở đợt kiểm kê</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <?php if (!$user->isAdmin && $myWarehouse && count($warehouses) === 1): ?>
      <?= wh_fixed($myWarehouse, 'Kiểm kê kho', 'warehouse_id') ?>
    <?php else: ?>
      <label>Kho<select name="warehouse_id" required><?= wh_options($warehouses, null, 'Chọn kho…') ?></select></label>
    <?php endif; ?>
    <label>Ghi chú<input name="note" maxlength="255" placeholder="Kiểm kê định kỳ, đột xuất…"></label>
    <p class="muted small">Hệ thống chụp tồn sổ sách của mọi mặt hàng/lô tại thời điểm mở. Mỗi kho chỉ có một đợt đang mở.</p>
    <div class="actions"><button type="submit"><?= icon('clipboard') ?>Mở đợt kiểm kê</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
