<div class="login">
  <section class="login-hero" aria-hidden="true">
    <img class="login-logo" src="/assets/img/logo-mark.svg" alt="" width="64" height="64">
    <h2>Stock<b>Flow</b> WMS</h2>
    <p>Quản lý kho · Điều chuyển · Kiểm kê<br>tại nhiều điểm, đối chiếu đến từng dòng phát sinh.</p>
    <div class="belt"><span></span><span></span><span></span><span></span></div>
    <ul class="hero-points">
      <li><?= icon('shield') ?>Phân quyền theo từng kho, kiểm tra phía máy chủ</li>
      <li><?= icon('scale') ?>Tồn luôn khớp sổ phát sinh — không bao giờ âm</li>
      <li><?= icon('truck') ?>Điều chuyển trừ kho đi & cộng kho đến cùng một giao dịch</li>
    </ul>
  </section>
  <section class="login-card">
    <h1>Đăng nhập</h1>
    <p class="muted">Dùng tài khoản được cấp quyền theo kho.</p>
    <?php if ($error): ?><div class="flash flash-err" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
    <form method="post" action="/login" class="form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label>Tên đăng nhập<input name="username" value="<?= e($username) ?>" required autofocus autocomplete="username"></label>
      <label>Mật khẩu<input name="password" type="password" required autocomplete="current-password"></label>
      <button type="submit" class="btn-block">Đăng nhập</button>
    </form>
    <details class="demo-accounts">
      <summary>Tài khoản demo</summary>
      <p>Mật khẩu chung <code>demo1234</code></p>
      <ul>
        <li><code>admin</code> — <b>chủ hệ thống</b>: xem thống kê cả 3 kho, quản lý kho / tài khoản / phân quyền (không nhập xuất)</li>
        <li><code>hn.quanly</code> · <code>dn.quanly</code> · <code>hcm.quanly</code> — <b>quản lý</b> kho HN01 / DN01 / HCM01</li>
        <li><code>hn.nv1</code>, <code>hn.nv2</code> — <b>nhân viên</b> kho HN01 (tương tự <code>dn.nv1</code>, <code>dn.nv2</code>, <code>hcm.nv1</code>, <code>hcm.nv2</code>)</li>
      </ul>
    </details>
  </section>
</div>
