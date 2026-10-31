<div class="login">
  <button class="theme-fab theme-toggle" type="button" data-theme-toggle aria-label="Đổi giao diện sáng / tối" title="Đổi giao diện sáng / tối"><?= str_replace('class="icon"', 'class="icon ic-sun"', icon('sun')) ?><?= str_replace('class="icon"', 'class="icon ic-moon"', icon('moon')) ?></button>
  <main class="login-main">
    <section class="login-card" aria-label="Đăng nhập">
      <header class="login-head">
        <div class="login-mark"><img class="login-full" src="/assets/img/stockflow-logo.png" alt="<?= e(APP_NAME) ?>" width="260" height="142"></div>
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
        <div class="demo-bar">
          <span>Dùng thử</span>
          <button type="button" class="demo-chip demo-admin" data-demo="admin" title="admin · mọi kho">Quản trị</button>
        </div>
        <div class="demo-pick">
          <select data-kho aria-label="Chọn kho">
            <?php foreach (['hn' => 'Hà Nội', 'dn' => 'Đà Nẵng', 'hcm' => 'TP.HCM'] as $k => $kn): ?>
            <option value="<?= $k ?>"><?= e($kn) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="demo-chip" data-demo="hn.quanly" data-role="quanly" title="hn.quanly">Quản lý</button>
          <button type="button" class="demo-chip" data-demo="hn.nv1" data-role="nv1" title="hn.nv1">Nhân viên</button>
        </div>
      </div>
    </section>
  </main>
</div>
<script src="/assets/js/password-toggle.js" defer></script>
<script src="/assets/js/login-countdown.js" defer></script>
<script src="/assets/js/login.js" defer></script>
