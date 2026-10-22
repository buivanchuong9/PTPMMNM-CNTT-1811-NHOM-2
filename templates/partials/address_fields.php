<?php
/**
 * Ba ô địa chỉ có gợi ý (xem /assets/js/address.js). Biến vào: $addr = ['street','ward','province'] điền sẵn; $addrRequired (mặc định true).
 * Máy chủ ghép lại bằng VnAddress::compose() — form chỉ gửi street / ward / province.
 */
$addr = ($addr ?? []) + ['street' => '', 'ward' => '', 'province' => ''];
$req = ($addrRequired ?? true) ? ' required' : '';
?>
<fieldset class="addr" data-vn-addr>
  <legend class="sr-only">Địa chỉ</legend>
  <label>Số nhà, đường / khu công nghiệp
    <input name="street" value="<?= e($addr['street']) ?>" maxlength="150" autocomplete="off" placeholder="VD: KCN Thăng Long, xã Kim Chung"<?= $req ?>>
  </label>
  <div class="addr-row">
    <label>Tỉnh / Thành phố
      <span class="combo"><input name="province" value="<?= e($addr['province']) ?>" maxlength="60" autocomplete="off" placeholder="Gõ để tìm: Hà Nội, Đà Nẵng…"<?= $req ?>></span>
    </label>
    <label>Phường / Xã
      <span class="combo"><input name="ward" value="<?= e($addr['ward']) ?>" maxlength="60" autocomplete="off" placeholder="Gõ để tìm phường, xã"<?= $req ?>></span>
    </label>
  </div>
  <p class="muted small addr-preview" data-addr-preview aria-live="polite"></p>
</fieldset>
