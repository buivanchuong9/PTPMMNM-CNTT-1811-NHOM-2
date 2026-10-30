<?php /** @var array $rows @var array $counts @var int $total @var string $status @var bool $canReview @var bool $canPropose @var bool $canReceive @var array $vatRates */
use StockFlow\Service\ItemProposalService as I;
$tones = ['' => 'all', 'PENDING' => 'warn', 'APPROVED' => 'ok', 'REJECTED' => 'bad', 'CANCELLED' => 'bad'];
?>
<header class="page-head">
  <div><h1>Đề xuất hàng mới</h1></div>
  <?php if ($canPropose): ?><div class="page-actions"><button type="button" data-scan-open><?= icon('scan') ?>Quét mã vạch</button></div><?php endif; ?>
</header>
<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <a href="/proposals"<?= $status === '' ? ' class="active" aria-current="page"' : '' ?> data-tone="all">Tất cả <span class="count"><?= n($total) ?></span></a>
  <?php foreach (I::LABELS as $k => [$label]): ?>
  <a href="/proposals?status=<?= e($k) ?>" data-tone="<?= $tones[$k] ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($counts[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã vạch</th><th>SKU</th><th>Tên hàng</th><th>Loại hàng</th><th>Nhà cung cấp</th><th>Kho</th><th>Trạng thái</th><th>Người đề xuất</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $q): $id = (int)$q['id']; [$label, $tone] = I::LABELS[$q['trang_thai']]; ?>
    <tr data-tone="<?= $tones[$q['trang_thai']] ?>">
      <td><span class="barcode-tag"><?= e($q['ma_vach']) ?></span></td>
      <td><b><?= e($q['ma_hang']) ?></b></td>
      <td><?= e($q['ten']) ?><br><small class="muted"><?= e($q['unit']) ?><?= $q['quan_ly_lo'] ? ' · quản lý theo lô' : '' ?></small></td>
      <td><span class="tag tag-info"><?= e($q['category']) ?></span></td>
      <td class="wrap"><?= e($q['supplier']) ?></td>
      <td><span class="wh-code"><?= e($q['kho_ma']) ?></span></td>
      <td><span class="tag tag-<?= e($tone) ?>"><?= e($label) ?></span>
        <?php if ($q['trang_thai'] === 'REJECTED'): ?><br><small class="warn-text"><?= e($q['xu_ly_ten']) ?>: <?= e($q['ly_do']) ?></small>
        <?php elseif ($q['trang_thai'] === 'APPROVED'): ?><br><small class="muted"><?= e($q['xu_ly_ten']) ?> · <?= e(dt($q['ngay_xu_ly'])) ?></small><?php endif; ?></td>
      <td class="nw"><?= e($q['de_xuat_ten']) ?><br><small class="muted"><?= e(dt($q['ngay_tao'])) ?></small></td>
      <td class="nw row-actions">
        <?php if ($q['trang_thai'] === 'PENDING' && $canReview): ?>
          <button type="button" class="btn-sm" data-dialog-open="approve-dlg" data-fill-action="/proposals/<?= $id ?>/approve" data-fill-label="<?= e($q['ma_hang'] . ' — ' . $q['ten'] . ' (mã vạch ' . $q['ma_vach'] . ')') ?>">Chấp nhận</button>
          <button type="button" class="btn-ghost btn-sm" data-dialog-open="reject-dlg" data-fill-action="/proposals/<?= $id ?>/reject" data-fill-label="<?= e($q['ma_hang'] . ' — ' . $q['ten']) ?>">Từ chối</button>
        <?php elseif ($q['trang_thai'] === 'PENDING' && $canPropose): ?>
          <form method="post" action="/proposals/<?= $id ?>/cancel" class="inline" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit" class="btn-ghost btn-sm" data-confirm="Rút lại đề xuất <?= e($q['ma_hang']) ?>?">Rút lại</button></form>
        <?php elseif ($q['trang_thai'] === 'APPROVED' && $canReceive && $q['mat_hang_id']): ?>
          <a class="btn btn-sm" href="/vouchers/new?type=RECEIPT&item=<?= (int)$q['mat_hang_id'] ?>">Lập phiếu nhập</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="empty">Chưa có đề xuất nào.</td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>

<?php if ($canReview): ?>
<dialog id="approve-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="approve-dlg-t">
  <form method="post" action="/proposals" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="approve-dlg-t">Chấp nhận đề xuất</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted" data-dlg-label></p>
    <label>Giá gốc (đồng / 1 đơn vị tính)<input name="cost" required inputmode="numeric" placeholder="VD: 21500" autocomplete="off"></label>
    <label>Thuế suất<select name="vat"><?php foreach ($vatRates as $v): ?><option value="<?= (int)$v ?>"<?= $v === 10 ? ' selected' : '' ?>><?= (int)$v ?>%</option><?php endforeach; ?></select></label>
    <p class="muted small">Mặt hàng mới được tạo kèm mã vạch và nhà cung cấp đã đề xuất. Giá bán do quản lý từng kho đặt sau.</p>
    <div class="actions"><button type="submit">Chấp nhận và tạo mặt hàng</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<dialog id="reject-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="reject-dlg-t">
  <form method="post" action="/proposals" class="form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="reject-dlg-t">Từ chối đề xuất</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <p class="muted" data-dlg-label></p>
    <label>Lý do từ chối<input name="reason" required maxlength="200" placeholder="Hàng ngoài danh mục kinh doanh, trùng mặt hàng đã có…" autocomplete="off"></label>
    <div class="actions"><button type="submit" class="btn-danger">Từ chối</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
  </form>
</dialog>
<?php endif; ?>
