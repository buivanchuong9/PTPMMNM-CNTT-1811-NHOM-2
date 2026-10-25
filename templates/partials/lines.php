<?php
/**
 * Chọn hàng cho phiếu nhập/xuất/điều chuyển: một ô tìm kiếm có gợi ý (không phân biệt hoa/thường, dấu),
 * chọn xong mới sinh dòng với số lượng +/−. Form vẫn gửi line[] = "mã hàng:mã lô" và qty[] như cũ.
 * Cần $pick; tuỳ chọn $stock (tồn theo kho) và $checkStock (cảnh báo khi số lượng vượt tồn).
 */
$pickItems = array_map(fn($p) => [
    'k' => $p['mat_hang_id'] . ':' . $p['lo_hang_id'], 'sku' => $p['ma_hang'], 'name' => $p['ten'], 'unit' => $p['unit'],
    'cat' => (int)($p['danh_muc_id'] ?? 0), 'sup' => $itemSuppliers[$p['mat_hang_id']] ?? [], 'lot' => $p['quan_ly_lo'] ? $p['so_lo'] : '', 'exp' => $p['quan_ly_lo'] && $p['han_dung'] ? dt($p['han_dung'], false) : '',
], $pick);
?>
<fieldset class="lines picker" id="picker"<?= !empty($suppliers) || !empty($supplierMode) ? ' data-supplier="1"' : '' ?> data-items="<?= e(json_encode($pickItems, JSON_UNESCAPED_UNICODE)) ?>"
          data-stock="<?= e(json_encode((object)($stock ?? []))) ?>" data-check="<?= !empty($checkStock) ? '1' : '' ?>"<?= !empty($prefill) ? ' data-prefill="' . e(json_encode($prefill)) . '"' : '' ?>>
  <legend>Dòng hàng</legend>
  <?php $showSup = empty($suppliers) && !empty($pickSups); ?>
  <?php if (empty($lockPick) && (!empty($pickCats) || $showSup)): ?>
  <div class="pick-filters">
    <?php if (!empty($pickCats)): ?><label>Danh mục hàng<select id="pick-cat" aria-label="Lọc theo danh mục"><option value="">Tất cả danh mục</option><?php foreach ($pickCats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['ten']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <?php if ($showSup): ?><label>Nhà cung cấp<select id="pick-sup" aria-label="Lọc theo nhà cung cấp"><option value="">Tất cả nhà cung cấp</option><?php foreach ($pickSups as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['ten']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="pick-box"<?= !empty($lockPick) ? ' hidden' : '' ?>>
    <?= icon('search') ?>
    <input type="text" id="pick-search" maxlength="50" data-search-clean placeholder="Bấm để chọn, hoặc gõ tên / SKU / số lô để tìm…" autocomplete="off"
           role="combobox" aria-expanded="false" aria-controls="pick-list" aria-label="Tìm mặt hàng">
    <ul id="pick-list" role="listbox" hidden></ul>
  </div>
  <p class="empty" id="pick-empty"<?= !empty($lockPick) ? ' hidden' : '' ?>>Chưa có mặt hàng nào. Bấm vào ô tìm kiếm phía trên để chọn hàng.</p>
  <div class="table-wrap" id="pick-wrap" hidden>
    <table class="dense pick-table">
      <thead><tr><th>Mặt hàng</th><th class="num">Tồn tại kho</th><th class="num">Số lượng</th><th></th></tr></thead>
      <tbody id="pick-rows"></tbody>
    </table>
  </div>
  <p class="pick-sum" id="pick-sum" hidden></p>
</fieldset>
