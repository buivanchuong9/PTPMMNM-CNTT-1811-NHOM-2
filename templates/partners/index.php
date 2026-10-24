<?php /** @var list<array> $rows @var array<string,int> $counts @var array<string,string> $types @var string $type @var string $q */
$qs = fn(array $over) => http_build_query(array_filter(array_merge(['type' => $type, 'q' => $q], $over), fn($v) => $v !== null && $v !== ''));
$tabs = ['' => 'Tất cả'] + $types;
$tone = ['' => 'all', 'SUPPLIER' => 'info', 'CUSTOMER' => 'ok'];
$form = function (string $id, string $title, string $action, bool $edit) use ($types, $csrf): void { ?>
<dialog id="<?= $id ?>" class="dlg" aria-labelledby="<?= $id ?>-t">
  <form method="post" action="<?= $action ?>" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><?php if ($edit): ?><input type="hidden" name="partner_id"><?php endif; ?>
    <div class="dlg-head"><h2 id="<?= $id ?>-t"><?= e($title) ?></h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>Mã đối tác<input name="code" required minlength="2" maxlength="30" pattern="[A-Za-z0-9._\-]{2,30}" title="2–30 ký tự: chữ không dấu, số, . _ -" placeholder="NCC-001" autocomplete="off"></label>
    <label>Tên đối tác<input name="name" required maxlength="160" autocomplete="off"></label>
    <label>Loại<select name="type" required><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label>Người liên hệ<input name="contact" maxlength="120" autocomplete="off"></label>
    <label>Số điện thoại<input name="phone" inputmode="tel" maxlength="15" pattern="[0-9 .+\-]{8,15}" autocomplete="off"></label>
    <label>Email<input type="email" name="email" maxlength="120" autocomplete="off"></label>
    <label>Địa chỉ<input name="address" maxlength="255" autocomplete="off"></label>
    <label>Ghi chú<input name="note" maxlength="255" autocomplete="off"></label>
    <div class="actions"><button type="submit"><?= $edit ? 'Lưu thay đổi' : icon('plus') . 'Thêm đối tác' ?></button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php }; ?>
<header class="page-head">
  <div><h1>Đối tác</h1></div>
  <div class="actions"><?php if ($user->isAdmin): ?><button type="button" data-dialog-open="partner-dlg"><?= icon('plus') ?>Thêm đối tác</button><?php endif; ?></div>
</header>
<nav class="tabs" aria-label="Loại đối tác" data-live="tabs">
  <?php foreach ($tabs as $k => $label): ?>
  <a href="/partners?<?= e($qs(['type' => $k, 'page' => null])) ?>" data-tone="<?= $tone[$k] ?>"<?= $type === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($counts[$k]) ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <input type="hidden" name="type" value="<?= e($type) ?>">
  <input type="search" name="q" maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Tìm mã, tên, người liên hệ, SĐT, email hoặc SKU hàng cung cấp…" aria-label="Tìm kiếm đối tác">
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã</th><th>Tên đối tác</th><th>Loại</th><th>Liên hệ</th><th>Địa chỉ</th><th>Mặt hàng cung cấp</th><th>Trạng thái</th><?php if ($user->isAdmin): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $x): $sup = $x['loai'] === 'SUPPLIER'; ?>
    <tr class="<?= $x['hoat_dong'] ? '' : 'row-off' ?>">
      <td><b><?= e($x['ma']) ?></b></td><td><?= e($x['ten']) ?><?= $x['ghi_chu'] !== '' ? '<br><small class="muted">' . e($x['ghi_chu']) . '</small>' : '' ?></td>
      <td><span class="tag <?= $sup ? 'tag-info' : 'tag-ok' ?>"><?= e($types[$x['loai']]) ?></span></td>
      <td class="wrap"><?= $x['nguoi_lien_he'] !== '' ? '<b>' . e($x['nguoi_lien_he']) . '</b><br>' : '' ?><small class="muted"><?= e(trim($x['so_dien_thoai'] . ' ' . $x['email']) ?: '—') ?></small></td>
      <td class="muted wrap"><?= e($x['dia_chi'] ?: '—') ?></td>
      <td class="wrap"><?php if (!$sup): ?><span class="muted">—</span><?php elseif (!$x['items']): ?><span class="muted">Chưa gắn mặt hàng</span><?php else: ?>
        <b><?= n(count($x['items'])) ?></b> mặt hàng: <small><?= e(implode(', ', array_slice(array_column($x['items'], 'sku'), 0, 4))) ?><?= count($x['items']) > 4 ? ' +' . (count($x['items']) - 4) : '' ?></small><?php endif; ?></td>
      <td><?= $x['hoat_dong'] ? '<span class="tag tag-ok">Đang hợp tác</span>' : '<span class="tag tag-danger">Ngừng hợp tác</span>' ?></td>
      <?php if ($user->isAdmin): ?><td><div class="inline-form">
        <button type="button" class="btn-ghost btn-sm" data-dialog-open="partner-edit-dlg" data-fill-label="<?= e($x['ma'] . ' — ' . $x['ten']) ?>"
          data-fill="<?= e(json_encode(['partner_id' => (string)$x['id'], 'code' => $x['ma'], 'name' => $x['ten'], 'type' => $x['loai'], 'contact' => $x['nguoi_lien_he'], 'phone' => $x['so_dien_thoai'], 'email' => $x['email'], 'address' => $x['dia_chi'], 'note' => $x['ghi_chu']], JSON_UNESCAPED_UNICODE)) ?>">Sửa</button>
        <form method="post" action="/partners/active" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="partner_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="active" value="<?= $x['hoat_dong'] ? '0' : '1' ?>">
          <button class="btn-ghost btn-sm"><?= $x['hoat_dong'] ? 'Ngừng' : 'Dùng lại' ?></button></form>
      </div></td><?php endif; ?></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="<?= $user->isAdmin ? 8 : 7 ?>" class="empty">Không có đối tác phù hợp.</td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
<?php if ($user->isAdmin) { $form('partner-dlg', 'Thêm đối tác', '/partners', false); $form('partner-edit-dlg', 'Sửa đối tác', '/partners/update', true); } ?>
