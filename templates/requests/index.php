<?php /** @var array $rows @var array $counts @var int $total @var string $status @var bool $canCreate @var bool $isManager */
use StockFlow\Service\TransferRequestService as T;
$tones = ['' => 'all', 'WAIT_OWN' => 'warn', 'WAIT_SOURCE' => 'info', 'ACCEPTED' => 'ok', 'REJECTED' => 'bad', 'CANCELLED' => 'bad'];
$mineId = (int)$user->warehouseId;
?>
<header class="page-head">
  <div><h1>Yêu cầu chuyển hàng</h1></div>
</header>
<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <a href="/requests"<?= $status === '' ? ' class="active" aria-current="page"' : '' ?> data-tone="all">Tất cả <span class="count"><?= n($total) ?></span></a>
  <?php foreach (T::LABELS as $k => [$label]): ?>
  <a href="/requests?status=<?= e($k) ?>" data-tone="<?= $tones[$k] ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($counts[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã</th><th>Kho cấp → Kho nhận</th><th>Hàng</th><th>Trạng thái</th><th>Người lập</th><th>Lúc tạo</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $q):
    $id = (int)$q['id']; $isNhan = $mineId === (int)$q['kho_nhan_id']; $isCap = $mineId === (int)$q['kho_cap_id'];
    [$label, $tone] = T::LABELS[$q['trang_thai']];
    $canAct = $isManager && $user->warehouseId !== null;
    $canCancel = in_array($q['trang_thai'], ['WAIT_OWN', 'WAIT_SOURCE'], true) && $isNhan && ($canAct || (int)$q['nguoi_lap_id'] === $user->id);
    $sum = implode(', ', array_map(fn($l) => $l['ma_hang'] . ' × ' . n($l['so_luong']) . ($l['quan_ly_lo'] ? ' (lô ' . $l['so_lo'] . ')' : ''), $q['lines'])); ?>
    <tr data-tone="<?= $tones[$q['trang_thai']] ?>"<?= is_new($changes, $id) ? ' class="is-new"' : '' ?>>
      <td><b><?= e($q['ma']) ?></b><?= new_tag($changes, $id) ?></td>
      <td class="nw"><span class="wh-code"><?= e($q['cap_ma']) ?></span> <span class="arrow">→</span> <span class="wh-code"><?= e($q['nhan_ma']) ?></span></td>
      <td class="wrap" title="<?= e($sum) ?>"><?= e(mb_strimwidth($sum, 0, 90, '…')) ?><?= $q['ghi_chu'] !== '' ? '<br><small class="muted">' . e($q['ghi_chu']) . '</small>' : '' ?></td>
      <td><span class="tag tag-<?= e($tone) ?>"><?= e($label) ?></span>
        <?php if ($q['trang_thai'] === 'REJECTED'): ?><br><small class="warn-text"><?= e($q['xu_ly_ten']) ?> từ chối: <?= e($q['ly_do']) ?></small>
        <?php elseif ($q['trang_thai'] === 'ACCEPTED' && $q['phieu_id']):   // mỗi kho chỉ xem phiếu của kho mình: kho nhận ⇒ phiếu nhận (DCN), kho cấp / quản trị ⇒ phiếu xuất (DCX)
              [$vid, $vma] = $isNhan && !$isCap ? [$q['phieu_nhan_id'], $q['phieu_nhan_ma']] : [$q['phieu_id'], $q['phieu_ma']]; ?>
          <br><?php if ($vid): ?><a href="/vouchers/<?= (int)$vid ?>"><small>Phiếu <?= e($vma) ?></small></a><?php else: ?><small class="muted">Kho cấp đang chuẩn bị xuất hàng</small><?php endif; ?>
        <?php elseif ($q['trang_thai'] === 'CANCELLED' && $q['ly_do'] !== ''): ?><br><small class="muted"><?= e($q['ly_do']) ?></small><?php endif; ?></td>
      <td class="nw"><?= e($q['lap_ten']) ?><?= $q['duyet_ten'] && (int)$q['nguoi_duyet_id'] !== (int)$q['nguoi_lap_id'] ? ' <span class="arrow">→</span> ' . e($q['duyet_ten']) : '' ?></td>
      <td class="muted"><?= e(dt($q['ngay_tao'])) ?></td>
      <td class="nw row-actions">
        <?php if ($q['trang_thai'] === 'WAIT_OWN' && $isNhan && $canAct): ?>
          <form method="post" action="/requests/<?= $id ?>/approve" class="inline" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="btn-sm">Xác nhận gửi</button></form>
        <?php elseif ($q['trang_thai'] === 'WAIT_SOURCE' && $isCap && $canAct): ?>
          <form method="post" action="/requests/<?= $id ?>/accept" class="inline" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="btn-sm">Chấp nhận</button></form>
          <button type="button" class="btn-ghost btn-sm" data-dialog-open="reject-dlg" data-fill-action="/requests/<?= $id ?>/reject" data-fill-label="<?= e($q['ma'] . ' — ' . $q['nhan_ma'] . ' xin hàng') ?>">Từ chối</button>
        <?php endif; ?>
        <?php if ($canCancel): ?>
          <form method="post" action="/requests/<?= $id ?>/cancel" class="inline" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="btn-ghost btn-sm" data-confirm="Huỷ yêu cầu <?= e($q['ma']) ?>?">Huỷ</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">Chưa có yêu cầu nào.</td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>

<?php if ($isManager): ?>
<dialog id="reject-dlg" class="dlg" aria-labelledby="reject-dlg-t">
  <form method="post" action="/requests" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="reject-dlg-t">Từ chối yêu cầu</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted" data-dlg-label></p>
    <label>Lý do từ chối<input name="reason" required maxlength="200" placeholder="Hết hàng, hàng đã giữ cho đơn khác…" autocomplete="off"></label>
    <div class="actions"><button type="submit" class="btn-danger">Từ chối</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
