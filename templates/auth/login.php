<div class="login">
  <aside class="login-aside">
    <a class="login-brand" href="/login"><img src="/assets/img/logo-mark.png" alt="" width="36" height="36"><span><?= app_brand() ?></span><small><?= e(APP_TAGLINE) ?></small></a>
    <div class="login-intro">
      <h2>Quản lý kho, <span>rõ từng dòng.</span></h2>
      <ul class="login-points">
        <li><?= icon('shield') ?><span>Phân quyền theo từng kho</span></li>
        <li><?= icon('scale') ?><span>Tồn luôn khớp sổ, không bao giờ âm</span></li>
        <li><?= icon('truck') ?><span>Điều chuyển kho trong một giao dịch</span></li>
      </ul>
      <div class="login-road" aria-hidden="true">
        <div class="login-lane">
          <svg class="login-truck" viewBox="0 0 200 88" focusable="false">
            <g class="truck-speed" stroke="#c9ced8" stroke-width="1.6" stroke-linecap="round"><path d="M-12 28h10M-8 38h8M-14 48h11"/></g>
            <ellipse cx="100" cy="83.5" rx="90" ry="2.2" fill="#111318" fill-opacity=".08"/>
            <g class="truck-body" stroke-linejoin="round">
              <rect x="6" y="58" width="186" height="5" fill="#1f2430"/>
              <rect x="6" y="6" width="132" height="52" rx="3" fill="#fff" stroke="#1f2430" stroke-width="2"/>
              <rect x="7" y="20" width="130" height="24" fill="#f1f2f5"/>
              <path d="M7 20h130M7 44h130" stroke="#cfd4de" stroke-width="1.2"/>
              <rect x="15" y="26" width="12" height="12" rx="2.5" fill="#1f2430"/>
              <rect x="33" y="28" width="48" height="3" rx="1.5" fill="#9aa1ae"/>
              <rect x="33" y="34" width="30" height="3" rx="1.5" fill="#cfd4de"/>
              <path d="M14 8v48" stroke="#cfd4de" stroke-width="1.2"/>
              <rect x="2" y="59" width="9" height="5" rx="1.5" fill="#1f2430"/>
              <path d="M142 60V26a5 5 0 0 1 5-5h22a5 5 0 0 1 4 2l15 14a5 5 0 0 1 2 4V60z" fill="#fff" stroke="#1f2430" stroke-width="2"/>
              <path d="M148 27h20l15 13h-35z" fill="#1f2430"/>
              <path d="M148 42v16" stroke="#cfd4de" stroke-width="1.3"/>
              <rect x="150" y="46" width="6" height="2" rx="1" fill="#6b7280"/>
              <rect x="139.5" y="28" width="3" height="11" rx="1.2" fill="#1f2430"/>
              <rect x="188" y="43" width="4" height="6" rx="1.2" fill="#e7e9ee" stroke="#1f2430" stroke-width="1"/>
              <rect x="183" y="53" width="11" height="7" rx="1.5" fill="#1f2430"/>
              <rect x="112" y="63" width="22" height="8" rx="2" fill="#4a505c"/>
              <rect x="72" y="63" width="34" height="3" rx="1" fill="#4a505c"/>
            </g>
            <path d="M33 71a13 13 0 0 1 26 0z" fill="#2b2f3a"/>
            <path d="M153 71a13 13 0 0 1 26 0z" fill="#2b2f3a"/>
            <rect x="62" y="70" width="2.4" height="10" rx="1" fill="#2b2f3a"/>
            <g transform="translate(46 71)"><g class="truck-wheel"><circle r="11.5" fill="#111318"/><circle r="6.2" fill="#e7e9ee"/><circle r="1.7" fill="#1f2430"/><circle cy="-3.8" r="1" fill="#1f2430"/></g></g>
            <g transform="translate(166 71)"><g class="truck-wheel"><circle r="11.5" fill="#111318"/><circle r="6.2" fill="#e7e9ee"/><circle r="1.7" fill="#1f2430"/><circle cy="-3.8" r="1" fill="#1f2430"/></g></g>
          </svg>
        </div>
      </div>
    </div>
    <p class="login-foot">© <?= e(APP_NAME) ?></p>
  </aside>

  <button class="theme-fab theme-toggle" type="button" data-theme-toggle aria-label="Đổi giao diện sáng / tối" title="Đổi giao diện sáng / tối"><?= str_replace('class="icon"', 'class="icon ic-sun"', icon('sun')) ?><?= str_replace('class="icon"', 'class="icon ic-moon"', icon('moon')) ?></button>
  <main class="login-main">
    <section class="login-card" aria-labelledby="login-title">
      <header class="login-head">
        <img class="login-logo" src="/assets/img/logo.png" alt="Chương Đại Dương" width="112" height="112">
        <h1 id="login-title">Đăng nhập</h1>
        <p>Dùng tài khoản được cấp quyền theo kho.</p>
      </header>
      <?php if ($error): ?><div class="flash flash-err" role="alert" id="login-error"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
      <form method="post" action="/login" class="form login-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Tên đăng nhập<input name="username" value="<?= e($username) ?>" required autofocus autocomplete="username" spellcheck="false" autocapitalize="none"<?= $error ? ' aria-invalid="true" aria-describedby="login-error"' : '' ?>></label>
        <label>Mật khẩu<input name="password" type="password" required autocomplete="current-password"<?= $error ? ' aria-invalid="true" aria-describedby="login-error"' : '' ?>></label>
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
  </main>
</div>
