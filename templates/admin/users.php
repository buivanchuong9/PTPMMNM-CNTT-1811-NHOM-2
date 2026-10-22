<?php /** @var array $users @var array $warehouses @var list<\StockFlow\Domain\AccountRole> $accountRoles */
$whOptions = fn(?int $sel = null) => '<option value="">Chọn kho…</option>' . wh_options($warehouses, $sel);
?>
<header class="page-head"><div><h1>Tài khoản</h1><p class="muted">Tài khoản cấp cao quản lý các tài khoản con: <b>quản lý kho</b> (mỗi kho đúng một) và <b>nhân viên kho</b> (nhiều). Mỗi tài khoản con gắn với một kho; chức năng được dùng quyết định ở <a href="/roles">Phân quyền chức năng</a>.</p></div>
  <div class="actions"><button type="button" data-dialog-open="user-dlg"><?= icon('plus') ?>Tạo tài khoản</button></div></header>
<section class="card flush"><div class="table-wrap"><table class="dense">
    <thead><tr><th>Tài khoản</th><th>Vai trò</th><th>Kho được gắn</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $role = \StockFlow\Domain\AccountRole::from($u['vai_tro']); $self = (int)$u['id'] === $user->id; ?>
      <tr class="<?= $u['hoat_dong'] ? '' : 'row-off' ?>">
        <td><b><?= e($u['ho_ten']) ?></b><br><small class="muted">@<?= e($u['ten_dang_nhap']) ?><?= $self ? ' · bạn' : '' ?></small></td>
        <td><span class="tag tag-<?= e($role->tone()) ?>"><?= e($role->label()) ?></span></td>
        <td class="wrap"><?= $u['kho_id'] ? '<span class="wh-code">' . e($u['kho_ma']) . '</span> ' . e($u['kho_ten']) . '<br><small class="muted">' . e($u['kho_dia_chi'] ?: '—') . '</small>' : '<span class="muted">Tất cả các kho</span>' ?></td>
        <td><?= $u['hoat_dong'] ? '<span class="tag tag-ok">Hoạt động</span>' : '<span class="tag tag-danger">Đã khoá</span>' ?></td>
        <td>
          <?php if ($role === \StockFlow\Domain\AccountRole::Admin): ?><span class="muted">—</span>
          <?php else: ?><button type="button" class="btn-ghost btn-sm" data-dialog-open="user-edit-dlg"
            data-fill-label="<?= e($u['ho_ten']) ?>"
            data-fill="<?= e(json_encode(['user_id' => (int)$u['id'], 'username' => $u['ten_dang_nhap'], 'role' => $u['vai_tro'], 'warehouse_id' => (string)($u['kho_id'] ?? ''), 'active' => $u['hoat_dong'] ? '0' : '1', 'lock_label' => $u['hoat_dong'] ? 'Khoá tài khoản' : 'Mở khoá'], JSON_UNESCAPED_UNICODE)) ?>"><?= icon('settings') ?>Sửa</button><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div><?= pager($pg) ?></section>

<dialog id="user-edit-dlg" class="dlg" aria-labelledby="user-edit-t">
  <form method="post" action="/users/update" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="user_id" value=""><input type="hidden" name="active" value="">
    <div class="dlg-head"><h2 id="user-edit-t">Sửa tài khoản</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Tài khoản: <b data-dlg-label></b></p>
    <label>Tên đăng nhập<input name="username" required maxlength="40" autocomplete="off" pattern="[A-Za-z0-9._\-]{3,40}" placeholder="chữ thường, số, dấu . _ -"></label>
    <label>Vai trò<select name="role" required data-role-select><?php foreach (array_reverse($accountRoles) as $ar): if ($ar === \StockFlow\Domain\AccountRole::Admin) { continue; } ?><option value="<?= e($ar->value) ?>"><?= e($ar->label()) ?></option><?php endforeach; ?></select></label>
    <label>Kho được gắn<select name="warehouse_id" data-kho-select required><?= $whOptions() ?></select></label>
    <label>Mật khẩu mới<input type="password" name="password" minlength="8" autocomplete="new-password" placeholder="Để trống nếu không đổi"></label>
    <div class="actions">
      <button type="submit">Lưu thay đổi</button>
      <button type="submit" class="btn-danger" formaction="/users/active" formnovalidate data-confirm="Đổi trạng thái khoá của tài khoản này? Lịch sử thao tác vẫn được giữ."><span data-fill-text="lock_label">Khoá tài khoản</span></button>
      <button type="button" class="btn-ghost" data-dialog-close>Huỷ</button>
    </div>
  </form>
</dialog>

<dialog id="user-dlg" class="dlg" aria-labelledby="user-dlg-t">
  <form method="post" action="/users" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="user-dlg-t">Tạo tài khoản</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
      <label>Vai trò<select name="role" required data-role-select><?php foreach (array_reverse($accountRoles) as $ar): ?><option value="<?= e($ar->value) ?>"<?= $ar === \StockFlow\Domain\AccountRole::Staff ? ' selected' : '' ?>><?= e($ar->label()) ?></option><?php endforeach; ?></select></label>
      <label>Kho được gắn<select name="warehouse_id" data-kho-select data-addr-for="addr-new"><?= $whOptions() ?></select><small class="muted wh-addr" id="addr-new"></small></label>
      <label>Tên đăng nhập<input name="username" required maxlength="40" autocomplete="off" placeholder="vd: hn.nv3"></label>
      <label>Họ tên<input name="full_name" required maxlength="120"></label>
      <label>Mật khẩu (≥ 8 ký tự)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <div class="actions"><button type="submit"><?= icon('plus') ?>Tạo tài khoản</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
      <p class="muted small">Mỗi kho chỉ có một quản lý. Tài khoản quản trị hệ thống không gắn kho và có toàn quyền.</p></form>
</dialog>

