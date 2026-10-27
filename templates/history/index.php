<header class="page-head">
  <div><h1>Lịch sử phát sinh</h1></div>
  <?php if ($auth->allows($user, \StockFlow\Domain\Permission::XemBaoCao)): ?><div class="actions"><a class="btn btn-ghost" href="/reports/movements.csv?<?= e(http_build_query(array_filter($f))) ?>"><?= icon('download') ?>Xuất CSV</a></div><?php endif; ?>
</header>
<form class="filters" method="get">
  <input type="search" name="q" maxlength="50" data-search-clean value="<?= e($f['q']) ?>" placeholder="Mã phiếu, SKU, tên hàng, lô…" aria-label="Tìm kiếm">
  <?php if ($user->isAdmin): ?><select name="warehouse" aria-label="Kho"><option value="">Mọi kho</option><?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"<?= (int)$w['id'] === $f['warehouse'] ? ' selected' : '' ?>><?= e($w['ma']) ?></option><?php endforeach; ?></select>
  <?php elseif ($warehouses): ?><span class="tag tag-info"><?= e($warehouses[0]['ma']) ?></span><?php endif; ?>
  <select name="item" aria-label="Mặt hàng" data-combo><option value="">Mọi mặt hàng</option><?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>"<?= (int)$i['id'] === $f['item'] ? ' selected' : '' ?>><?= e($i['ma_hang'] . ' — ' . $i['ten']) ?></option><?php endforeach; ?></select>
  <select name="type" aria-label="Loại"><option value="">Mọi loại</option><?php foreach (TYPE_LABELS as $k => [$l]): ?><option value="<?= $k ?>"<?= $k === $f['type'] ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <?php $rangeSel = $f['rangeSel']; $from = $f['from']; $to = $f['to']; include __DIR__ . '/../partials/range_filter.php'; ?>
  <select name="sort" aria-label="Sắp xếp theo thời gian"><option value="">Mới nhất trước</option><option value="old"<?= $f['sort'] === 'old' ? ' selected' : '' ?>>Cũ nhất trước</option></select>
  <?php if ($f['rangeSel'] || $f['sort']): ?><a class="btn btn-ghost" href="/history?<?= e(http_build_query(array_filter(['q' => $f['q'], 'warehouse' => $f['warehouse'], 'item' => $f['item'], 'type' => $f['type']]))) ?>">Xoá lọc thời gian</a><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>#</th><th>Thời gian</th><th>Phiếu</th><th>Loại</th><th>Kho</th><th>Hàng / lô</th><th class="num">Biến động</th><th class="num">Tồn sau</th><th>Lý do / ghi chú</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $m): ?>
    <tr<?= is_new_ledger($changes, (int)$m['id']) ? ' class="is-new"' : '' ?>><td class="muted"><?= (int)$m['id'] ?><?= is_new_ledger($changes, (int)$m['id']) ? ' <span class="new-tag">Mới</span>' : '' ?></td><td class="muted"><?= e(dt($m['ngay_ghi_so'])) ?></td><td><a href="/vouchers/<?= (int)$m['phieu_nhap_xuat_id'] ?>"><b><?= e($m['voucher_code']) ?></b></a></td><td><?= type_chip($m['loai']) ?></td>
      <td><span class="wh-code"><?= e($m['wh_code']) ?></span></td><td class="col-item"><b><?= e($m['ma_hang']) ?></b> <span class="muted"><?= e($m['ten']) ?></span><?= $m['so_lo'] !== 'NOLOT' ? ' <small class="tag tag-muted">' . e($m['so_lo']) . '</small>' : '' ?></td>
      <td class="num <?= $m['bien_dong'] > 0 ? 'pos' : 'neg' ?>"><b><?= signed((int)$m['bien_dong']) ?></b></td><td class="num"><?= n($m['ton_sau']) ?></td><td class="muted wrap"><?= e($m['ly_do'] ?: $m['ghi_chu']) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty">Không có phát sinh phù hợp.</td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
