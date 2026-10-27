<link rel="preload" href="/assets/img/login-hero.jpg" as="image">
<div class="login">
  <aside class="login-aside" aria-hidden="true">
    <img class="login-hero-img" src="/assets/img/login-hero.jpg" alt="" width="1382" height="1138" decoding="async" fetchpriority="high">
  </aside>

  <button class="theme-fab theme-toggle" type="button" data-theme-toggle aria-label="Đổi giao diện sáng / tối" title="Đổi giao diện sáng / tối"><?= str_replace('class="icon"', 'class="icon ic-sun"', icon('sun')) ?><?= str_replace('class="icon"', 'class="icon ic-moon"', icon('moon')) ?></button>
  <main class="login-main">
    <section class="login-card" aria-labelledby="login-title">
      <header class="login-head">
        <div class="login-mark"><img src="/assets/img/logo.png" alt="" width="44" height="44"><span><b><?= app_brand() ?></b><small>Hệ thống quản lý kho</small></span></div>
        <h1 id="login-title">Chào mừng trở lại</h1>
        <p>Đăng nhập bằng tài khoản được cấp cho kho của bạn.</p>
      </header>
      <?php $lock = (int)($lock ?? 0); ?>
      <?php if ($error): ?><div class="flash flash-err" role="alert" id="login-error"><?= icon('alert') ?><span><?php if ($lock > 0): ?>Tạm khoá <b data-countdown="<?= $lock ?>"><?= $lock >= 60 ? intdiv($lock, 60) . ' phút ' . sprintf('%02d', $lock % 60) . ' giây' : $lock . ' giây' ?></b> do nhập sai nhiều lần.<?php else: ?><?= e($error) ?><?php endif; ?></span></div><?php endif; ?>
      <form method="post" action="/login" class="form login-form" data-login-form>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Tên đăng nhập<span class="in-ic"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/></svg><input name="username" value="<?= e($username) ?>" required maxlength="40" autofocus autocomplete="username" spellcheck="false" autocapitalize="none" placeholder="VD: hn.quanly"<?= $error ? ' aria-invalid="true" aria-describedby="login-error"' : '' ?>></span></label>
        <label>Mật khẩu<span class="pw-field in-ic"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg><input name="password" type="password" required autocomplete="current-password" placeholder="••••••••"<?= $error ? ' aria-invalid="true" aria-describedby="login-error"' : '' ?>><button type="button" class="pw-toggle" data-pw-toggle aria-label="Hiện mật khẩu" aria-pressed="false" title="Hiện mật khẩu"><?= str_replace('class="icon"', 'class="icon ic-eye"', icon('eye')) ?><?= str_replace('class="icon"', 'class="icon ic-eye-off"', icon('eye-off')) ?></button></span>
          <small class="caps-warn" data-caps-warn hidden><?= icon('alert') ?>Đang bật Caps Lock</small></label>
        <button type="submit" class="btn-block" data-login-submit<?= $lock > 0 ? ' disabled' : '' ?>><span>Đăng nhập</span><svg class="icon login-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
      </form>
      <div class="demo-accounts">
        <p class="demo-title">Dùng thử nhanh <small>mật khẩu chung <code>demo1234</code></small></p>
        <div class="demo-pick">
          <?php foreach ([['admin', 'Quản trị', 'Mọi kho'], ['hn.quanly', 'Quản lý kho', 'HN01'], ['hn.nv1', 'Nhân viên kho', 'HN01']] as [$du, $dr, $dw]): ?>
          <button type="button" class="demo-chip" data-demo="<?= e($du) ?>"><b><?= e($dr) ?></b><small><?= e($du) ?> · <?= e($dw) ?></small></button>
          <?php endforeach; ?>
        </div>
        <p class="demo-more">Kho khác: <code>dn.quanly</code> <code>hcm.quanly</code> · nhân viên <code>dn.nv1</code> <code>hcm.nv1</code>…</p>
      </div>
    </section>
  </main>
</div>
<script src="/assets/js/password-toggle.js" defer></script>
<script src="/assets/js/login-countdown.js" defer></script>
<script src="/assets/js/login.js" defer></script>
