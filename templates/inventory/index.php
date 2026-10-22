<?php /** @var array $warehouses @var array $shown @var array $rows @var array $items @var array $units */ ?>
<header class="page-head">
  <div><h1>Hàng hoá</h1><p class="muted">Danh mục mặt hàng, quy cách lô và tồn kho hiện có — tồn tính trực tiếp từ sổ phát sinh đã ghi nhận. <b>Quy cách</b>: 1 lô gồm bao nhiêu đơn vị tính.</p></div>
  <div class="actions">
    <?php if ($auth->allows($user, \StockFlow\Domain\Permission::XemBaoCao)): ?><a class="btn btn-ghost" href="/reports/stock.csv"><?= icon('download') ?>Xuất CSV</a><?php endif; ?>
    <?php if ($user->isAdmin): ?><button type="button" data-dialog-open="item-dlg"><?= icon('plus') ?>Thêm mặt hàng</button>
      <button type="button" class="btn-ghost" data-dialog-open="unit-dlg"><?= icon('plus') ?>Thêm đơn vị tính</button><?php endif; ?>
    <?php if ($canAddBatch): ?><button type="button" class="btn-ghost" data-dialog-open="batch-dlg"><?= icon('plus') ?>Thêm lô</button><?php endif; ?>
  </div>
</header>
<form class="filters" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Tìm SKU hoặc tên hàng…" aria-label="Tìm kiếm">
  <?php if ($user->isAdmin): ?><select name="warehouse" aria-label="Kho"><?= wh_options($warehouses, $wh, 'Tất cả kho') ?></select>
  <?php elseif ($warehouses): ?><span class="tag tag-info"><?= e($warehouses[0]['ma'] . ' — ' . $warehouses[0]['ten']) ?></span><?php endif; ?>
  <select name="view" aria-label="Kiểu xem" data-live-reload><option value="">Theo mặt hàng</option><option value="batch"<?= $byBatch ? ' selected' : '' ?>>Theo lô (hạn dùng)</option></select>
  <?php if (!$byBatch): ?><select name="stock" aria-label="Tình trạng tồn">
    <?php foreach (['' => 'Mọi tình trạng', 'in' => 'Còn hàng', 'low' => 'Sắp hết', 'out' => 'Hết hàng'] as $k => $label): ?><option value="<?= e($k) ?>"<?= $stock === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
<?php if ($byBatch): ?>
  <thead><tr><th>Kho</th><th>SKU</th><th>Tên hàng</th><th>Lô</th><th>Hạn dùng</th><th class="num">Tồn</th><th>Quy đổi</th><th>Cập nhật cuối</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><span class="wh-code"><?= e($r['wh_code']) ?></span></td><td><b><?= e($r['ma_hang']) ?></b></td><td><?= e($r['ten']) ?></td><td><?= $r['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($r['so_lo']) ?></td>
      <td><?= e(dt($r['han_dung'], false)) ?></td><td class="num"><b><?= n($r['so_luong']) ?></b> <small class="muted"><?= e($r['unit']) ?></small></td>
      <td class="muted"><?= e(pack_label((int)$r['so_luong'], (int)$r['quy_cach_lo'])) ?: '—' ?></td><td class="muted"><?= e(dt($r['last_move'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">Không có tồn phù hợp.</td></tr><?php endif; ?>
  </tbody>
<?php else: ?>
  <thead><tr><th>SKU</th><th>Tên hàng</th><th>ĐVT</th><th>Quy cách lô</th><?php foreach ($shown as $w): ?><th class="num" title="<?= e($w['ten']) ?>"><?= e($w['ma']) ?></th><?php endforeach; ?><th class="num">Tổng tồn</th><th>Quy đổi</th><th>Tình trạng</th><?php if ($user->isAdmin): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $pack = (int)$r['quy_cach_lo']; ?>
    <tr><td><b><?= e($r['ma_hang']) ?></b></td><td><?= e($r['ten']) ?><?= $r['quan_ly_lo'] ? ' <span class="tag tag-info">Theo dõi số lô · ' . n($r['lots']) . '</span>' : '' ?></td><td><?= e($r['unit']) ?></td>
      <td><?= $pack > 1 ? '1 lô = <b>' . n($pack) . '</b> ' . e($r['unit']) : '<span class="muted">Bán lẻ (1)</span>' ?></td>
      <?php foreach ($shown as $w): $q1 = $r['by_wh'][(int)$w['id']] ?? 0; ?><td class="num <?= $q1 === 0 ? 'muted' : '' ?>"><?= n($q1) ?></td><?php endforeach; ?>
      <td class="num"><b><?= n($r['total']) ?></b> <small class="muted"><?= e($r['unit']) ?></small></td>
      <td class="muted"><?= e(pack_label($r['total'], $pack)) ?: '—' ?></td>
      <td><?= $r['total'] <= 0 ? '<span class="tag tag-warn">Hết hàng</span>' : ($r['low'] ? '<span class="tag tag-warn">Sắp hết</span>' : '<span class="tag tag-ok">Còn hàng</span>') ?></td>
      <?php if ($user->isAdmin): ?><td><button type="button" class="btn-ghost" data-dialog-open="edit-dlg" data-fill-label="<?= e($r['ma_hang'] . ' — ' . $r['ten'] . ' (ĐVT: ' . $r['unit'] . ')') ?>" data-fill="<?= e(json_encode(['item_id' => (string)$r['id'], 'pack_size' => (string)$pack, 'reorder_level' => (string)(int)$r['dinh_muc_toi_thieu']])) ?>">Sửa</button></td><?php endif; ?></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="<?= count($shown) + 7 + ($user->isAdmin ? 1 : 0) ?>" class="empty">Không có mặt hàng phù hợp.</td></tr><?php endif; ?>
  </tbody>
<?php endif; ?>
</table></div><?= pager($pg) ?></section>

<?php if ($canAddBatch): ?>
<dialog id="batch-dlg" class="dlg dlg-wide" aria-labelledby="batch-dlg-t">
  <form method="post" action="/batches" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="batch-dlg-t">Thêm lô</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>Mặt hàng<select name="item_id" required data-combo aria-label="Mặt hàng theo lô"><option value="">Chọn mặt hàng theo lô…</option><?php foreach ($items as $i): if ($i['quan_ly_lo']): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['ma_hang'] . ' — ' . $i['ten']) ?></option><?php endif; endforeach; ?></select></label>
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
    <label>Đơn vị tính<select name="unit_id" required><?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['ma']) ?></option><?php endforeach; ?></select></label>
    <label>Quy cách lô (1 lô = ? đơn vị tính)<input type="number" name="pack_size" min="1" max="1000000" step="1" value="1" required></label>
    <label class="check"><input type="checkbox" name="track_batch" value="1"> Quản lý theo lô / hạn dùng</label>
    <label>Định mức tồn tối thiểu<input type="number" name="reorder_level" min="0" max="1000000" step="1" value="0"></label>
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm mặt hàng</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<dialog id="edit-dlg" class="dlg" aria-labelledby="edit-dlg-t">
  <form method="post" action="/items/update" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="item_id">
    <div class="dlg-head"><h2 id="edit-dlg-t">Sửa quy cách</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted" data-dlg-label></p>
    <label>Quy cách lô (1 lô = ? đơn vị tính)<input type="number" name="pack_size" min="1" max="1000000" step="1" required></label>
    <label>Định mức tồn tối thiểu<input type="number" name="reorder_level" min="0" max="1000000" step="1" required></label>
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
