<?php /** @var string $kind @var list<array> $rows @var list<array> $valid @var string $fileName */
$bad = count($rows) - count($valid);
?>
<header class="page-head">
  <div><p class="crumb"><a href="/import">← Nhập từ file</a></p><h1>Xem trước: <?= $kind === 'items' ? 'danh mục mặt hàng' : 'tồn đầu kỳ' ?></h1><p class="muted"><?= e($fileName) ?> · <?= n(count($rows)) ?> dòng · <b class="pos"><?= n(count($valid)) ?> hợp lệ</b><?= $bad ? ' · <b class="neg">' . n($bad) . ' lỗi (bị bỏ qua)</b>' : '' ?></p></div>
  <div class="actions">
    <form method="post" action="/import/apply" class="inline-form" data-once>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="type" value="<?= e($kind) ?>">
      <input type="hidden" name="payload" value="<?= e(json_encode($valid, JSON_UNESCAPED_UNICODE)) ?>">
      <button type="submit"<?= $valid ? '' : ' disabled' ?>><?= icon('check') ?><?= $kind === 'items' ? 'Thêm ' . n(count($valid)) . ' mặt hàng' : 'Lập phiếu nhập nháp (' . n(count($valid)) . ' dòng)' ?></button>
    </form>
  </div>
</header>
<section class="card flush"><div class="table-wrap"><table class="dense">
  <?php if ($kind === 'items'): ?>
  <thead><tr><th>Dòng</th><th>SKU</th><th>Tên hàng</th><th>Đơn vị</th><th>Loại hàng</th><th>Theo lô</th><th class="num">Tối thiểu</th><th class="num">Quy cách</th><th class="num">Bảo hành</th><th>Kết quả</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $x): $d = $x['data']; ?>
    <tr data-tone="<?= $x['error'] ? 'bad' : 'ok' ?>"><td class="muted"><?= (int)$x['line'] ?></td><td><b><?= e($d['sku']) ?></b></td><td><?= e($d['name']) ?></td><td><?= e($d['unit']) ?></td><td><?= e($d['category'] ?: '—') ?></td>
      <td><?= $d['lot'] ? 'có' : 'không' ?></td><td class="num"><?= n($d['reorder']) ?></td><td class="num"><?= n($d['pack']) ?></td><td class="num"><?= $d['warranty'] ? n($d['warranty']) . ' th' : '—' ?></td>
      <td class="wrap"><?= $x['error'] ? '<span class="tag tag-danger">Lỗi</span> ' . e($x['error']) : '<span class="tag tag-ok">Hợp lệ</span>' ?></td></tr>
  <?php endforeach; ?>
  <?php else: ?>
  <thead><tr><th>Dòng</th><th>SKU</th><th>Tên hàng</th><th>Số lô</th><th>Hạn dùng</th><th class="num">Số lượng</th><th>Kết quả</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $x): $d = $x['data']; ?>
    <tr data-tone="<?= $x['error'] ? 'bad' : 'ok' ?>"><td class="muted"><?= (int)$x['line'] ?></td><td><b><?= e($d['sku']) ?></b></td><td><?= e($d['name'] ?: '—') ?></td><td><?= e($d['lot'] ?: '—') ?></td>
      <td><?= $d['expiry'] ? e(dt($d['expiry'], false)) : '—' ?></td><td class="num"><?= n($d['qty']) ?></td>
      <td class="wrap"><?= $x['error'] ? '<span class="tag tag-danger">Lỗi</span> ' . e($x['error']) : '<span class="tag tag-ok">Hợp lệ</span>' ?></td></tr>
  <?php endforeach; ?>
  <?php endif; ?>
  </tbody></table></div></section>
