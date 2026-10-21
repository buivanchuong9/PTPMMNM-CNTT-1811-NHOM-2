<?php /** @var array $users @var array $warehouses @var array $roles */ ?>
<header class="page-head"><div><h1>Tài khoản & quyền theo kho</h1><p class="muted">Quyền được kiểm tra phía máy chủ ở mọi thao tác ghi — ẩn nút trên giao diện chỉ là tiện lợi.</p></div></header>
<div class="grid-main">
  <section class="card flush"><div class="table-wrap"><table class="dense">
    <thead><tr><th>Tài khoản</th><th>Họ tên</th><th>Quyền theo kho</th><th>Cấp quyền</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr><td><b><?= e($u['username']) ?></b><?= $u['is_admin'] ? ' <span class="tag tag-info">Admin</span>' : '' ?></td><td><?= e($u['full_name']) ?></td>
        <td><?php foreach ($u['roles'] as $r): $role = \StockFlow\Domain\Role::from($r['role']); ?>
          <form method="post" action="/users/revoke" class="chip-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="warehouse_id" value="<?= (int)(array_column($warehouses, 'id', 'code')[$r['wh']] ?? 0) ?>"><input type="hidden" name="role" value="<?= e($r['role']) ?>">
            <span class="perm"><span class="wh-code"><?= e($r['wh']) ?></span> <?= e($role->label()) ?> <button class="x" aria-label="Thu hồi" data-confirm="Thu hồi quyền này?">✕</button></span></form>
        <?php endforeach; if (!$u['roles']) echo $u['is_admin'] ? '<span class="muted">Mọi kho (quản trị)</span>' : '<span class="muted">Chưa có quyền</span>'; ?></td>
        <td><?php if (!$u['is_admin']): ?><form method="post" action="/users/grant" class="inline-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <select name="warehouse_id" aria-label="Kho"><?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['code']) ?></option><?php endforeach; ?></select>
          <select name="role" aria-label="Vai trò"><?php foreach ($roles as $r): ?><option value="<?= e($r->value) ?>"><?= e($r->label()) ?></option><?php endforeach; ?></select><button class="btn-ghost">Cấp</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></section>
  <aside><section class="card"><h2>Tạo tài khoản</h2>
    <form method="post" action="/users" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label>Tên đăng nhập<input name="username" required maxlength="40" autocomplete="off"></label>
      <label>Họ tên<input name="full_name" required maxlength="120"></label>
      <label>Mật khẩu (≥ 8 ký tự)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <label class="check"><input type="checkbox" name="is_admin" value="1"> Quản trị viên (toàn quyền danh mục)</label>
      <button><?= icon('plus') ?>Tạo tài khoản</button></form></section></aside>
</div>
