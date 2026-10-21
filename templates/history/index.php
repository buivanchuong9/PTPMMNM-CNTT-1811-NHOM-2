<header class="page-head">
  <div><h1>Lịch sử phát sinh</h1><p class="muted">Sổ cái bất biến: mỗi dòng gắn với một dòng chứng từ đã xác nhận.</p></div>
  <div class="actions"><a class="btn btn-ghost" href="/reports/movements.csv?<?= e(http_build_query(array_filter($f))) ?>"><?= icon('download') ?>Xuất CSV</a></div>
</header>
<form class="filters" method="get">
  <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Mã chứng từ, SKU, tên hàng, lô…" aria-label="Tìm kiếm">
  <select name="warehouse" aria-label="Kho"><option value="">Mọi kho</option><?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"<?= (int)$w['id'] === $f['warehouse'] ? ' selected' : '' ?>><?= e($w['code']) ?></option><?php endforeach; ?></select>
  <select name="item" aria-label="Mặt hàng"><option value="">Mọi mặt hàng</option><?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>"<?= (int)$i['id'] === $f['item'] ? ' selected' : '' ?>><?= e($i['sku'] . ' — ' . $i['name']) ?></option><?php endforeach; ?></select>
  <select name="type" aria-label="Loại"><option value="">Mọi loại</option><?php foreach (TYPE_LABELS as $k => [$l]): ?><option value="<?= $k ?>"<?= $k === $f['type'] ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <input type="date" name="from" value="<?= e($f['from']) ?>" aria-label="Từ ngày"><input type="date" name="to" value="<?= e($f['to']) ?>" aria-label="Đến ngày">
  <button type="submit">Lọc</button>
</form>
<section class="card flush"><div class="table-wrap"><table class="dense">
  <thead><tr><th>#</th><th>Thời gian</th><th>Chứng từ</th><th>Loại</th><th>Kho</th><th>Hàng / lô</th><th class="num">Biến động</th><th class="num">Tồn sau</th><th>Lý do / ghi chú</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $m): ?>
    <tr><td class="muted"><?= (int)$m['id'] ?></td><td class="muted"><?= e(dt($m['posted_at'])) ?></td><td><a href="/vouchers/<?= (int)$m['voucher_id'] ?>"><b><?= e($m['voucher_code']) ?></b></a></td><td><?= type_chip($m['type']) ?></td>
      <td><span class="wh-code"><?= e($m['wh_code']) ?></span></td><td><b><?= e($m['sku']) ?></b> <span class="muted"><?= e($m['name']) ?></span><?= $m['batch_no'] !== 'NOLOT' ? ' <small class="tag tag-muted">' . e($m['batch_no']) . '</small>' : '' ?></td>
      <td class="num <?= $m['qty_change'] > 0 ? 'pos' : 'neg' ?>"><b><?= signed((int)$m['qty_change']) ?></b></td><td class="num"><?= n($m['balance_after']) ?></td><td class="muted"><?= e($m['reason'] ?: $m['note']) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty">Không có phát sinh phù hợp.</td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
