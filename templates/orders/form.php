<?php /** @var array $suppliers @var ?string $presetPartner */ ?>
<header class="page-head"><div><p class="crumb"><a href="/orders">← Đặt hàng nhà cung cấp</a></p><h1>Đơn đặt hàng mới</h1></div></header>
<form method="post" action="/orders" class="card form" data-once data-guard="submit" data-guard-save="Lưu đơn nháp">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="idem" value="<?= e($idem) ?>">
  <div class="row-3">
    <?php if ($myWarehouse): ?><?= wh_fixed($myWarehouse, 'Đặt cho kho', 'warehouse_id') ?><?php endif; ?>
    <label>Nhà cung cấp<select name="partner" id="supplier-select" required><option value="">Chọn nhà cung cấp…</option><?php foreach ($suppliers as $sp): ?><option value="<?= e($sp['ten']) ?>" data-sup="<?= (int)$sp['id'] ?>" data-contact="<?= e($sp['nguoi_lien_he']) ?>" data-phone="<?= e($sp['so_dien_thoai']) ?>" data-email="<?= e($sp['email']) ?>" data-addr="<?= e($sp['dia_chi']) ?>"<?= !empty($presetPartner) && $presetPartner === $sp['ten'] ? ' selected' : '' ?>><?= e($sp['ten']) ?> (<?= e($sp['ma']) ?>)</option><?php endforeach; ?></select></label>
    <label>Ngày dự kiến nhận<input type="date" name="expected" min="<?= e(gmdate('Y-m-d', time() + 7 * 3600)) ?>"></label>
  </div>
  <div class="supplier-card" id="supplier-card" hidden></div>
  <label>Ghi chú<input name="note" maxlength="255" placeholder="Điều khoản giao hàng, lưu ý…"></label>
  <?php $checkStock = false; $newLots = false; $autoLots = false; $supplierFirst = false; require __DIR__ . '/../partials/lines.php'; ?>
  <div class="actions"><button type="submit">Lưu đơn đặt hàng</button><a class="btn btn-ghost" href="/orders">Quay lại</a></div>
</form>
