<?php /** @var \StockFlow\Domain\VoucherType $type */
$isIn = $type === \StockFlow\Domain\VoucherType::Receipt;
?>
<header class="page-head"><div><h1><?= e($type->label()) ?> mới</h1><p class="muted"><?= $isIn ? 'Ghi nhận hàng vào kho (mua hàng, nhận từ nhà cung cấp). ' : 'Ghi nhận hàng ra khỏi kho (bán, tiêu hao). ' ?>Phiếu ở trạng thái nháp, chưa ảnh hưởng tồn đến khi người duyệt xác nhận.</p></div></header>
<?php if (!$warehouses): ?>
  <section class="card"><p class="empty">Bạn chưa có quyền «Nhân viên kho» ở kho nào nên không thể lập phiếu.</p></section>
<?php else: ?>
<form method="post" action="/vouchers" class="card form" data-once>
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="idem" value="<?= e($idem) ?>"><input type="hidden" name="type" value="<?= e($type->value) ?>">
  <div class="row-3">
    <label>Kho<select name="warehouse_id" required><?= wh_options($warehouses, $selected, 'Chọn kho…') ?></select></label>
    <label><?= $isIn ? 'Nhà cung cấp' : 'Người nhận / đơn hàng' ?><input name="partner" maxlength="120" placeholder="<?= $isIn ? 'Công ty …' : 'Khách hàng / mã đơn' ?>"></label>
    <label>Ghi chú<input name="note" maxlength="255" placeholder="Số chứng từ gốc, lý do…"></label>
  </div>
  <?php require __DIR__ . '/../partials/lines.php'; ?>
  <div class="actions"><button type="submit">Lưu phiếu nháp</button><a class="btn btn-ghost" href="/vouchers">Huỷ</a></div>
</form>
<?php endif; ?>
