<?php /** @var array $res */ $ok = $res['mismatches'] === 0; ?>
<header class="page-head">
  <div><h1>Đối chiếu tồn</h1><p class="muted">So sánh số dư với tổng sổ phát sinh theo từng (kho, hàng, lô), và kiểm tra mỗi dòng chứng từ đã xác nhận đều có đúng một dòng sổ.</p></div>
  <div class="actions"><a class="btn btn-ghost" href="/reports/reconcile.csv"><?= icon('download') ?>Xuất CSV</a></div>
</header>
<div class="verdict <?= $ok ? 'verdict-ok' : 'verdict-bad' ?>">
  <?= icon($ok ? 'shield' : 'alert') ?>
  <div><b><?= $ok ? 'KHỚP — tồn đối chiếu được với sổ phát sinh' : 'LỆCH — ' . n($res['mismatches']) . ' điểm không khớp' ?></b><br><span class="muted">Đã kiểm tra <?= n($res['checked']) ?> vị trí (kho · hàng · lô).</span></div>
</div>
<?php if ($res['voucher_mismatches']): ?>
<section class="card flush"><div class="card-head pad"><h2>Dòng chứng từ không khớp sổ</h2></div><div class="table-wrap"><table class="dense"><thead><tr><th>Chứng từ</th><th>Dòng</th><th class="num">SL phiếu</th><th class="num">Sổ</th></tr></thead><tbody>
<?php foreach ($res['voucher_mismatches'] as $m): ?><tr><td><?= e($m['code']) ?></td><td><?= (int)$m['line_id'] ?></td><td class="num"><?= n($m['qty']) ?></td><td class="num"><?= $m['qty_change'] === null ? '—' : n($m['qty_change']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<section class="card flush"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Kho</th><th>SKU</th><th>Lô</th><th class="num">Số dư</th><th class="num">Tổng sổ phát sinh</th><th class="num">Lệch</th></tr></thead>
  <tbody>
  <?php foreach ($res['rows'] as $r): $diff = (int)$r['balance'] - (int)$r['ledger']; if ($ok && $r['balance'] == 0 && $r['ledger'] == 0) { continue; } ?>
    <tr class="<?= $diff ? 'row-bad' : '' ?>"><td><span class="wh-code"><?= e($r['wh_code']) ?></span></td><td><b><?= e($r['sku']) ?></b></td><td><?= $r['batch_no'] === 'NOLOT' ? '<span class="muted">—</span>' : e($r['batch_no']) ?></td>
      <td class="num"><?= n($r['balance']) ?></td><td class="num"><?= n($r['ledger']) ?></td><td class="num"><?= $diff ? '<b class="neg">' . signed($diff) . '</b>' : '<span class="pos">✓</span>' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></section>
