<?php
/** @var \StockFlow\Domain\User $user @var list<string> $tabs @var string $tab @var array $prefs @var array $system @var list<int> $rowOptions @var list<int> $idleOptions */
$tabLabels = ['ca-nhan' => 'Cá nhân', 'he-thong' => 'Hệ thống', 'nhat-ky' => 'Nhật ký thao tác'];
$scopeNote = match ($user->role) {
    \StockFlow\Domain\AccountRole::Admin => 'Bạn là quản trị hệ thống: ngoài cài đặt cá nhân, bạn chỉnh được tham số chung của cả hệ thống và xem nhật ký thao tác.',
    \StockFlow\Domain\AccountRole::Manager => 'Bạn là quản lý kho: cài đặt ở đây chỉ gồm hồ sơ, mật khẩu và cách hiển thị của riêng bạn; thông tin kho và nhân viên quản lý ở trang Kho & nhân sự.',
    default => 'Cài đặt dành cho tài khoản nhân viên: hồ sơ, mật khẩu và cách hiển thị của riêng bạn.',
};
$rowsLabel = fn(int $n) => $n . ' dòng / trang';
?>
<header class="page-head"><div><h1>Cài đặt</h1></div></header>

<?php if (count($tabs) > 1): ?>
<nav class="tabs" aria-label="Nhóm cài đặt">
  <?php foreach ($tabs as $t): ?><a href="/settings?tab=<?= e($t) ?>"<?= $t === $tab ? ' class="active" aria-current="page"' : '' ?>><?= e($tabLabels[$t]) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($tab === 'ca-nhan'): ?>
<section class="card">
  <div class="card-head"><h2>Ảnh đại diện</h2><span class="muted small">JPG, PNG hoặc WebP · tối đa 2 MB · tối thiểu 64×64 điểm ảnh</span></div>
  <form class="form avatar-form" method="post" action="/settings/avatar" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="avatar-edit">
      <span data-avatar-preview><?= avatar_html($user->id, $user->avatar, $user->fullName, 'xl') ?></span>
      <div class="avatar-ctrl">
        <label class="btn btn-ghost file-pick"><?= icon('plus') ?>Chọn ảnh…<input class="sr-only" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-avatar-input></label>
        <small class="muted" data-avatar-name>Chưa chọn ảnh. Ảnh được cắt vuông và hiển thị hình tròn.</small>
        <small class="count-warn err" data-avatar-error role="alert" hidden></small>
        <div class="actions">
          <button type="submit" disabled data-avatar-submit>Tải lên</button>
          <?php if ($user->avatar): ?><button type="submit" class="btn-danger" formaction="/settings/avatar/remove" formnovalidate data-confirm="Xoá ảnh đại diện? Hệ thống sẽ hiển thị chữ cái đầu của tên bạn." data-confirm-ok="Xoá ảnh">Xoá ảnh</button><?php endif; ?>
        </div>
      </div>
    </div>
  </form>
</section>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Hồ sơ</h2></div>
    <dl class="kv">
      <div><dt>Tên đăng nhập</dt><dd><code><?= e($user->username) ?></code></dd></div>
      <div><dt>Vai trò</dt><dd><span class="tag tag-<?= e($user->role->tone()) ?>"><?= e($user->role->label()) ?></span></dd></div>
      <div><dt>Kho được gắn</dt><dd><?= $myWarehouse ? e($myWarehouse['ma'] . ' — ' . $myWarehouse['ten']) : 'Tất cả các kho' ?></dd></div>
    </dl>
    <form class="form" method="post" action="/settings/profile">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label>Họ và tên<input name="full_name" value="<?= e($user->fullName) ?>" required maxlength="120" autocomplete="name"></label>
      <div class="actions"><button type="submit">Lưu hồ sơ</button></div>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><h2>Đổi mật khẩu</h2><span class="muted small">tối thiểu <?= (int)$system['min_password'] ?> ký tự</span></div>
    <form class="form" method="post" action="/settings/password">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label>Mật khẩu hiện tại<input type="password" name="current" required autocomplete="current-password"></label>
      <label>Mật khẩu mới<input type="password" name="password" required minlength="<?= (int)$system['min_password'] ?>" maxlength="72" autocomplete="new-password"></label>
      <label>Nhập lại mật khẩu mới<input type="password" name="confirm" required minlength="<?= (int)$system['min_password'] ?>" maxlength="72" autocomplete="new-password"></label>
      <div class="actions"><button type="submit">Đổi mật khẩu</button></div>
    </form>
  </section>
</div>

<section class="card">
  <div class="card-head"><h2>Hiển thị</h2><span class="muted small">chỉ áp dụng cho tài khoản của bạn</span></div>
  <form class="form form-row" method="post" action="/settings/preferences">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <label>Số dòng mỗi trang trong các bảng
      <select name="rows">
        <option value="0"<?= $prefs['rows_choice'] === 0 ? ' selected' : '' ?>>Theo mặc định hệ thống (<?= (int)$system['rows_default'] ?>)</option>
        <?php foreach ($rowOptions as $n): ?><option value="<?= $n ?>"<?= $prefs['rows_choice'] === $n ? ' selected' : '' ?>><?= e($rowsLabel($n)) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="check"><input type="checkbox" name="compact" value="1"<?= $prefs['compact'] ? ' checked' : '' ?>> Bảng gọn hơn (giảm khoảng cách giữa các dòng)</label>
    <div class="actions"><button type="submit">Lưu tuỳ chọn</button></div>
  </form>
</section>

<?php elseif ($tab === 'he-thong'): ?>
<div class="kpis">
  <div class="kpi"><span class="kpi-ic"><?= icon('warehouse') ?></span><b><?= n($overview['kho']) ?></b><span>Kho đang hoạt động</span></div>
  <div class="kpi kpi-info"><span class="kpi-ic"><?= icon('users') ?></span><b><?= n($overview['tai_khoan']) ?></b><span>Tài khoản hoạt động<?= $overview['tai_khoan_khoa'] ? ' · ' . n($overview['tai_khoan_khoa']) . ' bị khoá' : '' ?></span></div>
  <div class="kpi kpi-ok"><span class="kpi-ic"><?= icon('box') ?></span><b><?= n($overview['mat_hang']) ?></b><span>Mặt hàng</span></div>
  <div class="kpi kpi-warn"><span class="kpi-ic"><?= icon('file') ?></span><b><?= n($overview['phieu']) ?></b><span>Phiếu</span></div>
</div>
<section class="card">
  <div class="card-head"><h2>Tham số hệ thống</h2><span class="muted small">áp dụng cho mọi tài khoản</span></div>
  <form method="post" action="/settings/system">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="set-list">
      <label class="set-row"><span class="set-text"><b>Số dòng mỗi trang mặc định</b><small class="muted">Mỗi tài khoản vẫn tự chọn lại được ở Cài đặt → Cá nhân.</small></span>
        <select name="rows"><?php foreach ($rowOptions as $n): ?><option value="<?= $n ?>"<?= $system['rows_default'] === $n ? ' selected' : '' ?>><?= e($rowsLabel($n)) ?></option><?php endforeach; ?></select></label>
      <label class="set-row"><span class="set-text"><b>Độ dài mật khẩu tối thiểu</b><small class="muted">Áp dụng cho tài khoản tạo mới, đổi mật khẩu và đặt lại mật khẩu (6–32 ký tự).</small></span>
        <input type="number" name="min_password" min="6" max="32" step="1" value="<?= (int)$system['min_password'] ?>" required></label>
      <label class="set-row"><span class="set-text"><b>Tự đăng xuất khi không thao tác</b><small class="muted">Bảo vệ tài khoản trên máy dùng chung. Đặt «Không tự đăng xuất» để tắt.</small></span>
        <select name="idle"><?php foreach ($idleOptions as $m): ?><option value="<?= $m ?>"<?= $system['idle_minutes'] === $m ? ' selected' : '' ?>><?= $m === 0 ? 'Không tự đăng xuất' : ($m >= 60 ? ($m / 60) . ' giờ' : $m . ' phút') ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="actions pad"><button type="submit">Lưu cài đặt hệ thống</button></div>
  </form>
</section>

<?php elseif ($tab === 'nhat-ky'): ?>
<section class="card flush">
  <div class="card-head pad"><h2>Nhật ký thao tác</h2><span class="muted small"><?= $logPg->total ?> thao tác gần nhất, toàn hệ thống</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Thời gian</th><th>Người thực hiện</th><th>Hành động</th><th>Đối tượng</th><th>Chi tiết</th></tr></thead>
    <tbody>
    <?php foreach ($log as $l): ?>
      <tr><td class="muted"><?= e(dt($l['ngay_tao'])) ?></td>
        <td><?= $l['ho_ten'] ? '<b>' . e($l['ho_ten']) . '</b> <small class="muted">@' . e($l['ten_dang_nhap']) . '</small>' : '<span class="muted">—</span>' ?></td>
        <td><code><?= e($l['hanh_dong']) ?></code></td><td class="muted"><?= e($l['doi_tuong']) ?></td><td class="muted wrap"><?= e($l['chi_tiet']) ?></td></tr>
    <?php endforeach; if (!$log): ?><tr><td colspan="5" class="empty">Chưa có thao tác nào được ghi.</td></tr><?php endif; ?>
    </tbody>
  </table></div><?= pager($logPg) ?>
</section>
<?php endif; ?>
