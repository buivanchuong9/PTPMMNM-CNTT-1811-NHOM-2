<?php /** @var array $rows @var array $tot @var string $from @var string $to @var string $q @var int $wh @var int $cat @var array $warehouses @var array $categories @var array $keep */
$card = fn(array $x) => '/reports/stock-card?' . http_build_query(['item' => $x['id'], 'warehouse' => $wh ?: null, 'from' => $from, 'to' => $to]);
?>
<header class="page-head">
  <div><h1>Nhập – Xuất – Tồn</h1></div>
  <div class="actions"><a class="btn btn-ghost" href="/reports/nxt?<?= e(http_build_query(['format' => 'csv'] + $keep)) ?>"><?= icon('download') ?>Xuất CSV</a></div>
</header>
<form class="filters" method="get">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="SKU hoặc tên hàng…" aria-label="Tìm kiếm">
  <?php if (count($warehouses) > 1): ?><select name="warehouse"<?= $wh ? ' class="on"' : '' ?> aria-label="Kho" data-live-reload><option value="">Mọi kho</option><?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"<?= $wh === (int)$w['id'] ? ' selected' : '' ?>><?= e($w['ma'] . ' — ' . $w['ten']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <select name="category"<?= $cat ? ' class="on"' : '' ?> aria-label="Loại hàng" data-live-reload><option value="">Mọi loại hàng</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $cat === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['ten']) ?></option><?php endforeach; ?></select>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
  <button type="submit" class="btn-ghost">Xem</button>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>SKU</th><th>Tên hàng</th><th>ĐVT</th><th class="num">Tồn đầu</th><th class="num">Nhập</th><th class="num">Chuyển đến</th><th class="num">Khách trả</th><th class="num">Xuất</th><th class="num">Chuyển đi</th><th class="num">Điều chỉnh</th><th class="num">Tồn cuối</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $x): ?>
    <tr><td><b><?= e($x['ma_hang']) ?></b></td><td><?= e($x['ten']) ?></td><td class="muted"><?= e($x['unit']) ?></td>
      <td class="num"><?= n($x['opening']) ?></td>
      <td class="num pos"><?= $x['q_in'] ? n($x['q_in']) : '<span class="muted">0</span>' ?></td>
      <td class="num"><?= $x['q_xin'] ? n($x['q_xin']) : '<span class="muted">0</span>' ?></td>
      <td class="num"><?= $x['q_ret'] ? n($x['q_ret']) : '<span class="muted">0</span>' ?></td>
      <td class="num neg"><?= $x['q_out'] ? n($x['q_out']) : '<span class="muted">0</span>' ?></td>
      <td class="num"><?= $x['q_xout'] ? n($x['q_xout']) : '<span class="muted">0</span>' ?></td>
      <td class="num"><?= $x['q_adj'] ? signed($x['q_adj']) : '<span class="muted">0</span>' ?></td>
      <td class="num"><b><?= n($x['closing']) ?></b></td>
      <td class="nw"><a href="<?= e($card($x)) ?>">Thẻ kho</a></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="12" class="empty">Không có phát sinh trong kỳ này.</td></tr>
  <?php else: ?>
    <tr class="total-row"><td colspan="3"><b>Tổng cộng (mọi mặt hàng, theo kỳ đang xem)</b></td><td class="num"><b><?= n($tot['opening']) ?></b></td><td class="num"><?= n($tot['q_in']) ?></td><td class="num"><?= n($tot['q_xin']) ?></td><td class="num"><?= n($tot['q_ret']) ?></td>
      <td class="num"><?= n($tot['q_out']) ?></td><td class="num"><?= n($tot['q_xout']) ?></td><td class="num"><?= $tot['q_adj'] ? signed($tot['q_adj']) : '0' ?></td><td class="num"><b><?= n($tot['closing']) ?></b></td><td></td></tr>
  <?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
<p class="muted small">Kỳ <?= e(dt($from, false)) ?> – <?= e(dt($to, false)) ?>. Tồn cuối = tồn đầu + nhập + chuyển đến + khách trả − xuất − chuyển đi ± điều chỉnh. Điều chuyển giữa hai kho khi xem mọi kho sẽ tự triệt tiêu.</p>
