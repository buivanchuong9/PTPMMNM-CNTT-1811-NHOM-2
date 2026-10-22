<?php /** @var array $items @var array $units @var array $batches */ ?>
<header class="page-head">
  <div><h1>Mặt hàng &amp; lô</h1><p class="muted">Danh mục dùng chung. Mặt hàng quản lý theo lô có số lô và hạn dùng riêng.</p></div>
  <div class="actions">
    <?php if ($user->isAdmin): ?><button type="button" data-dialog-open="item-dlg"><?= icon('plus') ?>Thêm mặt hàng</button>
      <button type="button" class="btn-ghost" data-dialog-open="unit-dlg"><?= icon('plus') ?>Thêm đơn vị tính</button><?php endif; ?>
    <?php if ($canAddBatch): ?><button type="button" class="btn-ghost" data-dialog-open="batch-dlg"><?= icon('plus') ?>Thêm lô</button><?php endif; ?>
  </div>
</header>

<section class="card flush"><div class="table-wrap"><table class="dense">
  <thead><tr><th>SKU</th><th>Tên hàng</th><th>ĐVT</th><th>Quản lý lô</th><th class="num">Định mức tối thiểu</th></tr></thead>
  <tbody>
  <?php foreach ($pageItems as $i): ?>
    <tr><td><b><?= e($i['ma_hang']) ?></b></td><td><?= e($i['ten']) ?></td><td><?= e($i['unit']) ?></td><td><?= $i['quan_ly_lo'] ? '<span class="tag tag-info">Theo lô · ' . n($i['lots']) . ' lô</span>' : '<span class="muted">—</span>' ?></td><td class="num"><?= n($i['dinh_muc_toi_thieu']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div><?= pager($pg) ?></section>
<?php if ($batches): ?><section class="card flush"><div class="card-head pad"><h2>Danh sách lô</h2></div><div class="table-wrap"><table class="dense"><thead><tr><th>SKU</th><th>Lô</th><th>Hạn dùng</th></tr></thead><tbody>
  <?php foreach ($batches as $b): ?><tr><td><b><?= e($b['ma_hang']) ?></b> <span class="muted"><?= e($b['ten']) ?></span></td><td><?= e($b['so_lo']) ?></td><td><?= e(dt($b['han_dung'], false)) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?= pager($bpg) ?></section><?php endif; ?>

<?php if ($canAddBatch): ?>
<dialog id="batch-dlg" class="dlg" aria-labelledby="batch-dlg-t">
  <form method="post" action="/batches" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="batch-dlg-t">Thêm lô</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>Mặt hàng<select name="item_id" required data-combo aria-label="Mặt hàng theo lô"><option value="">Chọn mặt hàng theo lô…</option><?php foreach ($items as $i): if ($i['quan_ly_lo']): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['ma_hang'] . ' — ' . $i['ten']) ?></option><?php endif; endforeach; ?></select></label>
    <label>Số lô<input name="batch_no" required maxlength="40" placeholder="L2607C" autocomplete="off"></label>
    <label>Hạn dùng<input type="date" name="expiry"></label>
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm lô</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($user->isAdmin): ?>
<dialog id="item-dlg" class="dlg" aria-labelledby="item-dlg-t">
  <form method="post" action="/items" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="item-dlg-t">Thêm mặt hàng</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>SKU<input name="sku" required maxlength="40" autocomplete="off"></label>
    <label>Tên hàng<input name="name" required maxlength="160"></label>
    <label>Đơn vị tính<select name="unit_id" required><?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['ma']) ?></option><?php endforeach; ?></select></label>
    <label class="check"><input type="checkbox" name="track_batch" value="1"> Quản lý theo lô / hạn dùng</label>
    <label>Định mức tồn tối thiểu<input type="number" name="reorder_level" min="0" value="0"></label>
    <div class="actions"><button type="submit"><?= icon('plus') ?>Thêm mặt hàng</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
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
