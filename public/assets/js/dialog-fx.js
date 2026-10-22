// Hiệu ứng mở / đóng cho mọi hộp thoại (<dialog>) trong hệ thống: StockFlow (.dlg) và Finova (.fv-dlg).
// Mở: CSS tự chạy khi dialog[open]. Đóng: close() được bọc để chạy hiệu ứng thoát trước khi đóng thật,
// nên mã sẵn có (nút Huỷ / ✕, bấm ra ngoài, nút Lưu của Finova…) không cần sửa gì. Phím Esc cũng đi qua đường này.
(function () {
  if (typeof HTMLDialogElement === 'undefined') { return; }
  var proto = HTMLDialogElement.prototype;
  var nativeClose = proto.close, nativeShow = proto.showModal;
  var reduced = function () { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; };

  proto.close = function (returnValue) {
    var dlg = this;
    if (!dlg.open || dlg.classList.contains('is-closing')) { return; }
    if (reduced()) { nativeClose.call(dlg, returnValue); return; }
    dlg.classList.add('is-closing');
    var finished = false;
    var finish = function () {
      if (finished) { return; }
      finished = true;
      clearTimeout(dlg.__fxTimer);
      dlg.removeEventListener('animationend', onEnd);
      dlg.__fxCancel = null;
      if (dlg.classList.contains('is-closing')) {            // chưa bị mở lại trong lúc chờ
        dlg.classList.remove('is-closing');
        nativeClose.call(dlg, returnValue);
      }
    };
    var onEnd = function (e) { if (e.animationName === 'dlg-out') { finish(); } };
    dlg.addEventListener('animationend', onEnd);
    dlg.__fxTimer = setTimeout(finish, 450);                  // dự phòng nếu animationend không bắn
    dlg.__fxCancel = function () { finished = true; clearTimeout(dlg.__fxTimer); dlg.removeEventListener('animationend', onEnd); dlg.classList.remove('is-closing'); };
  };

  proto.showModal = function () {
    if (this.classList.contains('is-closing') && this.__fxCancel) { this.__fxCancel(); return; }   // bấm mở lại khi đang đóng: giữ nguyên hộp thoại
    nativeShow.call(this);
  };

  // Esc: không để trình duyệt đóng ngay, mà đi qua close() có hiệu ứng
  document.addEventListener('cancel', function (e) {
    if (e.target instanceof HTMLDialogElement && e.cancelable) { e.preventDefault(); e.target.close(); }   // không huỷ được (chưa có thao tác người dùng): để trình duyệt tự đóng
  }, true);
})();
