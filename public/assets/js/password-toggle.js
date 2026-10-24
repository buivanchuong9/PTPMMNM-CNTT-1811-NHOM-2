// Nút con mắt ẩn / hiện mật khẩu ở trang đăng nhập. CSP chỉ cho script từ 'self' nên không dùng inline.
document.addEventListener('click', function (e) {
  var btn = e.target.closest ? e.target.closest('[data-pw-toggle]') : null;
  if (!btn) { return; }
  var input = btn.parentNode.querySelector('input');
  if (!input) { return; }
  var show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  btn.setAttribute('aria-pressed', String(show));
  btn.setAttribute('aria-label', show ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
  btn.setAttribute('title', show ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
  input.focus();
});
