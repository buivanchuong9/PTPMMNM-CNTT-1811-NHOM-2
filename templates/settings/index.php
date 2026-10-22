<?php
/** @var \StockFlow\Domain\User $user @var list<string> $tabs @var string $tab @var array $prefs @var array $system @var list<int> $rowOptions @var list<int> $idleOptions */
$tabLabels = ['ca-nhan' => 'Cá nhân', 'kho' => 'Kho của tôi', 'he-thong' => 'Hệ thống', 'nhat-ky' => 'Nhật ký thao tác'];
$scopeNote = match ($user->role) {
    \StockFlow\Domain\AccountRole::Admin => 'Bạn là quản trị hệ thống: ngoài cài đặt cá nhân, bạn chỉnh được tham số chung của cả hệ thống và xem nhật ký thao tác.',
    \StockFlow\Domain\AccountRole::Manager => 'Bạn là quản lý kho: ngoài cài đặt cá nhân, bạn chỉnh được thông tin kho và tài khoản nhân viên của kho mình.',
    default => 'Cài đặt dành cho tài khoản nhân viên: hồ sơ, mật khẩu và cách hiển thị của riêng bạn.',
};
$rowsLabel = fn(int $n) => $n . ' dòng / trang';
?>
<header class="page-head"><div><h1>Cài đặt</h1><p class="muted"><?= e($scopeNote) ?></p></div></header>

<?php if (count($tabs) > 1): ?>
<nav class="tabs" aria-label="Nhóm cài đặt">
  <?php foreach ($tabs as $t): ?><a href="/settings?tab=<?= e($t) ?>"<?= $t === $tab ? ' class="active" aria-current="page"' : '' ?>><?= e($tabLabels[$t]) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($tab === 'ca-nhan'): ?>
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
      <label>Mật khẩu mới<input type="password" name="password" required minlength="<?= (int)$system['min_password'] ?>" autocomplete="new-password"></label>
      <label>Nhập lại mật khẩu mới<input type="password" name="confirm" required minlength="<?= (int)$system['min_password'] ?>" autocomplete="new-password"></label>
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

<?php elseif ($tab === 'kho' && $myWarehouse): ?>
<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Thông tin kho <span class="wh-code"><?= e($myWarehouse['ma']) ?></span></h2><span class="muted small">mã kho do quản trị hệ thống cấp</span></div>
    <form class="form" method="post" action="/settings/warehouse">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label>Tên kho<input name="name" value="<?= e($myWarehouse['ten']) ?>" required maxlength="120"></label>
      <?php $addr = \StockFlow\Domain\VnAddress::parse($myWarehouse['dia_chi'] ?? ''); include __DIR__ . '/../partials/address_fields.php'; ?>
      <div class="actions"><button type="submit">Lưu thông tin kho</button></div>
    </form>
  </section>
  <section class="card">
    <div class="card-head"><h2>Phạm vi quản lý</h2></div>
    <p class="muted">Bạn chỉ thấy và thao tác trong kho <b><?= e($myWarehouse['ma']) ?></b>. Chức năng của quản lý và nhân viên do quản trị hệ thống phân quyền.</p>
    <div class="actions"><a class="btn btn-ghost" href="/warehouses"><?= icon('warehouse') ?>Kho &amp; nhân sự</a></div>
  </section>
</div>

<section class="card flush">
  <div class="card-head pad"><h2>Nhân viên kho <span class="count"><?= count($staff) ?></span></h2><span class="muted small">sửa tên đăng nhập, đặt lại mật khẩu hoặc khoá tài khoản</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Nhân viên</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
    <tbody>
    <?php foreach ($staff as $s): ?>
      <tr class="<?= $s['hoat_dong'] ? '' : 'row-off' ?>">
        <td><b><?= e($s['ho_ten']) ?></b><br><small class="muted">@<?= e($s['ten_dang_nhap']) ?></small></td>
        <td><?= $s['hoat_dong'] ? '<span class="tag tag-ok">Hoạt động</span>' : '<span class="tag tag-muted">Đã khoá</span>' ?></td>
        <td>
          <button type="button" class="btn-ghost btn-sm" data-dialog-open="staff-edit-dlg" data-fill-label="<?= e($s['ho_ten']) ?>"
            data-fill="<?= e(json_encode(['user_id' => (int)$s['id'], 'username' => $s['ten_dang_nhap'], 'active' => $s['hoat_dong'] ? '0' : '1', 'lock_label' => $s['hoat_dong'] ? 'Khoá tài khoản' : 'Mở khoá'], JSON_UNESCAPED_UNICODE)) ?>"><?= icon('settings') ?>Sửa</button>
        </td>
      </tr>
    <?php endforeach; if (!$staff): ?><tr><td colspan="3" class="empty">Kho chưa có nhân viên. Thêm ở trang «Kho &amp; nhân sự».</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>


<dialog id="staff-edit-dlg" class="dlg" aria-labelledby="staff-edit-t">
  <form method="post" action="/settings/staff/update" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="user_id" value=""><input type="hidden" name="active" value="">
    <div class="dlg-head"><h2 id="staff-edit-t">Sửa nhân viên</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Nhân viên: <b data-dlg-label></b></p>
    <label>Tên đăng nhập<input name="username" required maxlength="40" autocomplete="off" pattern="[A-Za-z0-9._\-]{3,40}" placeholder="chữ thường, số, dấu . _ -"></label>
    <label>Mật khẩu mới<input type="password" name="password" minlength="<?= (int)$system['min_password'] ?>" autocomplete="new-password" placeholder="Để trống nếu không đổi (≥ <?= (int)$system['min_password'] ?> ký tự)"></label>
    <div class="actions">
      <button type="submit">Lưu thay đổi</button>
      <button type="submit" class="btn-danger" formaction="/settings/staff/active" formnovalidate data-confirm="Đổi trạng thái khoá của nhân viên này? Lịch sử thao tác vẫn được giữ."><span data-fill-text="lock_label">Khoá tài khoản</span></button>
      <button type="button" class="btn-ghost" data-dialog-close>Huỷ</button>
    </div>
  </form>
</dialog>

<?php elseif ($tab === 'he-thong'): ?>
<div class="kpis">
  <div class="kpi"><span class="kpi-ic"><?= icon('warehouse') ?></span><b><?= n($overview['kho']) ?></b><span>Kho đang hoạt động</span></div>
  <div class="kpi kpi-info"><span class="kpi-ic"><?= icon('users') ?></span><b><?= n($overview['tai_khoan']) ?></b><span>Tài khoản hoạt động<?= $overview['tai_khoan_khoa'] ? ' · ' . n($overview['tai_khoan_khoa']) . ' bị khoá' : '' ?></span></div>
  <div class="kpi kpi-ok"><span class="kpi-ic"><?= icon('box') ?></span><b><?= n($overview['mat_hang']) ?></b><span>Mặt hàng</span></div>
  <div class="kpi kpi-warn"><span class="kpi-ic"><?= icon('file') ?></span><b><?= n($overview['phieu']) ?></b><span>Phiếu nhập xuất</span></div>
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
  <div class="card-head pad"><h2>Nhật ký thao tác</h2><span class="muted small"><?= count($log) ?> thao tác gần nhất, toàn hệ thống</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Thời gian</th><th>Người thực hiện</th><th>Hành động</th><th>Đối tượng</th><th>Chi tiết</th></tr></thead>
    <tbody>
    <?php foreach ($log as $l): ?>
      <tr><td class="muted"><?= e(dt($l['ngay_tao'])) ?></td>
        <td><?= $l['ho_ten'] ? '<b>' . e($l['ho_ten']) . '</b> <small class="muted">@' . e($l['ten_dang_nhap']) . '</small>' : '<span class="muted">—</span>' ?></td>
        <td><code><?= e($l['hanh_dong']) ?></code></td><td class="muted"><?= e($l['doi_tuong']) ?></td><td class="muted wrap"><?= e($l['chi_tiet']) ?></td></tr>
    <?php endforeach; if (!$log): ?><tr><td colspan="5" class="empty">Chưa có thao tác nào được ghi.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
