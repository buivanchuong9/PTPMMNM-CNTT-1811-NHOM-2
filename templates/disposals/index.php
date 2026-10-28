<?php /** @var array $rows @var array $counts @var array $loss @var array $expired @var array $pick @var string $status @var string $q @var bool $canPropose @var bool $canApprove */
use StockFlow\Service\DisposalService as D;
$totalLoss = array_sum(array_column($loss, 'value'));
$tabTone = ['CHO_DUYET' => 'warn', 'TRONG_KHO_HUY' => 'info', 'DA_TIEU_HUY' => 'all', 'TU_CHOI' => 'bad'];
$lotOf = fn(array $r) => $r['quan_ly_lo'] ? ' · lô ' . $r['so_lo'] . ($r['han_dung'] ? ' (HSD ' . dt($r['han_dung'], false) . ')' : '') : '';
?>
<header class="page-head">
  <div><h1>Kho huỷ</h1><p class="muted small">Hàng hết hạn, vỡ, hỏng, không bán được. Duyệt xong hàng rời tồn và nằm chờ tiêu huỷ.</p></div>
  <?php if ($canPropose): ?><div class="actions"><button type="button" data-dialog-open="dispose-dlg"><?= icon('plus') ?>Huỷ hàng</button></div><?php endif; ?>
</header>

<?php if ($expired): ?>
<section class="card flush" style="margin-bottom:16px">
  <div class="card-head pad"><h2><?= icon('alert') ?> Đã hết hạn mà vẫn còn trong kho</h2><span class="muted small"><?= n($expiredPg->total) ?> lô</span></div>
  <div class="table-wrap"><table class="dense"><tbody>
    <?php foreach ($expired as $x): ?>
    <tr><td><b><?= e($x['ma_hang']) ?></b> <?= e($x['ten']) ?><br><small class="muted">lô <?= e($x['so_lo']) ?> · hết hạn <?= e(dt($x['han_dung'], false)) ?></small></td>
      <td class="num"><b><?= n($x['qty']) ?></b> <small class="muted"><?= e($x['unit']) ?></small></td>
      <td class="num money"><?= (int)$x['gia_von'] > 0 ? n((int)$x['qty'] * (int)$x['gia_von']) . ' đ' : '<span class="muted">—</span>' ?></td>
      <td class="nw"><?php if ($canPropose && (int)$x['kho_id'] === (int)($myWarehouse['id'] ?? 0)): ?>
        <form method="post" action="/disposals" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="pick" value="<?= (int)$x['mat_hang_id'] . ':' . (int)$x['lo_hang_id'] ?>"><input type="hidden" name="qty" value="<?= (int)$x['qty'] ?>"><input type="hidden" name="reason" value="HET_HAN">
          <button type="submit" class="btn-ghost">Đưa vào kho huỷ</button></form>
        <?php else: ?><span class="wh-code"><?= e($x['wh_code']) ?></span><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div><?= pager($expiredPg) ?>
</section>
<?php endif; ?>

<?php if ($loss): ?>
<p class="muted" style="margin:0 0 12px">Thiệt hại theo giá vốn: <b class="neg"><?= n($totalLoss) ?> đ</b>
  <?php foreach ($loss as $k => $l): ?> · <?= e(D::REASONS[$k] ?? $k) ?> <b><?= n($l['value']) ?> đ</b> <small>(<?= n($l['qty']) ?> sp)</small><?php endforeach; ?></p>
<?php endif; ?>

<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <?php foreach (D::STATUS as $k => [$label]): ?>
  <a href="/disposals?<?= e(http_build_query(array_filter(['status' => $k, 'q' => $q]))) ?>" data-tone="<?= $tabTone[$k] ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($counts[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Mã phiếu, mặt hàng, số lô…" aria-label="Tìm kiếm">
</form>

<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã</th><th>Mặt hàng</th><th class="num">Số lượng</th><th>Lý do</th><th class="num">Giá trị vốn</th><th>Người lập</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $h): $st = D::STATUS[$h['trang_thai']]; ?>
    <tr data-tone="<?= $tabTone[$h['trang_thai']] ?>">
      <td class="nw"><b><?= e($h['ma']) ?></b><br><small class="muted"><?= e(dt_short($h['ngay_tao'])) ?> · <?= e($h['wh_code']) ?></small></td>
      <td><?= e($h['item_name']) ?><br><small class="muted"><?= e($h['ma_hang'] . $lotOf($h)) ?></small></td>
      <td class="num"><b><?= n($h['so_luong']) ?></b> <small class="muted"><?= e($h['unit']) ?></small></td>
      <td class="wrap"><?= e(D::REASONS[$h['ly_do']] ?? $h['ly_do']) ?><?= $h['ghi_chu'] !== '' ? '<br><small class="muted">' . e($h['ghi_chu']) . '</small>' : '' ?></td>
      <td class="num money"><?= (int)$h['gia_von'] > 0 ? n((int)$h['value']) . ' đ' : '<span class="muted">—</span>' ?></td>
      <td class="nw"><?= e($h['creator_name']) ?><?= $h['approver_name'] && $h['trang_thai'] !== 'CHO_DUYET' ? '<br><small class="muted">' . e($h['approver_name']) . ' ' . ($h['trang_thai'] === 'TU_CHOI' ? 'từ chối' : 'duyệt') . '</small>' : '' ?></td>
      <td class="nw">
        <?php if ($h['trang_thai'] === 'CHO_DUYET'): ?>
          <?php if ($canApprove): ?><form method="post" action="/disposals/<?= (int)$h['id'] ?>/approve" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit"><?= icon('check') ?>Duyệt</button></form><?php endif; ?>
          <?php if ($canApprove || $canPropose): ?><form method="post" action="/disposals/<?= (int)$h['id'] ?>/reject" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="btn-ghost"><?= $canApprove ? 'Từ chối' : 'Rút lại' ?></button></form><?php endif; ?>
        <?php elseif ($h['trang_thai'] === 'TRONG_KHO_HUY'): ?>
          <?php if ($canApprove): ?><form method="post" action="/disposals/<?= (int)$h['id'] ?>/destroy" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit">Đã tiêu huỷ</button></form><?php endif; ?>
          <?php if ($h['voucher_code']): ?><a href="/vouchers/<?= (int)$h['phieu_nhap_xuat_id'] ?>"><small>Phiếu <?= e($h['voucher_code']) ?></small></a><?php endif; ?>
        <?php elseif ($h['trang_thai'] === 'DA_TIEU_HUY'): ?><small class="muted"><?= e(dt_short($h['ngay_tieu_huy'])) ?></small>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty"><?= $q !== '' ? 'Không có phiếu huỷ phù hợp.' : 'Chưa có hàng nào ở mục này.' ?></td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>

<?php if ($canPropose): ?>
<dialog id="dispose-dlg" class="dlg" aria-labelledby="dispose-dlg-t">
  <form method="post" action="/disposals" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="dispose-dlg-t">Huỷ hàng</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <?php if ($myWarehouse): ?><?= wh_fixed($myWarehouse, 'Hàng của kho', 'wh') ?><?php endif; ?>
    <?php if (!$pick): ?><p class="muted">Kho đang không còn hàng để huỷ.</p><?php else: ?>
    <label>Mặt hàng / lô<select name="pick" required><option value="">Chọn mặt hàng / lô…</option>
      <?php foreach ($pick as $p): $exp = $p['han_dung'] && $p['han_dung'] < \StockFlow\Service\StockQuery::today(); ?>
      <option value="<?= (int)$p['mat_hang_id'] . ':' . (int)$p['lo_hang_id'] ?>"><?= e($p['ma_hang'] . ' — ' . $p['ten'] . $lotOf($p) . ($exp ? ' · ĐÃ HẾT HẠN' : '') . ' · tồn ' . $p['qty'] . ' ' . $p['unit']) ?></option>
      <?php endforeach; ?></select></label>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Số lượng huỷ<input type="number" name="qty" min="1" step="1" required></label>
      <label>Lý do<select name="reason" required><?php foreach (D::REASONS as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
    </div>
    <label>Ghi chú<input name="note" maxlength="255" placeholder="Rơi vỡ khi bốc xếp, thùng ướt…"></label>
    <p class="muted small"><?= $canApprove ? 'Bạn là người duyệt nên hàng vào kho huỷ và bị trừ tồn ngay.' : 'Chưa trừ tồn. Quản lý kho duyệt thì hàng mới rời tồn.' ?></p>
    <div class="actions"><button type="submit"><?= icon('check') ?><?= $canApprove ? 'Huỷ hàng' : 'Gửi đề xuất' ?></button><button type="button" class="btn-ghost" data-dialog-close>Đóng</button></div>
    <?php endif; ?>
  </form>
</dialog>
<?php endif; ?>
