<?php /** @var array $rows @var array $stats */ ?>
<header class="page-head"><div><h1>Kho</h1><p class="muted">Các điểm kho trong hệ thống.</p></div></header>
<div class="grid-main">
  <section class="card flush"><div class="table-wrap"><table class="dense">
    <thead><tr><th>Mã</th><th>Tên kho</th><th>Địa chỉ</th><th class="num">Đơn vị tồn</th><th class="num">Mã hàng</th><th>Trạng thái</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $w): $s = $stats[$w['id']] ?? null; ?>
      <tr><td><span class="wh-code"><?= e($w['code']) ?></span></td><td><?= e($w['name']) ?></td><td class="muted"><?= e($w['address']) ?></td><td class="num"><?= $s ? n($s['units']) : '<span class="muted" title="Ngoài phạm vi của bạn">—</span>' ?></td><td class="num"><?= $s ? n($s['skus']) : '<span class="muted">—</span>' ?></td>
        <td><?= $w['active'] ? '<span class="tag tag-ok">Hoạt động</span>' : '<span class="tag tag-muted">Ngừng</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?= pager($pg) ?></section>
  <aside><?php if ($user->isAdmin): ?><section class="card"><h2>Thêm kho</h2>
    <form method="post" class="form" action="/warehouses"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label>Mã kho<input name="code" required maxlength="20" placeholder="VD: HP01" autocomplete="off"></label>
      <label>Tên kho<input name="name" required maxlength="120"></label>
      <label>Địa chỉ<input name="address" maxlength="255"></label>
      <button><?= icon('plus') ?>Thêm kho</button></form></section><?php endif; ?></aside>
</div>
