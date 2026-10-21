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
        <li><code>hn.keeper</code> — lập phiếu & kiểm kê kho HN01</li>
        <li><code>hn.approver</code> — duyệt chứng từ kho HN01</li>
        <li><code>dn.keeper</code> / <code>dn.approver</code> — kho DN01</li>
        <li><code>hcm.keeper</code> / <code>hcm.approver</code> — kho HCM01</li>
        <li><code>giamsat</code> — mọi quyền trên cả 3 kho</li>
        <li><code>admin</code> — quản trị danh mục & tài khoản</li>
      </ul>
    </details>
  </section>
</div>
