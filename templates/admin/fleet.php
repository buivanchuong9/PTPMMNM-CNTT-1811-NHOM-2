<?php /** @var list<array> $vehicles @var list<array> $drivers @var list<array> $trips @var list<array> $warehouses */
$running = count(array_filter($allVehicles, fn($x) => $x['hoat_dong'] && $x['dang_den_kho_id'] !== null));
$issues = count($missing);
?>
<header class="page-head"><div><h1>Đội xe &amp; tài xế</h1></div>
  <div class="actions">
    <?php if ($canManage): ?><button type="button" data-dialog-open="vehicle-dlg"><?= icon('plus') ?>Thêm xe</button><?php endif; ?>
  </div></header>
<?php if (!$canManage): ?><p class="muted small">Chỉ xem — đội xe do quản lý từng kho quản lý (thêm xe, tài xế, xử lý mất hàng, trừ điểm &amp; lương).</p><?php endif; ?>

<section class="kpis" aria-label="Tình trạng đội xe">
  <div class="kpi"><span class="kpi-ic"><?= icon('truck') ?></span><b><?= n(count(array_filter($allVehicles, fn($x) => $x['hoat_dong']))) ?></b><span>Xe đang hoạt động</span></div>
  <div class="kpi kpi-info"><span class="kpi-ic"><?= icon('clock') ?></span><b><?= n($running) ?></b><span>Xe đang chạy trên đường</span></div>
  <div class="kpi <?= $issues ? 'kpi-warn' : 'kpi-ok' ?>"><span class="kpi-ic"><?= icon($issues ? 'alert' : 'check') ?></span><b><?= n($issues) ?></b><span>Chuyến chưa thấy hàng</span></div>
</section>

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

<form class="filters" method="get" action="/fleet">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Tìm biển số, tài xế, mã phiếu, lý do…" aria-label="Tìm kiếm trong đội xe">
  <label class="date-f">Tháng <input type="month" name="thang" value="<?= e($month) ?>" max="<?= e(date('Y-m')) ?>" aria-label="Tháng xem điểm, lương, thất thoát"></label>
  <?php if ($isAdmin): ?><select name="kho"<?= $khoSel ? ' class="on"' : '' ?> aria-label="Kho"><?= wh_options($warehouses, $khoSel ?: null, 'Mọi kho') ?></select><?php endif; ?>
  <select name="tt"<?= $status ? ' class="on"' : '' ?> aria-label="Trạng thái xe"><option value="">Mọi trạng thái xe</option><option value="ready"<?= $status === 'ready' ? ' selected' : '' ?>>Sẵn sàng</option><option value="running"<?= $status === 'running' ? ' selected' : '' ?>>Đang chạy</option><option value="off"<?= $status === 'off' ? ' selected' : '' ?>>Ngừng dùng</option></select>
  <select name="ai"<?= $bearer ? ' class="on"' : '' ?> aria-label="Ai chịu thất thoát"><option value="">Thất thoát: ai chịu</option><option value="TAI_XE"<?= $bearer === 'TAI_XE' ? ' selected' : '' ?>>Tài xế chịu</option><option value="CONG_TY"<?= $bearer === 'CONG_TY' ? ' selected' : '' ?>>Kho chịu</option></select>
  <?php if ($filtered): ?><a class="btn btn-ghost" href="/fleet">Xoá lọc</a><?php endif; ?>
</form>

<div data-live="results">
<section class="card flush">
  <div class="card-head pad"><h2>Xe <span class="count"><?= n(count($vehicles)) ?></span></h2><span class="muted small">kho tự thêm, đổi, ngừng hoặc xoá xe — không giới hạn số xe</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Biển số</th><th>Loại xe</th><th>Kho gốc</th><th>Đang ở / đang tới</th><th>Tài xế phụ trách</th><th>Trạng thái</th><?php if ($canManage): ?><th>Thao tác</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($vehicles as $x): $moving = $x['dang_den_kho_id'] !== null; ?>
      <tr class="<?= $x['hoat_dong'] ? '' : 'row-off' ?><?= is_new($changes, $x['id']) ? ' is-new' : '' ?>">
        <td><b><?= e($x['bien_so']) ?></b><?= new_tag($changes, $x['id']) ?></td>
        <td><?= e($x['loai_xe'] ?: '—') ?></td>
        <td><span class="wh-code"><?= e($x['goc_ma']) ?></span></td>
        <td><?= $moving
            ? '<span class="tag tag-info">Đang chạy</span> <span class="arrow">→</span> <span class="wh-code">' . e($x['den_ma']) . '</span> ' . e($x['den_ten'])
            : '<span class="wh-code">' . e($x['ht_ma']) . '</span> ' . e($x['ht_ten']) ?></td>
        <td><?= $x['tai_xe_ten'] ? '<b>' . e($x['tai_xe_ten']) . '</b>' . (!$x['tai_xe_hoat_dong'] ? ' <span class="tag tag-danger">Ngừng dùng</span>' : '') . '<br><small class="muted">CCCD ' . e($x['tai_xe_cccd']) . ($x['tai_xe_sdt'] ? ' · ' . e($x['tai_xe_sdt']) : '') . ($moving && $x['phieu_ma'] ? ' · <a href="/vouchers/' . (int)$x['phieu_id'] . '">' . e($x['phieu_ma']) . '</a>' : '') . '</small>' : '<span class="tag tag-warn">Chưa có tài xế</span>' ?></td>
        <td><?= !$x['hoat_dong'] ? '<span class="tag tag-danger">Ngừng dùng</span>' : ($moving ? '<span class="tag tag-info">Trên đường</span>' : '<span class="tag tag-ok">Sẵn sàng</span>') ?></td>
        <?php if ($canManage): ?><td>
          <div style="display:grid;gap:6px">
          <div class="inline-form">
            <button type="button" class="btn-ghost btn-sm" data-dialog-open="vehicle-edit-dlg" data-fill-label="<?= e($x['bien_so']) ?>"
              data-fill="<?= e(json_encode(['vehicle_id' => (int)$x['id'], 'plate' => $x['bien_so'], 'type' => $x['loai_xe'], 'warehouse_id' => (string)$x['kho_goc_id'], 'driver_name' => (string)($x['tai_xe_ten'] ?? ''), 'driver_cccd' => (string)($x['tai_xe_cccd'] ?? ''), 'driver_phone' => (string)($x['tai_xe_sdt'] ?? '')], JSON_UNESCAPED_UNICODE)) ?>">Sửa</button>
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
    <?php endforeach; if (!$vehicles): ?><tr><td colspan="7" class="empty"><?= $filtered ? 'Không có xe nào khớp bộ lọc.' : 'Chưa có xe nào.' . ($canManage ? ' Bấm «Thêm xe» (kèm thông tin tài xế) để bắt đầu.' : '') ?></td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section class="card flush">
  <div class="card-head pad"><h2>Điểm &amp; lương tài xế <small class="muted">tháng <?= e(date('m/Y', strtotime($month . '-01'))) ?></small></h2></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Tài xế</th><?php if (!$canManage): ?><th>Kho</th><?php endif; ?><th>Điểm</th><th>Lương tháng</th><th>Đã trừ <?= e(date('m/Y', strtotime($month . '-01'))) ?></th><th>Còn nhận</th><th>Số vụ mất</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($drivers as $d): $cut = (int)$d['tru_thang']; $score = (int)$d['diem']; ?>
      <tr class="<?= $d['hoat_dong'] ? '' : 'row-off' ?>">
        <td><b><?= e($d['ho_ten']) ?></b><?= $d['xe_phu_trach'] ? '<br><small class="muted">xe ' . e($d['xe_phu_trach']) . '</small>' : '' ?></td>
        <?php if (!$canManage): ?><td><span class="wh-code"><?= e($d['kho_ma'] ?? '—') ?></span></td><?php endif; ?>
        <td><span class="tag <?= $score >= 80 ? 'tag-ok' : ($score >= 50 ? 'tag-warn' : 'tag-danger') ?>"><?= $score ?>/<?= (int)\StockFlow\Service\FleetService::MAX_SCORE ?></span></td>
        <td><?= (int)$d['luong'] > 0 ? e(n($d['luong'])) . ' đ' : '<span class="muted">Chưa đặt</span>' ?></td>
        <td><?= $cut > 0 ? '<span class="tag tag-danger">−' . e(n($cut)) . ' đ</span>' : '<span class="muted">—</span>' ?></td>
        <td><?= (int)$d['luong'] > 0 ? '<b>' . e(n(max(0, (int)$d['luong'] - $cut))) . ' đ</b>' : '<span class="muted">—</span>' ?></td>
        <td><?= (int)$d['so_vu_mat'] ?: '<span class="muted">0</span>' ?></td>
        <?php if ($canManage): ?><td><button type="button" class="btn-ghost btn-sm" data-dialog-open="driver-pay-dlg" data-fill-label="<?= e($d['ho_ten']) ?>"
              data-fill="<?= e(json_encode(['driver_id' => (int)$d['id'], 'salary' => (string)(int)$d['luong'], 'score' => (string)$score], JSON_UNESCAPED_UNICODE)) ?>">Sửa</button></td><?php endif; ?>
      </tr>
    <?php endforeach; if (!$drivers): ?><tr><td colspan="8" class="empty"><?= $filtered ? 'Không có tài xế nào khớp bộ lọc.' : 'Chưa có tài xế.' ?></td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<?php if ($losses || $filtered): ?>
<section class="card flush">
  <div class="card-head pad"><h2>Thất thoát hàng tháng <?= e(date('m/Y', strtotime($month . '-01'))) ?> <span class="count"><?= n(count($losses)) ?></span></h2><span class="muted small">mất cả chuyến hoặc nhận thiếu · tổng trừ lương <b><?= e(n(array_sum(array_column($losses, 'tien_tru_luong')))) ?> đ</b></span></div>
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
    </tbody></table></div>
</section>
<?php endif; ?>

<?php $spare = array_values(array_filter($drivers, fn($d) => $d['xe_phu_trach_id'] === null)); if ($spare): ?>
<section class="card flush">
  <div class="card-head pad"><h2>Tài xế chưa gắn xe</h2><span class="muted small">hồ sơ cũ — muốn dùng lại thì nhập CCCD này khi thêm hoặc sửa xe</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Họ tên</th><th>CCCD</th><th>Điện thoại</th><th>Xe phụ trách</th><th>Chuyến đang chạy</th><th>Trạng thái</th><?php if ($canManage): ?><th>Thao tác</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($spare as $d): $busy = $d['chuyen_id'] !== null; ?>
      <tr class="<?= $d['hoat_dong'] ? '' : 'row-off' ?>">
        <td><b><?= e($d['ho_ten']) ?></b></td>
        <td><?= e($d['cccd']) ?></td>
        <td><?= e($d['so_dien_thoai'] ?: '—') ?></td>
        <td><?= $d['xe_phu_trach'] ? '<b>' . e($d['xe_phu_trach']) . '</b>' : '<span class="muted">Chưa gắn xe</span>' ?></td>
        <td><?= $busy ? '<a href="/vouchers/' . (int)$d['phieu_id'] . '">' . e($d['phieu_ma']) . '</a> <small class="muted">· xe ' . e($d['bien_so']) . '</small>' : '<span class="muted">—</span>' ?></td>
        <td><?= !$d['hoat_dong'] ? '<span class="tag tag-danger">Ngừng dùng</span>' : ($busy ? '<span class="tag tag-info">Đang chạy</span>' : '<span class="tag tag-ok">Rảnh</span>') ?></td>
        <?php if ($canManage): ?><td><?php if (!$busy): ?>
          <div class="inline-form">
            <button type="button" class="btn-ghost btn-sm" data-dialog-open="driver-edit-dlg" data-fill-label="<?= e($d['ho_ten']) ?>"
              data-fill="<?= e(json_encode(['driver_id' => (int)$d['id'], 'full_name' => $d['ho_ten'], 'cccd' => $d['cccd'], 'phone' => $d['so_dien_thoai']], JSON_UNESCAPED_UNICODE)) ?>">Sửa</button>
            <form method="post" action="/fleet/drivers/active" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="driver_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="active" value="<?= $d['hoat_dong'] ? '0' : '1' ?>">
              <button class="btn-ghost btn-sm" data-confirm="<?= $d['hoat_dong'] ? 'Ngừng dùng tài xế này?' : 'Dùng lại tài xế này?' ?>"><?= $d['hoat_dong'] ? 'Ngừng dùng' : 'Dùng lại' ?></button></form>
            <form method="post" action="/fleet/drivers/delete" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="driver_id" value="<?= (int)$d['id'] ?>">
              <button class="btn-danger btn-sm" data-confirm="Xoá tài xế <?= e($d['ho_ten']) ?>? Chỉ xoá được tài xế chưa từng chạy chuyến nào.">Xoá</button></form>
          </div>
          <?php else: ?><span class="muted small">Đang chạy — chưa sửa được</span><?php endif; ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>

<section class="card flush">
  <div class="card-head pad"><h2>Chuyến gần đây <span class="count"><?= n(count($trips)) ?></span></h2><span class="muted small">tối đa 50 chuyến mới nhất<?= $filtered ? ' khớp bộ lọc' : '' ?></span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Phiếu đi</th><th>Tuyến</th><th>Xe</th><th>Tài xế</th><th>Xuất kho</th><th>Kiểm tra lúc nhận</th><th>Trạng thái</th></tr></thead>
    <tbody>
    <?php foreach ($trips as $t): ?>
      <tr>
        <td><a href="/vouchers/<?= (int)$t['phieu_di_id'] ?>"><b><?= e($t['phieu_di_ma']) ?></b></a></td>
        <td><span class="wh-code"><?= e($t['di_kho']) ?></span> <span class="arrow">→</span> <span class="wh-code"><?= e($t['den_kho']) ?></span></td>
        <td><?= e($t['bien_so']) ?></td>
        <td><?= e($t['tai_xe_ten']) ?></td>
        <td><small class="muted"><?= e(dt($t['ngay_di'])) ?></small><br><span class="tag tag-ok">Tài xế đã xác nhận đủ hàng</span></td>
        <td class="wrap"><?php if ($t['trang_thai'] === 'DELIVERED' && $t['sai_lech'] !== ''): ?><span class="tag tag-danger">Nhận thiếu</span><br><small><?= e(preg_replace('/^Nhận thiếu: /u', '', $t['sai_lech'])) ?></small>
          <?php elseif ($t['trang_thai'] === 'DELIVERED'): ?><span class="tag tag-ok">Hàng đủ</span> <span class="tag tag-ok">Đúng tài xế</span>
          <?php elseif ($t['sai_lech'] !== ''): ?><span class="tag tag-danger">Chưa nhận được hàng</span><br><small><?= e(preg_replace('/^Chưa nhận được hàng: /u', '', $t['sai_lech'])) ?></small>
          <?php else: ?><span class="muted">Chưa nhận</span><?php endif; ?></td>
        <td><?= $t['trang_thai'] === 'LOST' ? '<span class="tag tag-danger">Đã mất hàng</span>' : ($t['trang_thai'] === 'DELIVERED' ? ($t['sai_lech'] !== '' ? '<span class="tag tag-warn">Đã giao — thiếu hàng</span>' : '<span class="tag tag-ok">Đã giao</span>') : ($t['sai_lech'] !== '' ? '<span class="tag tag-warn">Chưa thấy hàng</span>' : '<span class="tag tag-info">Đang vận chuyển</span>')) ?></td>
      </tr>
    <?php endforeach; if (!$trips): ?><tr><td colspan="7" class="empty"><?= $filtered ? 'Không có chuyến nào khớp bộ lọc.' : 'Chưa có chuyến điều chuyển nào có thông tin xe.' ?></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
</div>

<?php if ($canManage): ?>
<dialog id="vehicle-dlg" class="dlg" aria-labelledby="vehicle-dlg-t">
  <form method="post" action="/fleet/vehicles" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="vehicle-dlg-t">Thêm xe</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>Biển số<input name="plate" required maxlength="15" autocomplete="off" placeholder="vd: 30H-123.45" title="Ví dụ 30H-123.45 hoặc 51C-12345"></label>
    <label>Loại xe<input name="type" maxlength="60" autocomplete="off" placeholder="vd: Xe tải 2 tấn"></label>
    <label>Kho gốc<select name="warehouse_id" required><?= wh_options($warehouses, null, 'Chọn kho…') ?></select></label>
    <p class="dlg-sub"><b>Tài xế phụ trách</b></p>
    <label>Họ tên<input name="driver_name" required maxlength="120" autocomplete="off" placeholder="VD: Nguyễn Văn A"></label>
    <label>Số CCCD<input name="driver_cccd" required inputmode="numeric" pattern="[0-9]{12}" maxlength="12" autocomplete="off" placeholder="12 chữ số" title="Số CCCD gồm đúng 12 chữ số"></label>
    <label>Số điện thoại<input name="driver_phone" inputmode="tel" pattern="0[0-9]{9}" maxlength="10" autocomplete="off" placeholder="10 chữ số, bắt đầu bằng 0"></label>
    <p class="muted small">Xe mới đứng ở kho gốc. Kho có bao nhiêu xe cũng được; xe đi điều chuyển sẽ tự cập nhật vị trí.</p>
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm xe</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>

<dialog id="vehicle-edit-dlg" class="dlg" aria-labelledby="vehicle-edit-t">
  <form method="post" action="/fleet/vehicles/update" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="vehicle_id" value="">
    <div class="dlg-head"><h2 id="vehicle-edit-t">Sửa xe</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Xe: <b data-dlg-label></b></p>
    <label>Biển số<input name="plate" required maxlength="15" autocomplete="off" title="Ví dụ 30H-123.45 hoặc 51C-12345"></label>
    <label>Loại xe<input name="type" maxlength="60" autocomplete="off"></label>
    <label>Kho gốc<select name="warehouse_id" required><?= wh_options($warehouses, null, 'Chọn kho…') ?></select></label>
    <p class="dlg-sub"><b>Tài xế phụ trách</b></p>
    <label>Họ tên<input name="driver_name" required maxlength="120" autocomplete="off"></label>
    <label>Số CCCD<input name="driver_cccd" required inputmode="numeric" pattern="[0-9]{12}" maxlength="12" autocomplete="off" title="Số CCCD gồm đúng 12 chữ số"></label>
    <label>Số điện thoại<input name="driver_phone" inputmode="tel" pattern="0[0-9]{9}" maxlength="10" autocomplete="off" placeholder="10 chữ số, bắt đầu bằng 0"></label>
    <p class="muted small">Đổi kho gốc không dời xe đi đâu — vị trí xe đổi bằng ô «Đặt vị trí» ở danh sách. Đổi sang CCCD khác nghĩa là người khác lên thay: hồ sơ người cũ được giữ cho các chuyến đã chạy.</p>
    <div class="actions"><button type="submit">Lưu thay đổi</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>

<dialog id="loss-adj-dlg" class="dlg" aria-labelledby="loss-adj-t">
  <form method="post" action="/fleet/losses/adjust" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="loss_id" value="">
    <div class="dlg-head"><h2 id="loss-adj-t">Sửa mức xử lý thất thoát</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Phiếu: <b data-dlg-label></b></p>
    <label>Ai chịu<select name="bearer"><option value="TAI_XE">Tài xế (trừ lương, điểm)</option><option value="CONG_TY">Kho chịu (không trừ tài xế)</option></select></label>
    <label>Trừ lương (đ, không quá giá trị hàng)<input name="deduct" inputmode="numeric" autocomplete="off"></label>
    <label>Trừ điểm<input name="points" inputmode="numeric" autocomplete="off"></label>
    <div class="actions"><button type="submit">Lưu</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>

<dialog id="driver-pay-dlg" class="dlg" aria-labelledby="driver-pay-t">
  <form method="post" action="/fleet/drivers/pay" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="driver_id" value="">
    <div class="dlg-head"><h2 id="driver-pay-t">Điểm &amp; lương tài xế</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Tài xế: <b data-dlg-label></b></p>
    <label>Lương tháng (đ)<input name="salary" required inputmode="numeric" autocomplete="off" placeholder="VD: 9000000"></label>
    <label>Điểm (0–<?= (int)\StockFlow\Service\FleetService::MAX_SCORE ?>)<input name="score" required type="number" min="0" max="<?= (int)\StockFlow\Service\FleetService::MAX_SCORE ?>"></label>
    <div class="actions"><button type="submit">Lưu</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>

<dialog id="driver-edit-dlg"class="dlg" aria-labelledby="driver-edit-t">
  <form method="post" action="/fleet/drivers/update" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="driver_id" value="">
    <div class="dlg-head"><h2 id="driver-edit-t">Sửa tài xế</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Tài xế: <b data-dlg-label></b></p>
    <label>Họ tên<input name="full_name" required maxlength="120" autocomplete="off"></label>
    <label>Số CCCD<input name="cccd" required inputmode="numeric" pattern="[0-9]{12}" maxlength="12" autocomplete="off" title="Số CCCD gồm đúng 12 chữ số"></label>
    <label>Số điện thoại<input name="phone" inputmode="tel" pattern="0[0-9]{9}" maxlength="10" autocomplete="off" placeholder="10 chữ số, bắt đầu bằng 0"></label>
    <div class="actions"><button type="submit">Lưu thay đổi</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
