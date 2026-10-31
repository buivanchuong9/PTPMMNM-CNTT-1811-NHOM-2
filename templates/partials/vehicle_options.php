<?php
/**
 * Các <option> của ô «Chọn xe» khi xuất hàng huỷ: ghi chú đủ điều kiện / không đủ tải / giấy tờ, khoá xe không chở được.
 * Cần: $vehicles; tuỳ chọn $fit (TransportService::fitMap của tải đang chọn) và $recommended (xe nên dùng).
 */
$fit ??= [];
$recommended ??= null;
?>
<option value="">Chọn xe…</option>
<?php foreach ($vehicles as $x): $ft = $fit[(int)$x['id']] ?? null;
  $note = !$ft ? '' : ((int)$x['id'] === (int)$recommended && !$ft['hard'] && !$ft['load'] ? ' — ★ Khuyên dùng' : ($ft['hard'] ? ' — ✘ ' . $ft['hard'][0] : ($ft['load'] ? ' — ⚠ không đủ tải' : ($ft['warns'] ? ' — ⚠ chưa khai đủ thông số' : ' — ✔ đủ điều kiện')))); ?>
<option value="<?= (int)$x['id'] ?>" data-need="<?= e((string)\StockFlow\Domain\TransportRules::needFor($x)) ?>"<?= $ft && ($ft['hard'] || $ft['load']) ? ' disabled' : '' ?><?= $ft ? ' title="' . e(implode(' · ', array_merge($ft['hard'], $ft['load'], $ft['warns']))) . '"' : '' ?>><?= e($x['bien_so'] . ($x['loai_xe'] ? ' · ' . $x['loai_xe'] : '') . ((int)($x['tai_trong_kg'] ?? 0) > 0 ? ' · ' . ((int)$x['tai_trong_kg'] < 1000 ? (int)$x['tai_trong_kg'] . ' kg' : rtrim(rtrim(number_format($x['tai_trong_kg'] / 1000, 2, ',', '.'), '0'), ',') . ' tấn') : '') . (($nd = \StockFlow\Domain\TransportRules::needFor($x)) ? ' · cần bằng ' . $nd : '') . $note) ?></option>
<?php endforeach; ?>
