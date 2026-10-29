<?php
/**
 * Tab «Quy định vận tải» của trang Đội xe: hạng giấy phép lái xe theo khối lượng xe, độ tuổi, cách tính tải và khổ xe, kèm các giá trị
 * quản trị chỉnh được. Mọi kiểm tra khi chọn xe (tải trọng, thể tích, bằng lái, giấy tờ) dựa trên bảng này.
 * Cần: $limits (TransportService::limits), $isAdmin, $csrf.
 */
use StockFlow\Domain\TransportRules as R;

$m = fn(int $mm) => number_format($mm / 1000, 2, ',', '.');
?>
<section class="card">
  <h2>Hạng giấy phép lái xe và xe được lái</h2>
  <p class="muted small">Theo Luật Trật tự, an toàn giao thông đường bộ 2024 (có hiệu lực 01/01/2025). Hệ thống suy ra hạng bằng cần có từ <b>khối lượng toàn bộ theo thiết kế (GVW)</b> ghi trên giấy đăng kiểm của xe, rồi đối chiếu với bằng lái của tài xế được giao xe.</p>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Hạng</th><th>Được lái</th><th class="num">Tuổi tối thiểu</th><th class="num">Thời hạn bằng</th><th>Lái xe chở hàng?</th></tr></thead>
    <tbody>
    <?php foreach (R::CLASSES as $k => $c): ?>
      <tr><td><b><?= e($k) ?></b></td><td><?= e($c[3]) ?></td><td class="num"><?= (int)$c[1] ?></td><td class="num"><?= (int)$c[2] > 0 ? (int)$c[2] . ' năm' : 'không thời hạn' ?></td>
        <td><?= $c[4] ? '<span class="tag tag-ok">Có</span>' : '<span class="tag tag-warn">Không</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="card">
  <h2>Xe nào cần bằng gì</h2>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Khối lượng toàn bộ (GVW)</th><th>Hạng bằng cần có</th><th>Khi kéo rơ moóc trên <?= R::TRAILER_LIGHT_MAX ?> kg</th><th>Các hạng được phép lái</th></tr></thead>
    <tbody>
      <tr><td>đến <?= number_format(R::GVW_B_MAX, 0, ',', '.') ?> kg</td><td><b>B</b></td><td>BE</td><td>B, C1, C, BE, C1E, CE, B2 (cũ)</td></tr>
      <tr><td>trên <?= number_format(R::GVW_B_MAX, 0, ',', '.') ?> đến <?= number_format(R::GVW_C1_MAX, 0, ',', '.') ?> kg</td><td><b>C1</b></td><td>C1E</td><td>C1, C, C1E, CE</td></tr>
      <tr><td>trên <?= number_format(R::GVW_C1_MAX, 0, ',', '.') ?> kg</td><td><b>C</b></td><td>CE</td><td>C, CE</td></tr>
    </tbody></table></div>
  <p class="muted small">Hạng cao lái được xe của hạng thấp hơn trong cùng nhóm. Bằng hạng D (chở người) và mô tô không dùng để lái xe chở hàng của kho.</p>
</section>

<section class="card">
  <h2>Khi chọn xe, hệ thống chặn hoặc cảnh báo những gì</h2>
  <ul class="rows tight">
    <li><span><b>Chặn xuất kho</b> — hàng nặng hơn tải trọng xe · hàng chiếm nhiều hơn thể tích thùng xếp được · một món hàng không lọt lòng thùng · xe hết hạn đăng kiểm hoặc bảo hiểm TNDS · xe quá khổ giới hạn đường bộ · tài xế không có bằng đúng hạng cho xe · bằng lái hết hạn · chưa đủ tuổi tối thiểu của hạng bằng · bị trừ hết điểm · hết hạn khám sức khoẻ.</span></li>
    <li><span><b>Chỉ cảnh báo</b> — xe, hàng hoặc tài xế chưa khai thông số (tải trọng, thùng, GVW, bằng lái…) và giấy tờ sắp hết hạn trong <?= (int)$limits['canh_bao_han_ngay'] ?> ngày. Dữ liệu cũ vẫn dùng được, khai dần.</span></li>
    <li><span><b>Chọn nhiều xe</b> — mỗi xe được kiểm tra theo phần hàng nó chở, không theo cả lô.</span></li>
  </ul>
</section>

<section class="card">
  <h2>Cách tính tải</h2>
  <p class="small">Cân thật = số lượng × cân nặng của 1 đơn vị. Thể tích = số lượng × dài × rộng × cao. Trọng lượng quy đổi = thể tích × <b><?= (int)$limits['quy_doi_kg_m3'] ?> kg/m³</b>. Cước vận tải tính theo số lớn hơn giữa cân thật và quy đổi; xe phải đủ <b>cả</b> tải trọng (cân thật) <b>lẫn</b> thể tích thùng (chỉ xếp được khoảng <b><?= (int)$limits['xep_hang_pct'] ?>%</b> thể tích thùng vì có khe hở).</p>
  <p class="muted small">Giới hạn khổ xe không cần giấy phép lưu hành: cao <?= $m($limits['cao_max_mm']) ?> m · rộng <?= $m($limits['rong_max_mm']) ?> m · dài <?= $m($limits['dai_max_mm']) ?> m. Chưa kiểm tải trọng trục và tải trọng cầu đường.</p>
</section>

<?php if ($isAdmin): ?>
<section class="card">
  <h2>Chỉnh quy định (quản trị hệ thống)</h2>
  <p class="muted small">Các giá trị dưới đây là mặc định tham khảo của dự án. Hãy đối chiếu với văn bản hiện hành (Luật số 36/2024/QH15 và thông tư về tải trọng, khổ giới hạn của đường bộ) rồi chỉnh nếu khác.</p>
  <form method="post" action="/fleet/rules" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="row-3" style="grid-template-columns:repeat(3,1fr)">
      <label>Hệ số quy đổi (kg / m³)<input type="number" name="quy_doi_kg_m3" min="100" max="1000" step="1" value="<?= (int)$limits['quy_doi_kg_m3'] ?>" required></label>
      <label>Tỉ lệ xếp hàng vào thùng (%)<input type="number" name="xep_hang_pct" min="30" max="100" step="1" value="<?= (int)$limits['xep_hang_pct'] ?>" required></label>
      <label>Báo sắp hết hạn trước (ngày)<input type="number" name="canh_bao_han_ngay" min="0" max="180" step="1" value="<?= (int)$limits['canh_bao_han_ngay'] ?>" required></label>
      <label>Chiều cao xe tối đa (mm)<input type="number" name="cao_max_mm" min="2000" max="6000" step="10" value="<?= (int)$limits['cao_max_mm'] ?>" required></label>
      <label>Chiều rộng xe tối đa (mm)<input type="number" name="rong_max_mm" min="1500" max="4000" step="10" value="<?= (int)$limits['rong_max_mm'] ?>" required></label>
      <label>Chiều dài xe tối đa (mm)<input type="number" name="dai_max_mm" min="4000" max="25000" step="10" value="<?= (int)$limits['dai_max_mm'] ?>" required></label>
    </div>
    <div class="actions"><button type="submit">Lưu quy định</button></div>
  </form>
</section>
<?php endif; ?>
