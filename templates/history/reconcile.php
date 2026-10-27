<?php /** @var array $res */ $ok = $res['mismatches'] === 0; ?>
<header class="page-head">
  <div><h1>Đối chiếu tồn</h1></div>
  <?php if ($auth->allows($user, \StockFlow\Domain\Permission::XemBaoCao)): ?><div class="actions"><a class="btn btn-ghost" href="/reports/reconcile.csv"><?= icon('download') ?>Xuất CSV</a></div><?php endif; ?>
</header>
<div class="verdict <?= $ok ? 'verdict-ok' : 'verdict-bad' ?>">
  <?= icon($ok ? 'shield' : 'alert') ?>
  <div><b><?= $ok ? 'KHỚP — tồn đối chiếu được với sổ phát sinh' : 'LỆCH — ' . n($res['mismatches']) . ' điểm không khớp' ?></b><br><span class="muted">Đã kiểm tra <?= n($res['checked']) ?> vị trí (kho · hàng · lô).</span></div>
</div>
<?php if ($res['voucher_mismatches']): ?>
<section class="card flush"><div class="card-head pad"><h2>Dòng phiếu không khớp sổ</h2></div><div class="table-wrap"><table class="dense"><thead><tr><th>Phiếu</th><th>Dòng</th><th class="num">SL phiếu</th><th class="num">Sổ</th></tr></thead><tbody>
<?php foreach ($res['voucher_mismatches'] as $m): ?><tr><td><?= e($m['ma']) ?></td><td><?= (int)$m['line_id'] ?></td><td class="num"><?= n($m['so_luong']) ?></td><td class="num"><?= $m['bien_dong'] === null ? '—' : n($m['bien_dong']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php
$tabs = ['' => 'Tất cả', 'bad' => 'Lệch', 'ok' => 'Khớp'];
$tones = ['' => 'all', 'bad' => 'bad', 'ok' => 'ok'];
$qs = fn(array $over) => http_build_query(array_filter(array_merge(['q' => $q, 'warehouse' => $wh, 'category' => $cat === -1 ? 'none' : $cat, 'status' => $status], $over), fn($v) => $v !== null && $v !== '' && $v !== 0));
?>
<nav class="tabs" aria-label="Kết quả đối chiếu" data-live="tabs">
  <?php foreach ($tabs as $k => $label): ?>
  <a href="/reconcile?<?= e($qs(['status' => $k, 'page' => null])) ?>" data-tone="<?= $tones[$k] ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($counts[$k]) ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Tìm SKU, tên hàng hoặc số lô…" aria-label="Tìm vị trí kho">
  <?php if ($user->isAdmin && count($warehouses) > 1): ?><select name="warehouse"<?= $wh ? ' class="on"' : '' ?> aria-label="Kho"><?= wh_options($warehouses, $wh, 'Mọi kho') ?></select>
  <?php elseif ($warehouses): ?><span class="tag tag-info"><?= e($warehouses[0]['ma'] . ' — ' . $warehouses[0]['ten']) ?></span><?php endif; ?>
  <select name="category"<?= $cat ? ' class="on"' : '' ?> aria-label="Danh mục hàng hoá"><option value="">Mọi danh mục</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $cat === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['ten']) ?></option><?php endforeach; ?><option value="none"<?= $cat === -1 ? ' selected' : '' ?>>Chưa phân loại</option></select>
  <?php if ($q !== '' || $wh || $cat || $status): ?><a class="btn btn-ghost" href="/reconcile">Xoá lọc</a><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Kho</th><th>Hàng hoá</th><th>Lô</th><th class="num">Số dư</th><th class="num">Tổng sổ phát sinh</th><th class="num">Lệch</th><th>Phát sinh cuối</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($res['rows'] as $r): $diff = (int)$r['balance'] - (int)$r['ledger']; $lot = $r['so_lo'] === 'NOLOT' ? '' : $r['so_lo']; ?>
    <tr class="<?= $diff ? 'row-bad' : '' ?>"><td><span class="wh-code"><?= e($r['wh_code']) ?></span></td>
      <td><b><?= hl($r['ma_hang'], $q) ?></b> <?= hl($r['ten'], $q) ?><?= $r['category'] ? '<br><small class="muted">' . e($r['category']) . '</small>' : '' ?></td>
      <td><?= $lot === '' ? '<span class="muted">—</span>' : hl($lot, $q) . ($r['han_dung'] ? '<br><small class="muted">HSD ' . e(dt($r['han_dung'], false)) . '</small>' : '') ?></td>
      <td class="num"><?= n($r['balance']) ?> <small class="muted"><?= e($r['unit']) ?></small></td><td class="num"><?= n($r['ledger']) ?></td>
      <td class="num"><?= $diff ? '<b class="neg">' . signed($diff) . '</b>' : '<span class="pos">✓</span>' ?></td>
      <td class="muted"><?= $r['last_move'] ? e(dt_short($r['last_move'])) : '—' ?></td>
      <td class="nw"><a href="/history?<?= e(http_build_query(array_filter(['warehouse' => $r['kho_id'], 'item' => $r['mat_hang_id'], 'q' => $lot]))) ?>" title="Xem các dòng sổ phát sinh của vị trí này"><?= $diff ? '<b>Xem sổ →</b>' : 'Xem sổ →' ?></a></td></tr>
  <?php endforeach; if (!$res['rows']): ?><tr><td colspan="8" class="empty"><?= ($q !== '' || $wh || $cat || $status) ? 'Không có vị trí nào phù hợp bộ lọc.' : 'Chưa có vị trí nào để đối chiếu.' ?></td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
