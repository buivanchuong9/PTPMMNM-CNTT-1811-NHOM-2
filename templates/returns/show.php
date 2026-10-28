<?php /** @var array $t @var bool $canReceive @var bool $canClose */
use StockFlow\Service\SupplierReturnService as R;
[$stLabel, $stTone] = R::STATUS[$t['trang_thai']];
$waiting = $t['trang_thai'] === 'CHO_CAP_LAI';
?>
<header class="page-head">
  <div><p class="crumb"><a href="/returns">← Trả hàng nhà cung cấp</a></p><h1><?= e($t['ma']) ?> <span class="tag tag-<?= $stTone ?>"><?= e($stLabel) ?></span></h1>
    <p class="muted"><span class="wh-code"><?= e($t['wh_code']) ?></span> <?= e($t['wh_name']) ?> · trả <b><?= e($t['doi_tac']) ?></b> · từ phiếu nhập <a href="/vouchers/<?= (int)$t['phieu_nhap_xuat_id'] ?>"><?= e($t['receipt_code']) ?></a> · <?= e(dt($t['ngay_tao'])) ?></p></div>
  <div class="actions">
    <a class="btn" href="/returns/<?= (int)$t['id'] ?>/print" target="_blank" rel="noopener"><?= icon('printer') ?>In phiếu trả</a>
    <?php if ($waiting && $canReceive): ?><a class="btn" href="/vouchers/new?type=RECEIPT&amp;replace=<?= (int)$t['id'] ?>"><?= icon('in') ?>Nhập hàng cấp lại</a><?php endif; ?>
  </div>
</header>
<section class="card flush"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mặt hàng</th><th>Lô</th><th class="num">Trả lại</th><th class="num">Đã cấp lại</th><th class="num">Còn thiếu</th><th>Tình trạng</th></tr></thead>
  <tbody>
  <?php foreach ($t['lines'] as $l): $left = (int)$l['so_luong'] - (int)$l['so_luong_da_cap']; ?>
    <tr><td><b><?= e($l['ma_hang']) ?></b> <?= e($l['ten']) ?></td><td><?= $l['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($l['so_lo']) ?></td>
      <td class="num"><b><?= n($l['so_luong']) ?></b> <small class="muted"><?= e($l['unit']) ?></small></td><td class="num"><?= n($l['so_luong_da_cap']) ?></td>
      <td class="num <?= $left ? 'neg' : '' ?>"><?= $left ? n($left) : '✓' ?></td>
      <td><?= e(R::REASONS[$l['ly_do']] ?? $l['ly_do']) ?><?= $l['ghi_chu'] !== '' ? '<br><small class="muted">' . e($l['ghi_chu']) . '</small>' : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></section>
<?php if ($t['replacements']): ?>
<section class="card pad" style="margin-top:16px"><h2>Phiếu nhập cấp lại</h2>
  <p><?php foreach ($t['replacements'] as $rp): ?><a href="/vouchers/<?= (int)$rp['id'] ?>"><b><?= e($rp['ma']) ?></b></a> <?= status_tag($rp['trang_thai']) ?> &nbsp; <?php endforeach; ?></p></section>
<?php endif; ?>
<?php if ($waiting && $canClose): ?>
<form method="post" action="/returns/<?= (int)$t['id'] ?>/close" class="actions pad" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <button type="submit" class="btn-ghost" data-confirm="Nhà cung cấp không cấp lại nữa? Phiếu trả sẽ được đóng.">Nhà cung cấp không cấp lại</button>
</form>
<?php endif; ?>
