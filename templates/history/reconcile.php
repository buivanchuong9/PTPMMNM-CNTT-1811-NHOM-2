<?php /** @var array $res */ $ok = $res['mismatches'] === 0; ?>
<header class="page-head">
  <div><h1>Đối chiếu tồn</h1></div>
  <?php if ($auth->allows($user, \StockFlow\Domain\Permission::XemBaoCao)): ?><div class="actions"><a class="btn btn-ghost" href="/reports/reconcile.csv"><?= icon('download') ?>Xuất CSV</a></div><?php endif; ?>
</header>
<div class="verdict <?= $ok ? 'verdict-ok' : 'verdict-bad' ?>">
  <?= icon($ok ? 'shield' : 'alert') ?>
  <div><b><?= $ok ? 'KHỚP — tồn đối chiếu được với sổ phát sinh' : 'LỆCH — ' . n($res['mismatches']) . ' điểm không khớp' ?></b><br><span class="muted">Đã kiểm tra <?= n($res['checked']) ?> vị trí (kho · hàng · lô).</span></div>
</div>
<?php if ($res['voucher_mismatches']): ?>
<section class="card flush"><div class="card-head pad"><h2>Dòng phiếu nhập xuất không khớp sổ</h2></div><div class="table-wrap"><table class="dense"><thead><tr><th>Phiếu nhập xuất</th><th>Dòng</th><th class="num">SL phiếu</th><th class="num">Sổ</th></tr></thead><tbody>
<?php foreach ($res['voucher_mismatches'] as $m): ?><tr><td><?= e($m['ma']) ?></td><td><?= (int)$m['line_id'] ?></td><td class="num"><?= n($m['so_luong']) ?></td><td class="num"><?= $m['bien_dong'] === null ? '—' : n($m['bien_dong']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<section class="card flush"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Kho</th><th>SKU</th><th>Lô</th><th class="num">Số dư</th><th class="num">Tổng sổ phát sinh</th><th class="num">Lệch</th></tr></thead>
  <tbody>
  <?php foreach ($res['rows'] as $r): $diff = (int)$r['balance'] - (int)$r['ledger']; if ($ok && $r['balance'] == 0 && $r['ledger'] == 0) { continue; } ?>
    <tr class="<?= $diff ? 'row-bad' : '' ?>"><td><span class="wh-code"><?= e($r['wh_code']) ?></span></td><td><b><?= e($r['ma_hang']) ?></b></td><td><?= $r['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($r['so_lo']) ?></td>
      <td class="num"><?= n($r['balance']) ?></td><td class="num"><?= n($r['ledger']) ?></td><td class="num"><?= $diff ? '<b class="neg">' . signed($diff) . '</b>' : '<span class="pos">✓</span>' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div><?= pager($pg) ?></section>
