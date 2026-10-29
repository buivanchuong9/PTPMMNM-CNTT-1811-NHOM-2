<?php
/**
 * Thêm xe chở cùng (xuất kho điều chuyển): nằm ngay dưới ô «Xe chở hàng». Mỗi lần bấm «＋ Thêm xe» ra một ô chọn xe phụ;
 * có xe phụ thì hiện bảng chia số lượng từng dòng hàng. Xe chính chở phần còn lại. Gửi extra[id xe][id dòng] = số lượng; ô của xe không chọn bị khoá nên không gửi.
 * Cần: $ready (xe sẵn sàng có tài xế), $convoyLines (dòng phiếu).
 */
?>
<div class="convoy" data-convoy>
  <div data-convoy-rows></div>
  <button type="button" class="btn-ghost convoy-add" data-convoy-add>＋ Thêm xe chở cùng</button>
  <template data-convoy-tpl>
    <div class="convoy-row"><select data-convoy-sel aria-label="Xe chở cùng"><option value="">Chọn xe…</option>
      <?php foreach ($ready as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['bien_so'] . ' — ' . $x['ho_ten']) ?></option><?php endforeach; ?></select>
      <button type="button" class="btn-ghost" data-convoy-del aria-label="Bỏ xe này" title="Bỏ xe này">✕</button></div>
  </template>
  <div class="table-wrap" data-convoy-table hidden>
    <table class="dense">
      <thead><tr><th>Hàng hoá</th><th class="num">Trên phiếu</th><th class="num">Xe chính <small class="muted" data-convoy-main-plate></small></th>
        <?php foreach ($ready as $x): ?><th class="num" data-car="<?= (int)$x['id'] ?>" hidden><?= e($x['bien_so']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($convoyLines as $l): ?>
        <tr data-line data-total="<?= (int)$l['so_luong'] ?>"><td><b><?= e($l['ma_hang']) ?></b> <small class="muted"><?= $l['so_lo'] === 'NOLOT' ? '' : 'lô ' . e($l['so_lo']) ?></small></td>
          <td class="num"><?= n($l['so_luong']) ?></td>
          <td class="num"><b data-main>0</b></td>
          <?php foreach ($ready as $x): ?><td class="num" data-car="<?= (int)$x['id'] ?>" hidden><input type="number" class="qty-input" min="0" max="<?= (int)$l['so_luong'] ?>" step="1" value="0" name="extra[<?= (int)$x['id'] ?>][<?= (int)$l['id'] ?>]" disabled aria-label="<?= e($x['bien_so'] . ' chở ' . $l['ma_hang']) ?>"></td><?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted small" data-convoy-hint></p>
  <button type="button" class="btn-ghost" data-convoy-even hidden>Chia đều theo số xe</button>
</div>
