// Trang đăng nhập: cảnh báo Caps Lock, chọn nhanh tài khoản dùng thử, nút «Đang đăng nhập…» khi gửi form.
(function () {
  var form = document.querySelector('[data-login-form]');
  if (!form) return;
  var user = form.querySelector('[name=username]'), pass = form.querySelector('[name=password]');
  var caps = form.querySelector('[data-caps-warn]'), submit = form.querySelector('[data-login-submit]');

  function capsCheck(e) {
    if (caps && e.getModifierState) caps.hidden = !e.getModifierState('CapsLock');
  }
  pass.addEventListener('keydown', capsCheck);
  pass.addEventListener('keyup', capsCheck);
  pass.addEventListener('blur', function () { if (caps) caps.hidden = true; });

  // bấm một ô «Dùng thử nhanh» ⇒ điền sẵn tên + mật khẩu mẫu, đưa con trỏ tới nút Đăng nhập
  document.querySelectorAll('[data-demo]').forEach(function (b) {
    b.addEventListener('click', function () {
      user.value = b.getAttribute('data-demo');
      pass.value = 'demo1234';
      document.querySelectorAll('[data-demo]').forEach(function (x) { x.classList.toggle('on', x === b); });
      if (submit && !submit.disabled) submit.focus();
    });
  });

  // chọn kho ⇒ đổi tiền tố tên đăng nhập của hai ô Quản lý / Nhân viên (hn. / dn. / hcm.)
  var kho = document.querySelector('[data-kho]');
  if (kho) kho.addEventListener('change', function () {
    document.querySelectorAll('[data-role]').forEach(function (c) {
      var u = kho.value + '.' + c.getAttribute('data-role');
      c.setAttribute('data-demo', u);
      c.title = u;
      c.classList.remove('on');
    });
  });

  form.addEventListener('submit', function () {
    if (!submit || submit.disabled) return;
    submit.classList.add('busy');
    submit.querySelector('span').textContent = 'Đang đăng nhập…';
    setTimeout(function () { submit.disabled = true; }, 0);   // khoá sau khi trình duyệt đã nhận lệnh gửi, tránh bấm hai lần
  });
})();
