<?php /** @var list<array> $vehicles @var list<array> $drivers @var list<array> $trips @var list<array> $warehouses */
$issues = count($missing);
use StockFlow\Domain\TransportRules;
$tonne = fn($kg) => (int)$kg > 0 ? rtrim(rtrim(number_format($kg / 1000, 2, ',', '.'), '0'), ',') : '';   // kg → «2,5» tấn
$metre = fn($mm) => (int)$mm > 0 ? rtrim(rtrim(number_format($mm / 1000, 2, ',', '.'), '0'), ',') : '';
/** Dữ liệu điền sẵn vào hộp thoại sửa: thông số xe (kg, mm ⇒ tấn, mét) */
$specFill = fn(array $x) => ['cap_t' => $tonne($x['tai_trong_kg']), 'gvw_t' => $tonne($x['gvw_kg']), 'trailer_t' => $tonne($x['ro_mooc_kg']), 'body_type' => (string)$x['loai_thung'],
    'box_l' => $metre($x['thung_dai_mm']), 'box_w' => $metre($x['thung_rong_mm']), 'box_h' => $metre($x['thung_cao_mm']), 'tot_l' => $metre($x['tong_dai_mm']), 'tot_w' => $metre($x['tong_rong_mm']), 'tot_h' => $metre($x['tong_cao_mm']),
    'axles' => (int)$x['so_truc'] ?: '', 'vehicle_kind' => (string)($x['kieu_xe'] ?? 'TAI'), 'engine_cc' => (int)($x['dung_tich_cc'] ?? 0) ?: '', 'reg_exp' => (string)($x['han_dang_kiem'] ?? ''), 'ins_exp' => (string)($x['han_bao_hiem'] ?? '')];
/** Giấy phép lái xe: các cột của bảng tai_xe (hoặc có tiền tố tx_ khi lấy từ dòng xe) */
$licFill = fn(array $d, string $pre = '') => ['gplx_no' => (string)($d[$pre . 'so_gplx'] ?? ''), 'gplx_class' => (string)($d[$pre . 'hang_gplx'] ?? ''), 'birth' => (string)($d[$pre . 'ngay_sinh'] ?? ''), 'gplx_issued' => (string)($d[$pre . 'ngay_cap_gplx'] ?? ''),
    'gplx_expiry' => (string)($d[$pre . 'han_gplx'] ?? ''), 'gplx_place' => (string)($d[$pre . 'noi_cap_gplx'] ?? ''), 'health_exp' => (string)($d[$pre . 'han_kham_sk'] ?? ''), 'gplx_points' => (string)($d[$pre . 'diem_gplx'] ?? '')];
/** Thẻ giấy tờ: hết hạn (đỏ) · sắp hết hạn (vàng) · còn hạn (xanh) · chưa khai (vàng) — cùng quy tắc với lúc chọn xe */
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+30 days'));
$docTag = function (?string $date, string $name) use ($today, $soon): string {
    if (!$date) { return '<span class="tag tag-warn">' . e($name) . ': chưa khai</span>'; }
    $d = date('d/m/Y', strtotime($date));
    return $date < $today ? '<span class="tag tag-danger">' . e($name) . ' hết hạn ' . $d . '</span>' : ($date <= $soon ? '<span class="tag tag-warn">' . e($name) . ' sắp hết hạn ' . $d . '</span>' : '<span class="tag tag-ok">' . e($name) . ' đến ' . $d . '</span>');
};
?>
<header class="page-head"><div><h1>Đội xe &amp; tài xế</h1></div>
  <div class="actions">
    <?php if ($canManage): ?><button type="button" data-dialog-open="vehicle-dlg"><?= icon('plus') ?>Thêm xe</button>
      <button type="button" class="btn-ghost" data-dialog-open="driver-dlg"><?= icon('plus') ?>Thêm tài xế</button><?php endif; ?>
  </div></header>
<?php if (!$canManage): ?><p class="muted small">Chỉ xem — đội xe do quản lý từng kho quản lý (thêm xe, tài xế, xử lý mất hàng, trừ điểm &amp; lương).</p><?php endif; ?>

<?php
$tabs = ['chuyen' => ['Chuyến gần đây', $pgT->total + 0, $issues], 'xe' => ['Xe', $pgV->total, 0], 'taixe' => ['Tài xế', $pgD->total, 0], 'diem' => ['Điểm & lương', $pgD->total, 0], 'thatthoat' => ['Thất thoát', $pgL->total, 0], 'gia' => ['Đơn giá & quãng đường', null, 0], 'quydinh' => ['Quy định vận tải', null, 0]];
$keep = array_filter(['q' => $q, 'thang' => $month !== date('Y-m') ? $month : '', 'kho' => $khoSel ?: '', 'tt' => $status, 'ai' => $bearer]);
?>
<nav class="tabs" aria-label="Mục đội xe" data-live="tabs">
  <?php foreach ($tabs as $k => [$label, $count, $warn]): ?>
  <a href="/fleet?<?= e(http_build_query(['tab' => $k] + $keep)) ?>"<?= $tab === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?><?= $count !== null ? ' <span class="count">' . n($count) . '</span>' : '' ?><?= $warn ? ' <span class="count" style="background:var(--danger);color:#fff" title="Chuyến chưa thấy hàng">' . n($warn) . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php if (!in_array($tab, ['gia', 'quydinh'], true)): ?>
<form class="filters" method="get" action="/fleet">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Tìm biển số, tài xế, mã phiếu, lý do…" aria-label="Tìm kiếm trong đội xe">
  <?php if (in_array($tab, ['diem', 'thatthoat'], true)): ?><label class="date-f">Tháng <input type="month" name="thang" value="<?= e($month) ?>" max="<?= e(date('Y-m')) ?>" aria-label="Tháng xem điểm, lương, thất thoát"></label><?php endif; ?>
  <?php if ($isAdmin): ?><select name="kho"<?= $khoSel ? ' class="on"' : '' ?> aria-label="Kho"><?= wh_options($warehouses, $khoSel ?: null, 'Mọi kho') ?></select><?php endif; ?>
  <?php if ($tab === 'xe'): ?><select name="tt"<?= $status ? ' class="on"' : '' ?> aria-label="Trạng thái xe"><option value="">Mọi trạng thái xe</option><option value="ready"<?= $status === 'ready' ? ' selected' : '' ?>>Sẵn sàng</option><option value="running"<?= $status === 'running' ? ' selected' : '' ?>>Đang chạy</option><option value="off"<?= $status === 'off' ? ' selected' : '' ?>>Ngừng dùng</option></select><?php endif; ?>
  <?php if ($tab === 'thatthoat'): ?><select name="ai"<?= $bearer ? ' class="on"' : '' ?> aria-label="Ai chịu thất thoát"><option value="">Thất thoát: ai chịu</option><option value="TAI_XE"<?= $bearer === 'TAI_XE' ? ' selected' : '' ?>>Tài xế chịu</option><option value="CONG_TY"<?= $bearer === 'CONG_TY' ? ' selected' : '' ?>>Kho chịu</option></select><?php endif; ?>
  <?php if ($filtered): ?><a class="btn btn-ghost" href="/fleet">Xoá lọc</a><?php endif; ?>
</form>
<?php endif; ?>

<div data-live="results">
<?php if ($tab === 'chuyen'): ?>
<?php if ($missing): ?>
<section class="card flush missing-card">
  <div class="card-head pad"><h2><?= icon('alert') ?> Tài xế chưa thấy đâu <span class="count"><?= n(count($missing)) ?></span></h2><span class="muted small">kho đến báo chưa nhận được hàng — hàng vẫn đang trên đường, tồn chưa đổi</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Phiếu</th><th>Tuyến</th><th>Xe</th><th>Tài xế (gọi để hỏi)</th><th>Đã đi từ</th><th>Kho đến báo</th><th>Phản hồi cho kho</th></tr></thead>
    <tbody>
    <?php foreach ($missing as $m): ?>
      <tr>
        <td><a href="/vouchers/<?= (int)$m['phieu_den_id'] ?>"><b><?= e($m['phieu_den_ma']) ?></b></a><br><small class="muted">từ <a href="/vouchers/<?= (int)$m['phieu_di_id'] ?>"><?= e($m['phieu_di_ma']) ?></a></small></td>
        <td class="nw"><span class="wh-code"><?= e($m['di_kho']) ?></span> <span class="arrow">→</span> <span class="wh-code"><?= e($m['den_kho']) ?></span></td>
        <td><b><?= e($m['bien_so']) ?></b><?= $m['loai_xe'] ? '<br><small class="muted">' . e($m['loai_xe']) . '</small>' : '' ?></td>
        <td><b><?= e($m['tai_xe_ten']) ?></b><br><small class="muted"><?= e($m['so_dien_thoai'] ?: 'chưa có số') ?> · CCCD <?= e($m['cccd']) ?></small></td>
        <td class="muted"><?= e(dt_short($m['ngay_di'])) ?></td>
        <td class="wrap"><span class="tag tag-danger"><?= e(preg_replace('/^Chưa nhận được hàng: /u', '', $m['sai_lech'])) ?></span><br><small class="muted"><?= e($m['bao_boi'] ?? '—') ?><?= $m['bao_luc'] ? ' · ' . e(dt_short($m['bao_luc'])) : '' ?></small></td>
        <td class="wrap reply-cell">
          <?php if ($m['phan_hoi']): ?><div class="reply-last"><b><?= e($m['phan_hoi']) ?></b><br><small class="muted">gửi kho lúc <?= e(dt_short($m['phan_hoi_luc'])) ?><?= (int)$m['phan_hoi_so'] > 1 ? ' · ' . n($m['phan_hoi_so']) . ' phản hồi' : '' ?></small></div>
          <?php else: ?><span class="tag tag-warn">Chưa phản hồi kho</span><?php endif; ?>
          <?php if ($canManage): ?>
          <details class="reply-fold"><summary><?= $m['phan_hoi'] ? 'Cập nhật' : 'Phản hồi kho' ?></summary>
            <form method="post" action="/fleet/trips/reply" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="voucher_id" value="<?= (int)$m['phieu_den_id'] ?>">
              <label>Tài xế đang…<select name="status" required><option value="">Chọn tình hình…</option><?php foreach (\StockFlow\Service\FleetService::REPLY_STATUSES as $st): ?><option><?= e($st) ?></option><?php endforeach; ?></select></label>
              <label>Ghi chú cho kho<input name="note" maxlength="200" autocomplete="off" placeholder="VD: dự kiến tới kho lúc 16:30"></label>
              <button type="submit"><?= icon('check') ?>Gửi cho kho</button>
            </form></details>
          <details class="reply-fold"><summary>Xác nhận mất hàng</summary>
            <form method="post" action="/fleet/trips/loss" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="voucher_id" value="<?= (int)$m['phieu_den_id'] ?>">
              <p class="muted small">Giá trị hàng: <b><?= e(n($m['gia_tri_hang'])) ?> đ</b> · đóng chuyến, huỷ phiếu đến, tồn kho đến không đổi.</p>
              <label>Lý do<input name="reason" required maxlength="200" autocomplete="off" placeholder="VD: không liên lạc được tài xế sau 48 giờ"></label>
              <label>Ai chịu<select name="bearer"><option value="TAI_XE">Tài xế <?= e($m['tai_xe_ten']) ?> (trừ lương, điểm)</option><option value="CONG_TY">Kho chịu (không trừ tài xế)</option></select></label>
              <label>Trừ lương (đ)<input name="deduct" inputmode="numeric" autocomplete="off" placeholder="<?= e(n($m['gia_tri_hang'])) ?>" title="Để trống = trừ đủ giá trị hàng"></label>
              <label>Trừ điểm<input name="points" inputmode="numeric" autocomplete="off" placeholder="<?= (int)\StockFlow\Service\FleetService::DEFAULT_LOSS_POINTS ?>" title="Để trống = trừ mặc định"></label>
              <button type="submit" class="btn-danger" data-confirm="Xác nhận mất hàng chuyến <?= e($m['phieu_den_ma']) ?>? Không hoàn tác được."><?= icon('alert') ?>Xác nhận mất hàng</button>
            </form></details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>

<section class="card flush">
  <div class="card-head pad"><h2>Chuyến gần đây <span class="count"><?= n($pgT->total) ?></span></h2><span class="muted small">chuyến mới nhất, tối đa 200<?= $filtered ? ' khớp bộ lọc' : '' ?></span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Phiếu đi</th><th>Tuyến</th><th>Xe</th><th>Tài xế</th><th>Xuất kho</th><th>Kiểm tra lúc nhận</th><th>Km · tiền công</th><th>Trạng thái</th></tr></thead>
    <tbody>
    <?php foreach ($trips as $t): ?>
      <tr>
        <td><a href="/vouchers/<?= (int)$t['phieu_di_id'] ?>"><b><?= e($t['phieu_di_ma']) ?></b></a></td>
        <td><span class="wh-code"><?= e($t['di_kho']) ?></span> <span class="arrow">→</span> <span class="wh-code"><?= e($t['den_kho']) ?></span></td>
        <td><?= e($t['bien_so']) ?><?= $t['hinh_thuc'] === 'THUE_NGOAI' ? '<br><span class="tag tag-warn">Thuê ngoài</span>' : '' ?></td>
        <td><?= e($t['tai_xe_ten']) ?><?= $t['hinh_thuc'] === 'THUE_NGOAI' && $t['don_vi_van_tai'] !== '' ? '<br><small class="muted">' . e($t['don_vi_van_tai']) . '</small>' : '' ?></td>
        <td><small class="muted"><?= e(dt($t['ngay_di'])) ?></small><br><span class="tag tag-ok">Tài xế đã xác nhận đủ hàng</span></td>
        <td class="wrap"><?php if ($t['trang_thai'] === 'DELIVERED' && $t['sai_lech'] !== ''): ?><span class="tag tag-danger">Nhận thiếu</span><br><small><?= e(preg_replace('/^Nhận thiếu: /u', '', $t['sai_lech'])) ?></small>
          <?php elseif ($t['trang_thai'] === 'DELIVERED'): ?><span class="tag tag-ok">Hàng đủ</span> <span class="tag tag-ok">Đúng tài xế</span>
          <?php elseif ($t['sai_lech'] !== ''): ?><span class="tag tag-danger">Chưa nhận được hàng</span><br><small><?= e(preg_replace('/^Chưa nhận được hàng: /u', '', $t['sai_lech'])) ?></small>
          <?php else: ?><span class="muted">Chưa nhận</span><?php endif; ?></td>
        <td class="nw"><?= (int)$t['km'] > 0 ? e(n($t['km'])) . ' km<br><b>' . e(n($t['tien_tra'])) . ' đ</b>' . ((int)$t['tien_tra'] !== (int)$t['tien_goi_y'] ? ' <span class="tag tag-warn" title="Gợi ý ' . e(n($t['tien_goi_y'])) . ' đ">khác gợi ý</span>' : '') : '<span class="muted">—</span>' ?></td>
        <td><?= $t['trang_thai'] === 'RECALLED' ? '<span class="tag tag-warn">Đã thu hồi</span>' : ($t['trang_thai'] === 'LOST' ? '<span class="tag tag-danger">Đã mất hàng</span>' : ($t['trang_thai'] === 'DELIVERED' ? ($t['sai_lech'] !== '' ? '<span class="tag tag-warn">Đã giao — thiếu hàng</span>' : '<span class="tag tag-ok">Đã giao</span>') : ($t['sai_lech'] !== '' ? '<span class="tag tag-warn">Chưa thấy hàng</span>' : '<span class="tag tag-info">Đang vận chuyển</span>'))) ?></td>
      </tr>
    <?php endforeach; if (!$trips): ?><tr><td colspan="8" class="empty"><?= $filtered ? 'Không có chuyến nào khớp bộ lọc.' : 'Chưa có chuyến điều chuyển nào có thông tin xe.' ?></td></tr><?php endif; ?>
    </tbody></table></div><?= pager($pgT) ?>
</section>
<?php elseif ($tab === 'xe'): ?>
<section class="card flush">
  <div class="card-head pad"><h2>Xe <span class="count"><?= n($pgV->total) ?></span></h2><span class="muted small">xe quản lý riêng, không gắn tài xế cố định — mỗi chuyến chọn tài xế lúc xuất kho · không giới hạn số xe</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Biển số</th><th>Loại xe</th><th>Tải trọng · kích thước · giấy tờ</th><th>Kho gốc</th><th>Đang ở / đang tới</th><th>Trạng thái</th><?php if ($canManage): ?><th>Thao tác</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($vehicles as $x): $moving = $x['dang_den_kho_id'] !== null; ?>
      <tr class="<?= $x['hoat_dong'] ? '' : 'row-off' ?><?= is_new($changes, $x['id']) ? ' is-new' : '' ?>">
        <td><b><?= e($x['bien_so']) ?></b><?= new_tag($changes, $x['id']) ?></td>
        <td><?= e($x['loai_xe'] ?: '—') ?></td>
        <td class="spec-cell"><?php
          $need = TransportRules::needFor($x);
          $isMoto = ($x['kieu_xe'] ?? 'TAI') === 'MAY';
          if (($x['kieu_xe'] ?? 'TAI') !== 'TAI') { echo '<span class="tag tag-info">' . e(TransportRules::VEHICLE_KINDS[$x['kieu_xe']] ?? '') . '</span> '; }
          if ($isMoto) { echo (int)$x['dung_tich_cc'] > 0 ? '<small class="muted">' . (int)$x['dung_tich_cc'] . ' cm³ → cần bằng ' . e((string)$need) . '</small><br>' : '<span class="tag tag-warn">Dung tích: chưa khai</span><br>'; }
          echo (int)$x['tai_trong_kg'] > 0 ? '<b>' . ((int)$x['tai_trong_kg'] < 1000 ? e(n($x['tai_trong_kg'])) . ' kg' : e($tonne($x['tai_trong_kg'])) . ' tấn') . '</b>' : '<span class="tag tag-warn">Tải trọng: chưa khai</span>';
          echo (int)$x['gvw_kg'] > 0 ? ' <small class="muted">· GVW ' . e($tonne($x['gvw_kg'])) . ' tấn → cần bằng ' . e($need) . '</small>' : '';
          if ((int)$x['thung_dai_mm'] > 0) { echo '<br><small class="muted">' . e(TransportRules::BODY_TYPES[$x['loai_thung']] ?? 'Thùng') . ' ' . e($metre($x['thung_dai_mm'])) . '×' . e($metre($x['thung_rong_mm'])) . '×' . e($metre($x['thung_cao_mm'])) . ' m · ' . e(number_format(TransportRules::cubicMeters((int)$x['thung_dai_mm'], (int)$x['thung_rong_mm'], (int)$x['thung_cao_mm']), 1, ',', '.')) . ' m³</small>'; }
          if ((int)$x['tong_dai_mm'] > 0) { echo '<br><small class="muted">Cả xe ' . e($metre($x['tong_dai_mm'])) . '×' . e($metre($x['tong_rong_mm'])) . '×' . e($metre($x['tong_cao_mm'])) . ' m (dài×rộng×cao)</small>'; }
          echo '<br>' . ($isMoto ? '' : $docTag($x['han_dang_kiem'] ?? null, 'Đăng kiểm') . ' ') . $docTag($x['han_bao_hiem'] ?? null, 'Bảo hiểm');
        ?></td>
        <td><span class="wh-code"><?= e($x['goc_ma']) ?></span></td>
        <td><?= $moving
            ? '<span class="tag tag-info">Đang chạy</span> <span class="arrow">→</span> <span class="wh-code">' . e($x['den_ma']) . '</span> ' . e($x['den_ten']) . (!empty($x['chay_tai_xe']) ? '<br><small class="muted">tài xế ' . e($x['chay_tai_xe']) . '</small>' : '')
            : '<span class="wh-code">' . e($x['ht_ma']) . '</span> ' . e($x['ht_ten']) ?></td>
        <td><?= !$x['hoat_dong'] ? '<span class="tag tag-danger">Ngừng dùng</span>' : ($moving ? '<span class="tag tag-info">Trên đường</span>' : '<span class="tag tag-ok">Sẵn sàng</span>') ?></td>
        <?php if ($canManage): ?><td>
          <div style="display:grid;gap:6px">
          <div class="inline-form">
            <button type="button" class="btn-ghost btn-sm" data-dialog-open="vehicle-edit-dlg" data-fill-label="<?= e($x['bien_so']) ?>"
              data-fill="<?= e(json_encode(['vehicle_id' => (int)$x['id'], 'plate' => $x['bien_so'], 'type' => $x['loai_xe'], 'warehouse_id' => (string)$x['kho_goc_id']] + $specFill($x), JSON_UNESCAPED_UNICODE)) ?>">Sửa</button>
            <?php if (!$moving): ?><form method="post" action="/fleet/vehicles/delete" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="vehicle_id" value="<?= (int)$x['id'] ?>">
              <button class="btn-danger btn-sm" data-confirm="Xoá xe <?= e($x['bien_so']) ?>? Chỉ xoá được xe chưa từng chạy chuyến nào.">Xoá</button></form><?php endif; ?>
          </div>
          <?php if ($moving): ?><span class="muted small">Vị trí tự cập nhật khi kho đến nhận hàng</span>
          <?php else: ?>
          <form method="post" action="/fleet/vehicles/move" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="vehicle_id" value="<?= (int)$x['id'] ?>">
            <select name="warehouse_id" aria-label="Chuyển xe tới kho"><?= wh_options($warehouses, (int)$x['kho_hien_tai_id']) ?></select>
            <button class="btn-ghost btn-sm">Đặt vị trí</button>
          </form>
          <form method="post" action="/fleet/vehicles/active" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="vehicle_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="active" value="<?= $x['hoat_dong'] ? '0' : '1' ?>">
            <button class="btn-ghost btn-sm" data-confirm="<?= $x['hoat_dong'] ? 'Ngừng dùng xe này?' : 'Dùng lại xe này?' ?>"><?= $x['hoat_dong'] ? 'Ngừng dùng' : 'Dùng lại' ?></button></form>
          <?php endif; ?>
          </div>
        </td><?php endif; ?>
      </tr>
    <?php endforeach; if (!$vehicles): ?><tr><td colspan="7" class="empty"><?= $filtered ? 'Không có xe nào khớp bộ lọc.' : 'Chưa có xe nào.' . ($canManage ? ' Bấm «Thêm xe» để bắt đầu.' : '') ?></td></tr><?php endif; ?>
    </tbody></table></div><?= pager($pgV) ?>
</section>

<?php elseif ($tab === 'taixe'): ?>
<section class="card flush">
  <div class="card-head pad"><h2>Tài xế <span class="count"><?= n($pgD->total) ?></span></h2><span class="muted small">quản lý riêng, không gắn với xe nào — ai có bằng lái đúng hạng cho xe, đang rảnh và thuộc kho đi thì lái được; tài xế nghỉ thì chọn người khác lái thay</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Tài xế</th><th>Tuổi</th><th>Giấy phép lái xe</th><th>Khám sức khoẻ</th><th>Điểm GPLX</th><?php if (!$canManage): ?><th>Kho</th><?php endif; ?><th>Trạng thái</th><?php if ($canManage): ?><th>Thao tác</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($drivers as $d): $busy = $d['chuyen_id'] !== null; $cls = (string)$d['hang_gplx'];
          $age = ($d['ngay_sinh'] ?? '') !== '' ? TransportRules::ageOn((string)$d['ngay_sinh'], $today) : null;
          $young = $age !== null && $cls !== '' && isset(TransportRules::CLASSES[$cls]) && $age < TransportRules::minAge($cls); ?>
      <tr class="<?= $d['hoat_dong'] ? '' : 'row-off' ?>">
        <td><b><?= e($d['ho_ten']) ?></b><br><small class="muted">CCCD <?= e($d['cccd']) ?><?= $d['so_dien_thoai'] ? ' · ' . e($d['so_dien_thoai']) : '' ?></small></td>
        <td class="nw"><?= $age === null ? '<span class="tag tag-warn">Chưa khai</span>' : '<span class="tag ' . ($young ? 'tag-danger' : 'tag-muted') . '">' . (int)$age . ' tuổi</span>' ?></td>
        <td class="spec-cell"><?php if ($cls !== ''): ?>
          <span class="tag tag-ok">Bằng <?= e($cls) ?></span> <small class="muted"><?= e($d['so_gplx']) ?></small> <?= $docTag($d['han_gplx'] ?? null, 'Hạn') ?>
          <br><small class="muted">lái được <?= e(TransportRules::reach($cls)) ?><?= $young ? ' · <b class="neg">chưa đủ ' . TransportRules::minAge($cls) . ' tuổi cho hạng này</b>' : '' ?></small>
          <?php else: ?><span class="tag tag-warn">Chưa khai bằng lái</span><?php endif; ?>
          <?php if (!empty($d['anh_gplx'])): ?><br><a href="/fleet/drivers/<?= (int)$d['id'] ?>/license" target="_blank" rel="noopener"><small>Xem ảnh bằng</small></a><?php endif; ?></td>
        <td><?= $docTag($d['han_kham_sk'] ?? null, 'Khám SK') ?></td>
        <td class="nw"><?= (int)($d['diem_gplx'] ?? 12) ?>/12</td>
        <?php if (!$canManage): ?><td><span class="wh-code"><?= e($d['kho_ma'] ?? '—') ?></span></td><?php endif; ?>
        <td><?= !$d['hoat_dong'] ? '<span class="tag tag-danger">Nghỉ</span>' : ($busy ? '<span class="tag tag-info">Đang chạy</span><br><small class="muted">xe ' . e($d['xe_dang_chay']) . ' · <a href="/vouchers/' . (int)$d['phieu_id'] . '">' . e($d['phieu_ma']) . '</a></small>' : '<span class="tag tag-ok">Rảnh</span>') ?></td>
        <?php if ($canManage): ?><td><?php if (!$busy): ?>
          <div style="display:grid;gap:6px">
          <div class="inline-form">
            <button type="button" class="btn-ghost btn-sm" data-dialog-open="driver-edit-dlg" data-fill-label="<?= e($d['ho_ten']) ?>"
              data-fill="<?= e(json_encode(['driver_id' => (int)$d['id'], 'full_name' => $d['ho_ten'], 'cccd' => $d['cccd'], 'phone' => $d['so_dien_thoai']] + $licFill($d), JSON_UNESCAPED_UNICODE)) ?>">Sửa</button>
            <button type="button" class="btn-ghost btn-sm" data-dialog-open="license-photo-dlg" data-fill-label="<?= e($d['ho_ten']) ?>" data-fill="<?= e(json_encode(['driver_id' => (int)$d['id']])) ?>"><?= !empty($d['anh_gplx']) ? 'Đổi ảnh bằng' : 'Đính ảnh bằng' ?></button>
          </div>
          <div class="inline-form">
            <form method="post" action="/fleet/drivers/active" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="driver_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="active" value="<?= $d['hoat_dong'] ? '0' : '1' ?>">
              <button class="btn-ghost btn-sm" data-confirm="<?= $d['hoat_dong'] ? 'Cho tài xế này nghỉ? Sẽ không chọn được cho chuyến mới.' : 'Cho tài xế này làm việc lại?' ?>"><?= $d['hoat_dong'] ? 'Cho nghỉ' : 'Làm lại' ?></button></form>
            <form method="post" action="/fleet/drivers/delete" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="driver_id" value="<?= (int)$d['id'] ?>">
              <button class="btn-danger btn-sm" data-confirm="Xoá tài xế <?= e($d['ho_ten']) ?>? Chỉ xoá được tài xế chưa từng chạy chuyến nào.">Xoá</button></form>
          </div></div>
          <?php else: ?><span class="muted small">Đang chạy — chưa sửa được</span><?php endif; ?></td><?php endif; ?>
      </tr>
    <?php endforeach; if (!$drivers): ?><tr><td colspan="8" class="empty"><?= $filtered ? 'Không có tài xế nào khớp bộ lọc.' : 'Chưa có tài xế.' . ($canManage ? ' Bấm «Thêm tài xế» để bắt đầu.' : '') ?></td></tr><?php endif; ?>
    </tbody></table></div><?= pager($pgD) ?>
</section>

<?php elseif ($tab === 'diem'): ?>
<section class="card flush">
  <div class="card-head pad"><h2>Điểm &amp; thu nhập tài xế <small class="muted">tháng <?= e(date('m/Y', strtotime($month . '-01'))) ?></small></h2></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Tài xế</th><?php if (!$canManage): ?><th>Kho</th><?php endif; ?><th>Điểm</th><th>Chuyến · km</th><th>Tiền chuyến</th><th>Đã trừ <?= e(date('m/Y', strtotime($month . '-01'))) ?></th><th>Thực nhận</th><th>Số vụ mất</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($drivers as $d): $cut = (int)$d['tru_thang']; $score = (int)$d['diem']; ?>
      <tr class="<?= $d['hoat_dong'] ? '' : 'row-off' ?>">
        <td><b><?= e($d['ho_ten']) ?></b><?= $d['xe_dang_chay'] ? '<br><small class="muted">đang lái xe ' . e($d['xe_dang_chay']) . '</small>' : '' ?></td>
        <?php if (!$canManage): ?><td><span class="wh-code"><?= e($d['kho_ma'] ?? '—') ?></span></td><?php endif; ?>
        <td><span class="tag <?= $score >= 80 ? 'tag-ok' : ($score >= 50 ? 'tag-warn' : 'tag-danger') ?>"><?= $score ?>/<?= (int)\StockFlow\Service\FleetService::MAX_SCORE ?></span></td>
        <?php $dt = $driverTrips[(int)$d['id']] ?? []; ?>
        <td class="nw"><?php if ($dt): ?><button type="button" class="link-btn trips-btn" data-trips-toggle="<?= (int)$d['id'] ?>" aria-expanded="false" title="Xem từng chuyến"><b><?= (int)$d['so_chuyen'] ? e(n($d['so_chuyen'])) . ' chuyến' : 'Xem ' . count($dt) . ' chuyến' ?></b> ▾</button><?= (int)$d['so_chuyen'] ? '<br><small class="muted">' . e(n($d['tong_km'])) . ' km</small>' : '' ?>
          <?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?= (int)$d['tien_chuyen'] > 0 ? '<b>' . e(n($d['tien_chuyen'])) . ' đ</b>' : '<span class="muted">—</span>' ?></td>
        <td><?= $cut > 0 ? '<span class="tag tag-danger">−' . e(n($cut)) . ' đ</span>' : '<span class="muted">—</span>' ?></td>
        <td><?php $pay = (int)$d['tien_chuyen'] - $cut; ?><?= (int)$d['tien_chuyen'] > 0 ? '<b>' . e(n(max(0, $pay))) . ' đ</b>' : '<span class="muted">—</span>' ?></td>
        <td><?= (int)$d['so_vu_mat'] ?: '<span class="muted">0</span>' ?></td>
        <?php if ($canManage): ?><td><button type="button" class="btn-ghost btn-sm" data-dialog-open="driver-pay-dlg" data-fill-label="<?= e($d['ho_ten']) ?>"
              data-fill="<?= e(json_encode(['driver_id' => (int)$d['id'], 'score' => (string)$score], JSON_UNESCAPED_UNICODE)) ?>">Sửa</button></td><?php endif; ?>
      </tr>
      <?php if ($dt): $sumPay = 0; ?>
      <tr class="trips-row" id="trips-<?= (int)$d['id'] ?>" hidden><td colspan="<?= $canManage ? 8 : 8 ?>">
        <div class="trips-box">
          <table class="dense">
            <thead><tr><th>Ngày</th><th>Loại</th><th>Phiếu</th><th>Tuyến / nơi giao</th><th>Xe</th><th class="num">Km</th><th class="num">Tiền công</th><th>Tình trạng</th></tr></thead>
            <tbody>
            <?php foreach ($dt as $t):
                  $paid = $t['trang_thai'] === 'DELIVERED' && $t['hinh_thuc'] === 'NOI_BO'; $sumPay += $paid ? (int)$t['tien_tra'] : 0;
                  [$stL, $stT] = match ($t['trang_thai']) { 'DELIVERED' => ['Đã giao', 'tag-ok'], 'IN_TRANSIT' => ['Đang chạy', 'tag-info'], 'RECALLED' => ['Đã thu hồi', 'tag-warn'], 'LOST' => ['Mất hàng', 'tag-danger'], default => [$t['trang_thai'], 'tag-muted'] }; ?>
              <tr class="<?= $paid ? '' : 'row-off' ?>">
                <td class="nw"><?= e(dt($t['ngay'])) ?></td>
                <td><?= $t['loai'] === 'GIAO_KHACH' ? '<span class="tag tag-muted">Giao khách</span>' : '<span class="tag tag-info">Điều chuyển</span>' ?></td>
                <td><?php if ($isAdmin || (int)$t['kho_phieu_id'] === (int)$myWh): ?><a href="/vouchers/<?= (int)$t['phieu_id'] ?>"><?= e($t['phieu_ma']) ?></a><?php else: ?><span title="Phiếu của kho khác — chỉ kho xuất phát và quản trị xem được"><?= e($t['phieu_ma']) ?></span><?php endif; ?></td>
                <td><?= $t['loai'] === 'GIAO_KHACH' ? '<span class="wh-code">' . e($t['tu']) . '</span> <span class="arrow">→</span> ' . e($t['den']) : '<span class="wh-code">' . e($t['tu']) . '</span> <span class="arrow">→</span> <span class="wh-code">' . e($t['den']) . '</span>' ?></td>
                <td><?= e($t['bien_so'] ?? '—') ?></td>
                <td class="num"><?= (int)$t['km'] > 0 ? e(n($t['km'])) : '<span class="muted">—</span>' ?></td>
                <td class="num"><?= (int)$t['tien_tra'] > 0 ? '<b>' . e(n($t['tien_tra'])) . ' đ</b>' : '<span class="muted">—</span>' ?></td>
                <td><span class="tag <?= $stT ?>"><?= e($stL) ?></span><?= !$paid && $t['trang_thai'] !== 'DELIVERED' ? ' <small class="muted">chưa tính tiền</small>' : '' ?><?= (int)$t['km'] === 0 && $paid ? ' <small class="muted">chưa nhập km</small>' : '' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="6" class="num muted">Tổng tiền các chuyến đã giao trong tháng (cả <?= count($dt) ?> chuyến)</td><td class="num"><b><?= e(n($sumPay)) ?> đ</b></td><td></td></tr></tfoot>
          </table>
        </div>
      </td></tr>
      <?php endif; ?>
    <?php endforeach; if (!$drivers): ?><tr><td colspan="9" class="empty"><?= $filtered ? 'Không có tài xế nào khớp bộ lọc.' : 'Chưa có tài xế.' ?></td></tr><?php endif; ?>
    </tbody></table></div><?= pager($pgD) ?>
</section>

<?php elseif ($tab === 'quydinh'): ?>
<?php require __DIR__ . '/fleet_rules.php'; ?>
<?php elseif ($tab === 'gia'): ?>
<section class="card pad">
  <h2>Đơn giá mỗi km</h2>
  <p class="muted small">Kho đi đặt đơn giá. Khi xuất kho điều chuyển, hệ thống gợi ý tiền = số km × đơn giá; người lập chuyến vẫn nhập được số tiền khác nếu hợp lý. Lương tài xế của kho tính từ các chuyến đã giao theo số tiền này.</p>
  <table class="dense"><thead><tr><th>Kho</th><th class="num">Xe của kho (trả tài xế)</th><th class="num">Thuê ngoài</th></tr></thead><tbody>
  <?php foreach ($warehouses as $w): $rt = $rates[(int)$w['id']] ?? ['noi_bo' => 0, 'thue_ngoai' => 0]; $mine = $canManage && (int)$myWh === (int)$w['id']; ?>
    <tr><td><span class="wh-code"><?= e($w['ma']) ?></span> <?= e($w['ten']) ?></td>
    <?php if ($mine): ?>
      <td colspan="2"><form method="post" action="/fleet/rates" class="inline-form" style="flex-wrap:wrap;align-items:center;justify-content:flex-end" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label class="small">đ/km xe kho <input name="rate_own" inputmode="numeric" required maxlength="9" value="<?= (int)$rt['noi_bo'] ?>" style="width:110px"></label>
        <label class="small">đ/km thuê ngoài <input name="rate_hired" inputmode="numeric" required maxlength="9" value="<?= (int)$rt['thue_ngoai'] ?>" style="width:110px"></label>
        <button type="submit">Lưu</button></form></td>
    <?php else: ?><td class="num"><?= e(n($rt['noi_bo'])) ?> đ/km</td><td class="num"><?= e(n($rt['thue_ngoai'])) ?> đ/km</td><?php endif; ?></tr>
  <?php endforeach; ?></tbody></table>
</section>
<?php if ($canManage && $distances): ?>
<section class="card pad" style="margin-top:16px">
  <h2>Quãng đường từ kho của bạn</h2>
  <p class="muted small">Dùng chung hai chiều. Chưa có số liệu thì nhập km ngay khi lập chuyến đầu tiên, hệ thống sẽ ghi nhớ.</p>
  <form method="post" action="/fleet/distances" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <table class="dense"><thead><tr><th>Đến kho</th><th class="num">Quãng đường (km)</th></tr></thead><tbody>
    <?php foreach ($distances as $dk): ?>
      <tr><td><span class="wh-code"><?= e($dk['ma']) ?></span> <?= e($dk['ten']) ?></td><td class="num"><input name="km[<?= (int)$dk['id'] ?>]" inputmode="numeric" maxlength="4" value="<?= $dk['km'] ? (int)$dk['km'] : '' ?>" placeholder="chưa có" style="width:100px;text-align:right"></td></tr>
    <?php endforeach; ?></tbody></table>
    <div class="actions pad"><button type="submit">Lưu quãng đường</button></div>
  </form>
</section>
<?php endif; ?>
<?php else: ?>
<?php if ($losses || $filtered): ?>
<section class="card flush">
  <div class="card-head pad"><h2>Thất thoát hàng tháng <?= e(date('m/Y', strtotime($month . '-01'))) ?> <span class="count"><?= n($pgL->total) ?></span></h2><span class="muted small">mất cả chuyến hoặc nhận thiếu · tổng trừ lương <b><?= e(n(array_sum(array_column($losses, 'tien_tru_luong')))) ?> đ</b></span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Phiếu</th><th>Tuyến</th><th>Tài xế · xe</th><th>Lý do</th><th>Mất</th><th>Ai chịu</th><th>Ghi nhận</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($losses as $h): ?>
      <tr>
        <td><a href="/vouchers/<?= (int)$h['phieu_den_id'] ?>"><b><?= e($h['phieu_den_ma']) ?></b></a></td>
        <td class="nw"><span class="wh-code"><?= e($h['di_kho']) ?></span> <span class="arrow">→</span> <span class="wh-code"><?= e($h['den_kho']) ?></span></td>
        <td><?= e($h['tai_xe_ten']) ?><br><small class="muted"><?= e($h['bien_so']) ?></small></td>
        <td class="wrap"><?= e($h['ly_do']) ?></td>
        <td><b><?= e(n($h['gia_tri'])) ?> đ</b><?= $h['loai'] === 'NHAN_THIEU' ? '<br><small class="muted">nhận thiếu</small>' : '<br><small class="muted">mất cả chuyến</small>' ?></td>
        <td class="wrap"><?= $h['nguoi_chiu'] === 'TAI_XE' ? '<span class="tag tag-danger">Tài xế</span><br><small class="muted">−' . e(n($h['tien_tru_luong'])) . ' đ · −' . (int)$h['diem_tru'] . ' điểm</small>' : '<span class="tag tag-warn">Kho chịu</span>' ?></td>
        <td class="muted"><?= e($h['nguoi_xn']) ?><br><small><?= e(dt_short($h['ngay_tao'])) ?></small></td>
        <?php if ($canManage): ?><td><button type="button" class="btn-ghost btn-sm" data-dialog-open="loss-adj-dlg" data-fill-label="<?= e($h['phieu_den_ma']) ?>"
          data-fill="<?= e(json_encode(['loss_id' => (int)$h['id'], 'bearer' => $h['nguoi_chiu'], 'deduct' => (string)(int)$h['tien_tru_luong'], 'points' => (string)(int)$h['diem_tru']], JSON_UNESCAPED_UNICODE)) ?>">Sửa mức trừ</button></td><?php endif; ?>
      </tr>
    <?php endforeach; if (!$losses): ?><tr><td colspan="8" class="empty">Không có thất thoát nào khớp bộ lọc trong tháng này.</td></tr><?php endif; ?>
    </tbody></table></div><?= pager($pgL) ?>
</section>
<?php endif; ?>

<?php endif; ?>
</div>

<?php if ($canManage): ?>
<dialog id="vehicle-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="vehicle-dlg-t">
  <form method="post" action="/fleet/vehicles" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="vehicle-dlg-t">Thêm xe</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>Biển số<input name="plate" required maxlength="15" autocomplete="off" placeholder="vd: 30H-123.45" title="Ví dụ 30H-123.45 hoặc 51C-12345"></label>
    <label>Loại xe<input name="type" maxlength="60" autocomplete="off" placeholder="vd: Xe tải 2 tấn"></label>
    <label>Kho gốc<select name="warehouse_id" required><?= wh_options($warehouses, null, 'Chọn kho…') ?></select></label>
    <?php require __DIR__ . '/../partials/vehicle_specs_fields.php'; ?>
    <p class="muted small">Xe không gắn tài xế cố định: mỗi chuyến chọn tài xế lúc xuất kho (thêm tài xế ở mục «Tài xế»). Xe mới đứng ở kho gốc; xe đi điều chuyển sẽ tự cập nhật vị trí.
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm xe</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>

<dialog id="vehicle-edit-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="vehicle-edit-t">
  <form method="post" action="/fleet/vehicles/update" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="vehicle_id" value="">
    <div class="dlg-head"><h2 id="vehicle-edit-t">Sửa xe</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Xe: <b data-dlg-label></b></p>
    <label>Biển số<input name="plate" required maxlength="15" autocomplete="off" title="Ví dụ 30H-123.45 hoặc 51C-12345"></label>
    <label>Loại xe<input name="type" maxlength="60" autocomplete="off"></label>
    <label>Kho gốc<select name="warehouse_id" required><?= wh_options($warehouses, null, 'Chọn kho…') ?></select></label>
    <?php require __DIR__ . '/../partials/vehicle_specs_fields.php'; ?>
    <p class="muted small">Đổi kho gốc không dời xe đi đâu — vị trí xe đổi bằng ô «Đặt vị trí» ở danh sách.
    <div class="actions"><button type="submit">Lưu thay đổi</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>

<dialog id="loss-adj-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="loss-adj-t">
  <form method="post" action="/fleet/losses/adjust" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="loss_id" value="">
    <div class="dlg-head"><h2 id="loss-adj-t">Sửa mức xử lý thất thoát</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Phiếu: <b data-dlg-label></b></p>
    <label>Ai chịu<select name="bearer"><option value="TAI_XE">Tài xế (trừ lương, điểm)</option><option value="CONG_TY">Kho chịu (không trừ tài xế)</option></select></label>
    <label>Trừ lương (đ, không quá giá trị hàng)<input name="deduct" inputmode="numeric" autocomplete="off"></label>
    <label>Trừ điểm<input name="points" inputmode="numeric" autocomplete="off"></label>
    <div class="actions"><button type="submit">Lưu</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>

<dialog id="driver-pay-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="driver-pay-t">
  <form method="post" action="/fleet/drivers/pay" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="driver_id" value="">
    <div class="dlg-head"><h2 id="driver-pay-t">Điểm tài xế</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Tài xế: <b data-dlg-label></b></p>
    <label>Điểm (0–<?= (int)\StockFlow\Service\FleetService::MAX_SCORE ?>)<input name="score" required type="number" min="0" max="<?= (int)\StockFlow\Service\FleetService::MAX_SCORE ?>"></label>
    <div class="actions"><button type="submit">Lưu</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>

<dialog id="driver-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="driver-dlg-t">
  <form method="post" action="/fleet/drivers" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="driver-dlg-t">Thêm tài xế</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>Họ tên<input name="full_name" required maxlength="120" autocomplete="off" placeholder="VD: Nguyễn Văn A"></label>
    <label>Số CCCD<input name="cccd" required inputmode="numeric" pattern="[0-9]{12}" maxlength="12" autocomplete="off" placeholder="12 chữ số" title="Số CCCD gồm đúng 12 chữ số"></label>
    <label>Số điện thoại<input name="phone" inputmode="tel" pattern="0[0-9]{9}" maxlength="10" autocomplete="off" placeholder="10 chữ số, bắt đầu bằng 0"></label>
    <?php require __DIR__ . '/../partials/license_fields.php'; ?>
    <p class="muted small">Tài xế thuộc kho của bạn, không gắn với xe nào. Mỗi chuyến, người xuất kho chọn xe và tài xế — hệ thống chỉ cho chọn tài xế có bằng lái đúng hạng cho xe đó.</p>
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm tài xế</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>

<dialog id="license-photo-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="license-photo-t">
  <form method="post" action="/fleet/drivers/license-photo" enctype="multipart/form-data" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="driver_id" value="">
    <div class="dlg-head"><h2 id="license-photo-t">Ảnh giấy phép lái xe</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Tài xế: <b data-dlg-label></b></p>
    <label>Chọn ảnh (JPG, PNG hoặc WebP, tối đa 3 MB)<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label>
    <p class="muted small">Chụp rõ cả tấm bằng (mặt có số, hạng, ngày cấp, hạn). Ảnh chỉ người xem đội xe của kho này và quản trị mới xem được. Ảnh mới thay ảnh cũ.</p>
    <div class="actions"><button type="submit">Đính ảnh</button>
      <button type="submit" class="btn-ghost btn-danger-ghost" formaction="/fleet/drivers/license-photo/remove" formnovalidate data-confirm="Gỡ ảnh giấy phép lái xe của tài xế này?">Gỡ ảnh hiện có</button>
      <button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>

<dialog id="driver-edit-dlg" class="dlg dlg-form-wide dlg-xl"
 aria-labelledby="driver-edit-t">
  <form method="post" action="/fleet/drivers/update" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="driver_id" value="">
    <div class="dlg-head"><h2 id="driver-edit-t">Sửa tài xế</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Tài xế: <b data-dlg-label></b></p>
    <label>Họ tên<input name="full_name" required maxlength="120" autocomplete="off"></label>
    <label>Số CCCD<input name="cccd" required inputmode="numeric" pattern="[0-9]{12}" maxlength="12" autocomplete="off" title="Số CCCD gồm đúng 12 chữ số"></label>
    <label>Số điện thoại<input name="phone" inputmode="tel" pattern="0[0-9]{9}" maxlength="10" autocomplete="off" placeholder="10 chữ số, bắt đầu bằng 0"></label>
    <?php require __DIR__ . '/../partials/license_fields.php'; ?>
    <div class="actions"><button type="submit">Lưu thay đổi</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
