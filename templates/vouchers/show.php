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

<?php if ($original): ?><div class="flash flash-info"><?= icon('scale') ?><span>Phiếu nhập xuất điều chỉnh đảo cho <a href="/vouchers/<?= (int)$original['id'] ?>"><b><?= e($original['ma']) ?></b></a>. Lý do: <?= e($v['ly_do']) ?></span></div><?php endif; ?>
<?php if ($correction): ?><div class="flash flash-info"><?= icon('scale') ?><span>Phiếu nhập xuất này đã được điều chỉnh bằng <a href="/vouchers/<?= (int)$correction['id'] ?>"><b><?= e($correction['ma']) ?></b></a>. Lịch sử gốc được giữ nguyên.</span></div><?php endif; ?>

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
        <div><dt>Đối tác / lý do</dt><dd><?= e($v['doi_tac'] ?: $v['ly_do'] ?: '—') ?></dd></div>
        <div><dt>Ghi chú</dt><dd><?= e($v['ghi_chu'] ?: '—') ?></dd></div>
        <div><dt>Người lập</dt><dd><?= e($v['creator_name']) ?></dd></div>
        <div><dt>Người xác nhận</dt><dd><?= e($v['approver_name'] ?: '—') ?></dd></div>
        <?php if ($pair): ?><div><dt>Phiếu nhập xuất đối ứng</dt><dd><a href="/vouchers/<?= (int)$pair['id'] ?>"><?= e($pair['ma']) ?></a></dd></div><?php endif; ?>
      </dl>
    </section>

    <?php if ($v['trang_thai'] === 'DRAFT' && ($canApprove || $canDraft)): ?>
    <section class="card">
      <h2>Thao tác</h2>
      <?php if (!$allOk): ?><p class="flash flash-err small"><?= icon('alert') ?><span>Một số dòng không đủ tồn — không thể xác nhận cho đến khi tồn đủ.</span></p><?php endif; ?>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/confirm" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <?php if ($canApprove): ?><button class="btn-block"<?= $allOk ? '' : ' disabled' ?>><?= icon('check') ?><?= $v['loai'] === 'TRANSFER_OUT' ? 'Xuất kho đi (trừ tồn kho đi)' : ($v['loai'] === 'TRANSFER_IN' ? 'Đã nhận hàng (cộng tồn kho đến)' : 'Xác nhận & ghi sổ') ?></button>
        <?php else: ?><p class="muted small">Chỉ tài khoản có quyền «<?= $v['loai'] === 'TRANSFER_IN' ? 'Nhận hàng' : 'Người duyệt' ?>» tại kho <?= e($v['wh_code']) ?> mới xác nhận được.</p><?php endif; ?></form>
      <?php if ($v['loai'] !== 'TRANSFER_IN'): ?><form method="post" action="/vouchers/<?= (int)$v['id'] ?>/cancel" class="mt"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn-danger btn-block" data-confirm="Huỷ phiếu nháp này?">Huỷ phiếu</button></form><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($canCorrect && !$correction && $canAdjust): ?>
    <section class="card">
      <h2>Điều chỉnh phiếu nhập xuất</h2>
      <p class="muted small">Phiếu nhập xuất đã ghi sổ không được sửa hoặc xoá. Hãy lập phiếu nhập xuất điều chỉnh đảo<?= $isTransfer ? ' (đảo cả hai kho trong một giao dịch)' : '' ?> kèm lý do.</p>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/correct" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Lý do điều chỉnh<textarea name="reason" rows="3" maxlength="255" required placeholder="VD: Nhập nhầm số lượng, thực nhận 90"></textarea></label>
        <button class="btn-danger" data-confirm="Lập phiếu nhập xuất điều chỉnh đảo cho phiếu nhập xuất này?">Lập phiếu nhập xuất điều chỉnh</button>
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
