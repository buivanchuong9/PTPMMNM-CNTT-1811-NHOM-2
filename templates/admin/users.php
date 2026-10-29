<?php /** @var array $users @var array $warehouses @var list<\StockFlow\Domain\AccountRole> $accountRoles */
$whOptions = fn(?int $sel = null) => '<option value="">Chọn kho…</option>' . wh_options($warehouses, $sel);
?>
<header class="page-head"><div><h1>Tài khoản</h1></div>
  <div class="actions"><button type="button" data-dialog-open="user-dlg"><?= icon('plus') ?>Tạo tài khoản</button></div></header>
<?php $qs = fn(array $over) => http_build_query(array_filter(array_merge(['q' => $q, 'role' => $role, 'warehouse' => $wh ?: null, 'status' => $status], $over), fn($v) => $v !== null && $v !== '')); ?>
<form class="filters" method="get">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Tìm họ tên, tên đăng nhập hoặc kho…" aria-label="Tìm tài khoản">
  <select name="role"<?= $role ? ' class="on"' : '' ?> aria-label="Vai trò"><option value="">Mọi vai trò</option><?php foreach ($accountRoles as $ar): if ($ar === \StockFlow\Domain\AccountRole::Admin) { continue; } ?><option value="<?= e($ar->value) ?>"<?= $role === $ar->value ? ' selected' : '' ?>><?= e($ar->label()) ?></option><?php endforeach; ?></select>
  <select name="warehouse"<?= $wh ? ' class="on"' : '' ?> aria-label="Kho"><?= wh_options($warehouses, $wh ?: null, 'Mọi kho') ?></select>
  <select name="status"<?= $status ? ' class="on"' : '' ?> aria-label="Trạng thái"><option value="">Mọi trạng thái</option><option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Hoạt động</option><option value="locked"<?= $status === 'locked' ? ' selected' : '' ?>>Đã khoá</option></select>
  <?php if ($q !== '' || $role || $wh || $status): ?><a class="btn btn-ghost" href="/users">Xoá lọc</a><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
    <thead><tr><th>Tài khoản</th><th>Vai trò</th><th>Kho được gắn</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $role = \StockFlow\Domain\AccountRole::from($u['vai_tro']); $self = (int)$u['id'] === $user->id; ?>
      <tr class="<?= $u['hoat_dong'] ? '' : 'row-off' ?><?= is_new($changes, $u['id']) ? ' is-new' : '' ?>">
        <td><span class="person"><?= avatar_html((int)$u['id'], $u['anh_dai_dien'] ?? null, $u['ho_ten'], 'sm') ?><span><b><?= hl($u['ho_ten'], $q) ?></b><br><small class="muted">@<?= hl($u['ten_dang_nhap'], $q) ?><?= $self ? ' · bạn' : '' ?></small></span></span></td>
        <td><span class="tag tag-<?= e($role->tone()) ?>"><?= e($role->label()) ?></span></td>
        <td class="wrap"><?= $u['kho_id'] ? '<span class="wh-code">' . hl($u['kho_ma'], $q) . '</span> ' . hl($u['kho_ten'], $q) . '<br><small class="muted">' . e($u['kho_dia_chi'] ?: '—') . '</small>' : '<span class="muted">Tất cả các kho</span>' ?></td>
        <td><?= $u['hoat_dong'] ? '<span class="tag tag-ok">Hoạt động</span>' : '<span class="tag tag-danger">Đã khoá</span>' ?></td>
        <td>
          <?php if ($role === \StockFlow\Domain\AccountRole::Admin): ?><span class="muted">—</span>
          <?php else: ?><button type="button" class="btn-ghost btn-sm" data-dialog-open="user-edit-dlg"
            data-fill-label="<?= e($u['ho_ten']) ?>"
            data-fill="<?= e(json_encode(['user_id' => (int)$u['id'], 'username' => $u['ten_dang_nhap'], 'role' => $u['vai_tro'], 'warehouse_id' => (string)($u['kho_id'] ?? ''), 'active' => $u['hoat_dong'] ? '0' : '1', 'lock_label' => $u['hoat_dong'] ? 'Khoá tài khoản' : 'Mở khoá'], JSON_UNESCAPED_UNICODE)) ?>"><?= icon('settings') ?>Sửa</button><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; if (!$users): ?><tr><td colspan="5" class="empty">Không có tài khoản phù hợp<?= $total ? ' (' . n($total) . ' tài khoản trong hệ thống).' : '.' ?></td></tr><?php endif; ?>
    </tbody></table></div><?= pager($pg) ?></section>

<dialog id="user-edit-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="user-edit-t">
  <form method="post" action="/users/update" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="user_id" value=""><input type="hidden" name="active" value="">
    <div class="dlg-head"><h2 id="user-edit-t">Sửa tài khoản</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Tài khoản: <b data-dlg-label></b></p>
    <label>Tên đăng nhập<input name="username" required maxlength="40" autocomplete="off" pattern="[A-Za-z0-9._\-]{3,40}" placeholder="chữ thường, số, dấu . _ -"></label>
    <label>Vai trò<select name="role" required data-role-select><?php foreach (array_reverse($accountRoles) as $ar): if ($ar === \StockFlow\Domain\AccountRole::Admin) { continue; } ?><option value="<?= e($ar->value) ?>"><?= e($ar->label()) ?></option><?php endforeach; ?></select></label>
    <label>Kho được gắn<select name="warehouse_id" data-kho-select required><?= $whOptions() ?></select></label>
    <label>Mật khẩu mới<input type="password" name="password" minlength="<?= (int)$minPassword ?>" maxlength="72" autocomplete="new-password" placeholder="Để trống nếu không đổi"></label>
    <div class="actions">
      <button type="submit">Lưu thay đổi</button>
      <button type="submit" class="btn-danger" formaction="/users/active" formnovalidate data-confirm="Đổi trạng thái khoá của tài khoản này? Lịch sử thao tác vẫn được giữ."><span data-fill-text="lock_label">Khoá tài khoản</span></button>
      <button type="button" class="btn-ghost" data-dialog-close>Huỷ</button>
    </div>
  </form>
</dialog>

<dialog id="user-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="user-dlg-t">
  <form method="post" action="/users" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="user-dlg-t">Tạo tài khoản</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
      <label>Vai trò<select name="role" required data-role-select><?php foreach (array_reverse($accountRoles) as $ar): ?><option value="<?= e($ar->value) ?>"<?= $ar === \StockFlow\Domain\AccountRole::Staff ? ' selected' : '' ?>><?= e($ar->label()) ?></option><?php endforeach; ?></select></label>
      <label>Kho được gắn<select name="warehouse_id" data-kho-select data-addr-for="addr-new"><?= $whOptions() ?></select><small class="muted wh-addr" id="addr-new"></small></label>
      <label>Tên đăng nhập<input name="username" required maxlength="40" autocomplete="off" pattern="[A-Za-z0-9._\-]{3,40}" title="3–40 ký tự: chữ không dấu, số, dấu . _ -" placeholder="vd: hn.nv3"></label>
      <label>Họ tên<input name="full_name" required maxlength="120"></label>
      <label>Mật khẩu (≥ <?= (int)$minPassword ?> ký tự)<input type="password" name="password" required minlength="<?= (int)$minPassword ?>" maxlength="72" autocomplete="new-password"></label>
      <div class="actions"><button type="submit"><?= icon('plus') ?>Tạo tài khoản</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
      <p class="muted small">Mỗi kho chỉ có một quản lý. Tài khoản quản trị hệ thống không gắn kho và có toàn quyền.</p></form>
</dialog>

