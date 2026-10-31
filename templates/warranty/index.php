<?php /** @var array $rows @var array $counts @var array $items @var string $tab @var string $q @var array $f @var array $keep @var string $today @var bool $canHandle */
use StockFlow\Service\WarrantyService as W;
$tabs = ['TIEP_NHAN' => ['Đang giữ', 'warn'], 'WAITING' => ['Chờ trả khách', 'info'], 'DA_TRA' => ['Đã trả khách', 'all']];
?>
<header class="page-head">
  <div><h1>Bảo hành</h1></div>
  <?php if ($canHandle): ?><div class="actions"><button type="button" data-dialog-open="warranty-dlg"><?= icon('plus') ?>Nhận hàng bảo hành</button></div><?php endif; ?>
</header>

<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <?php foreach ($tabs as $k => [$label, $tone]): ?>
  <a href="/warranty?<?= e(http_build_query(['tab' => $k] + $keep)) ?>" data-tone="<?= $tone ?>"<?= $tab === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($counts[$k]) ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Mã phiếu, khách, SĐT, mã lô…" style="min-width:230px" aria-label="Tìm kiếm">
<?php if (count($f['whOpts']) > 1): ?>  <select name="warehouse"<?= $f['warehouse'] ? ' class="on"' : '' ?> aria-label="Kho"><?= filter_options($f['whOpts'], $f['warehouse'], 'Mọi kho') ?></select>
<?php endif; ?>
  <select name="item"<?= $f['item'] ? ' class="on"' : '' ?> aria-label="Mặt hàng" data-combo><?= filter_options($f['itemOpts'], $f['item'], 'Mọi mặt hàng') ?></select>
  <select name="valid"<?= $f['valid'] ? ' class="on"' : '' ?> aria-label="Hạn bảo hành"><?= filter_options(['yes' => 'Còn hạn', 'no' => 'Hết hạn'], $f['valid'], 'Mọi hạn bảo hành') ?></select>
<?php if ($tab === 'WAITING'): ?>  <select name="result"<?= $f['result'] ? ' class="on"' : '' ?> aria-label="Kết quả"><?= filter_options(array_map(fn($k) => W::STATUS[$k][0], array_combine(W::WAITING, W::WAITING)), $f['result'], 'Mọi kết quả') ?></select>
<?php endif; ?>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
  <select name="sort"<?= $f['sort'] ? ' class="on"' : '' ?> aria-label="Sắp xếp"><?= filter_options(['old' => 'Cũ nhất trước', 'expiry' => 'Sắp hết hạn trước'], $f['sort'], 'Mới nhất trước') ?></select>
  <?php if (array_diff_key($keep, ['q' => 1]) || $f['result']): ?><a class="btn btn-ghost" href="/warranty?<?= e(http_build_query(['tab' => $tab])) ?>">Xoá lọc</a><?php endif; ?>
</form>

<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã</th><th>Mặt hàng</th><th>Khách</th><th title="Ngày nhận → ngày hẹn trả">Nhận → hẹn trả</th><th>Bảo hành đến</th><th>Lỗi</th><th><?= $tab === 'TIEP_NHAN' ? 'Xử lý' : 'Kết quả' ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $b): $st = W::STATUS[$b['trang_thai']]; ?>
    <tr data-tone="<?= $b['trang_thai'] === 'TIEP_NHAN' ? 'warn' : ($b['trang_thai'] === 'TU_CHOI' ? 'bad' : 'ok') ?>">
      <td class="nw"><b><?= e($b['ma']) ?></b><br><small class="muted"><?= e(dt_short($b['ngay_tao'])) ?> · <?= e($b['wh_code']) ?></small></td>
      <td><?= e($b['item_name']) ?> <small class="muted">× <?= n($b['so_luong']) ?> <?= e($b['unit']) ?></small><br><small class="muted"><?= e($b['ma_hang']) ?><?= $b['sale_item'] ? ' · ' . e($b['sale_item']) : ($b['ma_lo'] ? ' · cả lô ' . e($b['ma_lo']) : '') ?></small></td>
      <td><?= e($b['khach_ten']) ?><?= $b['khach_sdt'] !== '' ? '<br><small class="muted">' . e($b['khach_sdt']) . '</small>' : '' ?>
      </td>
      <td class="nw"><?php if ($b['ngay_nhan']): ?><?= e(date('d/m', strtotime($b['ngay_nhan']))) ?><?php if ($b['ngay_hen_tra']): $late = $b['trang_thai'] === 'TIEP_NHAN' && $b['ngay_hen_tra'] < $today; ?> → <b<?= $late ? ' class="neg"' : '' ?>><?= e(date('d/m', strtotime($b['ngay_hen_tra']))) ?></b><?= $late ? '<br><small class="neg">Quá hẹn</small>' : '' ?><?php endif; ?><?php else: ?>—<?php endif; ?></td>
      <td class="nw"><?= e(dt($b['het_han'], false)) ?><br><?= (int)$b['con_han'] ? '<span class="tag tag-ok">Còn hạn</span>' : '<span class="tag tag-danger">Hết hạn</span>' ?></td>
      <td class="wrap"><?= e($b['loi']) ?></td>
      <td class="wrap">
        <?php if ($b['trang_thai'] === 'TIEP_NHAN'): ?>
          <?php if ($canHandle): ?>
          <form method="post" action="/warranty/<?= (int)$b['id'] ?>/resolve" class="inline-form warranty-act" style="flex-wrap:wrap;align-items:center" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input name="note" maxlength="255" placeholder="Ghi chú (bắt buộc nếu từ chối)" aria-label="Ghi chú xử lý">
            <button type="submit" name="result" value="DA_SUA">Sửa xong</button>
            <?php if ((int)$b['con_han']): ?><button type="submit" name="result" value="DOI_MOI" class="btn-ghost" title="Lấy 1 đơn vị từ tồn kho đổi cho khách">Đổi mới</button><?php endif; ?>
            <button type="submit" name="result" value="TU_CHOI" class="btn-ghost">Từ chối</button>
          </form>
          <?php else: ?><span class="tag tag-<?= $st[1] ?>"><?= e($st[0]) ?></span><?php endif; ?>
        <?php else: ?>
          <span class="tag tag-<?= $st[1] ?>"><?= e($st[0]) ?></span>
          <?= $b['ket_qua'] !== '' ? '<small class="muted"> · ' . e($b['ket_qua']) . '</small>' : '' ?>
          <?php if ($b['voucher_code']): ?><br><a href="/vouchers/<?= (int)$b['phieu_nhap_xuat_id'] ?>"><small>Phiếu trừ kho <?= e($b['voucher_code']) ?></small></a><?php endif; ?>
          <?php if ($b['trang_thai'] === 'DA_TRA'): ?><br><small class="muted">Trả <?= e(dt_short($b['ngay_tra'])) ?></small>
          <?php elseif ($canHandle): ?>
          <form method="post" action="/warranty/<?= (int)$b['id'] ?>/return" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit"><?= icon('check') ?>Trả khách</button></form>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty"><?= $keep || $f['result'] ? 'Không có phiếu bảo hành phù hợp.' : 'Chưa có hàng nào ở mục này.' ?></td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>

<?php if ($canHandle): ?>
<dialog id="warranty-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="warranty-dlg-t">
  <form method="post" action="/warranty" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="warranty-dlg-t">Nhận hàng bảo hành</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <?php if ($myWarehouse): ?><?= wh_fixed($myWarehouse, 'Nhận tại kho', 'wh') ?><?php endif; ?>
    <?php if (!$items): ?><p class="muted">Chưa có mặt hàng nào được đặt thời hạn bảo hành. Quản trị viên đặt ở <a href="/inventory">Hàng hoá → Sửa</a>.</p><?php else: ?>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Mã lô hàng<input name="lot_code" maxlength="20" autocomplete="off" spellcheck="false" placeholder="LB-XXXXXXXX" data-sale-lot></label>
      <label>Mã món hàng (bỏ trống = cả lô)<input name="item_code" maxlength="20" autocomplete="off" spellcheck="false" placeholder="SP-XXXXXXXX" data-sale-item></label>
    </div>
    <p class="sale-lookup muted small" data-sale-info>Chỉ nhập mã lô hàng: nhận bảo hành cả lô. Nhập thêm mã món hàng: chỉ bảo hành đúng chiếc đó. Mặt hàng, số lượng và hạn bảo hành tự lấy theo mã.</p>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Tên khách<input name="customer" maxlength="120" required></label>
      <label>Điện thoại<input name="phone" maxlength="20" inputmode="tel" required></label>
    </div>
    <label>Địa chỉ khách <span class="muted">(không bắt buộc)</span><input name="address" maxlength="255"></label>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Ngày nhận bảo hành<input type="date" name="received_on" value="<?= e($today) ?>" max="<?= e($today) ?>" required data-warranty-from></label>
      <label>Hẹn trả khách (ước lượng)<input type="date" name="due_on" value="<?= e(date('Y-m-d', strtotime($today . ' +7 days'))) ?>" min="<?= e($today) ?>" data-warranty-due></label>
    </div>
    <label>Tình trạng lỗi<input name="defect" maxlength="255" required placeholder="Không lên nguồn, vỡ màn hình…"></label>
    <p class="muted small">Mặt hàng phải có nhà sản xuất / nhà cung cấp. Quá hạn bảo hành vẫn nhận được, nhưng không đổi mới miễn phí. Hàng của khách không tính vào tồn kho.</p>
    <div class="actions"><button type="submit"><?= icon('check') ?>Nhận hàng</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
    <?php endif; ?>
  </form>
</dialog>
<?php endif; ?>
