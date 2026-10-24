<?php /** @var array $v @var array $lines @var array $check @var array $movements @var ?array $pair @var ?array $correction @var ?array $original @var array $trail */
$isXfer = in_array($v['loai'], ['TRANSFER_OUT', 'TRANSFER_IN'], true);
if ($isXfer) {   // hành trình hàng điều chuyển: lập → xuất kho đi (trừ kho đi) → kho đến nhận (cộng kho đến)
    $recvAt = $v['loai'] === 'TRANSFER_IN' ? ($v['trang_thai'] === 'CONFIRMED' ? [$v['ngay_xac_nhan'], $v['approver_name']] : [null, null]) : [null, null];
    $steps = $v['loai'] === 'TRANSFER_OUT'
        ? [['Lập phiếu', $v['ngay_tao'], $v['creator_name']], ['Xuất kho đi', $v['ngay_xac_nhan'], $v['approver_name']], ['Kho đến nhận hàng', null, null]]
        : [['Xuất kho đi', $v['ngay_tao'], $v['creator_name']], ['Đang vận chuyển', null, null], ['Kho đến nhận hàng', $recvAt[0], $recvAt[1]]];
    $stage = match (true) {
        $v['trang_thai'] === 'CANCELLED' => -1,
        $v['loai'] === 'TRANSFER_OUT' => $v['trang_thai'] === 'DRAFT' ? 1 : (($v['pair_status'] ?? '') === 'CONFIRMED' ? 3 : 2),
        default => $v['trang_thai'] === 'DRAFT' ? 2 : 3,
    };
} else {
    $steps = [['Lập phiếu', $v['ngay_tao'], $v['creator_name']], ['Kiểm tra', null, null], ['Xác nhận & ghi sổ', $v['ngay_xac_nhan'], $v['approver_name']]];
    $stage = match ($v['trang_thai']) { 'DRAFT' => 1, 'CONFIRMED' => 3, default => -1 };
}
$allOk = $check === [] || !in_array(false, array_column($check, 'ok'), true);
?>
<header class="page-head">
  <div><p class="crumb"><a href="/vouchers">← Phiếu nhập xuất</a></p>
    <h1><?= e($v['ma']) ?> <?= voucher_status_tag($v) ?> <?= type_chip($v['loai']) ?></h1>
    <p class="muted"><span class="wh-code"><?= e($v['wh_code']) ?></span> <?= e($v['wh_name']) ?>
      <?php if ($v['cp_code']): ?> <span class="arrow"><?= $v['loai'] === 'TRANSFER_IN' ? '←' : '→' ?></span> <span class="wh-code"><?= e($v['cp_code']) ?></span> <?= e($v['cp_name']) ?><?php endif; ?></p></div>
</header>

<?php if ($stage >= 0): ?>
<ol class="track" style="--stage:<?= $stage ?>" aria-label="Tiến trình phiếu nhập xuất">
  <?php foreach ($steps as $i => [$label, $at, $by]): $cls = $i < $stage ? 'done' : ($i === $stage ? 'current' : ''); ?>
  <li class="<?= $cls ?>"><span class="dot"><?= $i < $stage ? icon('check') : $i + 1 ?></span><b><?= e($label) ?></b><small><?= $at ? e(dt($at)) . ' · ' . e($by) : ($isXfer ? ($i < $stage ? 'Hoàn tất' : ($i === $stage ? 'Đang chờ' : 'Chưa thực hiện')) : ($i === 1 ? ($stage === 1 ? 'Xem tồn hiện có bên dưới' : 'Đạt — kiểm tra khi ghi sổ') : 'Chưa thực hiện')) ?></small></li>
  <?php endforeach; ?>
</ol>
<?php else: ?>
<div class="flash flash-muted"><?= icon('alert') ?><span>Phiếu đã bị huỷ khi còn là bản nháp; không có tác động đến tồn.</span></div>
<?php endif; ?>

<?php if ($original): ?><div class="flash flash-info"><?= icon('scale') ?><span>Đây là phiếu điều chỉnh, dùng để sửa sai cho phiếu <a href="/vouchers/<?= (int)$original['id'] ?>"><b><?= e($original['ma']) ?></b></a>. Lý do: <?= e($v['ly_do']) ?></span></div><?php endif; ?>
<?php if ($correction): ?><div class="flash flash-info"><?= icon('scale') ?><span>Phiếu này đã được sửa sai bằng phiếu điều chỉnh <a href="/vouchers/<?= (int)$correction['id'] ?>"><b><?= e($correction['ma']) ?></b></a>. Lịch sử gốc được giữ nguyên.</span></div><?php endif; ?>

<div class="grid-main">
  <div>
    <section class="card flush">
      <div class="card-head pad"><h2>Dòng hàng</h2><?php if ($v['trang_thai'] === 'DRAFT'): ?><span class="muted small">Kiểm tra tồn khả dụng trước khi xác nhận</span><?php endif; ?></div>
      <div class="table-wrap"><table class="dense">
        <thead><tr><th>#</th><th>SKU</th><th>Tên hàng</th><th>Lô</th><th class="num">Số lượng</th><?php if ($v['trang_thai'] === 'DRAFT' && in_array($v['loai'], ['ISSUE', 'TRANSFER_OUT'], true)): ?><th class="num">Tồn hiện có</th><th>Kiểm tra</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($lines as $i => $l): $c = $check[$i] ?? null; ?>
          <tr><td class="muted"><?= $i + 1 ?></td><td><b><?= e($l['ma_hang']) ?></b></td><td><?= e($l['ten']) ?><?= $l['ghi_chu'] !== '' ? '<br><small class="muted">' . e($l['ghi_chu']) . '</small>' : '' ?></td>
            <td><?= $l['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($l['so_lo']) . ($l['han_dung'] ? ' <small class="muted">HSD ' . e(dt($l['han_dung'], false)) . '</small>' : '') ?></td>
            <td class="num"><b class="<?= $v['loai'] === 'ADJUSTMENT' ? ($l['so_luong'] > 0 ? 'pos' : 'neg') : '' ?>"><?= $v['loai'] === 'ADJUSTMENT' ? signed((int)$l['so_luong']) : n($l['so_luong']) ?></b> <small class="muted"><?= e($l['unit']) ?></small></td>
            <?php if ($c && in_array($v['loai'], ['ISSUE', 'TRANSFER_OUT'], true)): ?><td class="num"><?= n($c['available']) ?></td><td><?= $c['ok'] ? '<span class="tag tag-ok">Đủ hàng</span>' : '<span class="tag tag-danger">Thiếu ' . n(abs($c['available'] - (int)$l['so_luong'])) . '</span>' ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>

    <?php if ($movements): ?>
    <section class="card flush">
      <div class="card-head pad"><h2>Đã ghi vào sổ phát sinh</h2><span class="muted small"><?= count($movements) ?> dòng · bất biến</span></div>
      <div class="table-wrap"><table class="dense">
        <thead><tr><th>#</th><th>Kho</th><th>Thời gian</th><th class="num">Biến động</th><th class="num">Tồn sau</th></tr></thead>
        <tbody><?php foreach ($movements as $m): ?><tr><td class="muted"><?= (int)$m['id'] ?></td><td><span class="wh-code"><?= e($m['wh_code']) ?></span></td><td class="muted"><?= e(dt($m['ngay_ghi_so'])) ?></td>
          <td class="num <?= $m['bien_dong'] > 0 ? 'pos' : 'neg' ?>"><b><?= signed((int)$m['bien_dong']) ?></b></td><td class="num"><?= n($m['ton_sau']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    </section>
    <?php endif; ?>
  </div>

  <aside>
    <section class="card">
      <h2>Thông tin</h2>
      <dl class="meta">
        <?php if ($isXfer): ?>
        <div><dt><?= $v['loai'] === 'TRANSFER_IN' ? 'Kho chuyển đi' : 'Kho chuyển đến' ?></dt><dd><?= $v['cp_code'] ? '<span class="wh-code">' . e($v['cp_code']) . '</span> ' . e($v['cp_name']) : '—' ?></dd></div>
        <?php elseif ($v['loai'] === 'ADJUSTMENT'): ?>
        <div><dt>Lý do</dt><dd><?= e($v['ly_do'] ?: '—') ?></dd></div>
        <?php else: ?>
        <div><dt>Đối tác</dt><dd><?= e($v['doi_tac'] ?: '—') ?></dd></div>
        <?php endif; ?>
        <div><dt>Ghi chú</dt><dd><?= e($v['ghi_chu'] ?: '—') ?></dd></div>
        <div><dt>Người lập</dt><dd><?= e($v['creator_name']) ?></dd></div>
        <div><dt>Người xác nhận</dt><dd><?= e($v['approver_name'] ?: '—') ?></dd></div>
        <?php if ($pair): ?><div><dt>Phiếu nhập xuất đối ứng</dt><dd><a href="/vouchers/<?= (int)$pair['id'] ?>"><?= e($pair['ma']) ?></a></dd></div><?php endif; ?>
      </dl>
    </section>

    <?php if ($trip): ?>
    <section class="card">
      <h2>Vận chuyển</h2>
      <dl class="meta">
        <div><dt>Xe</dt><dd><b><?= e($trip['bien_so']) ?></b><?= $trip['loai_xe'] ? ' · ' . e($trip['loai_xe']) : '' ?></dd></div>
        <div><dt>Tài xế</dt><dd><b><?= e($trip['tai_xe_ten']) ?></b><br><small class="muted">CCCD <?= e($trip['cccd']) ?><?= $trip['so_dien_thoai'] ? ' · ' . e($trip['so_dien_thoai']) : '' ?></small></dd></div>
        <div><dt>Xuất kho đi</dt><dd><?= e(dt($trip['ngay_di'])) ?> · <?= e($trip['giao_ten']) ?><br><span class="tag tag-ok">Tài xế đã xác nhận nhận đủ hàng</span></dd></div>
        <div><dt>Kho đến kiểm tra</dt><dd>
          <?php if ($trip['trang_thai'] === 'DELIVERED'): ?><span class="tag tag-ok">Hàng đủ</span> <span class="tag tag-ok">Đúng tài xế, xe</span><br><small class="muted"><?= e(dt($trip['ngay_nhan'])) ?> · <?= e($trip['nhan_ten']) ?></small>
          <?php elseif ($trip['sai_lech'] !== ''): ?><span class="tag tag-danger">Báo sai lệch</span><br><small><?= e($trip['sai_lech']) ?></small><br><small class="muted"><?= e($trip['nhan_ten'] ?? '') ?></small>
          <?php else: ?><span class="muted">Chưa nhận hàng</span><?php endif; ?></dd></div>
      </dl>
    </section>
    <?php endif; ?>

    <?php if ($v['trang_thai'] === 'DRAFT' && ($canApprove || $canDraft)): ?>
    <section class="card">
      <h2>Thao tác</h2>
      <?php if (!$allOk): ?><p class="flash flash-err small"><?= icon('alert') ?><span>Một số dòng không đủ tồn — không thể xác nhận cho đến khi tồn đủ.</span></p><?php endif; ?>
      <?php $isOut = $v['loai'] === 'TRANSFER_OUT'; $recvTrip = $v['loai'] === 'TRANSFER_IN' && $trip && $trip['trang_thai'] === 'IN_TRANSIT';
            $ready = array_values(array_filter($vehicles, fn($x) => $x['tai_xe_id'] && $x['tai_xe_hoat_dong'])); $noFleet = $isOut && $canApprove && !$ready; ?>
      <?php if ($noFleet): ?><p class="flash flash-err small"><?= icon('alert') ?><span><?= $vehicles
          ? 'Xe tại kho này chưa có tài xế phụ trách. Liên hệ quản trị hệ thống ở trang Đội xe &amp; tài xế.'
          : 'Kho này chưa có xe sẵn sàng (xe đang chạy hoặc chưa được thêm). Liên hệ quản trị hệ thống ở trang Đội xe &amp; tài xế.' ?></span></p><?php endif; ?>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/confirm" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <?php if ($canApprove && $isOut && !$noFleet): ?>
        <div class="form">
          <label>Xe chở hàng (tài xế đi theo xe)<select name="vehicle_id" required><option value="">Chọn xe…</option><?php foreach ($ready as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['bien_so'] . ' — ' . $x['ho_ten'] . ' · CCCD ' . $x['cccd']) ?></option><?php endforeach; ?></select></label>
          <label class="check"><input type="checkbox" name="driver_ok" value="1" required> Tài xế đã kiểm và xác nhận nhận đủ hàng</label>
        </div>
        <?php elseif ($canApprove && $recvTrip): ?>
        <div class="form">
          <p class="muted small">Đối chiếu với thông tin ở khung «Vận chuyển»: người giao có đúng tài xế <b><?= e($trip['tai_xe_ten']) ?></b>, xe <b><?= e($trip['bien_so']) ?></b> không?</p>
          <label class="check"><input type="checkbox" name="full_ok" value="1"> Hàng nhận đủ, đúng số lượng</label>
          <label class="check"><input type="checkbox" name="driver_match" value="1"> Đúng tài xế và đúng xe</label>
          <label>Lý do sai lệch (chỉ điền khi báo sai lệch)<textarea name="reason" rows="2" maxlength="200" placeholder="VD: thiếu 5 thùng, tài xế khác người đã đăng ký"></textarea></label>
        </div>
        <?php endif; ?>
        <?php if ($canApprove): ?><button class="btn-block"<?= ($allOk && !$noFleet) ? '' : ' disabled' ?>><?= icon('check') ?><?= $v['loai'] === 'TRANSFER_OUT' ? 'Xuất kho đi (trừ tồn kho đi)' : ($v['loai'] === 'TRANSFER_IN' ? ($recvTrip ? 'Xác nhận đã nhận hàng (cộng tồn kho đến)' : 'Đã nhận hàng (cộng tồn kho đến)') : 'Xác nhận & ghi sổ') ?></button>
        <?php if ($recvTrip): ?><button type="submit" class="btn-danger btn-block mt" formaction="/vouchers/<?= (int)$v['id'] ?>/discrepancy" formnovalidate data-confirm="Báo sai lệch cho phiếu này? Hàng chưa được cộng vào tồn kho đến."><?= icon('alert') ?>Báo sai lệch (chưa nhận hàng)</button><?php endif; ?>
        <?php else: ?><p class="muted small">Chỉ tài khoản có quyền «<?= $v['loai'] === 'TRANSFER_IN' ? 'Nhận hàng' : 'Người duyệt' ?>» tại kho <?= e($v['wh_code']) ?> mới xác nhận được.</p><?php endif; ?></form>
      <?php if ($v['loai'] !== 'TRANSFER_IN'): ?><form method="post" action="/vouchers/<?= (int)$v['id'] ?>/cancel" class="mt"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn-danger btn-block" data-confirm="Huỷ phiếu nháp này?">Huỷ phiếu</button></form><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($canCorrect && !$correction && $canAdjust): ?>
    <section class="card">
      <h2>Sửa sai phiếu này</h2>
      <p class="muted small">Phiếu đã ghi sổ không được sửa hoặc xoá. Nếu phiếu bị sai, hãy lập phiếu điều chỉnh kèm lý do: phiếu điều chỉnh sẽ hoàn lại số lượng của phiếu này<?= $isTransfer ? ' ở cả hai kho trong một giao dịch' : '' ?>, lịch sử cũ vẫn được giữ nguyên.</p>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/correct" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Lý do điều chỉnh<textarea name="reason" rows="3" maxlength="255" required placeholder="VD: Nhập nhầm số lượng, thực nhận 90"></textarea></label>
        <button class="btn-danger" data-confirm="Lập phiếu điều chỉnh để hoàn lại số lượng của phiếu này? Việc này không xoá lịch sử cũ.">Lập phiếu điều chỉnh</button>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($trail): ?>
    <section class="card"><h2>Nhật ký thao tác</h2>
      <ul class="rows tight"><?php foreach ($trail as $a): ?><li><span><b><?= e($a['hanh_dong']) ?></b><br><small class="muted"><?= e($a['ho_ten'] ?? '—') ?> · <?= e(dt($a['ngay_tao'])) ?></small></span></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>
  </aside>
</div>
