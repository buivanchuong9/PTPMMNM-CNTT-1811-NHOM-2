<header class="page-head">
  <div><h1>Kiểm kê</h1><p class="muted">Đếm thực tế → nộp kèm lý do chênh lệch → người duyệt xác nhận → hệ thống lập phiếu nhập xuất điều chỉnh.</p></div>
  <?php if ($warehouses): ?><div class="actions"><button type="button" data-dialog-open="count-dlg"><?= icon('clipboard') ?>Mở đợt kiểm kê</button></div><?php endif; ?>
</header>
<section class="card flush"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã đợt</th><th>Kho</th><th>Trạng thái</th><th>Tiến độ đếm</th><th class="num">Dòng lệch</th><th>Phiếu nhập xuất điều chỉnh</th><th>Mở lúc</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): $pct = $c['line_total'] ? round($c['counted'] / $c['line_total'] * 100) : 0; ?>
    <tr><td><a href="/counts/<?= (int)$c['id'] ?>"><b><?= e($c['ma']) ?></b></a></td><td><span class="wh-code"><?= e($c['wh_code']) ?></span></td><td><?= status_tag($c['trang_thai']) ?></td>
      <td><div class="bar sm"><i style="--w:<?= $pct ?>%"></i></div><small class="muted"><?= n($c['counted']) ?>/<?= n($c['line_total']) ?></small></td>
      <td class="num"><?= (int)$c['variances'] > 0 ? '<span class="tag tag-warn">' . n($c['variances']) . '</span>' : '<span class="muted">0</span>' ?></td>
      <td><?= $c['phieu_nhap_xuat_dieu_chinh_id'] ? '<a href="/vouchers/' . (int)$c['phieu_nhap_xuat_dieu_chinh_id'] . '">Xem phiếu nhập xuất</a>' : '<span class="muted">—</span>' ?></td><td class="muted"><?= e(dt($c['ngay_tao'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">Chưa có đợt kiểm kê.</td></tr><?php endif; ?>
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
