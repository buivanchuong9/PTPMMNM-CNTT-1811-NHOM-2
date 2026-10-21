<?php /** @var array $items @var array $units @var array $batches */ ?>
<header class="page-head"><div><h1>Mặt hàng & lô</h1><p class="muted">Danh mục dùng chung. Mặt hàng quản lý theo lô có số lô và hạn dùng riêng.</p></div></header>
<div class="grid-main">
  <div>
    <section class="card flush"><div class="table-wrap"><table class="dense">
      <thead><tr><th>SKU</th><th>Tên hàng</th><th>ĐVT</th><th>Quản lý lô</th><th class="num">Định mức tối thiểu</th></tr></thead>
      <tbody>
      <?php foreach ($items as $i): ?>
        <tr><td><b><?= e($i['sku']) ?></b></td><td><?= e($i['name']) ?></td><td><?= e($i['unit']) ?></td><td><?= $i['track_batch'] ? '<span class="tag tag-info">Theo lô · ' . n($i['lots']) . ' lô</span>' : '<span class="muted">—</span>' ?></td><td class="num"><?= n($i['reorder_level']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></section>
    <?php if ($batches): ?><section class="card flush"><div class="card-head pad"><h2>Danh sách lô</h2></div><div class="table-wrap"><table class="dense"><thead><tr><th>SKU</th><th>Lô</th><th>Hạn dùng</th></tr></thead><tbody>
      <?php foreach ($batches as $b): ?><tr><td><b><?= e($b['sku']) ?></b> <span class="muted"><?= e($b['name']) ?></span></td><td><?= e($b['batch_no']) ?></td><td><?= e(dt($b['expiry_date'], false)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section><?php endif; ?>
  </div>
  <aside>
    <?php if ($canAddBatch): ?><section class="card"><h2>Thêm lô</h2>
      <form method="post" action="/batches" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Mặt hàng<select name="item_id" required><option value="">Chọn mặt hàng theo lô…</option><?php foreach ($items as $i): if ($i['track_batch']): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['sku'] . ' — ' . $i['name']) ?></option><?php endif; endforeach; ?></select></label>
        <label>Số lô<input name="batch_no" required maxlength="40" placeholder="L2607C" autocomplete="off"></label>
        <label>Hạn dùng<input type="date" name="expiry"></label>
        <button><?= icon('plus') ?>Thêm lô</button></form></section><?php endif; ?>
    <?php if ($user->isAdmin): ?>
    <section class="card"><h2>Thêm mặt hàng</h2>
      <form method="post" action="/items" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>SKU<input name="sku" required maxlength="40" autocomplete="off"></label>
        <label>Tên hàng<input name="name" required maxlength="160"></label>
        <label>Đơn vị tính<select name="unit_id" required><?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['code']) ?></option><?php endforeach; ?></select></label>
        <label class="check"><input type="checkbox" name="track_batch" value="1"> Quản lý theo lô / hạn dùng</label>
        <label>Định mức tồn tối thiểu<input type="number" name="reorder_level" min="0" value="0"></label>
        <button><?= icon('plus') ?>Thêm mặt hàng</button></form></section>
    <section class="card"><h2>Thêm đơn vị tính</h2>
      <form method="post" action="/units" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Ký hiệu<input name="code" required maxlength="20" placeholder="pallet"></label><label>Tên đầy đủ<input name="name" required maxlength="60"></label><button class="btn-ghost">Thêm</button></form></section>
    <?php endif; ?>
  </aside>
</div>
