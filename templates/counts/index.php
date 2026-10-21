<header class="page-head"><div><h1>Kiểm kê</h1><p class="muted">Đếm thực tế → nộp kèm lý do chênh lệch → người duyệt xác nhận → hệ thống lập chứng từ điều chỉnh.</p></div></header>
<div class="grid-main">
  <section class="card flush"><div class="table-wrap"><table class="dense">
    <thead><tr><th>Mã đợt</th><th>Kho</th><th>Trạng thái</th><th>Tiến độ đếm</th><th class="num">Dòng lệch</th><th>Chứng từ điều chỉnh</th><th>Mở lúc</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): $pct = $c['line_total'] ? round($c['counted'] / $c['line_total'] * 100) : 0; ?>
      <tr><td><a href="/counts/<?= (int)$c['id'] ?>"><b><?= e($c['code']) ?></b></a></td><td><span class="wh-code"><?= e($c['wh_code']) ?></span></td><td><?= status_tag($c['status']) ?></td>
        <td><div class="bar sm"><i style="--w:<?= $pct ?>%"></i></div><small class="muted"><?= n($c['counted']) ?>/<?= n($c['line_total']) ?></small></td>
        <td class="num"><?= (int)$c['variances'] > 0 ? '<span class="tag tag-warn">' . n($c['variances']) . '</span>' : '<span class="muted">0</span>' ?></td>
        <td><?= $c['adjustment_voucher_id'] ? '<a href="/vouchers/' . (int)$c['adjustment_voucher_id'] . '">Xem chứng từ</a>' : '<span class="muted">—</span>' ?></td><td class="muted"><?= e(dt($c['created_at'])) ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">Chưa có đợt kiểm kê.</td></tr><?php endif; ?>
    </tbody></table></div></section>
  <aside><section class="card">
    <h2>Mở đợt kiểm kê</h2>
    <?php if (!$warehouses): ?><p class="muted">Bạn chưa có quyền «Phụ trách kiểm kê» ở kho nào.</p><?php else: ?>
    <form method="post" action="/counts" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label>Kho<select name="warehouse_id" required><?= wh_options($warehouses, null, 'Chọn kho…') ?></select></label>
      <label>Ghi chú<input name="note" maxlength="255" placeholder="Kiểm kê định kỳ, đột xuất…"></label>
      <button type="submit"><?= icon('clipboard') ?>Mở đợt kiểm kê</button>
      <p class="muted small">Hệ thống chụp tồn sổ sách của mọi mặt hàng/lô tại thời điểm mở. Mỗi kho chỉ có một đợt đang mở.</p>
    </form><?php endif; ?>
  </section></aside>
</div>
