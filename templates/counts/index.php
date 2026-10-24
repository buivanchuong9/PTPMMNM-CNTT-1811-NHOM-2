<?php /** @var array $rows @var array $statusCounts @var array $summary @var array $allWarehouses @var array $reasons @var int $totalActive @var int $dueDays */
$tones = ['' => 'all', 'OPEN' => 'info', 'REVIEW' => 'warn', 'CLOSED' => 'ok', 'CANCELLED' => 'bad'];
$tabs = ['' => 'Tất cả', 'OPEN' => 'Đang kiểm đếm', 'REVIEW' => 'Chờ duyệt', 'CLOSED' => 'Đã đóng', 'CANCELLED' => 'Đã huỷ'];
$qs = fn(array $over) => http_build_query(array_filter(array_merge(['status' => $status, 'warehouse' => $wh, 'range' => $rangeSel === 'custom' ? '' : $rangeSel, 'from' => $rangeSel === 'custom' ? $from : null, 'to' => $rangeSel === 'custom' ? $to : null], $over), fn($v) => $v !== null && $v !== ''));
$ageDays = fn(?string $t) => $t ? (int)floor((time() - strtotime($t . ' UTC')) / 86400) : 0;
?>
<header class="page-head">
  <div><h1>Kiểm kê</h1></div>
  <?php if ($warehouses): ?><div class="actions"><button type="button" data-dialog-open="count-dlg"><?= icon('clipboard') ?>Mở đợt kiểm kê</button></div><?php endif; ?>
</header>

<?php if ($user->isAdmin && $summary): ?>
<section class="cards-3" style="margin-bottom:16px" aria-label="Tình hình kiểm kê theo kho">
  <?php foreach ($summary as $s): $due = !empty($s['due']); ?>
  <a class="card pad" href="/counts?<?= e($qs(['warehouse' => (int)$s['id'], 'status' => ''])) ?>" style="display:block;<?= $due ? 'border-left:4px solid var(--brand);' : '' ?><?= (int)$wh === (int)$s['id'] ? 'outline:2px solid var(--brand)' : '' ?>">
    <div class="wh-top"><span><span class="wh-code"><?= e($s['ma']) ?></span> <b><?= e($s['ten']) ?></b></span><span class="muted"><?= n($s['total']) ?> đợt</span></div>
    <p style="margin:8px 0 0">
      <?php if ((int)$s['open_n']): ?><span class="tag tag-info"><?= n($s['open_n']) ?> đang đếm</span> <?php endif; ?>
      <?php if ((int)$s['review_n']): ?><span class="tag tag-warn"><?= n($s['review_n']) ?> chờ duyệt</span> <?php endif; ?>
      <?php if ($due): ?><b class="warn-text"><?= icon('alert') ?> <?= $s['days_since'] === null ? 'Chưa kiểm kê lần nào' : 'Quá hạn kiểm kê · ' . n($s['days_since']) . ' ngày' ?></b>
      <?php elseif ($s['last_closed']): ?><span class="muted">Chốt gần nhất <?= e(dt_short($s['last_closed'])) ?> · <?= $s['days_since'] > 0 ? n($s['days_since']) . ' ngày trước' : 'hôm nay' ?></span>
      <?php endif; ?>
    </p>
  </a>
  <?php endforeach; ?>
</section>

<?php if ($reasons): $whCols = array_filter($summary, fn($s) => !$wh || (int)$s['id'] === (int)$wh); ?>
<section class="card flush" style="margin-bottom:16px">
  <div class="card-head pad"><h2>Lý do chênh lệch</h2><span class="muted small">số dòng lệch (lệch ròng) · đợt chờ duyệt và đã đóng</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Lý do</th><?php foreach ($whCols as $s): ?><th class="num"><?= e($s['ma']) ?></th><?php endforeach; ?><th class="num">Tổng</th></tr></thead>
    <tbody>
    <?php foreach ($reasons as $reason => $byWh): $sumN = array_sum(array_column($byWh, 'n')); $sumNet = array_sum(array_column($byWh, 'net')); ?>
      <tr><td><?= e($reason) ?></td>
        <?php foreach ($whCols as $s): $cell = $byWh[(int)$s['id']] ?? null; ?><td class="num"><?= $cell ? '<b>' . n($cell['n']) . '</b> <small class="' . ($cell['net'] > 0 ? 'pos' : ($cell['net'] < 0 ? 'neg' : 'muted')) . '">(' . signed($cell['net']) . ')</small>' : '<span class="muted">—</span>' ?></td><?php endforeach; ?>
        <td class="num"><b><?= n($sumN) ?></b> <small class="<?= $sumNet > 0 ? 'pos' : ($sumNet < 0 ? 'neg' : 'muted') ?>">(<?= signed($sumNet) ?>)</small></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>
<?php endif; ?>

<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <?php foreach ($tabs as $k => $label): $c = $k === '' ? $totalActive : ($statusCounts[$k] ?? 0); ?>
  <a href="/counts?<?= e($qs(['status' => $k])) ?>" data-tone="<?= $tones[$k] ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($c) ?></span></a>
  <?php endforeach; ?>
</nav>
<?php if ($user->isAdmin): ?>
<form class="filters" method="get">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <select name="warehouse"<?= $wh ? ' class="on"' : '' ?> aria-label="Kho"><?= wh_options($allWarehouses, $wh, 'Mọi kho') ?></select>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
  <?php if ($rangeSel): ?><a class="btn btn-ghost" href="/counts?<?= e($qs(['range' => null, 'from' => null, 'to' => null])) ?>">Xoá lọc thời gian</a><?php endif; ?>
</form>
<?php endif; ?>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã đợt</th><th>Kho</th><th>Trạng thái</th><th>Tiến độ đếm</th><th class="num">Dòng lệch</th><th class="num">Lệch ròng</th><th>Người lập / duyệt</th><th>Phiếu điều chỉnh</th><th>Mở lúc</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): $pct = $c['line_total'] ? round($c['counted'] / $c['line_total'] * 100) : 0; $net = (int)$c['net_variance'];
        $age = $c['trang_thai'] === 'REVIEW' ? $ageDays($c['ngay_nop']) : ($c['trang_thai'] === 'OPEN' ? $ageDays($c['ngay_tao']) : 0); ?>
    <tr data-tone="<?= status_tone($c['trang_thai']) ?>"><td><a href="/counts/<?= (int)$c['id'] ?>"><b><?= e($c['ma']) ?></b></a></td><td><span class="wh-code"><?= e($c['wh_code']) ?></span></td>
      <td><?= status_tag($c['trang_thai']) ?>
        <?php if ($c['trang_thai'] === 'OPEN'): ?><br><small class="muted"><?= e($c['creator_name']) ?> đang đếm<?= $age > 0 ? ' · <span class="' . ($age >= 3 ? 'warn-text' : '') . '">' . n($age) . ' ngày</span>' : '' ?></small>
        <?php elseif ($c['trang_thai'] === 'REVIEW'): ?><br><small class="muted">Chờ <?= e($c['manager_name'] ?: 'quản lý kho ' . $c['wh_code']) ?> duyệt<?= $age > 0 ? ' · <span class="' . ($age >= 3 ? 'warn-text' : '') . '">' . n($age) . ' ngày</span>' : '' ?></small>
        <?php elseif ($c['trang_thai'] === 'CANCELLED' && $c['cancel_by']): ?><br><small class="muted" title="<?= e($c['cancel_reason'] ?: 'Không ghi lý do') ?>"><?= e($c['cancel_by']) ?> huỷ · <?= e(dt_short($c['cancel_at'])) ?><?= $c['cancel_reason'] ? ' · ' . e(mb_strimwidth($c['cancel_reason'], 0, 40, '…')) : '' ?></small><?php endif; ?></td>
      <td><div class="bar sm"><i style="--w:<?= $pct ?>%"></i></div><small class="muted"><?= n($c['counted']) ?>/<?= n($c['line_total']) ?></small></td>
      <td class="num"><?= (int)$c['variances'] > 0 ? '<span class="tag tag-warn">' . n($c['variances']) . '</span>' : '<span class="muted">0</span>' ?></td>
      <td class="num <?= $net > 0 ? 'pos' : ($net < 0 ? 'neg' : '') ?>"><?= $c['counted'] ? signed($net) : '—' ?></td>
      <td class="nw"><?= e($c['creator_name']) ?><?= $c['approver_name'] ? ' <span class="arrow">→</span> ' . e($c['approver_name']) : '' ?></td>
      <td><?= $c['phieu_nhap_xuat_dieu_chinh_id'] ? '<a href="/vouchers/' . (int)$c['phieu_nhap_xuat_dieu_chinh_id'] . '">Xem phiếu</a>' : '<span class="muted">—</span>' ?></td><td class="muted"><?= e(dt($c['ngay_tao'])) ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty"><?= $status === '' && ($statusCounts['CANCELLED'] ?? 0) ? 'Chưa có đợt kiểm kê nào (các đợt đã huỷ xem ở tab «Đã huỷ»).' : 'Không có đợt kiểm kê phù hợp.' ?></td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>

<?php if ($warehouses): ?>
<dialog id="count-dlg" class="dlg" aria-labelledby="count-dlg-t">
  <form method="post" action="/counts" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="count-dlg-t">Mở đợt kiểm kê</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <?php if (!$user->isAdmin && $myWarehouse && count($warehouses) === 1): ?>
      <?= wh_fixed($myWarehouse, 'Kiểm kê kho', 'warehouse_id') ?>
    <?php else: ?>
      <label>Kho<select name="warehouse_id" required><?= wh_options($warehouses, null, 'Chọn kho…') ?></select></label>
    <?php endif; ?>
    <label>Ghi chú<input name="note" maxlength="255" placeholder="Kiểm kê định kỳ, đột xuất…"></label>
    <p class="muted small">Hệ thống chụp tồn sổ sách của mọi mặt hàng/lô tại thời điểm mở. Mỗi kho chỉ có một đợt đang mở.</p>
    <div class="actions"><button type="submit"><?= icon('clipboard') ?>Mở đợt kiểm kê</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
