<?php /** @var array $rows @var array $stats */
use StockFlow\Domain\Permission as P;
// Thêm nhân viên: tài khoản cấp cao thêm cho kho bất kỳ; quản lý kho chỉ cho kho của mình (nếu được giao chức năng này).
$canAddStaff = fn(array $w) => $w['hoat_dong'] && $auth->allows($user, P::QuanLyNhanVien) && ($user->isAdmin || (int)$user->warehouseId === (int)$w['id']);
$anyAdd = (bool)array_filter($rows, $canAddStaff);
// Sửa địa chỉ: tài khoản cấp cao mọi kho; quản lý kho của mình
$canEditAddr = fn(array $w) => $user->isAdmin || ($user->role === \StockFlow\Domain\AccountRole::Manager && (int)$user->warehouseId === (int)$w['id']);
$anyAddr = (bool)array_filter($rows, $canEditAddr);
// Sửa / khoá nhân viên: chỉ quản lý của chính kho đó (SettingsService::ownStaff kiểm lại ở máy chủ)
$canEditStaff = fn(array $w) => $user->role === \StockFlow\Domain\AccountRole::Manager && (int)$user->warehouseId === (int)$w['id'] && $auth->allows($user, P::QuanLyNhanVien);
$anyEdit = (bool)array_filter($rows, $canEditStaff);
?>
<header class="page-head">
  <div><h1>Kho &amp; nhân sự</h1></div>
  <?php if ($user->isAdmin): ?><div class="actions"><button type="button" data-dialog-open="wh-dlg"><?= icon('plus') ?>Thêm kho</button><a class="btn btn-ghost" href="/users"><?= icon('users') ?>Quản lý tài khoản</a></div><?php endif; ?>
</header>
<div class="filters" data-filter-box=".wh-card"><input type="search" maxlength="50" placeholder="Tìm kho, địa chỉ, quản lý, nhân viên…" aria-label="Tìm kho hoặc nhân viên" data-filter-q></div>
  <?php foreach ($rows as $w): $s = $stats[$w['id']] ?? null; $m = $w['quan_ly']; ?>
    <section class="card wh-card<?= is_new($changes, $w['id']) ? ' is-new' : '' ?>">
      <div class="wh-card-head">
        <div>
          <h2><span class="wh-code"><?= e($w['ma']) ?></span> <?= e($w['ten']) ?><?= new_tag($changes, $w['id']) ?> <?= $w['hoat_dong'] ? '' : '<span class="tag tag-muted">Ngừng</span>' ?></h2>
          <p class="muted"><?= icon('warehouse') ?> <?= e($w['dia_chi'] ?: 'Chưa có địa chỉ') ?><?= $s ? ' · <b>' . n($s['units']) . '</b> đơn vị tồn' : '' ?></p>
        </div>
        <div class="actions">
        <?php if ($canEditAddr($w)): ?>
          <button type="button" class="btn-ghost" data-dialog-open="addr-dlg" data-wh="<?= (int)$w['id'] ?>" data-wh-label="<?= e($w['ma'] . ' — ' . $w['ten']) ?>" data-fill="<?= e(json_encode(\StockFlow\Domain\VnAddress::parse($w['dia_chi']), JSON_UNESCAPED_UNICODE)) ?>"><?= icon('warehouse') ?>Sửa địa chỉ</button>
        <?php endif; ?>
        <?php if ($canAddStaff($w)): ?>
          <button type="button" data-dialog-open="staff-dlg" data-wh="<?= (int)$w['id'] ?>" data-wh-label="<?= e($w['ma'] . ' — ' . $w['ten']) ?>"><?= icon('plus') ?>Thêm nhân viên</button>
        <?php endif; ?>
        </div>
      </div>

      <div class="wh-manager">
        <span class="wh-role">Quản lý kho</span>
        <?php if ($m): ?>
          <?= avatar_html((int)$m['id'], $m['anh_dai_dien'] ?? null, $m['ho_ten'], 'sm') ?><b><?= e($m['ho_ten']) ?></b> <code><?= e($m['ten_dang_nhap']) ?></code>
          <?= $m['hoat_dong'] ? '' : '<span class="tag tag-danger">Đã khoá</span>' ?>
        <?php else: ?><span class="tag tag-warn">Chưa có quản lý</span><?php endif; ?>
      </div>

      <div class="table-wrap"><table class="dense staff-table">
        <caption class="staff-cap">Nhân viên kho <span class="count"><?= count($w['nhan_vien']) ?></span></caption>
        <thead><tr><th>Họ và tên</th><th>Tên đăng nhập</th><th>Ngày tạo</th><?php if ($canEditStaff($w)): ?><th>Thao tác</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($w['nhan_vien'] as $nv): ?>
          <tr class="<?= $nv['hoat_dong'] ? '' : 'row-off' ?>">
            <td class="wrap"><?= avatar_html((int)$nv['id'], $nv['anh_dai_dien'] ?? null, $nv['ho_ten'], 'sm') ?><b><?= e($nv['ho_ten']) ?></b><?= $nv['hoat_dong'] ? '' : ' <span class="tag tag-danger">Đã khoá</span>' ?></td>
            <td class="wrap"><code><?= e($nv['ten_dang_nhap']) ?></code></td>
            <td class="muted"><?= e(dt_short($nv['ngay_tao'])) ?></td>
            <?php if ($canEditStaff($w)): ?><td>
              <button type="button" class="btn-ghost btn-sm" data-dialog-open="staff-edit-dlg" data-fill-label="<?= e($nv['ho_ten']) ?>"
                data-fill="<?= e(json_encode(['user_id' => (int)$nv['id'], 'username' => $nv['ten_dang_nhap'], 'active' => $nv['hoat_dong'] ? '0' : '1', 'lock_label' => $nv['hoat_dong'] ? 'Khoá tài khoản' : 'Mở khoá'], JSON_UNESCAPED_UNICODE)) ?>"><?= icon('settings') ?>Sửa</button>
            </td><?php endif; ?>
          </tr>
        <?php endforeach; if (!$w['nhan_vien']): ?><tr><td colspan="<?= $canEditStaff($w) ? 4 : 3 ?>" class="empty">Kho chưa có nhân viên<?= $canAddStaff($w) ? ' — bấm «Thêm nhân viên» để tạo tài khoản đầu tiên.' : '.' ?></td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </section>
  <?php endforeach; if (!$rows): ?><section class="card"><p class="empty">Chưa có kho nào.</p></section><?php endif; ?>
  <section class="card flush"><?= pager($pg) ?></section>

<?php if ($user->isAdmin): ?>
<dialog id="wh-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="wh-dlg-t">
  <form method="post" action="/warehouses" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="wh-dlg-t">Thêm kho</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>Mã kho<input name="code" required minlength="2" maxlength="20" pattern="[A-Za-z0-9_\-]{2,20}" title="2–20 ký tự: chữ không dấu, số, _ hoặc -" placeholder="VD: HP01" autocomplete="off"></label>
    <label>Tên kho<input name="name" required maxlength="120"></label>
    <?php $addr = []; $addrRequired = false; include __DIR__ . '/../partials/address_fields.php'; ?>
    <p class="dlg-sub"><b>Tài khoản quản lý kho</b></p>
    <label>Họ và tên<input name="manager_name" required maxlength="120" autocomplete="off" placeholder="VD: Nguyễn Văn A"></label>
    <label>Tên đăng nhập<input name="manager_username" required maxlength="40" autocomplete="off" placeholder="chữ thường, số, dấu . _ -" pattern="[A-Za-z0-9._\-]{3,40}"></label>
    <label>Mật khẩu (≥ <?= (int)$minPassword ?> ký tự)<input type="password" name="manager_password" required minlength="<?= (int)$minPassword ?>" maxlength="72" autocomplete="new-password"></label>
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm kho</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($anyAddr): ?>
<dialog id="addr-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="addr-dlg-t">
  <form method="post" action="/warehouses/address" class="form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="warehouse_id" value="">
    <div class="dlg-head"><h2 id="addr-dlg-t">Sửa địa chỉ kho</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Kho: <b data-dlg-wh></b></p>
    <?php $addr = []; $addrRequired = true; include __DIR__ . '/../partials/address_fields.php'; ?>
    <div class="actions"><button type="submit">Lưu địa chỉ</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($anyAdd): ?>
<dialog id="staff-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="staff-dlg-title">
  <form method="post" action="/warehouses/staff" class="form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="warehouse_id" value="">
    <div class="dlg-head"><h2 id="staff-dlg-title">Thêm nhân viên</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Kho: <b data-dlg-wh></b></p>
    <label>Họ và tên<input name="full_name" required maxlength="120" autocomplete="off" placeholder="VD: Nguyễn Văn A"></label>
    <label>Tên đăng nhập<input name="username" required maxlength="40" autocomplete="off" placeholder="chữ thường, số, dấu . _ -" pattern="[A-Za-z0-9._\-]{3,40}"></label>
    <label>Mật khẩu (≥ <?= (int)$minPassword ?> ký tự)<input type="password" name="password" required minlength="<?= (int)$minPassword ?>" maxlength="72" autocomplete="new-password"></label>
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm nhân viên</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($anyEdit): ?>
<dialog id="staff-edit-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="staff-edit-t">
  <form method="post" action="/warehouses/staff/update" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="user_id" value=""><input type="hidden" name="active" value="">
    <div class="dlg-head"><h2 id="staff-edit-t">Sửa nhân viên</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted dlg-sub">Nhân viên: <b data-dlg-label></b></p>
    <label>Tên đăng nhập<input name="username" required maxlength="40" autocomplete="off" pattern="[A-Za-z0-9._\-]{3,40}" title="3–40 ký tự: chữ không dấu, số, dấu . _ -" placeholder="chữ thường, số, dấu . _ -"></label>
    <label>Mật khẩu mới<input type="password" name="password" minlength="<?= (int)$minPassword ?>" maxlength="72" autocomplete="new-password" placeholder="Để trống nếu không đổi (≥ <?= (int)$minPassword ?> ký tự)"></label>
    <div class="actions">
      <button type="submit">Lưu thay đổi</button>
      <button type="submit" class="btn-danger" formaction="/warehouses/staff/active" formnovalidate data-confirm="Đổi trạng thái khoá của nhân viên này? Lịch sử thao tác vẫn được giữ."><span data-fill-text="lock_label">Khoá tài khoản</span></button>
      <button type="button" class="btn-ghost" data-dialog-close>Huỷ</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
