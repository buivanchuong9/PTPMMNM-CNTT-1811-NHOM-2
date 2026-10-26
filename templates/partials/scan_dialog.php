<?php
/** Hộp thoại quét mã vạch dùng chung (mở từ nút trên thanh trên cùng hoặc nút «Mã vạch» ở trang Hàng hoá). Xử lý ở /assets/js/scan.js. */
?>
<dialog id="scan-dlg" class="dlg dlg-wide scan" aria-labelledby="scan-dlg-t" data-csrf="<?= e($csrf) ?>">
  <div class="dlg-head"><h2 id="scan-dlg-t" data-scan-title>Quét mã vạch</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
  <p class="muted" data-scan-sub>Quét bằng camera, tải ảnh lên hoặc dán ảnh (Ctrl+V). Ảnh chỉ được đọc ngay trên máy bạn, hệ thống chỉ nhận dãy số mã vạch.</p>
  <div class="scan-src">
    <button type="button" data-scan-cam><?= icon('scan') ?>Bật camera</button>
    <label class="btn btn-ghost">Tải ảnh lên<input type="file" accept="image/*" hidden data-scan-file></label>
  </div>
  <div class="scan-view" data-scan-view hidden><video playsinline muted></video><i class="scan-line" aria-hidden="true"></i></div>
  <div class="scan-drop" data-scan-drop tabindex="0">Dán ảnh mã vạch vào đây (Ctrl+V) hoặc kéo thả ảnh</div>
  <form class="scan-manual" data-scan-manual>
    <input name="code" maxlength="64" autocomplete="off" inputmode="text" placeholder="Hoặc gõ / dùng máy quét USB rồi Enter" aria-label="Mã vạch">
    <button type="submit" class="btn-ghost">Tra mã</button>
  </form>
  <p class="scan-msg" data-scan-msg role="status" hidden></p>
  <div class="scan-result" data-scan-result></div>
</dialog>
