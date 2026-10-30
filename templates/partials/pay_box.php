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
<div class="form pay-box" data-pay<?= !empty($dm) && isset($v['id']) ? ' data-km-url="/vouchers/' . (int)$v['id'] . '/delivery-km"' : '' ?> data-rate-own="<?= (int)$payInfo['rates']['noi_bo'] ?>" data-rate-hired="<?= (int)$payInfo['rates']['thue_ngoai'] ?>">
  <div class="pay-mode">
    <?php if ($dm): ?><label class="check"><input type="radio" name="hinh_thuc" value="TU_LAY" checked<?= $fa ?>> Khách tự đến lấy (đơn nhỏ)</label><?php endif; ?>
    <label class="check"><input type="radio" name="hinh_thuc" value="NOI_BO"<?= !$dm && $ready ? ' checked' : '' ?><?= $ready ? '' : ' disabled' ?><?= $fa ?>> <?= $dm ? 'Giao bằng xe của kho' : 'Xe của kho' ?> (trả công tài xế theo km)</label>
    <label class="check"><input type="radio" name="hinh_thuc" value="THUE_NGOAI"<?= !$dm && !$ready ? ' checked' : '' ?><?= $fa ?>> Thuê đơn vị vận tải ngoài</label>
  </div>
  <?php
// Xe và tài xế KHÔNG gắn cố định: chọn riêng. Tài xế nào có bằng đúng hạng cho xe đã chọn, đang rảnh và thuộc kho này thì lái được; danh sách tự lọc theo xe (crew.js).
$crewDrivers = $drivers ?? [];
$crewFit = $driverFit ?? [];
$driverOptions = function () use ($crewDrivers, $crewFit): string {
    $o = '';
    foreach ($crewDrivers as $dr) {
        $ft = $crewFit[(int)$dr['id']] ?? ['hard' => [], 'class' => '', 'age' => null];
        $label = $dr['ho_ten'] . ($ft['class'] !== '' ? ' — bằng ' . $ft['class'] : ' — chưa khai bằng') . ($ft['age'] !== null ? ' · ' . $ft['age'] . ' tuổi' : '');
        $o .= '<option value="' . (int)$dr['id'] . '" data-class="' . e($ft['class']) . '" data-bad="' . e($ft['hard'][0] ?? '') . '">' . e($label) . '</option>';
    }
    return $o;
};
?>
<div data-pay-own data-crew="<?= e(json_encode(\StockFlow\Domain\TransportRules::coverTable())) ?>"<?= $dm || !$ready ? ' hidden' : '' ?>>
    <label>Xe chở hàng (tài xế đi theo xe)<select name="vehicle_id"<?= $dm ? '' : ($ready ? ' required' : '') ?><?= $fa ?>><option value="">Chọn xe…</option><?php $rec = (int)($recommended ?? 0); $kindShort = ['MAY' => ' (xe máy)', 'OTO_NHO' => ' (ô tô nhỏ)', 'TAI' => ''];
      $readySorted = $ready;
      if ($rec) { usort($readySorted, fn($a, $b) => ((int)$b['id'] === $rec) <=> ((int)$a['id'] === $rec)); }   // xe khuyên dùng đứng đầu danh sách
      foreach ($readySorted as $x): $ft = ($fit ?? [])[(int)$x['id']] ?? null;
        // ghi chú đủ / không đủ điều kiện ngay trên từng xe; lỗi riêng của xe, tài xế (giấy tờ, bằng lái…) thì không cho chọn
        $note = !$ft ? '' : ((int)$x['id'] === $rec && !$ft['hard'] && !$ft['load'] ? ' — ★ Khuyên dùng (nhỏ nhất vẫn đủ chở)' : ($ft['hard'] ? ' — ✘ ' . $ft['hard'][0] : ($ft['load'] ? ' — ⚠ không đủ tải một mình' : ($ft['warns'] ? ' — ⚠ chưa khai đủ thông số' : ' — ✔ đủ điều kiện')))); ?>
      <option value="<?= (int)$x['id'] ?>" data-need="<?= e((string)\StockFlow\Domain\TransportRules::needFor($x)) ?>"<?= $ft && $ft['hard'] ? ' disabled' : '' ?><?= $rec && (int)$x['id'] === $rec && !$ft['hard'] && !$ft['load'] ? ' selected' : '' ?><?= $ft ? ' title="' . e(implode(' · ', array_merge($ft['hard'], $ft['load'], $ft['warns']))) . '"' : '' ?>><?= e($x['bien_so'] . ($kindShort[$x['kieu_xe'] ?? 'TAI'] ?? '') . ((int)($x['tai_trong_kg'] ?? 0) > 0 ? ' · ' . ((int)$x['tai_trong_kg'] < 1000 ? (int)$x['tai_trong_kg'] . ' kg' : rtrim(rtrim(number_format($x['tai_trong_kg'] / 1000, 2, ',', '.'), '0'), ',') . ' tấn') : '') . (($nd = \StockFlow\Domain\TransportRules::needFor($x)) ? ' · cần bằng ' . $nd : '') . $note) ?></option><?php endforeach; ?></select></label>
    <label>Tài xế lái xe<select name="driver_id"<?= $dm ? '' : ($ready ? ' required' : '') ?><?= $fa ?>><option value="">Chọn tài xế…</option><?= $driverOptions() ?></select></label>
    <?php if (!$dm && !empty($convoyLines) && count($ready) >= 2): require __DIR__ . '/convoy_box.php'; endif; ?>
  </div>
  <fieldset class="notrecv" data-pay-ext hidden><legend>Thông tin đơn vị vận tải (bắt buộc đủ)</legend>
    <label>Đơn vị vận tải<input name="ext_company" data-req maxlength="120" autocomplete="off" placeholder="Tên công ty / nhà xe"<?= $fa ?>></label>
    <label>Họ tên tài xế<input name="ext_driver" data-req maxlength="120" autocomplete="off"<?= $fa ?>></label>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Số CCCD tài xế<input name="ext_cccd" data-req inputmode="numeric" pattern="[0-9]{12}" maxlength="12" autocomplete="off" title="12 chữ số"<?= $fa ?>></label>
      <label>Điện thoại tài xế<input name="ext_phone" data-req inputmode="tel" pattern="0[0-9]{9}" maxlength="10" autocomplete="off" placeholder="10 chữ số"<?= $fa ?>></label>
      <label>Biển số xe<input name="ext_plate" data-req maxlength="15" autocomplete="off" placeholder="vd: 30H-123.45"<?= $fa ?>></label>
      <label>Loại xe<input name="ext_type" data-req maxlength="60" autocomplete="off" placeholder="vd: Xe tải 2 tấn"<?= $fa ?>></label>
      <label>Tải trọng xe (tấn)<input name="ext_cap" data-req inputmode="decimal" maxlength="7" autocomplete="off" placeholder="vd: 2,5"<?= $fa ?>></label>
      <label>Khối lượng toàn bộ GVW (tấn)<input name="ext_gvw" data-req inputmode="decimal" maxlength="7" autocomplete="off" placeholder="vd: 4,9" title="Ghi trên giấy đăng kiểm — quyết định hạng bằng lái cần có"<?= $fa ?>></label>
      <label>Số giấy phép lái xe<input name="ext_lic_no" data-req maxlength="20" autocomplete="off"<?= $fa ?>></label>
      <label>Hạng bằng<select name="ext_lic_class" data-req<?= $fa ?>><option value="">Chọn…</option><?php foreach (array_filter(\StockFlow\Domain\TransportRules::CLASSES, fn($k) => $k[4]) as $k => $cl): ?><option value="<?= e($k) ?>"><?= e($cl[0]) ?></option><?php endforeach; ?></select></label>
      <label>Hạn bằng lái<input type="date" name="ext_lic_exp"<?= $fa ?>></label>
    </div>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Tiền cọc hàng (bằng giá trị hàng)<input value="<?= e(n((int)($goodsValue ?? 0))) ?> đ" readonly></label>
      <label>Hình thức nộp cọc<select name="ext_coc_pt" data-req<?= $fa ?>><option value="">Chọn…</option><?php foreach (\StockFlow\Service\DeliveryService::METHODS as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
    </div>
    <label class="check"><input type="checkbox" name="ext_coc_ok" value="1" data-req<?= $fa ?>> Đã thu đủ tiền cọc — mất hàng thì kho giữ khoản cọc này</label>
  </fieldset>
  <div data-pay-fields<?= $dm ? ' hidden' : '' ?>>
    <?php if ($dm): ?>
    <p class="muted small" style="margin:0"><b>Địa chỉ giao hàng</b> — chọn tỉnh, phường theo gợi ý; điểm giao trên bản đồ demo sẽ chọn ngẫu nhiên trong phường đó.</p>
    <?php $addr = \StockFlow\Domain\VnAddress::parse($cust['dia_chi'] ?? ''); $addrNeed = true; include __DIR__ . '/address_fields.php'; ?>
    <?php if (!empty($v['doi_tac'])): ?><label class="check"><input type="checkbox" name="save_cust" value="1" checked> Lưu địa chỉ và người nhận này cho khách «<?= e($v['doi_tac']) ?>» — lần sau chọn khách là tự điền</label><?php endif; ?>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Người nhận hàng<input name="giao_nguoi_nhan" data-need maxlength="120" autocomplete="off" value="<?= e(($cust['nguoi_lien_he'] ?? '') ?: ($cust['ten'] ?? '')) ?>"<?= $fa ?>></label>
      <label>Điện thoại người nhận<input name="giao_sdt" data-need inputmode="tel" pattern="0[0-9]{9}" maxlength="10" autocomplete="off" value="<?= e($cust['so_dien_thoai'] ?? '') ?>" placeholder="10 chữ số"<?= $fa ?>></label>
    </div>
    <?php endif; ?>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Quãng đường (km)<input name="km" data-need inputmode="numeric" maxlength="4" autocomplete="off" value="<?= (int)($payInfo['km'] ?? 0) ?: '' ?>"<?= ($payInfo['km'] ?? null) ? '' : ' placeholder="' . ($dm ? 'Nhập km tới nơi giao' : 'Chưa có số liệu — nhập km') . '"' ?><?= $fa ?>></label>
      <label>Tiền trả cho chuyến (đ)<input name="tien" data-need inputmode="numeric" maxlength="10" autocomplete="off"<?= $fa ?>></label>
    </div>
    <?php if (!empty($payInfo['estimated'])): ?><p class="muted small"><?= icon('clock') ?> Quãng đường được tự ước tính theo vị trí hai kho — sửa lại nếu thực tế khác, số này sẽ được ghi nhớ cho lần sau.</p><?php endif; ?>
    <p class="muted small" data-pay-hint></p>
    <label>Ghi chú về giá (nếu khác gợi ý)<input name="gia_note" maxlength="255" autocomplete="off" placeholder="VD: đường xấu, hàng cồng kềnh"<?= $fa ?>></label>
    <label class="check"><input type="checkbox" name="driver_ok" value="1" data-need<?= $fa ?>> Tài xế đã kiểm và xác nhận nhận đủ hàng</label>
  </div>
</div>
