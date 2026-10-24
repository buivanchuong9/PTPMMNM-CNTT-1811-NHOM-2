<?php /** @var \StockFlow\Domain\VoucherType $type */
$isIn = $type === \StockFlow\Domain\VoucherType::Receipt;
?>
<header class="page-head"><div><h1><?= e($type->label()) ?> mới</h1></div></header>
<?php if (!$warehouses): ?>
  <section class="card"><p class="empty">Bạn chưa có quyền «Nhân viên kho» ở kho nào nên không thể lập phiếu.</p></section>
<?php else: ?>
<form method="post" action="/vouchers" class="card form" data-once>
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="idem" value="<?= e($idem) ?>"><input type="hidden" name="type" value="<?= e($type->value) ?>">
  <div class="row-3">
    <?php if (!$user->isAdmin && $myWarehouse): ?>
      <?= wh_fixed($myWarehouse, $isIn ? 'Nhập vào kho' : 'Xuất từ kho', 'warehouse_id') ?>
    <?php else: ?>
      <label>Kho<select name="warehouse_id" required data-addr-for="addr-wh"><?= wh_options($warehouses, $selected, 'Chọn kho…') ?></select><small class="muted wh-addr" id="addr-wh"></small></label>
    <?php endif; ?>
    <?php if ($isIn): ?>
    <label>Nhà cung cấp<select name="partner" id="supplier-select" required<?= $suppliers ? '' : ' disabled' ?>><option value=""><?= $suppliers ? 'Chọn nhà cung cấp…' : 'Chưa có nhà cung cấp' ?></option><?php foreach ($suppliers as $sp): ?><option value="<?= e($sp['ten']) ?>" data-sup="<?= (int)$sp['id'] ?>"><?= e($sp['ten']) ?> (<?= e($sp['ma']) ?>)</option><?php endforeach; ?></select></label>
    <?php else: ?>
    <label>Khách hàng<input name="partner" maxlength="120" list="partner-list" autocomplete="off" placeholder="Chọn khách hàng có sẵn hoặc gõ tên khách mới…"><datalist id="partner-list"><?php foreach ($partnerNames ?? [] as $pn): ?><option value="<?= e($pn) ?>"><?php endforeach; ?></datalist></label>
    <?php endif; ?>
    <label>Ghi chú<input name="note" maxlength="255" placeholder="Số phiếu nhập xuất gốc, lý do…"></label>
  </div>
  <?php $checkStock = !$isIn; require __DIR__ . '/../partials/lines.php'; ?>
  <div class="actions"><button type="submit">Lưu phiếu nháp</button><a class="btn btn-ghost" href="/vouchers">Huỷ</a></div>
</form>
<?php endif; ?>
