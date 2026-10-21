<?php /** @var array $c */
$open = $c['trang_thai'] === 'OPEN';
$review = $c['trang_thai'] === 'REVIEW';
$editable = $open && $canCount;
$net = 0; $varN = 0;
foreach ($c['lines'] as $l) { if ($l['so_luong_thuc_dem'] !== null) { $d = (int)$l['so_luong_thuc_dem'] - (int)$l['so_luong_so_sach']; $net += $d; $varN += $d !== 0 ? 1 : 0; } }
?>
<header class="page-head">
  <div><p class="crumb"><a href="/counts">← Kiểm kê</a></p><h1><?= e($c['ma']) ?> <?= status_tag($c['trang_thai']) ?></h1>
    <p class="muted"><span class="wh-code"><?= e($c['wh_code']) ?></span> <?= e($c['wh_name']) ?> · mở <?= e(dt($c['ngay_tao'])) ?> bởi <?= e($c['creator_name']) ?><?= $c['ghi_chu'] !== '' ? ' · ' . e($c['ghi_chu']) : '' ?>
    <?php if ($c['adj_code']): ?> · <a href="/vouchers/<?= (int)$c['phieu_nhap_xuat_dieu_chinh_id'] ?>">Phiếu nhập xuất điều chỉnh <b><?= e($c['adj_code']) ?></b></a><?php endif; ?></p></div>
</header>
<form method="post" class="card flush" data-count-form data-once>
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <div class="summary-strip"><span><b data-var-lines><?= n($varN) ?></b> dòng lệch</span><span>Chênh lệch ròng <b data-var-net class="<?= $net > 0 ? 'pos' : ($net < 0 ? 'neg' : '') ?>"><?= signed($net) ?></b></span><span class="muted small">Điều chỉnh = thực đếm − sổ sách lúc mở đợt, cộng vào tồn hiện tại.</span></div>
  <div class="table-wrap"><table class="dense count-table">
    <thead><tr><th>SKU</th><th>Tên hàng</th><th>Lô</th><th class="num">Sổ sách</th><th class="num">Thực đếm</th><th class="num">Chênh lệch</th><th>Lý do chênh lệch</th></tr></thead>
    <tbody>
    <?php foreach ($c['lines'] as $l): $key = $l['mat_hang_id'] . ':' . $l['lo_hang_id'];
      $d = $l['so_luong_thuc_dem'] === null ? null : (int)$l['so_luong_thuc_dem'] - (int)$l['so_luong_so_sach'];
      [$cat, $note] = array_pad(explode(': ', (string)$l['ly_do'], 2), 2, ''); ?>
      <tr data-system="<?= (int)$l['so_luong_so_sach'] ?>">
        <td><b><?= e($l['ma_hang']) ?></b></td><td><?= e($l['ten']) ?></td><td><?= $l['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($l['so_lo']) ?></td>
        <td class="num"><?= n($l['so_luong_so_sach']) ?></td>
        <td class="num"><?php if ($editable): ?><input class="qty-input count-input" type="number" min="0" name="counted[<?= e($key) ?>]" value="<?= $l['so_luong_thuc_dem'] === null ? '' : (int)$l['so_luong_thuc_dem'] ?>" aria-label="Thực đếm <?= e($l['ma_hang']) ?>"><?php else: echo $l['so_luong_thuc_dem'] === null ? '—' : n($l['so_luong_thuc_dem']); endif; ?></td>
        <td class="num variance <?= $d === null || $d === 0 ? '' : ($d > 0 ? 'pos' : 'neg') ?>"><?= $d === null ? '—' : ($d === 0 ? '✓' : signed($d)) ?></td>
        <td class="reason-cell"><?php if ($editable): ?>
          <select name="reason[<?= e($key) ?>]" class="reason-select" aria-label="Lý do <?= e($l['ma_hang']) ?>"<?= $d ? '' : ' hidden' ?>><option value="">Chọn lý do…</option><?php foreach ($reasons as $r): ?><option<?= $cat === $r ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select>
          <input name="reason_note[<?= e($key) ?>]" class="reason-note" placeholder="Ghi chú thêm" value="<?= e($note) ?>"<?= $d ? '' : ' hidden' ?>>
        <?php else: echo $l['ly_do'] !== '' ? e($l['ly_do']) : '<span class="muted">—</span>'; endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <div class="actions pad">
    <?php if ($editable): ?>
      <button formaction="/counts/<?= (int)$c['id'] ?>/save" class="btn-ghost">Lưu tạm</button>
      <button formaction="/counts/<?= (int)$c['id'] ?>/submit">Nộp kết quả kiểm kê</button>
    <?php endif; ?>
    <?php if ($review && $canApprove): ?>
      <button formaction="/counts/<?= (int)$c['id'] ?>/approve" data-confirm="Xác nhận chênh lệch và lập phiếu nhập xuất điều chỉnh tồn? Phiếu nhập xuất đã ghi sổ không thể xoá (chỉ điều chỉnh đảo).">Xác nhận chênh lệch & lập phiếu nhập xuất điều chỉnh</button>
      <button formaction="/counts/<?= (int)$c['id'] ?>/reject" class="btn-ghost">Trả lại để đếm lại</button>
    <?php elseif ($review): ?><span class="muted">Đang chờ người duyệt kho <?= e($c['wh_code']) ?> xác nhận.</span><?php endif; ?>
    <?php if (($open || $review) && ($canCount || $canApprove)): ?><button formaction="/counts/<?= (int)$c['id'] ?>/cancel" class="btn-danger" data-confirm="Huỷ đợt kiểm kê này?">Huỷ đợt</button><?php endif; ?>
  </div>
</form>
