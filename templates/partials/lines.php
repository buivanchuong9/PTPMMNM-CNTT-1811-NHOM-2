<?php
/**
 * Chọn hàng cho phiếu nhập/xuất/điều chuyển: một ô tìm kiếm có gợi ý (không phân biệt hoa/thường, dấu),
 * chọn xong mới sinh dòng với số lượng +/−. Form vẫn gửi line[] = "mã hàng:mã lô" và qty[] như cũ.
 * Cần $pick; tuỳ chọn $stock (tồn theo kho) và $checkStock (cảnh báo khi số lượng vượt tồn).
 */
$pickItems = array_map(fn($p) => [
    'k' => $p['mat_hang_id'] . ':' . $p['lo_hang_id'], 'sku' => $p['ma_hang'], 'name' => $p['ten'], 'unit' => $p['unit'],
    'lot' => $p['quan_ly_lo'] ? $p['so_lo'] : '', 'exp' => $p['quan_ly_lo'] && $p['han_dung'] ? dt($p['han_dung'], false) : '',
], $pick);
?>
<fieldset class="lines picker" id="picker" data-items="<?= e(json_encode($pickItems, JSON_UNESCAPED_UNICODE)) ?>"
          data-stock="<?= e(json_encode((object)($stock ?? []))) ?>" data-check="<?= !empty($checkStock) ? '1' : '' ?>">
  <legend>Dòng hàng</legend>
  <div class="pick-box">
    <?= icon('search') ?>
    <input type="text" id="pick-search" placeholder="Bấm để chọn, hoặc gõ tên / SKU / số lô để tìm…" autocomplete="off"
           role="combobox" aria-expanded="false" aria-controls="pick-list" aria-label="Tìm mặt hàng">
    <ul id="pick-list" role="listbox" hidden></ul>
  </div>
  <p class="empty" id="pick-empty">Chưa có mặt hàng nào. Bấm vào ô tìm kiếm phía trên để chọn hàng.</p>
  <div class="table-wrap" id="pick-wrap" hidden>
    <table class="dense pick-table">
      <thead><tr><th>Mặt hàng</th><th class="num">Tồn tại kho</th><th class="num">Số lượng</th><th></th></tr></thead>
      <tbody id="pick-rows"></tbody>
    </table>
  </div>
  <p class="pick-sum" id="pick-sum" hidden></p>
</fieldset>
