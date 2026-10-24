// Đếm ngược thời gian tạm khoá đăng nhập theo thời gian thực. CSP chỉ cho script từ 'self' nên không dùng inline.
(function () {
  var el = document.querySelector('[data-countdown]');
  if (!el) { return; }
  var end = Date.now() + parseInt(el.getAttribute('data-countdown'), 10) * 1000;   // dùng đồng hồ máy để không lệch khi tab bị trì hoãn
  var box = document.getElementById('login-error');
  var submit = document.querySelector('.login-form [type=submit]');

  function fmt(s) {
    return s >= 60 ? Math.floor(s / 60) + ' phút ' + ('0' + (s % 60)).slice(-2) + ' giây' : s + ' giây';
  }
  function tick() {
    var left = Math.max(0, Math.ceil((end - Date.now()) / 1000));
    if (left > 0) { el.textContent = fmt(left); return; }
    clearInterval(timer);
    if (box) { box.parentNode.removeChild(box); }
    if (submit) { submit.disabled = false; }
  }
  var timer = setInterval(tick, 250);
  tick();
})();
