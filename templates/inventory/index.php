<?php /** @var array $warehouses @var array $shown @var array $rows @var array $items @var array $units @var array $categories @var bool $canAddCat @var int $supF @var list<array> $suppliers @var int $cat @var int $uncategorized @var int $totalItems */ ?>
<header class="page-head">
  <div><h1>Hàng hoá</h1></div>
  <div class="actions">
    <?php if ($auth->allows($user, \StockFlow\Domain\Permission::XemBaoCao)): ?><a class="btn btn-ghost" href="/reports/stock.csv"><?= icon('download') ?>Xuất CSV</a><?php endif; ?>
    <?php if ($user->isAdmin): ?><button type="button" data-dialog-open="item-dlg"><?= icon('plus') ?>Thêm mặt hàng</button>
      <button type="button" class="btn-ghost" data-dialog-open="unit-dlg"><?= icon('plus') ?>Thêm đơn vị tính</button>
<?php endif; ?>
    <?php if ($canAddCat): ?><button type="button" class="btn-ghost" data-dialog-open="cat-dlg"><?= icon('plus') ?>Thêm danh mục</button><?php endif; ?>
    <?php if ($canAddBatch): ?><button type="button" class="btn-ghost" data-dialog-open="batch-dlg"><?= icon('plus') ?>Thêm lô</button><?php endif; ?>
  </div>
</header>
<?php $catQs = fn($v) => http_build_query(array_filter(['category' => $v, 'q' => $q, 'warehouse' => $wh, 'view' => $byBatch ? 'batch' : '', 'stock' => $stock, 'supplier' => $supF], fn($x) => $x !== null && $x !== '' && $x !== 0)); ?>
<div class="cat-bar">
  <input type="search" id="cat-search" class="cat-search" placeholder="Tìm danh mục…" aria-label="Tìm danh mục" autocomplete="off">
  <nav class="tabs cat-tabs" id="cat-tabs" aria-label="Danh mục hàng hoá" data-live="tabs">
  <a href="/inventory?<?= e($catQs('')) ?>"<?= $cat === 0 ? ' class="active" aria-current="page"' : '' ?>>Tất cả <span class="count"><?= n($totalItems) ?></span></a>
  <?php foreach ($categories as $c): ?><a href="/inventory?<?= e($catQs((string)$c['id'])) ?>"<?= $cat === (int)$c['id'] ? ' class="active" aria-current="page"' : '' ?>><?= e($c['ten']) ?> <span class="count"><?= n($c['items']) ?></span></a>
  <?php endforeach; ?>
  <?php if ($uncategorized > 0): ?><a href="/inventory?<?= e($catQs('none')) ?>"<?= $cat === -1 ? ' class="active" aria-current="page"' : '' ?>>Chưa phân loại <span class="count"><?= n($uncategorized) ?></span></a><?php endif; ?>
  </nav>
</div>
<?php if (!$categories): ?><p class="muted">Chưa có danh mục nào<?= $user->isAdmin ? ' — bấm «Thêm danh mục» để tạo.' : '.' ?></p><?php endif; ?>
<form class="filters" method="get">
  <input type="search" name="q" maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Tìm SKU hoặc tên hàng…" aria-label="Tìm kiếm">
  <?php if ($user->isAdmin): ?><select name="warehouse" aria-label="Kho"><?= wh_options($warehouses, $wh, 'Tất cả kho') ?></select>
  <?php elseif ($mine): ?><span class="tag tag-info"><?= e($mine['ma'] . ' — ' . $mine['ten']) ?></span><?php endif; ?>
  <input type="hidden" name="category" value="<?= $cat === -1 ? 'none' : ($cat ?: '') ?>">
  <select name="supplier" aria-label="Nhà cung cấp"><option value="">Mọi nhà cung cấp</option><?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $supF === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['ten']) ?></option><?php endforeach; ?></select>
  <select name="view" aria-label="Kiểu xem" data-live-reload><option value="">Theo mặt hàng</option><option value="batch"<?= $byBatch ? ' selected' : '' ?>>Theo lô (hạn dùng)</option></select>
  <?php if (!$byBatch): ?><select name="stock" aria-label="Tình trạng tồn">
    <?php foreach (['' => 'Mọi tình trạng', 'in' => 'Còn hàng', 'low' => 'Sắp hết', 'out' => 'Hết hàng'] as $k => $label): ?><option value="<?= e($k) ?>"<?= $stock === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select><?php endif; ?>
</form>
<?php if (!$byBatch && !$user->isAdmin && count($shown) > 1): ?><p class="muted small">Bảng hiển thị tồn của <b>tất cả các kho</b> (chỉ xem). Di chuột lên mã kho để biết quản lý kho cần liên hệ khi muốn nhờ chuyển hàng.</p><?php endif; ?>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
<?php if ($byBatch): ?>
  <thead><tr><th>Kho</th><th>SKU</th><th>Tên hàng</th><th>Loại hàng</th><th>Lô</th><th>Hạn dùng</th><th class="num">Tồn</th><th>Quy đổi</th><th>Cập nhật cuối</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><span class="wh-code"><?= e($r['wh_code']) ?></span></td><td><b><?= e($r['ma_hang']) ?></b></td><td><?= e($r['ten']) ?></td><td><?= $r['category'] ? '<span class="tag tag-info">' . e($r['category']) . '</span>' : '<span class="muted">—</span>' ?></td><td><?= $r['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($r['so_lo']) ?></td>
      <td><?= e(dt($r['han_dung'], false)) ?></td><td class="num"><b><?= n($r['so_luong']) ?></b> <small class="muted"><?= e($r['unit']) ?></small></td>
      <td class="muted"><?= e(pack_label((int)$r['so_luong'], (int)$r['quy_cach_lo'])) ?: '—' ?></td><td class="muted"><?= e(dt($r['last_move'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty">Không có tồn phù hợp.</td></tr><?php endif; ?>
  </tbody>
<?php else: $anyPack = (bool)array_filter($rows, fn($x) => (int)$x['quy_cach_lo'] > 1); ?>
  <thead><tr><th>SKU</th><th>Tên hàng</th><th>Loại hàng</th><th>Nhà cung cấp</th><th>ĐVT</th><?php if ($anyPack): ?><th>Đóng gói</th><?php endif; ?><?php foreach ($shown as $w): $own = $mine && (int)$mine['id'] === (int)$w['id']; ?><th class="num" title="<?= e($w['ten'] . ($own ? ' (kho của bạn)' : ($w['quan_ly'] ? ' · Quản lý: ' . $w['quan_ly'] : ''))) ?>"><?= e($w['ma']) ?><?= $own ? ' <small>(bạn)</small>' : '' ?></th><?php endforeach; ?><th class="num">Tổng tồn</th><th>Tình trạng</th><?php if ($user->isAdmin): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $pack = (int)$r['quy_cach_lo']; ?>
    <tr<?= is_new($changes, $r['id']) ? ' class="is-new"' : '' ?>><td><b><?= e($r['ma_hang']) ?></b><?= new_tag($changes, $r['id']) ?></td><td><?= e($r['ten']) ?></td><td><?= $r['category'] ? '<span class="tag tag-info">' . e($r['category']) . '</span>' : '<span class="tag tag-warn">Chưa phân loại</span>' ?></td>
      <td class="wrap"><?php if ($r['suppliers']): foreach ($r['suppliers'] as $s): ?><a class="tag tag-muted" href="/partners?q=<?= e(urlencode($s['ma'])) ?>" title="<?= e($s['ten']) ?>"><?= e($s['ten']) ?></a> <?php endforeach; else: ?><span class="muted">—</span><?php endif; ?></td><td><?= e($r['unit']) ?></td>
      <?php if ($anyPack): ?><td class="muted nw"><?= $pack > 1 ? '<b>' . n($pack) . '</b> ' . e($r['unit']) . '/kiện' : '' ?></td><?php endif; ?>
      <?php foreach ($shown as $w): $q1 = $r['by_wh'][(int)$w['id']] ?? 0; ?><td class="num <?= $q1 === 0 ? 'muted' : '' ?>"<?= $mine && (int)$mine['id'] === (int)$w['id'] ? ' style="background:var(--accent-soft,rgba(238,77,45,.08));font-weight:600"' : '' ?>><?= n($q1) ?><?php if ($q1 > 0 && $canRequest && !($mine && (int)$mine['id'] === (int)$w['id'])): ?> <a class="btn btn-ghost btn-sm" href="/requests/new?from=<?= (int)$w['id'] ?>&amp;item=<?= (int)$r['id'] ?>&amp;qty=1" title="Xin kho <?= e($w['ma']) ?> chuyển hàng sang kho của bạn"><?= icon('truck') ?>Yêu cầu chuyển hàng</a><?php endif; ?></td><?php endforeach; ?>
      <td class="num"><b><?= n($r['total']) ?></b> <small class="muted"><?= e($r['unit']) ?></small><?php if ($pack > 1 && ($pl = pack_label($r['total'], $pack)) !== ''): ?><br><small class="muted">≈ <?= e($pl) ?></small><?php endif; ?></td>
      <td><?= $r['total'] <= 0 ? '<span class="tag tag-warn">Hết hàng</span>' : ($r['low'] ? '<span class="tag tag-warn">Sắp hết</span>' : '<span class="tag tag-ok">Còn hàng</span>') ?></td>
      <?php if ($user->isAdmin): ?><td><button type="button" class="btn-ghost" data-dialog-open="edit-dlg" data-fill-label="<?= e($r['ma_hang'] . ' — ' . $r['ten'] . ' (ĐVT: ' . $r['unit'] . ')') ?>" data-fill="<?= e(json_encode(['item_id' => (string)$r['id'], 'category_id' => (string)(int)$r['danh_muc_id'], 'supplier_ids' => implode(',', array_column($r['suppliers'], 'id')), 'pack_size' => (string)$pack, 'reorder_level' => (string)(int)$r['dinh_muc_toi_thieu']])) ?>">Sửa</button></td><?php endif; ?></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="<?= count($shown) + 7 + ($user->isAdmin ? 1 : 0) ?>" class="empty">Không có mặt hàng phù hợp.</td></tr><?php endif; ?>
  </tbody>
<?php endif; ?>
</table></div><?= pager($pg) ?></section>

<?php if ($canAddBatch): ?>
<dialog id="batch-dlg" class="dlg dlg-wide" aria-labelledby="batch-dlg-t">
  <form method="post" action="/batches" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="batch-dlg-t">Thêm lô</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>Loại hàng hoá<select id="batch-cat" aria-label="Loại hàng hoá"><option value="">Tất cả loại hàng</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['ten']) ?></option><?php endforeach; ?></select></label>
    <label>Mặt hàng<select name="item_id" id="batch-item" required aria-label="Mặt hàng theo lô"><option value="">Chọn mặt hàng theo lô…</option><?php foreach ($items as $i): if ($i['quan_ly_lo']): ?><option value="<?= (int)$i['id'] ?>" data-cat="<?= (int)$i['danh_muc_id'] ?>"><?= e($i['ma_hang'] . ' — ' . $i['ten']) ?></option><?php endif; endforeach; ?></select></label>
    <label>Số lô<input name="batch_no" required maxlength="40" pattern="[A-Za-z0-9._\-]{1,40}" title="1–40 ký tự: chữ không dấu, số, . _ -" placeholder="L2607C" autocomplete="off"></label>
    <label>Hạn dùng<input type="date" name="expiry"></label>
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm lô</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($user->isAdmin): ?>
<dialog id="item-dlg" class="dlg" aria-labelledby="item-dlg-t">
  <form method="post" action="/items" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="item-dlg-t">Thêm mặt hàng</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>SKU<input name="sku" required minlength="2" maxlength="40" pattern="[A-Za-z0-9._\-]{2,40}" title="2–40 ký tự: chữ không dấu, số, . _ -" autocomplete="off"></label>
    <label>Tên hàng<input name="name" required maxlength="160"></label>
    <label>Loại hàng hoá (danh mục)<select name="category_id" required><option value="">Chọn danh mục…</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['ten']) ?></option><?php endforeach; ?></select></label>
    <label>Đơn vị tính<select name="unit_id" required><?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['ma']) ?></option><?php endforeach; ?></select></label>
    <fieldset class="sup-pick"><legend>Nhà cung cấp của mặt hàng</legend>
      <?php foreach ($suppliers as $s): ?><label class="check"><input type="checkbox" name="supplier_ids[]" value="<?= (int)$s['id'] ?>" data-sup-check> <?= e($s['ten']) ?> <small class="muted"><?= e($s['ma']) ?></small></label>
      <?php endforeach; if (!$suppliers): ?><p class="muted small">Chưa có nhà cung cấp — thêm ở trang <a href="/partners">Đối tác</a>.</p><?php endif; ?>
    </fieldset>
    <label>Đóng gói (1 kiện = ? đơn vị tính)<input type="number" name="pack_size" min="1" max="1000000" step="1" value="1" required></label>
    <label class="check"><input type="checkbox" name="track_batch" value="1"> Quản lý theo lô / hạn dùng</label>
    <label>Định mức tồn tối thiểu<input type="number" name="reorder_level" min="0" max="1000000" step="1" value="0"></label>
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm mặt hàng</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<dialog id="edit-dlg" class="dlg" aria-labelledby="edit-dlg-t">
  <form method="post" action="/items/update" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="item_id">
    <div class="dlg-head"><h2 id="edit-dlg-t">Sửa mặt hàng</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted" data-dlg-label></p>
    <label>Loại hàng hoá (danh mục)<select name="category_id" required><option value="">Chọn danh mục…</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['ten']) ?></option><?php endforeach; ?></select></label>
    <fieldset class="sup-pick"><legend>Nhà cung cấp của mặt hàng</legend>
      <?php foreach ($suppliers as $s): ?><label class="check"><input type="checkbox" name="supplier_ids[]" value="<?= (int)$s['id'] ?>" data-sup-check> <?= e($s['ten']) ?> <small class="muted"><?= e($s['ma']) ?></small></label>
      <?php endforeach; if (!$suppliers): ?><p class="muted small">Chưa có nhà cung cấp — thêm ở trang <a href="/partners">Đối tác</a>.</p><?php endif; ?>
    </fieldset>
    <label>Đóng gói (1 kiện = ? đơn vị tính)<input type="number" name="pack_size" min="1" max="1000000" step="1" required></label>
    <label>Định mức tồn tối thiểu<input type="number" name="reorder_level" min="0" max="1000000" step="1" required></label>
    <p class="muted small">Số lượng tồn không sửa tay được — tồn chỉ thay đổi qua phiếu nhập/xuất, điều chuyển hoặc kiểm kê.</p>
    <div class="actions"><button type="submit">Lưu</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<dialog id="unit-dlg" class="dlg" aria-labelledby="unit-dlg-t">
  <form method="post" action="/units" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="unit-dlg-t">Thêm đơn vị tính</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>Ký hiệu<input name="code" required maxlength="20" placeholder="pallet" autocomplete="off"></label>
    <label>Tên đầy đủ<input name="name" required maxlength="60"></label>
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($canAddCat): ?>
<dialog id="cat-dlg" class="dlg" aria-labelledby="cat-dlg-t">
  <form method="post" action="/categories" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="cat-dlg-t">Thêm danh mục hàng hoá</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>Mã danh mục<input name="code" required minlength="2" maxlength="30" pattern="[A-Za-z0-9._\-]{2,30}" title="2–30 ký tự: chữ không dấu, số, . _ -" placeholder="FOOD" autocomplete="off"></label>
    <label>Tên danh mục<input name="name" required maxlength="100" placeholder="Thực phẩm & đồ uống"></label>
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
