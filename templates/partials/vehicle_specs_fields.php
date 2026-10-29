<?php
/**
 * Thông số kỹ thuật của xe (dùng trong hộp thoại thêm / sửa xe). Nhập theo đơn vị người dùng quen: tải trọng tính bằng TẤN, kích thước bằng MÉT.
 * Để trống = chưa khai (khi chọn xe chỉ bị cảnh báo). Hạng bằng lái cần có suy ra từ khối lượng toàn bộ (GVW) — xem trang «Quy định vận tải».
 * Toàn bộ ô nằm trong một lưới tự chia cột (5 cột trên màn hình máy tính) để form ngắn, không phải kéo xuống.
 */
?>
<fieldset class="specs" data-spec><legend>Thông số xe</legend>
  <div class="row-3 specs-grid">
    <label>Kiểu xe<select name="vehicle_kind" data-spec-kind><?php foreach (\StockFlow\Domain\TransportRules::VEHICLE_KINDS as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === 'TAI' ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label>Dung tích xi-lanh (cm³, xe máy)<input name="engine_cc" inputmode="numeric" maxlength="4" autocomplete="off" placeholder="vd: 110" data-spec-cc title="Xe máy đến 175 cm³ cần bằng A1, trên 175 cm³ cần bằng A"></label>
    <label>Tải trọng chở hàng (tấn)<input name="cap_t" inputmode="decimal" maxlength="7" autocomplete="off" placeholder="vd: 2,5" title="Khối lượng hàng chuyên chở cho phép ghi trên giấy đăng kiểm"></label>
    <label>Khối lượng toàn bộ GVW (tấn)<input name="gvw_t" inputmode="decimal" maxlength="7" autocomplete="off" placeholder="vd: 4,9" data-spec-gvw title="Tự trọng xe + hàng + người, ghi trên giấy đăng kiểm; quyết định hạng bằng lái"></label>
    <label>Rơ moóc kéo theo (tấn)<input name="trailer_t" inputmode="decimal" maxlength="7" autocomplete="off" placeholder="0 = không kéo" data-spec-trailer></label>
    <label>Loại thùng<select name="body_type"><option value="">Chưa khai</option><?php foreach (\StockFlow\Domain\TransportRules::BODY_TYPES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label>Số trục<input name="axles" type="number" min="2" max="10" step="1" placeholder="vd: 2"></label>

    <label>Lòng thùng dài (m)<input name="box_l" inputmode="decimal" maxlength="6" autocomplete="off" placeholder="vd: 4,2"></label>
    <label>Lòng thùng rộng (m)<input name="box_w" inputmode="decimal" maxlength="6" autocomplete="off" placeholder="vd: 2,0"></label>
    <label>Lòng thùng cao (m)<input name="box_h" inputmode="decimal" maxlength="6" autocomplete="off" placeholder="vd: 2,0"></label>
    <label>Hạn đăng kiểm<input type="date" name="reg_exp"></label>
    <label>Hạn bảo hiểm TNDS<input type="date" name="ins_exp"></label>

    <label>Cả xe — dài (m)<input name="tot_l" inputmode="decimal" maxlength="6" autocomplete="off" placeholder="vd: 6,3"></label>
    <label>Cả xe — rộng (m)<input name="tot_w" inputmode="decimal" maxlength="6" autocomplete="off" placeholder="vd: 1,95"></label>
    <label>Cả xe — cao (m)<input name="tot_h" inputmode="decimal" maxlength="6" autocomplete="off" placeholder="vd: 2,5"></label>
  </div>
  <p class="muted small" data-spec-need>Bằng lái cần có: nhập GVW để biết (≤ 3,5 tấn hạng B · đến 7,5 tấn hạng C1 · trên 7,5 tấn hạng C; kéo rơ moóc trên 0,75 tấn thêm «E»).</p>
</fieldset>
