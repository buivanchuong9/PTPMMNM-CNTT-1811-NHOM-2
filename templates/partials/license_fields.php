<?php
/**
 * Giấy phép lái xe và hồ sơ tài xế (hộp thoại thêm / sửa xe và sửa tài xế). Hạng bằng theo Luật Trật tự, an toàn giao thông đường bộ 2024 (hiệu lực 01/01/2025);
 * bằng cấp trước đó còn hiệu lực đến hết hạn (B2 dùng như hạng B). Chưa khai thì để trống — khi chọn xe chỉ bị cảnh báo «chưa khai».
 */
use StockFlow\Domain\TransportRules;

$moto = array_intersect_key(TransportRules::CLASSES, array_flip(['A1', 'A']));
$truck = array_filter(TransportRules::CLASSES, fn($c, $k) => $c[4] && !isset($moto[$k]), ARRAY_FILTER_USE_BOTH);
$other = array_filter(TransportRules::CLASSES, fn($c) => !$c[4]);
?>
<fieldset class="specs"><legend>Giấy phép lái xe</legend>
  <div class="row-3" style="grid-template-columns:1fr 1fr">
    <label>Số giấy phép lái xe<input name="gplx_no" maxlength="20" autocomplete="off" placeholder="vd: 790123456789"></label>
    <label>Hạng bằng<select name="gplx_class"><option value="">Chưa khai</option>
      <optgroup label="Mô tô — giao hàng bằng xe máy"><?php foreach ($moto as $k => $c): ?><option value="<?= e($k) ?>"><?= e($c[0]) ?></option><?php endforeach; ?></optgroup>
      <optgroup label="Lái được ô tô, xe tải"><?php foreach ($truck as $k => $c): ?><option value="<?= e($k) ?>"><?= e($c[0]) ?></option><?php endforeach; ?></optgroup>
      <optgroup label="Không lái được xe chở hàng"><?php foreach ($other as $k => $c): ?><option value="<?= e($k) ?>"><?= e($c[0]) ?></option><?php endforeach; ?></optgroup></select></label>
  </div>
  <div class="row-3" style="grid-template-columns:repeat(3,1fr)">
    <label>Ngày sinh <small class="muted" data-age></small><input type="date" name="birth" data-birth></label>
    <label>Ngày cấp bằng<input type="date" name="gplx_issued"></label>
    <label>Hạn bằng<input type="date" name="gplx_expiry" title="Để trống: hệ thống tự tính từ ngày cấp theo thời hạn của hạng bằng"></label>
  </div>
  <div class="row-3" style="grid-template-columns:2fr 1fr 1fr">
    <label>Nơi cấp<input name="gplx_place" maxlength="120" autocomplete="off" placeholder="vd: Sở GTVT Hà Nội"></label>
    <label>Hạn khám sức khoẻ<input type="date" name="health_exp"></label>
    <label>Điểm GPLX còn lại<input type="number" name="gplx_points" min="0" max="12" step="1" placeholder="12"></label>
  </div>
  <p class="muted small">Đủ tuổi tối thiểu tại ngày cấp: hạng B, C1 từ 18 · C, BE từ 21 · C1E, CE, D1, D2 từ 24 · D từ 27. Hạn bằng để trống thì tự tính từ ngày cấp.</p>
</fieldset>
