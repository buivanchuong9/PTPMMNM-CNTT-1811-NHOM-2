<?php /** @var list<array> $rows @var array<string,int> $counts @var array<string,string> $types @var string $type @var string $q @var list<array> $units @var list<array> $categories @var bool $canEdit @var list<array> $items */
$qs = fn(array $over) => http_build_query(array_filter(array_merge(['type' => $type, 'q' => $q, 'status' => $status, 'category' => $category === -1 ? 'none' : $category, 'item' => $item], $over), fn($v) => $v !== null && $v !== ''));
$tabs = ['' => 'Tất cả'] + $types;
$tone = ['' => 'all', 'SUPPLIER' => 'info', 'CUSTOMER' => 'ok'];
$form = function (string $id, string $title, string $action, bool $edit) use ($types, $csrf, $items, $units, $categories): void { ?>
<dialog id="<?= $id ?>" class="dlg dlg-wide" aria-labelledby="<?= $id ?>-t">
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
    <fieldset class="sup-pick"><legend>Mặt hàng cung cấp (chỉ với nhà cung cấp)</legend>
      <?php foreach ($items as $it): ?><label class="check"><input type="checkbox" name="item_ids[]" value="<?= (int)$it['id'] ?>" data-item-check> <b><?= e($it['ma_hang']) ?></b> <?= e($it['ten']) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <fieldset class="newitems" data-newitems><legend>Hàng mới do nhà cung cấp này cung cấp</legend>
      <p class="muted small">Hàng chưa có trong kho: nhập SKU, tên, đơn vị tính và danh mục — hệ thống tự thêm vào Hàng hoá và gắn với nhà cung cấp này.</p>
      <div class="newitem-list"></div>
      <button type="button" class="btn-ghost btn-sm" data-add-newitem><?= icon('plus') ?>Thêm hàng mới</button>
      <template data-newitem-tpl>
        <div class="newitem-row">
          <input name="new_sku[]" placeholder="SKU" maxlength="40" pattern="[A-Za-z0-9._\-]{2,40}" title="2–40 ký tự: chữ không dấu, số, . _ -" autocomplete="off" aria-label="SKU">
          <input name="new_name[]" placeholder="Tên hàng" maxlength="160" autocomplete="off" aria-label="Tên hàng">
          <select name="new_unit[]" aria-label="Đơn vị tính"><?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['ma']) ?></option><?php endforeach; ?></select>
          <select name="new_cat[]" aria-label="Danh mục"><option value="0">Chưa có danh mục</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['ten']) ?></option><?php endforeach; ?></select>
          <select name="new_lot[]" aria-label="Quản lý theo lô"><option value="0">Không theo lô</option><option value="1">Theo lô / HSD</option></select>
          <button type="button" class="icon-btn" data-remove-newitem aria-label="Bỏ dòng">✕</button>
        </div>
      </template>
    </fieldset>
    <div class="actions"><button type="submit"><?= $edit ? 'Lưu thay đổi' : icon('plus') . 'Thêm đối tác' ?></button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php }; ?>
<header class="page-head">
  <div><h1>Đối tác</h1></div>
  <div class="actions"><?php if ($canEdit): ?><button type="button" data-dialog-open="partner-dlg"><?= icon('plus') ?>Thêm đối tác</button><?php endif; ?></div>
</header>
<nav class="tabs" aria-label="Loại đối tác" data-live="tabs">
  <?php foreach ($tabs as $k => $label): ?>
  <a href="/partners?<?= e($qs(['type' => $k, 'page' => null])) ?>" data-tone="<?= $tone[$k] ?>"<?= $type === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($counts[$k]) ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <input type="hidden" name="type" value="<?= e($type) ?>">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Tìm mã, tên, người liên hệ, SĐT, email hoặc SKU hàng cung cấp…" aria-label="Tìm kiếm đối tác">
  <select name="status"<?= $status ? ' class="on"' : '' ?> aria-label="Trạng thái hợp tác"><option value="">Mọi trạng thái</option><option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Đang hợp tác</option><option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Ngừng hợp tác</option></select>
  <select name="category"<?= $category ? ' class="on"' : '' ?> aria-label="Danh mục hàng cung cấp" data-live-reload><option value="">Mọi danh mục hàng</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $category === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['ten']) ?></option><?php endforeach; ?><option value="none"<?= $category === -1 ? ' selected' : '' ?>>Hàng chưa phân loại</option></select>
  <select name="item"<?= $item ? ' class="on"' : '' ?> aria-label="Mặt hàng cung cấp" data-combo><option value="">Mọi mặt hàng</option><?php foreach ($itemOptions as $i): ?><option value="<?= (int)$i['id'] ?>"<?= $item === (int)$i['id'] ? ' selected' : '' ?>><?= e($i['ma_hang'] . ' — ' . $i['ten']) ?></option><?php endforeach; ?></select>
  <?php if ($status || $category || $item): ?><a class="btn btn-ghost" href="/partners?<?= e($qs(['status' => null, 'category' => null, 'item' => null, 'page' => null])) ?>">Xoá lọc</a><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã</th><th>Tên đối tác</th><th>Loại</th><th>Liên hệ</th><th>Địa chỉ</th><th>Mặt hàng cung cấp</th><th>Trạng thái</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $x): $sup = $x['loai'] === 'SUPPLIER'; ?>
    <tr class="<?= $x['hoat_dong'] ? '' : 'row-off' ?><?= is_new($changes, $x['id']) ? ' is-new' : '' ?>">
      <td><b><?= hl($x['ma'], $q) ?></b><?= new_tag($changes, $x['id']) ?></td><td><?= hl($x['ten'], $q) ?><?= $x['ghi_chu'] !== '' ? '<br><small class="muted">' . e($x['ghi_chu']) . '</small>' : '' ?></td>
      <td><span class="tag <?= $sup ? 'tag-info' : 'tag-ok' ?>"><?= e($types[$x['loai']]) ?></span></td>
      <td class="wrap"><?= $x['nguoi_lien_he'] !== '' ? '<b>' . hl($x['nguoi_lien_he'], $q) . '</b><br>' : '' ?><small class="muted"><?= trim($x['so_dien_thoai'] . ' ' . $x['email']) !== '' ? hl(trim($x['so_dien_thoai'] . ' ' . $x['email']), $q) : '—' ?></small></td>
      <td class="muted wrap"><?= e($x['dia_chi'] ?: '—') ?></td>
      <td class="wrap"><?php if (!$sup): ?><span class="muted">—</span><?php elseif (!$x['items']): ?><span class="muted">Chưa gắn mặt hàng</span><?php else: ?>
        <?php // hàng khớp từ khoá / mặt hàng đang lọc / danh mục đang lọc xếp lên đầu để thấy ngay vì sao đối tác hiện ra
        $hit = fn(array $it) => ($item && (int)$it['id'] === $item) || ($q !== '' && str_contains(fold_vn($it['sku']), fold_vn($q))) || ($category && !$item && ($category === -1 ? !(int)($catOf[$it['id']] ?? 0) : (int)($catOf[$it['id']] ?? 0) === $category));
        $sk = $x['items']; usort($sk, fn($a, $b) => $hit($b) <=> $hit($a)); $shownSk = array_slice($sk, 0, 4); ?>
        <b><?= n(count($sk)) ?></b> mặt hàng: <small><?= implode(', ', array_map(fn($it) => $hit($it) ? '<mark class="hl">' . e($it['sku']) . '</mark>' : e($it['sku']), $shownSk)) ?><?= count($sk) > 4 ? ' +' . (count($sk) - 4) : '' ?></small><?php endif; ?></td>
      <td><?= $x['hoat_dong'] ? '<span class="tag tag-ok">Đang hợp tác</span>' : '<span class="tag tag-danger">Ngừng hợp tác</span>' ?></td>
      <?php if ($canEdit): ?><td><div class="inline-form">
        <button type="button" class="btn-ghost btn-sm" data-dialog-open="partner-edit-dlg" data-fill-label="<?= e($x['ma'] . ' — ' . $x['ten']) ?>"
          data-fill="<?= e(json_encode(['partner_id' => (string)$x['id'], 'code' => $x['ma'], 'name' => $x['ten'], 'type' => $x['loai'], 'contact' => $x['nguoi_lien_he'], 'phone' => $x['so_dien_thoai'], 'email' => $x['email'], 'address' => $x['dia_chi'], 'note' => $x['ghi_chu'], 'item_ids' => implode(',', array_column($x['items'], 'id'))], JSON_UNESCAPED_UNICODE)) ?>">Sửa</button>
        <form method="post" action="/partners/active" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="partner_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="active" value="<?= $x['hoat_dong'] ? '0' : '1' ?>">
          <button class="btn-ghost btn-sm"><?= $x['hoat_dong'] ? 'Ngừng' : 'Dùng lại' ?></button></form>
      </div></td><?php endif; ?></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="<?= $canEdit ? 8 : 7 ?>" class="empty">Không có đối tác phù hợp.</td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
<?php if ($canEdit) { $form('partner-dlg', 'Thêm đối tác', '/partners', false); $form('partner-edit-dlg', 'Sửa đối tác', '/partners/update', true); } ?>
