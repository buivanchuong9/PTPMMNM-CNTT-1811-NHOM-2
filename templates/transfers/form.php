<header class="page-head"><div><p class="crumb"><a href="/vouchers">← Phiếu nhập xuất</a></p><h1>Điều chuyển kho</h1></div></header>
<?php if (!$from): ?>
  <section class="card"><p class="empty">Bạn chưa có quyền «Nhân viên kho» ở kho nào nên không thể lập phiếu điều chuyển.</p></section>
<?php else: ?>
<form method="post" action="/transfers" class="card form" data-once data-guard="submit" data-guard-save="Lưu phiếu nháp">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="idem" value="<?= e($idem) ?>">
  <div class="route">
    <?php if (!$user->isAdmin && $myWarehouse): ?>
      <?= wh_fixed($myWarehouse, 'Kho đi (kho của bạn)', 'from_wh') ?>
    <?php else: ?>
      <label>Kho đi<select name="from_wh" required data-addr-for="addr-from"><?= wh_options($from, null, 'Chọn kho đi…') ?></select><small class="muted wh-addr" id="addr-from"></small></label>
    <?php endif; ?>
    <span class="route-arrow" aria-hidden="true"><?= icon('truck') ?></span>
    <label>Kho đến<select name="to_wh" required data-addr-for="addr-to"><?= wh_options($user->isAdmin ? $to : array_values(array_filter($to, fn($w) => (int)$w['id'] !== (int)$user->warehouseId)), null, 'Chọn kho đến…') ?></select><small class="muted wh-addr" id="addr-to"></small></label>
  </div>
  <label>Ghi chú<input name="note" maxlength="255" placeholder="Lý do điều chuyển, đơn vị vận chuyển…"></label>
  <?php $checkStock = true; $autoLots = true; require __DIR__ . '/../partials/lines.php'; ?>
  <div class="actions"><button type="submit">Lưu phiếu nháp</button><a class="btn btn-ghost" href="/vouchers">Quay lại</a></div>
</form>
<?php endif; ?>
