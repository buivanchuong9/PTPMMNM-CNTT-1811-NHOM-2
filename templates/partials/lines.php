<?php
/**
 * Chọn hàng cho phiếu nhập/xuất/điều chuyển: một ô tìm kiếm có gợi ý (không phân biệt hoa/thường, dấu),
 * chọn xong mới sinh dòng với số lượng +/−. Form vẫn gửi line[] = "mã hàng:mã lô" và qty[] như cũ.
 * Cần $pick; tuỳ chọn $stock (tồn theo kho) và $checkStock (cảnh báo khi số lượng vượt tồn).
 */
$pickRows = $pick;
if (!empty($newLots)) {   // phiếu nhập: hàng theo lô chỉ liệt kê một lần cho mỗi mặt hàng — số lô và hạn dùng gõ ngay trên dòng
    $seen = [];
    $pickRows = [];
    $lotsOf = [];   // lô đã có của từng mặt hàng — chọn để tự điền số lô + hạn dùng
    foreach ($pick as $p) {
        if ($p['quan_ly_lo']) {
            $lotsOf[$p['mat_hang_id']][] = ['lot' => $p['so_lo'], 'exp' => $p['han_dung'] ? dt($p['han_dung'], false) : '', 'iso' => $p['han_dung']];
        }
    }
    foreach ($pick as $p) {
        if ($p['quan_ly_lo']) {
            if (isset($seen[$p['mat_hang_id']])) {
                continue;
            }
            $seen[$p['mat_hang_id']] = true;
            $p['lo_hang_id'] = 'new';
            $p['lots'] = $lotsOf[$p['mat_hang_id']] ?? [];
            $p['so_lo'] = '';
            $p['han_dung'] = null;
        }
        $pickRows[] = $p;
    }
}
if (!empty($autoLots)) {   // phiếu xuất / điều chuyển đi: mỗi mặt hàng một dòng, nhập tổng số lượng — hệ thống tự chia lô theo hạn dùng
    $groups = [];
    foreach ($pick as $p) {
        $id = $p['mat_hang_id'];
        $groups[$id] ??= $p;
        $groups[$id]['lots'] ??= [];
        if ($p['quan_ly_lo']) {
            $groups[$id]['lots'][] = ['k' => $id . ':' . $p['lo_hang_id'], 'lot' => $p['so_lo'], 'exp' => $p['han_dung'] ? dt($p['han_dung'], false) : '', 'iso' => $p['han_dung']];
        }
    }
    $pickRows = [];
    foreach ($groups as $id => $g) {
        $g['lo_hang_id'] = 'auto';
        $g['quan_ly_lo'] = false;   // dòng không gắn một lô cụ thể
        $pickRows[] = $g;
    }
}
$pickItems = array_map(fn($p) => (!empty($p['lots']) ? ['lots' => $p['lots']] : []) + [
    'k' => $p['mat_hang_id'] . ':' . $p['lo_hang_id'], 'sku' => $p['ma_hang'], 'name' => $p['ten'], 'unit' => $p['unit'],
    'cat' => (int)($p['danh_muc_id'] ?? 0), 'sup' => $itemSuppliers[$p['mat_hang_id']] ?? [], 'lot' => $p['quan_ly_lo'] ? $p['so_lo'] : '', 'exp' => $p['quan_ly_lo'] && $p['han_dung'] ? dt($p['han_dung'], false) : '', 'tracked' => (bool)$p['quan_ly_lo'], 'cost' => (int)($p['gia_von'] ?? 0),
], $pickRows);
if (empty($prefill) && !empty($prefillItem)) {   // mở từ màn hình quét mã vạch: chọn sẵn mặt hàng vừa quét (1 đơn vị)
    foreach ($pickItems as $pi) {
        if (str_starts_with($pi['k'], $prefillItem . ':')) {
            $prefill = [[$pi['k'], 1]];
            break;
        }
    }
}
?>
<fieldset class="lines picker" id="picker"<?= !empty($suppliers) || !empty($supplierMode) ? ' data-supplier="1"' : '' ?> data-items="<?= e(json_encode($pickItems, JSON_UNESCAPED_UNICODE)) ?>"
          data-stock="<?= e(json_encode((object)($stock ?? []))) ?>" data-check="<?= !empty($checkStock) ? '1' : '' ?>" data-newlots="<?= !empty($newLots) ? '1' : '' ?>" data-auto="<?= !empty($autoLots) ? '1' : '' ?>"<?= !empty($prefill) ? ' data-prefill="' . e(json_encode($prefill)) . '"' : '' ?>>
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
