<?php /** @var array $warehouses @var array $shown @var array $rows */ ?>
<header class="page-head">
  <div><h1>Tồn kho</h1><p class="muted">Tính trực tiếp từ sổ phát sinh đã ghi nhận. Mở rộng theo lô để xem hạn dùng.</p></div>
  <div class="actions"><a class="btn btn-ghost" href="/reports/stock.csv"><?= icon('download') ?>Xuất CSV</a></div>
</header>
<form class="filters" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Tìm SKU hoặc tên hàng…" aria-label="Tìm kiếm">
  <select name="warehouse" aria-label="Kho"><?= wh_options($warehouses, $wh, 'Tất cả kho') ?></select>
  <select name="view" aria-label="Kiểu xem"><option value="">Theo mặt hàng</option><option value="batch"<?= $byBatch ? ' selected' : '' ?>>Theo lô</option></select>
  <button type="submit">Lọc</button>
</form>
<section class="card flush"><div class="table-wrap"><table class="dense">
<?php if ($byBatch): ?>
  <thead><tr><th>Kho</th><th>SKU</th><th>Tên hàng</th><th>Lô</th><th>Hạn dùng</th><th class="num">Tồn</th><th>Cập nhật cuối</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><span class="wh-code"><?= e($r['wh_code']) ?></span></td><td><b><?= e($r['sku']) ?></b></td><td><?= e($r['name']) ?></td><td><?= $r['batch_no'] === 'NOLOT' ? '<span class="muted">—</span>' : e($r['batch_no']) ?></td>
      <td><?= e(dt($r['expiry_date'], false)) ?></td><td class="num"><b><?= n($r['quantity']) ?></b> <small class="muted"><?= e($r['unit']) ?></small></td><td class="muted"><?= e(dt($r['last_move'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">Không có tồn phù hợp.</td></tr><?php endif; ?>
  </tbody>
<?php else: ?>
  <thead><tr><th>SKU</th><th>Tên hàng</th><?php foreach ($shown as $w): ?><th class="num" title="<?= e($w['name']) ?>"><?= e($w['code']) ?></th><?php endforeach; ?><th class="num">Tổng</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $low = $r['reorder_level'] > 0 && $r['total'] <= $r['reorder_level']; ?>
    <tr><td><b><?= e($r['sku']) ?></b></td><td><?= e($r['name']) ?></td>
      <?php foreach ($shown as $w): $q1 = $r['by_wh'][(int)$w['id']] ?? 0; ?><td class="num <?= $q1 === 0 ? 'muted' : '' ?>"><?= n($q1) ?></td><?php endforeach; ?>
      <td class="num"><b><?= n($r['total']) ?></b> <small class="muted"><?= e($r['unit']) ?></small><?= $low ? ' <span class="tag tag-warn">Sắp hết</span>' : '' ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="<?= count($shown) + 3 ?>" class="empty">Không có tồn phù hợp.</td></tr><?php endif; ?>
  </tbody>
<?php endif; ?>
</table></div></section>
