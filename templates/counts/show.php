<?php /** @var array $c */
$open = $c['trang_thai'] === 'OPEN';
$review = $c['trang_thai'] === 'REVIEW';
$editable = $open && $canCount;
$net = $tot['net']; $varN = $tot['var'];
?>
<header class="page-head">
  <div><p class="crumb"><a href="/counts">← Kiểm kê</a></p><h1><?= e($c['ma']) ?> <?= status_tag($c['trang_thai']) ?></h1>
    <p class="muted"><span class="wh-code"><?= e($c['wh_code']) ?></span> <?= e($c['wh_name']) ?> · mở <?= e(dt($c['ngay_tao'])) ?> bởi <?= e($c['creator_name']) ?><?= $c['ghi_chu'] !== '' ? ' · ' . e($c['ghi_chu']) : '' ?>
    <?php if ($c['adj_code']): ?> · <a href="/vouchers/<?= (int)$c['phieu_nhap_xuat_dieu_chinh_id'] ?>">Phiếu điều chỉnh <b><?= e($c['adj_code']) ?></b></a><?php endif; ?></p></div>
</header>
<?php if (!empty($cancel)): ?><div class="flash flash-muted"><?= icon('alert') ?><span>Đợt đã bị huỷ bởi <b><?= e($cancel['by'] ?? '—') ?></b> lúc <?= e(dt($cancel['at'])) ?>. Lý do: <?= e($cancel['reason'] !== '' ? $cancel['reason'] : 'không ghi') ?>.</span></div><?php endif; ?>
<form class="filters" method="get" data-count-search>
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="64" data-search-clean value="<?= e($q) ?>" placeholder="Quét mã vạch hoặc gõ SKU, tên hàng, số lô…" aria-label="Tìm dòng kiểm kê">
  <?php if ($auth->allows($user, \StockFlow\Domain\Permission::XemTonKho) || $auth->allows($user, \StockFlow\Domain\Permission::XemDanhMuc)): ?><button type="button" class="icon-btn" data-scan-fill="[data-count-search] [name=q]" title="Quét mã vạch bằng camera" aria-label="Quét mã vạch bằng camera"><?= icon('scan') ?></button><?php endif; ?>
  <?php if ($q !== ''): ?><a class="btn btn-ghost" href="/counts/<?= (int)$c['id'] ?>">Xoá tìm kiếm</a><?php endif; ?>
</form>
<form method="post" class="card flush" data-count-form<?= $editable ? ' data-save="/counts/' . (int)$c['id'] . '/save" data-base-net="' . (int)$base['net'] . '" data-base-var="' . (int)$base['var'] . '" data-base-bad="' . (int)$base['bad'] . '"' : '' ?> data-once>
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="goto" value="">
  <div class="summary-strip"><span><b data-var-lines><?= n($varN) ?></b> dòng lệch</span><span>Chênh lệch ròng <b data-var-net class="<?= $net > 0 ? 'pos' : ($net < 0 ? 'neg' : '') ?>"><?= signed($net) ?></b></span><span><b data-bad-total class="<?= $tot['bad'] ? 'neg' : '' ?>"><?= n($tot['bad']) ?></b> không đạt chất lượng</span><span class="muted small">Chỉ nhập những dòng <b>có thay đổi</b> — dòng để trống coi như khớp. <b>Thực đếm</b> là số hàng đang có; <b>Chất lượng</b> là phần trong đó đã hết hạn, vỡ, hỏng… — khi duyệt, phần này được trừ khỏi tồn và chuyển vào <a href="/disposals">Kho huỷ</a>. Số đã nhập ở trang này được lưu khi bạn chuyển trang.</span></div>
  <div class="table-wrap"><table class="dense count-table">
    <thead><tr><th>SKU</th><th>Tên hàng</th><th>Lô</th><th class="num">Sổ sách</th><th class="num">Thực đếm</th><th class="num">Chênh lệch</th><th>Chất lượng (không đạt)</th><th class="num">Bán được</th><th>Lý do chênh lệch</th></tr></thead>
    <tbody>
    <?php foreach ($c['lines'] as $l): $key = $l['mat_hang_id'] . ':' . $l['lo_hang_id'];
      $d = $l['so_luong_thuc_dem'] === null ? null : (int)$l['so_luong_thuc_dem'] - (int)$l['so_luong_so_sach'];
      [$cat, $note] = array_pad(explode(': ', (string)$l['ly_do'], 2), 2, ''); ?>
      <tr data-system="<?= (int)$l['so_luong_so_sach'] ?>">
        <td><b><?= e($l['ma_hang']) ?></b></td><td><?= e($l['ten']) ?><?= $l['ma_vach'] ? '<br><small class="muted">' . e($l['ma_vach']) . '</small>' : '' ?></td><td><?= $l['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($l['so_lo']) ?></td>
        <td class="num"><?= n($l['so_luong_so_sach']) ?></td>
        <td class="num"><?php if ($editable): ?><input class="qty-input count-input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="7" autocomplete="off" name="counted[<?= e($key) ?>]" placeholder="<?= (int)$l['so_luong_so_sach'] ?>" value="<?= $l['so_luong_thuc_dem'] === null ? '' : (int)$l['so_luong_thuc_dem'] ?>" aria-label="Thực đếm <?= e($l['ma_hang']) ?>"><small class="count-warn" role="status" hidden></small><?php else: echo $l['so_luong_thuc_dem'] === null ? '<span class="muted">khớp</span>' : n($l['so_luong_thuc_dem']); endif; ?></td>
        <td class="num variance <?= $d === null || $d === 0 ? '' : ($d > 0 ? 'pos' : 'neg') ?>"><?= $d === null ? '<span class="muted">khớp</span>' : ($d === 0 ? '✓' : signed($d)) ?></td>
        <?php $bad = (int)$l['so_luong_hong']; $have = $l['so_luong_thuc_dem'] === null ? (int)$l['so_luong_so_sach'] : (int)$l['so_luong_thuc_dem']; ?>
        <td class="quality-cell"><?php if ($editable): ?>
          <input class="qty-input bad-input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="7" autocomplete="off" name="bad_qty[<?= e($key) ?>]" placeholder="0" value="<?= $bad ?: '' ?>" aria-label="Không đạt chất lượng <?= e($l['ma_hang']) ?>">
          <select name="bad_reason[<?= e($key) ?>]" class="bad-reason" aria-label="Tình trạng <?= e($l['ma_hang']) ?>"<?= $bad ? '' : ' hidden' ?>><option value="">Tình trạng…</option><?php foreach ($badReasons as $k => $label): ?><option value="<?= e($k) ?>"<?= $l['ly_do_hong'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
          <input name="bad_note[<?= e($key) ?>]" class="bad-note" maxlength="200" placeholder="Ghi chú" value="<?= e($l['ghi_chu_hong']) ?>"<?= $bad ? '' : ' hidden' ?>>
        <?php elseif ($bad): ?><span class="tag tag-danger"><?= n($bad) ?></span> <small class="muted"><?= e($badReasons[$l['ly_do_hong']] ?? $l['ly_do_hong']) ?><?= $l['ghi_chu_hong'] !== '' ? ' · ' . e($l['ghi_chu_hong']) : '' ?></small>
        <?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td class="num good-qty"><?= n($have - $bad) ?></td>
        <td class="reason-cell"><?php if ($editable): ?>
          <select name="reason[<?= e($key) ?>]" class="reason-select" aria-label="Lý do <?= e($l['ma_hang']) ?>"<?= $d ? '' : ' hidden' ?>><option value="">Chọn lý do…</option><?php foreach ($reasons as $r): ?><option<?= $cat === $r ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select>
          <input name="reason_note[<?= e($key) ?>]" class="reason-note" maxlength="200" placeholder="Ghi chú thêm" value="<?= e($note) ?>"<?= $d ? '' : ' hidden' ?>>
        <?php else: echo $l['ly_do'] !== '' ? e($l['ly_do']) : '<span class="muted">—</span>'; endif; ?></td>
      </tr>
    <?php endforeach; if (!$c['lines']): ?><tr><td colspan="10" class="empty">Không có dòng nào khớp «<?= e($q) ?>».</td></tr><?php endif; ?>
    </tbody></table></div>
  <?= pager($pg) ?>
  <div class="actions pad">
    <?php if ($editable): ?>
      <button formaction="/counts/<?= (int)$c['id'] ?>/save" class="btn-ghost">Lưu tạm</button>
      <button formaction="/counts/<?= (int)$c['id'] ?>/submit">Nộp kết quả kiểm kê</button>
    <?php endif; ?>
    <?php if ($review && $canApprove): ?>
      <button formaction="/counts/<?= (int)$c['id'] ?>/approve" data-confirm="Xác nhận chênh lệch và lập phiếu điều chỉnh để cập nhật lại tồn kho? Phiếu đã ghi sổ không thể xoá, chỉ sửa sai bằng một phiếu điều chỉnh khác.">Xác nhận chênh lệch & lập phiếu điều chỉnh</button>
      <button formaction="/counts/<?= (int)$c['id'] ?>/reject" class="btn-ghost">Trả lại để đếm lại</button>
    <?php elseif ($review): ?><span class="muted">Đang chờ người duyệt kho <?= e($c['wh_code']) ?> xác nhận.</span><?php endif; ?>
    <?php if (($open || $review) && ($canCount || $canApprove)): ?><input type="text" name="cancel_reason" maxlength="200" class="cancel-reason" placeholder="Lý do huỷ (bắt buộc khi huỷ)" aria-label="Lý do huỷ đợt"><button formaction="/counts/<?= (int)$c['id'] ?>/cancel" class="btn-danger" data-confirm="Huỷ đợt kiểm kê này?" formnovalidate>Huỷ đợt</button><?php endif; ?>
  </div>
</form>
