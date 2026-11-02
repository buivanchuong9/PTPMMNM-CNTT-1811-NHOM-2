<?php /** @var array $item @var array $lines @var int $opening @var int $closing @var string $from @var string $to @var int $wh @var array $warehouses @var array $keep */
$back = '/reports/nxt?' . http_build_query(['warehouse' => $wh ?: null, 'from' => $from, 'to' => $to]);
?>
<header class="page-head">
  <div><p class="crumb"><a href="<?= e($back) ?>">← Nhập – Xuất – Tồn</a></p><h1>Thẻ kho <?= e($item['ma_hang']) ?> <span class="muted"><?= e($item['ten']) ?></span></h1></div>
  <div class="actions"><a class="btn btn-ghost" href="/reports/stock-card?<?= e(http_build_query(['item' => $item['id'], 'format' => 'csv'] + $keep)) ?>"><?= icon('download') ?>Xuất CSV</a></div>
</header>
<form class="filters" method="get">
  <input type="hidden" name="item" value="<?= (int)$item['id'] ?>">
  <?php if (count($warehouses) > 1): ?><select name="warehouse"<?= $wh ? ' class="on"' : '' ?> aria-label="Kho" data-live-reload><option value="">Mọi kho</option><?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"<?= $wh === (int)$w['id'] ? ' selected' : '' ?>><?= e($w['ma'] . ' — ' . $w['ten']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
  <button type="submit" class="btn-ghost">Xem</button>
</form>
<section class="card flush"><div class="table-wrap"><table class="dense nxt">
  <thead><tr><th>Thời gian</th><th>Phiếu</th><th>Kho</th><th>Lô</th><th>Đối tác / lý do</th><th class="num">Nhập</th><th class="num">Xuất</th><th class="num">Tồn</th></tr></thead>
  <tbody>
    <tr class="total-row"><td colspan="7"><b>Tồn đầu kỳ</b> (<?= e(dt($from, false)) ?>)</td><td class="num"><b><?= n($opening) ?></b> <small class="muted"><?= e($item['unit']) ?></small></td></tr>
  <?php foreach ($lines as $l): ?>
    <tr><td class="nw muted"><?= e(dt($l['ngay_ghi_so'])) ?></td>
      <td class="nw"><a href="/vouchers/<?= (int)$l['phieu_nhap_xuat_id'] ?>"><b><?= e($l['voucher_code']) ?></b></a> <?= type_chip($l['loai']) ?></td>
      <td><span class="wh-code"><?= e($l['wh_code']) ?></span></td>
      <td><?= $l['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($l['so_lo']) ?></td>
      <td class="muted wrap"><?= e($l['doi_tac'] ?: ($l['ly_do'] ?: '—')) ?></td>
      <td class="num nxt-in"><?= $l['bien_dong'] > 0 ? '+' . n($l['bien_dong']) : '' ?></td>
      <td class="num nxt-out"><?= $l['bien_dong'] < 0 ? '−' . n(-$l['bien_dong']) : '' ?></td>
      <td class="num nxt-close<?= $l['balance'] < 0 ? ' nxt-out' : '' ?>"><b><?= n($l['balance']) ?></b></td></tr>
  <?php endforeach; if (!$lines): ?><tr><td colspan="8" class="empty">Không có phát sinh trong kỳ này.</td></tr><?php endif; ?>
    <tr class="total-row"><td colspan="7"><b>Tồn cuối kỳ</b> (<?= e(dt($to, false)) ?>)</td><td class="num"><b><?= n($closing) ?></b> <small class="muted"><?= e($item['unit']) ?></small></td></tr>
  </tbody></table></div></section>
