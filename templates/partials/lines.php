<?php /** Bảng dòng hàng dùng chung cho phiếu nhập/xuất/điều chuyển. Cần $pick. */
$row = '<div class="line"><select name="line[]" aria-label="Mặt hàng / lô">' . pick_options($pick) . '</select><input name="qty[]" type="number" min="1" max="1000000" inputmode="numeric" placeholder="Số lượng" aria-label="Số lượng"><button type="button" class="icon-btn remove-line" aria-label="Xoá dòng">✕</button></div>';
?>
<fieldset class="lines"><legend>Dòng hàng</legend>
  <div id="lines" data-template="<?= e($row) ?>"><?= str_repeat($row, 3) ?></div>
  <button type="button" class="btn-ghost" id="add-line"><?= icon('plus') ?>Thêm dòng</button>
</fieldset>
