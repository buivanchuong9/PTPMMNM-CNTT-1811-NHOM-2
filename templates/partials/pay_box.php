<?php
/**
 * Khối «vận chuyển & tiền công»: xe của kho / thuê đơn vị vận tải ngoài, quãng đường, tiền gợi ý (km × đơn giá) sửa được.
 * Dùng cho xuất kho điều chuyển và giao hàng bán. $deliveryMode: thêm lựa chọn «Khách tự đến lấy» + địa chỉ giao.
 * Cần: $ready (xe sẵn sàng có tài xế), $payInfo (km gợi ý, rates); tuỳ chọn $formId (gắn các ô vào form ngoài khối), $address.
 */
$dm = !empty($deliveryMode);
$fa = !empty($formId) ? ' form="' . e($formId) . '"' : '';
?>
<?php if ($dm): ?>
<fieldset class="notrecv pay-money"><legend>Thanh toán — số tiền <?= e(n((int)($saleTotal ?? 0))) ?> đ</legend>
  <label class="check"><input type="radio" name="thanh_toan" value="DA_TT" data-need-pay required<?= $fa ?>> Khách đã thanh toán</label>
  <label class="check"><input type="radio" name="thanh_toan" value="COD" data-need-pay required<?= $fa ?>> Thu tiền khi nhận hàng (giao hàng hoặc khách đến lấy)</label>
  <label data-paid-method hidden>Hình thức thanh toán<select name="pt_tt"<?= $fa ?>><option value="">Chọn…</option><?php foreach (\StockFlow\Service\DeliveryService::METHODS as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
</fieldset>
<?php endif; ?>
<div class="form pay-box" data-pay data-rate-own="<?= (int)$payInfo['rates']['noi_bo'] ?>" data-rate-hired="<?= (int)$payInfo['rates']['thue_ngoai'] ?>">
  <div class="pay-mode">
    <?php if ($dm): ?><label class="check"><input type="radio" name="hinh_thuc" value="TU_LAY" checked<?= $fa ?>> Khách tự đến lấy (đơn nhỏ)</label><?php endif; ?>
    <label class="check"><input type="radio" name="hinh_thuc" value="NOI_BO"<?= !$dm && $ready ? ' checked' : '' ?><?= $ready ? '' : ' disabled' ?><?= $fa ?>> <?= $dm ? 'Giao bằng xe của kho' : 'Xe của kho' ?> (trả công tài xế theo km)</label>
    <label class="check"><input type="radio" name="hinh_thuc" value="THUE_NGOAI"<?= !$dm && !$ready ? ' checked' : '' ?><?= $fa ?>> Thuê đơn vị vận tải ngoài</label>
  </div>
  <div data-pay-own<?= $dm || !$ready ? ' hidden' : '' ?>>
    <label>Xe chở hàng (tài xế đi theo xe)<select name="vehicle_id"<?= $dm ? '' : ($ready ? ' required' : '') ?><?= $fa ?>><option value="">Chọn xe…</option><?php foreach ($ready as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['bien_so'] . ' — ' . $x['ho_ten'] . ' · CCCD ' . $x['cccd']) ?></option><?php endforeach; ?></select></label>
  </div>
  <fieldset class="notrecv" data-pay-ext hidden><legend>Thông tin đơn vị vận tải (bắt buộc đủ)</legend>
    <label>Đơn vị vận tải<input name="ext_company" data-req maxlength="120" autocomplete="off" placeholder="Tên công ty / nhà xe"<?= $fa ?>></label>
    <label>Họ tên tài xế<input name="ext_driver" data-req maxlength="120" autocomplete="off"<?= $fa ?>></label>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Số CCCD tài xế<input name="ext_cccd" data-req inputmode="numeric" pattern="[0-9]{12}" maxlength="12" autocomplete="off" title="12 chữ số"<?= $fa ?>></label>
      <label>Điện thoại tài xế<input name="ext_phone" data-req inputmode="tel" pattern="0[0-9]{9}" maxlength="10" autocomplete="off" placeholder="10 chữ số"<?= $fa ?>></label>
      <label>Biển số xe<input name="ext_plate" data-req maxlength="15" autocomplete="off" placeholder="vd: 30H-123.45"<?= $fa ?>></label>
      <label>Loại xe<input name="ext_type" data-req maxlength="60" autocomplete="off" placeholder="vd: Xe tải 2 tấn"<?= $fa ?>></label>
    </div>
  </fieldset>
  <div data-pay-fields<?= $dm ? ' hidden' : '' ?>>
    <?php if ($dm): ?>
    <label>Địa chỉ giao hàng<input name="giao_dia_chi" data-need maxlength="255" autocomplete="off" value="<?= e($cust['dia_chi'] ?? '') ?>" placeholder="Số nhà, đường, phường, tỉnh"<?= $fa ?>></label>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Người nhận hàng<input name="giao_nguoi_nhan" data-need maxlength="120" autocomplete="off" value="<?= e(($cust['nguoi_lien_he'] ?? '') ?: ($cust['ten'] ?? '')) ?>"<?= $fa ?>></label>
      <label>Điện thoại người nhận<input name="giao_sdt" data-need inputmode="tel" pattern="0[0-9]{9}" maxlength="10" autocomplete="off" value="<?= e($cust['so_dien_thoai'] ?? '') ?>" placeholder="10 chữ số"<?= $fa ?>></label>
    </div>
    <?php endif; ?>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Quãng đường (km)<input name="km" data-need inputmode="numeric" maxlength="4" autocomplete="off" value="<?= (int)($payInfo['km'] ?? 0) ?: '' ?>"<?= ($payInfo['km'] ?? null) ? '' : ' placeholder="' . ($dm ? 'Nhập km tới nơi giao' : 'Chưa có số liệu — nhập km') . '"' ?><?= $fa ?>></label>
      <label>Tiền trả cho chuyến (đ)<input name="tien" data-need inputmode="numeric" maxlength="10" autocomplete="off"<?= $fa ?>></label>
    </div>
    <p class="muted small" data-pay-hint></p>
    <label>Ghi chú về giá (nếu khác gợi ý)<input name="gia_note" maxlength="255" autocomplete="off" placeholder="VD: đường xấu, hàng cồng kềnh"<?= $fa ?>></label>
    <label class="check"><input type="checkbox" name="driver_ok" value="1" data-need<?= $fa ?>> Tài xế đã kiểm và xác nhận nhận đủ hàng</label>
  </div>
</div>
