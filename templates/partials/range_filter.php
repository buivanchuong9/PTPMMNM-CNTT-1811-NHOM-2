<?php /** Ô lọc thời gian: mốc nhanh (hôm nay, hôm qua, 7 ngày…) + «Tuỳ chọn» để chọn từ ngày / đến ngày. Cần: $rangeSel ('' | khoá DATE_RANGES | 'custom'), $from, $to. */ ?>
<select name="range"<?= $rangeSel ? ' class="on"' : '' ?> aria-label="Thời gian" data-live-reload>
  <option value="">Mọi thời gian</option>
  <?php foreach (DATE_RANGES as $k => $label): ?><option value="<?= e($k) ?>"<?= $rangeSel === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  <option value="custom"<?= $rangeSel === 'custom' ? ' selected' : '' ?>>Tuỳ chọn…</option>
</select>
<?php if ($rangeSel === 'custom'): ?>
<label class="date-f">Từ <input type="date" name="from" value="<?= e((string)$from) ?>" aria-label="Từ ngày"></label>
<label class="date-f">Đến <input type="date" name="to" value="<?= e((string)$to) ?>" aria-label="Đến ngày"></label>
<?php endif; ?>
