<link rel="preload" href="/assets/img/login-hero.jpg" as="image">
<div class="login">
  <aside class="login-aside" aria-hidden="true">
    <img class="login-hero-img" src="/assets/img/login-hero.jpg" alt="" width="1382" height="1138" decoding="async" fetchpriority="high">
  </aside>

  <button class="theme-fab theme-toggle" type="button" data-theme-toggle aria-label="Đổi giao diện sáng / tối" title="Đổi giao diện sáng / tối"><?= str_replace('class="icon"', 'class="icon ic-sun"', icon('sun')) ?><?= str_replace('class="icon"', 'class="icon ic-moon"', icon('moon')) ?></button>
  <main class="login-main">
    <section class="login-card" aria-labelledby="login-title">
      <header class="login-head">
        <img class="login-logo" src="/assets/img/logo.png" alt="Chương Đại Dương" width="112" height="112">
        <h1 id="login-title">Đăng nhập</h1>
        <p>Dùng tài khoản được cấp quyền theo kho.</p>
      </header>
      <?php $lock = (int)($lock ?? 0); ?>
      <?php if ($error): ?><div class="flash flash-err" role="alert" id="login-error"><?= icon('alert') ?><span><?php if ($lock > 0): ?>Tạm khoá <b data-countdown="<?= $lock ?>"><?= $lock >= 60 ? intdiv($lock, 60) . ' phút ' . sprintf('%02d', $lock % 60) . ' giây' : $lock . ' giây' ?></b> do nhập sai nhiều lần.<?php else: ?><?= e($error) ?><?php endif; ?></span></div><?php endif; ?>
      <form method="post" action="/login" class="form login-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Tên đăng nhập<input name="username" value="<?= e($username) ?>" required maxlength="40" autofocus autocomplete="username" spellcheck="false" autocapitalize="none"<?= $error ? ' aria-invalid="true" aria-describedby="login-error"' : '' ?>></label>
        <label>Mật khẩu<span class="pw-field"><input name="password" type="password" required autocomplete="current-password"<?= $error ? ' aria-invalid="true" aria-describedby="login-error"' : '' ?>><button type="button" class="pw-toggle" data-pw-toggle aria-label="Hiện mật khẩu" aria-pressed="false" title="Hiện mật khẩu"><?= str_replace('class="icon"', 'class="icon ic-eye"', icon('eye')) ?><?= str_replace('class="icon"', 'class="icon ic-eye-off"', icon('eye-off')) ?></button></span></label>
        <button type="submit" class="btn-block"<?= $lock > 0 ? ' disabled' : '' ?>>Đăng nhập</button>
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
  </main>
</div>
<script src="/assets/js/password-toggle.js" defer></script>
<script src="/assets/js/login-countdown.js" defer></script>
