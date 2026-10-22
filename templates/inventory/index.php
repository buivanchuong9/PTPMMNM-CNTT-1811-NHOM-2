<?php /** @var array $warehouses @var array $shown @var array $rows */ ?>
<header class="page-head">
  <div><h1>Tồn kho</h1><p class="muted">Tính trực tiếp từ sổ phát sinh đã ghi nhận. Mở rộng theo lô để xem hạn dùng.</p></div>
  <?php if ($auth->allows($user, \StockFlow\Domain\Permission::XemBaoCao)): ?><div class="actions"><a class="btn btn-ghost" href="/reports/stock.csv"><?= icon('download') ?>Xuất CSV</a></div><?php endif; ?>
</header>
<form class="filters" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Tìm SKU hoặc tên hàng…" aria-label="Tìm kiếm">
  <?php if ($user->isAdmin): ?><select name="warehouse" aria-label="Kho"><?= wh_options($warehouses, $wh, 'Tất cả kho') ?></select>
  <?php elseif ($warehouses): ?><span class="tag tag-info"><?= e($warehouses[0]['ma'] . ' — ' . $warehouses[0]['ten']) ?></span><?php endif; ?>
  <select name="view" aria-label="Kiểu xem"><option value="">Theo mặt hàng</option><option value="batch"<?= $byBatch ? ' selected' : '' ?>>Theo lô</option></select>
  <button type="submit">Lọc</button>
</form>
<section class="card flush"><div class="table-wrap"><table class="dense">
<?php if ($byBatch): ?>
  <thead><tr><th>Kho</th><th>SKU</th><th>Tên hàng</th><th>Lô</th><th>Hạn dùng</th><th class="num">Tồn</th><th>Cập nhật cuối</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><span class="wh-code"><?= e($r['wh_code']) ?></span></td><td><b><?= e($r['ma_hang']) ?></b></td><td><?= e($r['ten']) ?></td><td><?= $r['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($r['so_lo']) ?></td>
      <td><?= e(dt($r['han_dung'], false)) ?></td><td class="num"><b><?= n($r['so_luong']) ?></b> <small class="muted"><?= e($r['unit']) ?></small></td><td class="muted"><?= e(dt($r['last_move'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">Không có tồn phù hợp.</td></tr><?php endif; ?>
  </tbody>
<?php else: ?>
  <thead><tr><th>SKU</th><th>Tên hàng</th><?php foreach ($shown as $w): ?><th class="num" title="<?= e($w['ten']) ?>"><?= e($w['ma']) ?></th><?php endforeach; ?><th class="num">Tổng</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $low = $r['dinh_muc_toi_thieu'] > 0 && $r['total'] <= $r['dinh_muc_toi_thieu']; ?>
    <tr><td><b><?= e($r['ma_hang']) ?></b></td><td><?= e($r['ten']) ?></td>
      <?php foreach ($shown as $w): $q1 = $r['by_wh'][(int)$w['id']] ?? 0; ?><td class="num <?= $q1 === 0 ? 'muted' : '' ?>"><?= n($q1) ?></td><?php endforeach; ?>
      <td class="num"><b><?= n($r['total']) ?></b> <small class="muted"><?= e($r['unit']) ?></small><?= $low ? ' <span class="tag tag-warn">Sắp hết</span>' : '' ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="<?= count($shown) + 3 ?>" class="empty">Không có tồn phù hợp.</td></tr><?php endif; ?>
  </tbody>
<?php endif; ?>
</table></div><?= pager($pg) ?></section>
