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
<?php $catQs = fn($v) => http_build_query(array_filter(['category' => $v, 'q' => $q, 'warehouse' => $wh, 'view' => $byBatch ? 'batch' : '', 'price' => $priceSel, 'supplier' => $supF, 'expiry' => $expiry], fn($x) => $x !== null && $x !== '' && $x !== 0)); ?>
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
  <?php if ($byBatch): ?><select name="expiry" aria-label="Hạn dùng" data-live-reload>
    <?php foreach (['' => 'Mọi hạn dùng', 'soon' => 'Sắp hết hạn (≤ 30 ngày)', 'expired' => 'Đã hết hạn'] as $k => $label): ?><option value="<?= e($k) ?>"<?= $expiry === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select><?php endif; ?>
  <?php if (!$byBatch): ?><select name="price" aria-label="Lọc và sắp xếp theo giá" data-live-reload>
    <?php foreach (['' => 'Sắp theo SKU', 'sell_desc' => 'Giá bán: cao → thấp', 'sell_asc' => 'Giá bán: thấp → cao', 'cost_desc' => 'Giá gốc: cao → thấp', 'cost_asc' => 'Giá gốc: thấp → cao',
                   'margin_desc' => 'Lãi: cao → thấp', 'margin_asc' => 'Lãi: thấp → cao', 'none' => 'Chưa có giá bán'] as $k => $label): ?><option value="<?= e($k) ?>"<?= $priceSel === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense<?= $byBatch ? '' : ' sticky-2' ?>">
<?php if ($byBatch): ?>
  <thead><tr><th>Kho</th><th>SKU</th><th>Tên hàng</th><th>Loại hàng</th><th>Lô</th><th>Hạn dùng</th><th class="num">Tồn</th><th>Quy đổi</th><th>Cập nhật cuối</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><span class="wh-code"><?= e($r['wh_code']) ?></span></td><td><b><?= e($r['ma_hang']) ?></b></td><td><?= e($r['ten']) ?></td><td><?= $r['category'] ? '<span class="tag tag-info">' . e($r['category']) . '</span>' : '<span class="muted">—</span>' ?></td><td><?= $r['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($r['so_lo']) ?></td>
      <td><?= e(dt($r['han_dung'], false)) ?> <?= expiry_tag($r['expiry']) ?></td><td class="num"><b><?= n($r['so_luong']) ?></b> <small class="muted"><?= e($r['unit']) ?></small></td>
      <td class="muted"><?= e(pack_label((int)$r['so_luong'], (int)$r['quy_cach_lo'])) ?: '—' ?></td><td class="muted"><?= e(dt($r['last_move'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty">Không có tồn phù hợp.</td></tr><?php endif; ?>
  </tbody>
<?php else: $anyPack = (bool)array_filter($rows, fn($x) => (int)$x['quy_cach_lo'] > 1); ?>
  <thead><tr><th rowspan="2">SKU</th><th rowspan="2">Tên hàng</th><th rowspan="2">Mã vạch</th><th rowspan="2">Loại hàng</th><th rowspan="2">Nhà cung cấp</th><th rowspan="2">ĐVT</th><?php if ($anyPack): ?><th rowspan="2">Đóng gói</th><?php endif; ?><?php foreach ($shown as $w): $own = $mine && (int)$mine['id'] === (int)$w['id']; ?><th rowspan="2" class="num" title="<?= e($w['ten'] . ($own ? ' (kho của bạn)' : ($w['quan_ly'] ? ' · Quản lý: ' . $w['quan_ly'] : ''))) ?>"><?= e($w['ma']) ?><?= $own ? ' <small>(bạn)</small>' : '' ?></th><?php endforeach; ?><th rowspan="2" class="num">Tổng tồn</th><th rowspan="2" class="num">Giá gốc</th><th class="group" colspan="<?= max(1, count($shown)) ?>">Giá bán từng kho</th><?php if (!$user->isAdmin): ?><th rowspan="2" title="Theo tồn của kho bạn so với định mức tồn tối thiểu">Tình trạng kho bạn</th><?php endif; ?><?php if ($user->isAdmin || $canPrice): ?><th rowspan="2"></th><?php endif; ?></tr>
    <tr class="sub-head"><?php foreach ($shown as $w): $own = $mine && (int)$mine['id'] === (int)$w['id']; ?><th class="num" title="<?= e($w['ten']) ?>"><?= e($w['ma']) ?><?= $own ? ' <small>(bạn)</small>' : '' ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $pack = (int)$r['quy_cach_lo']; ?>
    <tr<?= is_new($changes, $r['id']) ? ' class="is-new"' : '' ?>><td><b><?= e($r['ma_hang']) ?></b><?= new_tag($changes, $r['id']) ?></td><td class="item-name"><button type="button" class="link-btn" data-dialog-open="lots-dlg" title="Xem các lô và hạn dùng" data-fill-label="<?= e($r['ma_hang'] . ' — ' . $r['ten'] . ' (ĐVT: ' . $r['unit'] . ')') ?>" data-lots="<?= e(json_encode(['tracked' => (bool)$r['quan_ly_lo'], 'unit' => $r['unit'], 'lots' => $lotsOf[(int)$r['id']] ?? []], JSON_UNESCAPED_UNICODE)) ?>"><?= e($r['ten']) ?></button><?php if ((int)$r['bao_hanh_thang'] > 0): ?> <span class="tag tag-muted" title="Thời hạn bảo hành">BH <?= (int)$r['bao_hanh_thang'] ?> th</span><?php endif; ?></td><td class="nw"><?php if ($r['ma_vach']): ?><span class="barcode-tag" title="Mã vạch"><?= e($r['ma_vach']) ?></span><?php elseif (!$canBarcode): ?><span class="muted">—</span><?php endif; ?><?php if ($canBarcode): ?><button type="button" class="link-btn barcode-btn" data-scan-assign="<?= (int)$r['id'] ?>" data-scan-code="<?= e((string)$r['ma_vach']) ?>" data-scan-label="<?= e($r['ma_hang'] . ' — ' . $r['ten']) ?>"><?= $r['ma_vach'] ? 'Đổi mã vạch' : '+ Gán mã vạch' ?></button><?php endif; ?></td><td><?= $r['category'] ? '<span class="tag tag-info">' . e($r['category']) . '</span>' : '<span class="tag tag-warn">Chưa phân loại</span>' ?></td>
      <td class="wrap"><?php if ($r['suppliers']): foreach ($r['suppliers'] as $s): ?><a class="tag tag-muted" href="/partners?q=<?= e(urlencode($s['ma'])) ?>" title="<?= e($s['ten']) ?>"><?= e($s['ten']) ?></a> <?php endforeach; else: ?><span class="muted">—</span><?php endif; ?></td><td><?= e($r['unit']) ?></td>
      <?php if ($anyPack): ?><td class="muted nw"><?= $pack > 1 ? '<b>' . n($pack) . '</b> ' . e($r['unit']) . '/kiện' : '' ?></td><?php endif; ?>
      <?php foreach ($shown as $w): $q1 = $r['by_wh'][(int)$w['id']] ?? 0; $lv = $q1 >= 100 ? 'ok' : ($q1 >= 50 ? 'warn' : 'bad'); ?><td class="num <?= $q1 === 0 && !$user->isAdmin ? 'muted' : '' ?>"<?= $mine && (int)$mine['id'] === (int)$w['id'] ? ' style="background:var(--accent-soft,rgba(238,77,45,.08));font-weight:600"' : '' ?>><?php if ($user->isAdmin): ?><span class="stk stk-<?= $lv ?>" title="<?= $lv === 'ok' ? 'Còn nhiều (từ 100)' : ($lv === 'warn' ? 'Dưới 100' : ($q1 === 0 ? 'Hết hàng' : 'Dưới 50')) ?>"><?= n($q1) ?></span><?php else: ?><?= n($q1) ?><?php endif; ?><?php if ($q1 > 0 && $canRequest && !($mine && (int)$mine['id'] === (int)$w['id'])): ?> <a class="req-btn" href="/requests/new?from=<?= (int)$w['id'] ?>&amp;item=<?= (int)$r['id'] ?>&amp;qty=1" title="Yêu cầu kho <?= e($w['ma']) ?> chuyển hàng sang kho của bạn" aria-label="Yêu cầu kho <?= e($w['ma']) ?> chuyển hàng"><?= icon('truck') ?></a><?php endif; ?></td><?php endforeach; ?>
      <td class="num"><b><?= n($r['total']) ?></b> <small class="muted"><?= e($r['unit']) ?></small><?php if ($pack > 1 && ($pl = pack_label($r['total'], $pack)) !== ''): ?><br><small class="muted">≈ <?= e($pl) ?></small><?php endif; ?></td>
      <td class="num money"><?= (int)$r['gia_von'] > 0 ? '<b>' . n((int)$r['gia_von']) . '</b> đ<br><small class="muted">thuế ' . (int)$r['thue_suat'] . '%</small>' : '<span class="muted">Chưa có</span>' ?></td>
      <?php $sp = $sellAt[(int)$r['id']] ?? []; ?><?php foreach ($shown as $w): $own = $mine && (int)$mine['id'] === (int)$w['id']; $pw = (int)($sp[(int)$w['id']] ?? 0); ?>
      <td class="num money price-cell<?= $own ? ' own' : '' ?>"><?php if ($pw > 0): ?><b><?= n($pw) ?></b><?php if ((int)$r['gia_von'] > 0): ?><br><small class="<?= $pw >= (int)$r['gia_von'] ? 'muted' : 'neg' ?>">lãi <?= round(($pw - (int)$r['gia_von']) / $pw * 100) ?>%</small><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <?php endforeach; ?>
      <?php if (!$user->isAdmin): ?><td><?= $r['own'] <= 0 ? '<span class="tag tag-warn">Hết hàng</span>' : ($r['low'] ? '<span class="tag tag-warn">Sắp hết</span>' : '<span class="tag tag-ok">Còn hàng</span>') ?></td><?php endif; ?>
      <?php if ($user->isAdmin || $canPrice): ?><td class="nw"><?php if ($canPrice): ?><button type="button" class="btn-ghost" data-dialog-open="price-dlg" data-fill-label="<?= e($r['ma_hang'] . ' — ' . $r['ten']) ?>" data-hist="<?= e(json_encode($priceHist[(int)$r['id']] ?? [], JSON_UNESCAPED_UNICODE)) ?>" data-fill="<?= e(json_encode(['item_id' => (string)$r['id'], 'cost_show' => (string)(int)$r['gia_von'], 'price' => (string)(int)($sp[(int)($mine['id'] ?? 0)] ?? 0), 'reason' => ''])) ?>">Giá bán</button> <?php endif; ?><?php if ($canCost): ?><button type="button" class="btn-ghost" data-dialog-open="cost-dlg" data-fill-label="<?= e($r['ma_hang'] . ' — ' . $r['ten']) ?>" data-hist="<?= e(json_encode($priceHist[(int)$r['id']] ?? [], JSON_UNESCAPED_UNICODE)) ?>" data-fill="<?= e(json_encode(['item_id' => (string)$r['id'], 'cost' => (string)(int)$r['gia_von'], 'vat' => (string)(int)$r['thue_suat'], 'reason' => ''])) ?>">Giá gốc</button> <?php endif; ?><?php if ($user->isAdmin): ?><button type="button" class="btn-ghost" data-dialog-open="edit-dlg" data-fill-label="<?= e($r['ma_hang'] . ' — ' . $r['ten'] . ' (ĐVT: ' . $r['unit'] . ')') ?>" data-fill="<?= e(json_encode(['item_id' => (string)$r['id'], 'category_id' => (string)(int)$r['danh_muc_id'], 'supplier_ids' => implode(',', array_column($r['suppliers'], 'id')), 'pack_size' => (string)$pack, 'reorder_level' => (string)(int)$r['dinh_muc_toi_thieu'], 'warranty_months' => (string)(int)$r['bao_hanh_thang']])) ?>">Sửa</button><?php endif; ?></td><?php endif; ?></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="<?= count($shown) * 2 + 8 + ($user->isAdmin ? 0 : 1) + ($user->isAdmin || $canPrice ? 1 : 0) ?>" class="empty">Không có mặt hàng phù hợp.</td></tr><?php endif; ?>
  </tbody>
<?php endif; ?>
</table></div><?= pager($pg) ?></section>

<?php if ($canPrice): ?>
<dialog id="price-dlg" class="dlg dlg-form-wide" aria-labelledby="price-dlg-t">
  <form method="post" action="/items/price" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="item_id">
    <div class="dlg-head"><h2 id="price-dlg-t">Giá bán của kho <?= e($myWarehouse['ma'] ?? '') ?></h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted" data-dlg-label></p>
    <div class="row-3 price-row">
      <label>Giá gốc (đ) — quản trị đặt<input name="cost_show" readonly tabindex="-1"></label>
      <label>Giá bán của kho bạn, chưa thuế (đ)<input name="price" inputmode="numeric" required maxlength="13" autocomplete="off" data-price-calc></label>
      <label>Lý do điều chỉnh<input name="reason" maxlength="200" placeholder="Khuyến mãi, giá thị trường tăng…" autocomplete="off"></label>
    </div>
    <p class="muted small price-margin" data-margin></p>
    <p class="muted small">Mỗi kho một giá bán; giá gốc và thuế do quản trị đặt chung cho mọi kho. Phiếu đã lập giữ nguyên giá tại lúc lập.</p>
    <div class="table-wrap" data-price-hist></div>
    <div class="actions"><button type="submit">Lưu giá bán</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($canCost): ?>
<dialog id="cost-dlg" class="dlg dlg-form-wide" aria-labelledby="cost-dlg-t">
  <form method="post" action="/items/cost" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="item_id">
    <div class="dlg-head"><h2 id="cost-dlg-t">Giá gốc và thuế</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted" data-dlg-label></p>
    <div class="row-3 price-row">
      <label>Giá gốc (đ / 1 đơn vị)<input name="cost" inputmode="numeric" required maxlength="13" autocomplete="off"></label>
      <label>Thuế GTGT<select name="vat"><?php foreach (\StockFlow\Service\PricingService::VAT_RATES as $rate): ?><option value="<?= $rate ?>"><?= $rate ?>%</option><?php endforeach; ?></select></label>
      <label>Lý do điều chỉnh<input name="reason" maxlength="200" placeholder="Nhà cung cấp tăng giá, nhập sai…" autocomplete="off"></label>
    </div>
    <p class="muted small">Giá gốc và thuế dùng chung mọi kho; điều chuyển giữa kho lấy luôn giá gốc này. Quản lý từng kho tự đặt giá bán ra của kho mình.</p>
    <div class="table-wrap" data-price-hist></div>
    <div class="actions"><button type="submit">Lưu giá gốc</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
<dialog id="lots-dlg" class="dlg dlg-wide" aria-labelledby="lots-dlg-t" data-whs="<?= e(json_encode(array_map(fn($w) => ['id' => (int)$w['id'], 'ma' => $w['ma']], $shown))) ?>">
  <form method="dialog" class="form">
    <div class="dlg-head"><h2 id="lots-dlg-t">Các lô và hạn dùng</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted" data-dlg-label></p>
    <div class="table-wrap" data-lots-body></div>
    <p class="muted small">Xuất kho và điều chuyển đi luôn lấy lô hết hạn trước. Lô đã quá hạn không được xuất đi.</p>
    <div class="actions"><button type="button" class="btn-ghost" data-dialog-close>Đóng</button></div>
  </form>
</dialog>
<?php if ($canAddBatch): ?>
<dialog id="batch-dlg" class="dlg dlg-wide" aria-labelledby="batch-dlg-t">
  <form method="post" action="/batches" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="batch-dlg-t">Thêm lô</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>Loại hàng hoá<select id="batch-cat" aria-label="Loại hàng hoá"><option value="">Tất cả loại hàng</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['ten']) ?></option><?php endforeach; ?></select></label>
    <label>Mặt hàng<select name="item_id" id="batch-item" required aria-label="Mặt hàng theo lô"><option value="">Chọn mặt hàng theo lô…</option><?php foreach ($items as $i): if ($i['quan_ly_lo']): ?><option value="<?= (int)$i['id'] ?>" data-cat="<?= (int)$i['danh_muc_id'] ?>"><?= e($i['ma_hang'] . ' — ' . $i['ten']) ?></option><?php endif; endforeach; ?></select></label>
    <label>Số lô<input name="batch_no" required maxlength="40" pattern="[A-Za-z0-9._\-]{1,40}" title="1–40 ký tự: chữ không dấu, số, . _ -" placeholder="L2607C" autocomplete="off"></label>
    <label>Hạn dùng<input type="date" name="expiry" required></label>
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm lô</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($user->isAdmin): ?>
<dialog id="item-dlg" class="dlg dlg-form-wide" aria-labelledby="item-dlg-t">
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
    <div class="row-3 price-row">
      <label>Giá gốc (đ / 1 đơn vị)<input name="cost" inputmode="numeric" required maxlength="13" autocomplete="off"></label>
      <label>Thuế GTGT<select name="vat"><?php foreach (\StockFlow\Service\PricingService::VAT_RATES as $rate): ?><option value="<?= $rate ?>"<?= $rate === 10 ? ' selected' : '' ?>><?= $rate ?>%</option><?php endforeach; ?></select></label>
      <p class="muted small" style="margin:0;align-self:center">Giá bán do quản lý từng kho đặt sau.</p>
    </div>
    <label>Đóng gói (1 kiện = ? đơn vị tính)<input type="number" name="pack_size" min="1" max="1000000" step="1" value="1" required></label>
    <label class="check"><input type="checkbox" name="track_batch" value="1"> Quản lý theo lô / hạn dùng</label>
    <label>Định mức tồn tối thiểu<input type="number" name="reorder_level" min="0" max="1000000" step="1" value="0"></label>
    <label>Bảo hành (tháng, 0 = không bảo hành)<input type="number" name="warranty_months" min="0" max="120" step="1" value="0"></label>
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
    <label>Bảo hành (tháng, 0 = không bảo hành)<input type="number" name="warranty_months" min="0" max="120" step="1" required></label>
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
