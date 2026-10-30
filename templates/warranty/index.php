<?php /** @var array $rows @var array $counts @var array $items @var string $tab @var string $q @var string $today @var bool $canHandle */
use StockFlow\Service\WarrantyService as W;
$tabs = ['TIEP_NHAN' => ['Đang giữ', 'warn'], 'WAITING' => ['Chờ trả khách', 'info'], 'DA_TRA' => ['Đã trả khách', 'all']];
?>
<header class="page-head">
  <div><h1>Bảo hành</h1><p class="muted small">Hàng khách mang đến để sửa hoặc đổi. Chỉ mặt hàng có thời hạn bảo hành mới nhận.</p></div>
  <?php if ($canHandle): ?><div class="actions"><button type="button" data-dialog-open="warranty-dlg"><?= icon('plus') ?>Nhận hàng bảo hành</button></div><?php endif; ?>
</header>

<nav class="tabs" aria-label="Trạng thái" data-live="tabs">
  <?php foreach ($tabs as $k => [$label, $tone]): ?>
  <a href="/warranty?<?= e(http_build_query(array_filter(['tab' => $k, 'q' => $q]))) ?>" data-tone="<?= $tone ?>"<?= $tab === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($counts[$k]) ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Mã phiếu, khách, số điện thoại, mặt hàng…" aria-label="Tìm kiếm">
</form>

<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Mã</th><th>Mặt hàng</th><th>Khách</th><th>Bảo hành đến</th><th>Lỗi</th><th><?= $tab === 'TIEP_NHAN' ? 'Xử lý' : 'Kết quả' ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $b): $st = W::STATUS[$b['trang_thai']]; ?>
    <tr data-tone="<?= $b['trang_thai'] === 'TIEP_NHAN' ? 'warn' : ($b['trang_thai'] === 'TU_CHOI' ? 'bad' : 'ok') ?>">
      <td class="nw"><b><?= e($b['ma']) ?></b><br><small class="muted"><?= e(dt_short($b['ngay_tao'])) ?> · <?= e($b['wh_code']) ?></small></td>
      <td><?= e($b['item_name']) ?> <small class="muted">× <?= n($b['so_luong']) ?> <?= e($b['unit']) ?></small><br><small class="muted"><?= e($b['ma_hang']) ?></small></td>
      <td><?= e($b['khach_ten']) ?><?= $b['khach_sdt'] !== '' ? '<br><small class="muted">' . e($b['khach_sdt']) . '</small>' : '' ?></td>
      <td class="nw"><?= e(dt($b['het_han'], false)) ?><br><?= (int)$b['con_han'] ? '<span class="tag tag-ok">Còn hạn</span>' : '<span class="tag tag-danger">Hết hạn</span>' ?></td>
      <td class="wrap"><?= e($b['loi']) ?></td>
      <td class="wrap">
        <?php if ($b['trang_thai'] === 'TIEP_NHAN'): ?>
          <?php if ($canHandle): ?>
          <form method="post" action="/warranty/<?= (int)$b['id'] ?>/resolve" class="inline-form" style="flex-wrap:wrap;align-items:center" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
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
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty"><?= $q !== '' ? 'Không có phiếu bảo hành phù hợp.' : 'Chưa có hàng nào ở mục này.' ?></td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>

<?php if ($canHandle): ?>
<dialog id="warranty-dlg" class="dlg dlg-form-wide dlg-xl" aria-labelledby="warranty-dlg-t">
  <form method="post" action="/warranty" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="dlg-head"><h2 id="warranty-dlg-t">Nhận hàng bảo hành</h2><button type="button" class="icon-btn" data-dialog-close aria-label="Đóng">✕</button></div>
    <?php if ($myWarehouse): ?><?= wh_fixed($myWarehouse, 'Nhận tại kho', 'wh') ?><?php endif; ?>
    <?php if (!$items): ?><p class="muted">Chưa có mặt hàng nào được đặt thời hạn bảo hành. Quản trị viên đặt ở <a href="/inventory">Hàng hoá → Sửa</a>.</p><?php else: ?>
    <label>Mặt hàng<select name="item_id" required><option value="">Chọn mặt hàng…</option><?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['ma_hang'] . ' — ' . $i['ten'] . ' (BH ' . $i['bao_hanh_thang'] . ' tháng)') ?></option><?php endforeach; ?></select></label>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Số lượng<input type="number" name="qty" min="1" max="1000" value="1" required></label>
      <label>Mã phiếu mua (phiếu xuất bán)<input name="phieu_mua" maxlength="30" required autocomplete="off" placeholder="vd: PX-261030-00001"></label>
    </div>
    <div class="row-3" style="grid-template-columns:1fr 1fr">
      <label>Tên khách<input name="customer" maxlength="120" required></label>
      <label>Điện thoại<input name="phone" maxlength="20" inputmode="tel"></label>
    </div>
    <label>Tình trạng lỗi<input name="defect" maxlength="255" required placeholder="Không lên nguồn, vỡ màn hình…"></label>
    <p class="muted small">Ngày mua lấy theo phiếu mua; mặt hàng phải có nhà sản xuất / nhà cung cấp. Quá hạn bảo hành vẫn nhận được, nhưng không đổi mới miễn phí. Hàng của khách không tính vào tồn kho.</p>
    <div class="actions"><button type="submit"><?= icon('check') ?>Nhận hàng</button><button type="button" class="btn-ghost" data-dialog-close>Huỷ</button></div>
    <?php endif; ?>
  </form>
</dialog>
<?php endif; ?>
