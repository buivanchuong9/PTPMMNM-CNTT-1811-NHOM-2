<?php /** @var array $rows @var array $counts @var array $loss @var array $expired @var array $pick @var string $status @var string $q @var bool $canPropose @var bool $canApprove @var list<array> $vehicles @var list<array> $drivers @var ?array $site @var array $driverFit @var array $loadBy @var array $fitBy @var array $recBy */
use StockFlow\Service\DisposalService as D;
$totalLoss = array_sum(array_column($loss, 'value'));
$tabTone = ['CHO_DUYET' => 'warn', 'TRONG_KHO_HUY' => 'info', 'DA_TIEU_HUY' => 'all', 'TU_CHOI' => 'bad'];
$lotOf = fn(array $r) => $r['quan_ly_lo'] ? ' · lô ' . $r['so_lo'] . ($r['han_dung'] ? ' (HSD ' . dt($r['han_dung'], false) . ')' : '') : '';
?>
<header class="page-head">
  <div><h1>Kho huỷ</h1></div>
  <div class="actions">
    <?php if ($canApprove): ?><button type="button" class="btn-ghost" data-dialog-open="site-dlg"><?= icon('pin') ?>Nơi tiêu huỷ</button><?php endif; ?>
    <?php if ($canPropose): ?><button type="button" data-dialog-open="dispose-dlg"><?= icon('plus') ?>Huỷ hàng</button><?php endif; ?></div>
</header>
<?php if (!empty($site)): ?><p class="muted small" style="margin:0 0 12px">Hàng huỷ của kho được giao đến: <b><?= e($site['name']) ?></b> — <?= e($site['address']) ?><?= $site['custom'] ? '' : ' (mặc định)' ?></p><?php endif; ?>

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
        <?php elseif ($h['trang_thai'] === 'TRONG_KHO_HUY' && $h['xe_id'] === null): ?>
          <?php if ($canApprove): ?>
          <?php $load = $loadBy[(int)$h['id']] ?? null; $fit = $fitBy[(int)$h['id']] ?? []; $recommended = $recBy[(int)$h['id']] ?? null; require __DIR__ . '/../partials/load_summary.php'; ?>
          <form method="post" action="/disposals/<?= (int)$h['id'] ?>/load" class="inline-form" style="flex-wrap:wrap;align-items:center" data-once data-crew="<?= e(json_encode(\StockFlow\Domain\TransportRules::coverTable())) ?>"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <select name="vehicle_id" required aria-label="Xe chở hàng"><option value="">Chọn xe…</option>
              <?php foreach ($vehicles as $x): $ft = $fit[(int)$x['id']] ?? null;
                $note = !$ft ? '' : ((int)$x['id'] === (int)$recommended && !$ft['hard'] && !$ft['load'] ? ' — ★ Khuyên dùng' : ($ft['hard'] ? ' — ✘ ' . $ft['hard'][0] : ($ft['load'] ? ' — ⚠ không đủ tải' : ($ft['warns'] ? ' — ⚠ chưa khai đủ thông số' : ' — ✔ đủ điều kiện')))); ?>
              <option value="<?= (int)$x['id'] ?>" data-need="<?= e((string)\StockFlow\Domain\TransportRules::needFor($x)) ?>"<?= $ft && ($ft['hard'] || $ft['load']) ? ' disabled' : '' ?><?= $ft ? ' title="' . e(implode(' · ', array_merge($ft['hard'], $ft['load'], $ft['warns']))) . '"' : '' ?>><?= e($x['bien_so'] . ($x['loai_xe'] ? ' · ' . $x['loai_xe'] : '') . ((int)($x['tai_trong_kg'] ?? 0) > 0 ? ' · ' . ((int)$x['tai_trong_kg'] < 1000 ? (int)$x['tai_trong_kg'] . ' kg' : rtrim(rtrim(number_format($x['tai_trong_kg'] / 1000, 2, ',', '.'), '0'), ',') . ' tấn') : '') . (($nd = \StockFlow\Domain\TransportRules::needFor($x)) ? ' · cần bằng ' . $nd : '') . $note) ?></option>
              <?php endforeach; ?></select>
            <select name="driver_id" required aria-label="Tài xế"><option value="">Chọn tài xế…</option>
              <?php foreach ($drivers as $dr): $ft = $driverFit[(int)$dr['id']] ?? ['hard' => [], 'class' => '', 'age' => null]; ?>
              <option value="<?= (int)$dr['id'] ?>" data-class="<?= e($ft['class']) ?>" data-bad="<?= e($ft['hard'][0] ?? '') ?>"><?= e($dr['ho_ten'] . ($ft['class'] !== '' ? ' — bằng ' . $ft['class'] : ' — chưa khai bằng')) ?></option>
              <?php endforeach; ?></select>
            <label class="check"><input type="checkbox" name="driver_ok" value="1" required> Tài xế đã nhận hàng lên xe</label>
            <button type="submit"><?= icon('truck') ?>Xuất đi tiêu huỷ</button></form>
          <small class="muted">Giao đến: <?= e($site['name'] ?? '') ?> · <?= e($site['address'] ?? '') ?></small>
          <?php if ($canApprove): ?><form method="post" action="/disposals/<?= (int)$h['id'] ?>/undo" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="btn-ghost" data-confirm="Trả hàng về tồn kho và không huỷ nữa?">↩ Quay lại</button></form><?php endif; ?>
          <?php endif; ?>
          <?php if ($h['voucher_code']): ?><a href="/vouchers/<?= (int)$h['phieu_nhap_xuat_id'] ?>"><small>Phiếu <?= e($h['voucher_code']) ?></small></a><?php endif; ?>
        <?php elseif ($h['trang_thai'] === 'TRONG_KHO_HUY'): ?>
          <small class="muted"><?= icon('truck') ?> Đang trên xe <b><?= e($h['bien_so']) ?></b> · <?= e($h['tai_xe_ten']) ?> · đi <?= e(dt_short($h['ngay_xuat'])) ?><br>Đến: <?= e($h['don_vi_tieu_huy']) ?> · <?= e($h['dia_chi_tieu_huy']) ?></small>
          <?php if ($canApprove): ?><form method="post" action="/disposals/<?= (int)$h['id'] ?>/destroy" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" data-confirm="Xe đã đến nơi tiêu huỷ? Hàng sẽ được ghi nhận đã tiêu huỷ."><?= icon('check') ?>Xác nhận đã đến — tiêu huỷ</button></form><?php endif; ?>
          <?php if ($canApprove): ?><form method="post" action="/disposals/<?= (int)$h['id'] ?>/undo" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="btn-ghost" data-confirm="Hàng xuống xe, nằm lại kho huỷ?">↩ Quay lại</button></form><?php endif; ?>
        <?php elseif ($h['trang_thai'] === 'DA_TIEU_HUY'): ?><small class="muted"><?= e(dt_short($h['ngay_tieu_huy'])) ?><?= $h['dia_chi_tieu_huy'] !== '' ? '<br>' . e($h['don_vi_tieu_huy']) . ' · ' . e($h['dia_chi_tieu_huy']) : '' ?></small>
          <?php if ($canApprove): ?><form method="post" action="/disposals/<?= (int)$h['id'] ?>/undo" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="btn-ghost" data-confirm="Bấm nhầm? Hàng quay lại trạng thái đang trên xe.">↩ Quay lại</button></form><?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty"><?= $q !== '' ? 'Không có phiếu huỷ phù hợp.' : 'Chưa có hàng nào ở mục này.' ?></td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>

<?php if ($canApprove && !empty($site)): ?>
<dialog id="site-dlg" class="dlg dlg-form-wide" aria-labelledby="site-dlg-t">
  <form method="post" action="/disposals/site" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="site-dlg-t">Nơi tiêu huỷ</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <label>Tên đơn vị / bãi tiêu huỷ<input name="noi" maxlength="120" value="<?= e($site['custom'] ? $site['name'] : '') ?>" placeholder="<?= e($site['name']) ?>"></label>
    <label>Địa chỉ<input name="dia_chi" maxlength="255" value="<?= e($site['custom'] ? $site['address'] : '') ?>" placeholder="<?= e($site['address']) ?>"></label>
    <p class="muted small">Để trống cả hai ô thì dùng nơi mặc định. Hàng đã lên xe vẫn đi đến nơi đã chốt lúc xuất.</p>
    <div class="actions"><button type="submit"><?= icon('check') ?>Lưu</button><button type="button" class="btn-ghost" data-dialog-close>Đóng</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($canPropose): ?>
<dialog id="dispose-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="dispose-dlg-t">
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
