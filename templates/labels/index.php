<?php /** @var array $rows @var array $lots @var string $q @var int $cat @var int $sup @var string $code @var string $lot @var array $suppliers @var array $formats @var array $categories */ ?>
<header class="page-head"><div><h1>In tem mã vạch</h1></div></header>
<form class="filters" method="get">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="SKU, tên hàng, mã vạch…" aria-label="Tìm kiếm">
  <select name="category"<?= $cat ? ' class="on"' : '' ?> aria-label="Loại hàng" data-live-reload><option value="">Mọi loại hàng</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $cat === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['ten']) ?></option><?php endforeach; ?></select>
  <select name="supplier"<?= $sup ? ' class="on"' : '' ?> aria-label="Nhà cung cấp" data-live-reload><?= filter_options(array_column($suppliers, 'ten', 'id'), $sup ?: '', 'Mọi nhà cung cấp') ?></select>
  <select name="code"<?= $code !== '' ? ' class="on"' : '' ?> aria-label="Mã vạch" data-live-reload><?= filter_options(['has' => 'Đã gắn mã vạch', 'none' => 'Chưa gắn mã vạch'], $code, 'Mọi mã vạch') ?></select>
  <select name="lot"<?= $lot !== '' ? ' class="on"' : '' ?> aria-label="Quản lý theo lô" data-live-reload><?= filter_options(['lot' => 'Hàng theo lô / hạn dùng', 'nolot' => 'Hàng không theo lô'], $lot, 'Mọi hàng') ?></select>
  <?php if ($q !== '' || $cat || $sup || $code !== '' || $lot !== ''): ?><a class="btn btn-ghost" href="/labels">Xoá lọc</a><?php endif; ?>
</form>
<form method="get" action="/labels/print" target="_blank" id="label-form">
  <section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
    <thead><tr><th style="width:36px"><input type="checkbox" data-check-all aria-label="Chọn tất cả"></th><th>SKU</th><th>Tên hàng</th><th>Mã vạch</th><th>In kèm lô</th><th class="num" style="width:90px">Số tem</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i): $id = (int)$i['id']; ?>
      <tr><td><input type="checkbox" name="sel[]" value="<?= $id ?>" aria-label="Chọn <?= e($i['ma_hang']) ?>"></td>
        <td><b><?= e($i['ma_hang']) ?></b></td><td><?= e($i['ten']) ?></td>
        <td class="nw"><?= $i['ma_vach'] ? '<span class="barcode-tag">' . e($i['ma_vach']) . '</span>' : '<span class="muted" title="Chưa gắn mã vạch — tem in mã theo SKU">theo SKU</span>' ?></td>
        <td><?php if (!empty($lots[$id])): ?><select name="lot[<?= $id ?>]" aria-label="Lô"><option value="">Không in lô</option>
          <?php foreach ($lots[$id] as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['so_lo'] . ($l['han_dung'] ? ' · HSD ' . dt($l['han_dung'], false) : '')) ?></option><?php endforeach; ?></select>
          <?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td class="num"><input type="number" name="n[<?= $id ?>]" value="1" min="1" max="100" aria-label="Số tem"></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">Không có mặt hàng phù hợp.</td></tr><?php endif; ?>
    </tbody></table></div><?= pager($pg) ?></section>
  <div class="card" style="margin-top:12px">
    <div class="actions" style="align-items:flex-end">
      <label style="min-width:320px">Khổ giấy<select name="format"><?php foreach ($formats as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
      <button type="submit"><?= icon('printer') ?>Xem và in tem</button>
    </div>
  </div>
</form>
<script>
  document.addEventListener('change', function (e) {
    if (!e.target.matches || !e.target.matches('[data-check-all]')) return;
    document.querySelectorAll('#label-form input[name="sel[]"]').forEach(function (c) { c.checked = e.target.checked; });
  });
</script>
