<?php /** @var \StockFlow\Domain\VoucherType $type */
$isIn = $type === \StockFlow\Domain\VoucherType::Receipt;
?>
<header class="page-head"><div><p class="crumb"><?php if (!empty($replace)): ?><a href="/returns/<?= (int)$replace['id'] ?>">← Phiếu trả <?= e($replace['ma']) ?></a><?php else: ?><a href="/vouchers">← Phiếu nhập xuất</a><?php endif; ?></p><h1><?= e($type->label()) ?> mới</h1><?php if (!empty($replace)): ?><p class="muted">Hàng nhà cung cấp cấp lại cho phiếu trả <a href="/returns/<?= (int)$replace['id'] ?>"><b><?= e($replace['ma']) ?></b></a> — số lượng điền sẵn là phần còn thiếu.</p><?php endif; ?></div></header>
<?php if (!$warehouses): ?>
  <section class="card"><p class="empty">Bạn chưa có quyền «Nhân viên kho» ở kho nào nên không thể lập phiếu.</p></section>
<?php else: ?>
<form method="post" action="/vouchers" class="card form" data-once data-guard="submit" data-guard-save="Lưu phiếu nháp">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="idem" value="<?= e($idem) ?>"><input type="hidden" name="type" value="<?= e($type->value) ?>"><?php if (!empty($replace)): ?><input type="hidden" name="replace" value="<?= (int)$replace['id'] ?>"><?php endif; ?>
  <div class="row-3">
    <?php if (!$user->isAdmin && $myWarehouse): ?>
      <?= wh_fixed($myWarehouse, $isIn ? 'Nhập vào kho' : 'Xuất từ kho', 'warehouse_id') ?>
    <?php else: ?>
      <label>Kho<select name="warehouse_id" required data-addr-for="addr-wh"><?= wh_options($warehouses, $selected, 'Chọn kho…') ?></select><small class="muted wh-addr" id="addr-wh"></small></label>
    <?php endif; ?>
    <?php if ($isIn): ?>
    <label>Nhà cung cấp<select name="partner" id="supplier-select" required<?= $suppliers ? '' : ' disabled' ?>><option value=""><?= $suppliers ? 'Chọn nhà cung cấp…' : 'Chưa có nhà cung cấp' ?></option><?php foreach ($suppliers as $sp): ?><option value="<?= e($sp['ten']) ?>" data-sup="<?= (int)$sp['id'] ?>"<?= !empty($replace) && $replace['doi_tac'] === $sp['ten'] ? ' selected' : '' ?>><?= e($sp['ten']) ?> (<?= e($sp['ma']) ?>)</option><?php endforeach; ?></select></label>
    <?php else: ?>
    <label>Khách hàng<input name="partner" maxlength="120" list="partner-list" autocomplete="off" placeholder="Chọn khách hàng có sẵn hoặc gõ tên khách mới…"><datalist id="partner-list"><?php foreach ($partnerNames ?? [] as $pn): ?><option value="<?= e($pn) ?>"<?= !empty($partnerKho[$pn]) ? ' label="' . e($pn . ' · kho ' . $partnerKho[$pn]) . '"' : '' ?>><?php endforeach; ?></datalist></label>
    <?php endif; ?>
    <label>Ghi chú<input name="note" maxlength="255" placeholder="Số phiếu gốc, lý do…"></label>
  </div>
  <?php if ($isIn): ?><p class="muted small">Với mỗi dòng: <b>Số lượng</b> là số nhận từ nhà cung cấp, <b>Không đạt</b> là phần kiểm tra thấy không đạt chất lượng. Tồn kho chỉ cộng phần đạt (nhận − không đạt); phần không đạt được lập phiếu trả nhà cung cấp để cấp lại.</p><?php endif; ?>
  <?php $checkStock = !$isIn; $newLots = $isIn; $autoLots = !$isIn; require __DIR__ . '/../partials/lines.php'; ?>
  <div class="actions"><button type="submit">Lưu phiếu nháp</button><a class="btn btn-ghost" href="<?= !empty($replace) ? '/returns/' . (int)$replace['id'] : '/vouchers' ?>">Quay lại</a></div>
</form>
<?php endif; ?>
