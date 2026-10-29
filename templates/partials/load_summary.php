<?php
/**
 * Tóm tắt tải của lô hàng (phiếu điều chuyển / xuất bán): cân thật, thể tích, trọng lượng quy đổi, hàng chưa khai số liệu; nếu đang chọn xe thì
 * nói rõ có xe nào chở được một mình không, hay phải chia nhiều xe. Cần: $load (TransportService::loadOf), tuỳ chọn $fit, $vehicles.
 */
$kgFmt = fn(float $x) => number_format($x, $x < 10 && $x > 0 ? 1 : 0, ',', '.') . ' kg';
$hasLoad = !empty($load) && ($load['weight_kg'] > 0 || $load['volume_m3'] > 0 || $load['no_weight'] || $load['no_size']);
?>
<?php if ($hasLoad): ?>
<div class="load-sum">
  <p><?= icon('truck') ?> <b>Hàng cần chở:</b> <?= e($kgFmt($load['weight_kg'])) ?> · <?= e(number_format($load['volume_m3'], 2, ',', '.')) ?> m³
    <?php if ($load['volume_m3'] > 0): ?><small class="muted">· tính cước theo <?= e($kgFmt($load['chargeable_kg'])) ?> (số lớn hơn giữa cân thật và thể tích quy đổi)</small><?php endif; ?></p>
  <?php if ($load['no_weight'] || $load['no_size']): ?>
  <p class="small"><span class="tag tag-warn">Chưa khai đủ</span> <span class="muted"><?= $load['no_weight'] ? 'cân nặng: ' . e(implode(', ', array_slice($load['no_weight'], 0, 4))) . (count($load['no_weight']) > 4 ? '…' : '') : '' ?><?= $load['no_weight'] && $load['no_size'] ? ' · ' : '' ?><?= $load['no_size'] ? 'kích thước: ' . e(implode(', ', array_slice($load['no_size'], 0, 4))) . (count($load['no_size']) > 4 ? '…' : '') : '' ?>
      — số tính ở trên có thể thấp hơn thực tế. Khai ở trang Hàng hoá.</span></p>
  <?php endif; ?>
  <?php if (!empty($recommended) && !empty($vehicles)): $rv = array_values(array_filter($vehicles, fn($x) => (int)$x['id'] === (int)$recommended))[0] ?? null; if ($rv): ?>
  <p class="small"><span class="tag tag-ok">★ Khuyên dùng</span> <span class="muted">Hàng nhẹ — <b><?= e($rv['bien_so']) ?></b> (<?= e(['MAY' => 'xe máy', 'OTO_NHO' => 'ô tô nhỏ', 'TAI' => 'xe tải'][$rv['kieu_xe'] ?? 'TAI'] ?? 'xe') ?>, tải <?= (int)$rv['tai_trong_kg'] < 1000 ? (int)$rv['tai_trong_kg'] . ' kg' : e(rtrim(rtrim(number_format($rv['tai_trong_kg'] / 1000, 2, ',', '.'), '0'), ',')) . ' tấn' ?>) là xe nhỏ nhất vẫn đủ chở, đỡ dùng xe lớn và tiền công.</span></p>
  <?php endif; endif; ?>
  <?php if (!empty($fit) && !empty($vehicles)):
        $usable = array_filter($fit, fn($f) => !$f['hard']);                       // xe không vướng lỗi riêng (giấy tờ, bằng lái…)
        $alone = array_filter($usable, fn($f) => !$f['load']);                     // …và chở được cả lô một mình
        if ($usable && !$alone && $load['weight_kg'] > 0):
            $caps = array_map(fn($x) => (int)$x['tai_trong_kg'], array_filter($vehicles, fn($x) => isset($usable[(int)$x['id']])));
            rsort($caps); $need = 0; $sum = 0;
            foreach ($caps as $cap) { $sum += $cap; $need++; if ($sum >= $load['weight_kg']) { break; } } ?>
  <p class="small"><span class="tag tag-warn">Cần nhiều xe</span> <span class="muted">Không xe nào ở kho chở được cả lô một mình<?= $need > 1 && $sum >= $load['weight_kg'] ? ' — cần ít nhất ' . $need . ' xe theo tải trọng' : '' ?>. Bấm «＋ Thêm xe chở cùng» để chia hàng.</span></p>
  <?php elseif (!$usable): ?>
  <p class="small"><span class="tag tag-danger">Không có xe</span> <span class="muted">Xe sẵn sàng đều vướng lỗi riêng (giấy tờ hết hạn, bằng lái không đúng hạng…) — xem lý do ở từng xe trong danh sách bên dưới.</span></p>
  <?php endif; endif; ?>
</div>
<?php endif; ?>
