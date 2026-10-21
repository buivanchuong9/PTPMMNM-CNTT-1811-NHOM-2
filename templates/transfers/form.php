<header class="page-head"><div><h1>Điều chuyển kho</h1><p class="muted">Một phiếu điều chuyển đi tại kho nguồn; khi xác nhận, hệ thống trừ kho đi và cộng kho đến trong <b>cùng một giao dịch</b> và lập đủ chứng từ ở cả hai nơi.</p></div></header>
<?php if (!$from): ?>
  <section class="card"><p class="empty">Bạn chưa có quyền «Nhân viên kho» ở kho nào nên không thể lập phiếu điều chuyển.</p></section>
<?php else: ?>
<form method="post" action="/transfers" class="card form" data-once>
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="idem" value="<?= e($idem) ?>">
  <div class="route">
    <label>Kho đi<select name="from_wh" required><?= wh_options($from, null, 'Chọn kho đi…') ?></select></label>
    <span class="route-arrow" aria-hidden="true"><?= icon('truck') ?></span>
    <label>Kho đến<select name="to_wh" required><?= wh_options($to, null, 'Chọn kho đến…') ?></select></label>
  </div>
  <label>Ghi chú<input name="note" maxlength="255" placeholder="Lý do điều chuyển, đơn vị vận chuyển…"></label>
  <?php require __DIR__ . '/../partials/lines.php'; ?>
  <div class="actions"><button type="submit">Lưu phiếu nháp</button><a class="btn btn-ghost" href="/vouchers">Huỷ</a></div>
</form>
<?php endif; ?>
