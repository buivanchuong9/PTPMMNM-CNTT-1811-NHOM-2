<?php /** @var array $v @var array $lines @var array $check @var array $movements @var ?array $pair @var ?array $correction @var ?array $original @var array $trail @var ?array $manager @var ?array $route @var array $convoy @var ?array $breakdown */
$isXfer = in_array($v['loai'], ['TRANSFER_OUT', 'TRANSFER_IN'], true);
$draft = $v['trang_thai'] === 'DRAFT';
$cancelled = $v['trang_thai'] === 'CANCELLED';
if ($isXfer) {   // hành trình hàng điều chuyển: lập → xuất kho đi (trừ kho đi) → kho đến nhận (cộng kho đến)
    $recvAt = $v['loai'] === 'TRANSFER_IN' ? ($v['trang_thai'] === 'CONFIRMED' ? [$v['ngay_xac_nhan'], $v['approver_name']] : [null, null]) : [null, null];
    // kho nằm ngay dưới bước của nó: kho đi dưới «Xuất kho đi», kho đến dưới «Kho đến nhận»
    $here = [$v['wh_code'], $v['wh_name']];
    $there = [$v['cp_code'], $v['cp_name']];
    [$fromWh, $toWh] = $v['loai'] === 'TRANSFER_OUT' ? [$here, $there] : [$there, $here];
    $steps = $v['loai'] === 'TRANSFER_OUT'
        ? [['Lập phiếu', $v['ngay_tao'], $v['creator_name']], ['Xuất kho đi', $v['ngay_xac_nhan'], $v['approver_name'], $fromWh], ['Kho đến nhận', null, null, $toWh]]
        : [['Xuất kho đi', $v['ngay_tao'], $v['creator_name'], $fromWh], ['Đang vận chuyển', null, null], ['Kho đến nhận', $recvAt[0], $recvAt[1], $toWh]];
    $stage = match (true) {
        $cancelled => -1,
        $v['loai'] === 'TRANSFER_OUT' => $draft ? 1 : (($v['pair_status'] ?? '') === 'CONFIRMED' ? 3 : 2),
        default => $draft ? 2 : 3,
    };
} else {
    $steps = [['Lập phiếu', $v['ngay_tao'], $v['creator_name']], ['Xác nhận & ghi sổ', $v['ngay_xac_nhan'], $v['approver_name']]];
    $stage = match ($v['trang_thai']) { 'DRAFT' => 1, 'CONFIRMED' => 2, default => -1 };
}
$allOk = $check === [] || !in_array(false, array_column($check, 'ok'), true);
$isOutLines = in_array($v['loai'], ['ISSUE', 'TRANSFER_OUT'], true);
$showCheck = $draft && $isOutLines;
// nhận hàng điều chuyển có chuyến đang chạy: kho đến nhập SỐ THỰC NHẬN ngay cạnh số lượng
$recvTrip = $v['loai'] === 'TRANSFER_IN' && $draft && $trip && $trip['trang_thai'] === 'IN_TRANSIT';
$recvForm = $recvTrip && $canApprove;
$missingNow = $recvTrip && $trip['sai_lech'] !== '';
$shortBy = [];   // đã nhận thiếu: «mặt hàng:lô» ⇒ phần thiếu
foreach ($shortage ?? [] as $sh) { $shortBy[$sh['mat_hang_id'] . ':' . $sh['lo_hang_id']] = (int)$sh['thieu']; }
$shortVoucher = $shortage[0] ?? null;
// phiếu nhập/xuất thường: nút thao tác nằm ngay trên đầu trang; điều chuyển cần chọn xe / kiểm hàng nên có khung riêng
$priced = in_array($v['loai'], ['RECEIPT', 'ISSUE'], true);   // phiếu có tiền: đơn giá, thuế, thành tiền (giá lưu tại lúc lập phiếu)
$sumNet = $sumTax = 0;
foreach ($lines as $pl) {
    $net = (int)$pl['so_luong'] * (int)($pl['don_gia'] ?? 0);
    $sumNet += $net;
    $sumTax += (int)round($net * (int)$pl['thue_suat'] / 100);
}
$issueForm = $draft && $v['loai'] === 'ISSUE' && $canApprove;   // phiếu xuất: nút xác nhận nằm cuối khối «Giao hàng cho khách»
$headActions = $draft && !$isXfer && ($canApprove || $canDraft) && !$issueForm;
$actionCard = $draft && $isXfer && ($canApprove || $canDraft);
// kho đi còn thu hồi được chuyến khi xe chưa tới và kho đến chưa nhận / chưa báo thiếu
$canRecall = $v['loai'] === 'TRANSFER_OUT' && $v['trang_thai'] === 'CONFIRMED' && $canApprove && $trip && $trip['trang_thai'] === 'IN_TRANSIT' && $trip['sai_lech'] === '' && !$correction;
// kho đi báo xe hỏng / xe chạy tiếp (cùng điều kiện với thu hồi chuyến, nhưng không bị chặn khi kho đến đã báo «chưa nhận được hàng»)
$canBreak = $v['loai'] === 'TRANSFER_OUT' && $v['trang_thai'] === 'CONFIRMED' && $canApprove && $trip && $trip['trang_thai'] === 'IN_TRANSIT';
$cancelEntry = null;
foreach ($trail as $a) { if (in_array($a['hanh_dong'], ['voucher.cancel'], true)) { $cancelEntry = $a; } }
?>
<header class="page-head vhead">
  <div><p class="crumb"><a href="/vouchers">← Phiếu</a></p>
    <h1><?php [$typeLabel, $typeTone] = TYPE_LABELS[$v['loai']] ?? [$v['loai'], 'adj']; ?><span class="type-big type-<?= e($typeTone) ?>"><?= icon(['in' => 'in', 'out' => 'out', 'xfer' => 'truck'][$typeTone] ?? 'scale') ?><?= e($typeLabel) ?></span> <?= e($v['ma']) ?> <?= voucher_status_tag($v) ?></h1>
    <p class="muted"><?php if (!$isXfer || $stage < 0): ?><span class="wh-code"><?= e($v['wh_code']) ?></span> <?= e($v['wh_name']) ?>
      <?php if ($v['cp_code']): ?> <span class="arrow"><?= $v['loai'] === 'TRANSFER_IN' ? '←' : '→' ?></span> <span class="wh-code"><?= e($v['cp_code']) ?></span> <?= e($v['cp_name']) ?><?php endif; ?>
      · <?php endif; ?><?= e(dt($v['ngay_tao'])) ?></p></div>
  <?php if ($v['trang_thai'] === 'CONFIRMED' && $v['loai'] !== 'ADJUSTMENT'): ?>
  <div class="actions"><a class="btn" href="/vouchers/<?= (int)$v['id'] ?>/print" target="_blank" rel="noopener"><?= icon('printer') ?>In phiếu</a></div>
  <?php endif; ?>
</header>

<?php if ($draft && $v['loai'] !== 'TRANSFER_IN' && ($canApprove || $canDraft)): ?><span hidden data-guard-draft data-cancel="/vouchers/<?= (int)$v['id'] ?>/cancel"></span><?php endif; ?>
<?php if ($stage >= 0): ?>
<ol class="flow" aria-label="Tiến trình phiếu">
  <?php foreach ($steps as $i => $step): [$label, $at, $by] = $step; $sw = $step[3] ?? null; $cls = $i < $stage ? 'done' : ($i === $stage ? 'current' : ''); ?>
  <li class="<?= $cls ?>" style="--i:<?= $i ?>"<?= $by ? ' title="' . e($by) . '"' : '' ?>><span class="dot"><?= $i < $stage ? icon('check') : $i + 1 ?></span>
    <span><b><?= e($label) ?></b><?php if ($sw && $sw[0]): ?><span class="flow-wh"><span class="wh-code"><?= e($sw[0]) ?></span><small><?= e($sw[1]) ?></small></span><?php endif; ?><?php if ($at): ?><small><?= e(dt_short($at)) ?></small><?php elseif ($i === $stage): ?><small>Đang chờ</small><?php endif; ?></span></li>
  <?php endforeach; ?>
</ol>
<?php else: ?>
<div class="flash flash-muted"><?= icon('alert') ?><span>Phiếu đã bị huỷ khi còn là bản nháp<?= $cancelEntry ? ' — <b>' . e($cancelEntry['ho_ten'] ?? '—') . '</b> huỷ lúc ' . e(dt($cancelEntry['ngay_tao'])) : '' ?>; không có tác động đến tồn.</span></div>
<?php endif; ?>

<?php if ($draft && !$canApprove && !$headActions && !$actionCard): ?>
<div class="flash flash-info"><?= icon('clock') ?><span>Đang chờ <b><?= e($manager['ho_ten'] ?? ('quản lý kho ' . $v['wh_code'])) ?></b> <?= $v['loai'] === 'TRANSFER_IN' ? 'nhận hàng' : 'xác nhận' ?>.</span></div>
<?php elseif ($headActions && !$canApprove): ?>
<div class="flash flash-info"><?= icon('clock') ?><span>Chờ <b><?= e($manager['ho_ten'] ?? ('quản lý kho ' . $v['wh_code'])) ?></b> xác nhận.</span></div>
<?php endif; ?>

<?php if ($breakdown): ?><div class="flash flash-err"><?= icon('alert') ?><span><b>Xe <?= e($trip['bien_so']) ?> hỏng giữa đường</b> — <?= e($breakdown['ly_do']) ?> (báo lúc <?= e(dt_short($breakdown['ngay_hong'])) ?>). Hàng sẽ tới muộn<?= $v['loai'] === 'TRANSFER_IN' ? '; chưa nhận được cho tới khi kho đi báo xe chạy tiếp' : '' ?>.</span></div><?php endif; ?>
<?php if ($missingNow): ?><div class="flash flash-err"><?= icon('alert') ?><span><b>Đã báo chưa nhận được hàng</b> — <?= e($trip['sai_lech']) ?>. Quản trị hệ thống đã được báo. Khi hàng tới, nhập số thực nhận bên dưới rồi xác nhận.</span></div><?php endif; ?>
<?php if (!empty($replies) && $trip && $trip['trang_thai'] === 'IN_TRANSIT'): $rp = $replies[0]; ?><div class="flash flash-info"><?= icon('clock') ?><span><b>Quản trị phản hồi</b> (<?= e($rp['ho_ten'] ?? '—') ?> · <?= e(dt_short($rp['ngay_tao'])) ?>): <?= e($rp['noi_dung']) ?></span></div><?php endif; ?>
<?php if ($shortVoucher): ?><div class="flash flash-info"><?= icon('alert') ?><span>Nhận thiếu hàng — phần thiếu được ghi bằng phiếu điều chỉnh <a href="/vouchers/<?= (int)$shortVoucher['adj_id'] ?>"><b><?= e($shortVoucher['adj_ma']) ?></b></a>; tồn kho chỉ tăng đúng số thực nhận.</span></div><?php endif; ?>
<?php if ($original): ?><div class="flash flash-info"><?= icon('scale') ?><span>Phiếu điều chỉnh, sửa sai cho phiếu <a href="/vouchers/<?= (int)$original['id'] ?>"><b><?= e($original['ma']) ?></b></a>. Lý do: <?= e($v['ly_do']) ?></span></div><?php endif; ?>
<?php if ($correction): ?><div class="flash flash-info"><?= icon('scale') ?><span>Đã được sửa sai bằng phiếu điều chỉnh <a href="/vouchers/<?= (int)$correction['id'] ?>"><b><?= e($correction['ma']) ?></b></a>. Lịch sử gốc được giữ nguyên.</span></div><?php endif; ?>

<div class="grid-main">
  <div>
    <?php ob_start(); if ($actionCard): ?>
    <section class="card">
      <h2>Thao tác</h2>
      <?php if ($canApprove && $v['loai'] === 'TRANSFER_OUT') { require __DIR__ . '/../partials/load_summary.php'; } ?>
      <?php if (!$allOk): ?><p class="flash flash-err small"><?= icon('alert') ?><span>Một số dòng không đủ tồn — không thể xác nhận cho đến khi tồn đủ.</span></p><?php endif; ?>
      <?php $isOut = $v['loai'] === 'TRANSFER_OUT';
            $ready = array_values($vehicles); $noFleet = $isOut && $canApprove && (!$ready || !$drivers); ?>
      <?php if ($noFleet): ?><p class="flash flash-err small"><?= icon('alert') ?><span><?= !$ready ? 'Kho này chưa có xe sẵn sàng (xe đang chạy hoặc chưa được thêm).' : 'Kho này chưa có tài xế rảnh (đang chạy chuyến khác, nghỉ hoặc chưa được thêm ở mục «Tài xế»).' ?> Bạn vẫn xuất được bằng cách thuê đơn vị vận tải ngoài.</span></p><?php endif; ?>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/confirm"<?= $recvForm ? ' id="recv-form"' : '' ?> data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <?php if ($canApprove && $isOut): ?>
        <?php $deliveryMode = false; $formId = ''; $convoyLines = $lines; require __DIR__ . '/../partials/pay_box.php'; ?>
        <?php elseif ($canApprove && $recvTrip): ?>
        <div class="form">
          <p class="muted small">Nhập <b>số thực nhận</b> ở cột «Thực nhận» của bảng Dòng hàng (mặc định bằng số trên phiếu). Nhận thiếu thì tồn chỉ tăng đúng số thực nhận.</p>
          <div class="form short-why"><label>Nếu nhận thiếu — lý do (bắt buộc khi thực nhận ít hơn số trên phiếu)<select name="short_reason"><option value="">Không thiếu / chọn lý do…</option><?php foreach (\StockFlow\Service\TransferService::SHORT_REASONS as $sr): ?><option><?= e($sr) ?></option><?php endforeach; ?></select></label>
            <label>Ghi chú lý do thiếu<input name="short_note" maxlength="120" autocomplete="off" placeholder="VD: 5 thùng vỡ do xe phanh gấp"></label></div>
          <label class="check"><input type="checkbox" name="driver_match" value="1" required> Đúng tài xế <b><?= e($trip['tai_xe_ten']) ?></b> và đúng xe <b><?= e($trip['bien_so']) ?></b></label>
        </div>
        <?php endif; ?>
        <?php if ($canApprove): ?><button class="btn-block"<?= $allOk && !$breakdown ? '' : ' disabled' ?><?= $breakdown ? ' title="Xe đang hỏng giữa đường — chưa nhận được hàng"' : '' ?>><?= icon('check') ?><?= $v['loai'] === 'TRANSFER_OUT' ? 'Xuất kho đi (trừ tồn kho đi)' : ($recvTrip ? 'Xác nhận đã nhận hàng' : 'Đã nhận hàng (cộng tồn kho đến)') ?></button>
        <?php if ($recvTrip): ?>
        <fieldset class="notrecv"><legend>Chưa nhận được hàng?</legend>
          <label>Lý do<select name="missing_reason"><option value="">Chọn lý do…</option><?php foreach (\StockFlow\Service\TransferService::MISSING_REASONS as $mr): ?><option><?= e($mr) ?></option><?php endforeach; ?></select></label>
          <label>Ghi chú (không bắt buộc)<input name="missing_note" maxlength="120" autocomplete="off" placeholder="VD: gọi tài xế không nghe máy từ 14h"></label>
          <button type="submit" class="btn-danger btn-block" formaction="/vouchers/<?= (int)$v['id'] ?>/not-received" formnovalidate data-confirm="Báo chưa nhận được hàng? Tồn kho đến không đổi và quản trị hệ thống sẽ được báo."><?= icon('alert') ?>Chưa nhận được hàng — báo quản trị</button>
        </fieldset>
        <?php endif; ?>
        <?php else: ?><p class="muted small">Chỉ tài khoản có quyền «<?= $v['loai'] === 'TRANSFER_IN' ? 'Nhận hàng' : 'Người duyệt' ?>» tại kho <?= e($v['wh_code']) ?> mới xác nhận được<?= !empty($manager['ho_ten']) ? ' — liên hệ <b>' . e($manager['ho_ten']) . '</b>' : '' ?>.</p><?php endif; ?></form>
      <?php if ($v['loai'] !== 'TRANSFER_IN'): ?><form method="post" action="/vouchers/<?= (int)$v['id'] ?>/cancel" class="mt"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn-danger btn-block" data-confirm="Huỷ phiếu nháp này?">Huỷ phiếu</button></form><?php endif; ?>
    </section>
    <?php endif; $actionHtml = ob_get_clean(); if (!$recvForm) { echo $actionHtml; } ?>


    <?php if (!empty($deliveryRoute)): $dr = $deliveryRoute; ?>
    <section class="card flush route-card">
      <div class="card-head pad"><h2>Lộ trình giao hàng</h2><span class="muted small"><span class="wh-code"><?= e($dr['from']['code']) ?></span> → <?= e($dr['to']['name']) ?><?= $dr['km'] > 0 ? ' · ' . e(n($dr['km'])) . ' km' : '' ?> · <?= e(['MAY' => 'xe máy', 'OTO_NHO' => 'ô tô nhỏ', 'TAI' => 'xe tải'][$dr['kind']] ?? 'xe') ?> <?= e($dr['plate']) ?></span></div>
      <div id="route-map" class="route-map route-map-sm" data-route="<?= e(json_encode($dr, JSON_UNESCAPED_UNICODE)) ?>"></div>
      <div class="route-foot pad"><small class="muted">Minh hoạ lộ trình giao hàng: điểm giao chọn ngẫu nhiên trong phường của địa chỉ, không phải GPS thật.</small>
        <span class="route-actions"><button type="button" class="btn-ghost" id="route-replay"><?= icon('truck') ?>Chạy lại</button>
        <?php if ($canApprove && empty($dr['arrived'])): ?><form method="post" action="/vouchers/<?= (int)$v['id'] ?>/delivered" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button><?= icon('check') ?>Tài xế đã giao đến nơi (demo)</button></form><?php endif; ?></span></div>
    </section>
    <?php endif; ?>

    <?php if (!empty($route)): ?>
    <section class="card flush route-card">
      <div class="card-head pad"><h2>Lộ trình</h2><span class="muted small"><span class="wh-code"><?= e($route['from']['code']) ?></span> → <span class="wh-code"><?= e($route['to']['code']) ?></span><?= $route['km'] > 0 ? ' · ' . e(n($route['km'])) . ' km' : '' ?> · xe <?= e($route['plate']) ?></span></div>
      <div id="route-map" class="route-map" data-route="<?= e(json_encode($route, JSON_UNESCAPED_UNICODE)) ?>"></div>
      <div class="route-foot pad"><small class="muted">Minh hoạ lộ trình, không phải vị trí GPS thật.</small><button type="button" class="btn-ghost" id="route-replay"><?= icon('truck') ?>Chạy lại</button></div>
    </section>
    <?php endif; ?>

    <section class="card flush">
      <div class="card-head pad"><h2>Dòng hàng <span class="count"><?= n(count($lines)) ?></span><?php if (!empty($load) && $load['weight_kg'] > 0): ?> <small class="muted">· <?= e(number_format($load['weight_kg'], $load['weight_kg'] < 10 ? 1 : 0, ',', '.')) ?> kg<?= $load['volume_m3'] > 0 ? ' · ' . e(number_format($load['volume_m3'], 2, ',', '.')) . ' m³' : '' ?></small><?php endif; ?></h2>
        <?php if ($showCheck): ?><?= $allOk ? '<span class="tag tag-ok">Đủ hàng</span>' : '<span class="tag tag-danger">Thiếu hàng</span>' ?><?php endif; ?></div>
      <div class="table-wrap"><table class="dense">
        <thead><tr><th>Hàng hoá</th><th>Lô</th><th class="num">Số lượng</th><?php if ($priced): ?><th class="num"><?= $v['loai'] === 'RECEIPT' ? 'Giá gốc' : 'Giá bán' ?></th><th class="num">Thuế</th><th class="num">Thành tiền</th><?php endif; ?><?php if ($recvForm || $shortBy): ?><th class="num">Thực nhận</th><?php endif; ?><?php if ($showCheck): ?><th class="num">Tồn hiện có</th><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($lines as $i => $l): $c = $check[$i] ?? null; ?>
          <tr><td><b><?= e($l['ma_hang']) ?></b> <?= e($l['ten']) ?><?= $l['ghi_chu'] !== '' ? '<br><small class="muted">' . e($l['ghi_chu']) . '</small>' : '' ?></td>
            <td><?= $l['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($l['so_lo']) . ($l['han_dung'] ? '<br><small class="muted">HSD ' . e(dt($l['han_dung'], false)) . '</small>' : '') ?></td>
            <td class="num"><b class="<?= $v['loai'] === 'ADJUSTMENT' ? ($l['so_luong'] > 0 ? 'pos' : 'neg') : '' ?>"><?= $v['loai'] === 'ADJUSTMENT' ? signed((int)$l['so_luong']) : n($l['so_luong']) ?></b> <small class="muted"><?= e($l['unit']) ?></small><?= $v['loai'] !== 'ADJUSTMENT' && ($pl = pack_label((int)$l['so_luong'], (int)($l['quy_cach_lo'] ?? 1))) !== '' ? '<br><small class="muted">= ' . e($pl) . '</small>' : '' ?></td>
            <?php if ($priced): $ln = (int)$l['so_luong'] * (int)($l['don_gia'] ?? 0); ?><td class="num money"><?= n((int)($l['don_gia'] ?? 0)) ?> đ</td><td class="num"><?= (int)$l['thue_suat'] ?>%</td><td class="num money"><b><?= n($ln) ?> đ</b></td><?php endif; ?>
            <?php if ($recvForm): ?><td class="num recv-cell"><input type="number" class="qty-input" name="received[<?= (int)$l['id'] ?>]" form="recv-form" min="0" max="<?= (int)$l['so_luong'] ?>" step="1" value="<?= (int)$l['so_luong'] ?>" data-recv="<?= (int)$l['so_luong'] ?>" aria-label="Thực nhận <?= e($l['ma_hang']) ?>"><small class="recv-short"></small></td>
            <?php elseif ($shortBy): $lost = $shortBy[$l['mat_hang_id'] . ':' . $l['lo_hang_id']] ?? 0; ?><td class="num"><b><?= n((int)$l['so_luong'] - $lost) ?></b> <small class="muted"><?= e($l['unit']) ?></small><?= $lost ? '<br><span class="tag tag-danger">Thiếu ' . n($lost) . '</span>' : '' ?></td><?php endif; ?>
            <?php if ($showCheck): ?><?php if ($c): ?><td class="num"><?= n($c['available']) ?></td><td><?= $c['ok'] ? '<span class="tag tag-ok">Đủ</span>' : '<span class="tag tag-danger">Thiếu ' . n(abs($c['available'] - (int)$l['so_luong'])) . '</span>' ?></td><?php else: ?><td></td><td></td><?php endif; ?><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php if ($priced && $sumNet > 0): ?>
      <div class="totals" aria-label="Tổng tiền phiếu">
        <div><span class="muted"><?= $v['loai'] === 'ISSUE' ? 'Tiền hàng (chưa thuế)' : 'Tiền hàng nhập (chưa thuế)' ?></span><span class="money"><?= n($sumNet) ?> đ</span></div>
        <div><span class="muted">Thuế GTGT</span><span class="money"><?= n($sumTax) ?> đ</span></div>
        <div class="grand"><span>Tổng thanh toán</span><span class="money"><?= n($sumNet + $sumTax) ?> đ</span></div>
      </div>
      <?php endif; ?>
    </section>

    <?php if (!empty($saleCodes)): $lineOf = array_column($lines, null, 'id'); ?>
    <section class="card flush sale-codes">
      <div class="card-head pad"><h2>Mã bảo hành <span class="count"><?= n(array_sum(array_map(fn($g) => count($g['units']), $saleCodes))) ?></span></h2><span class="muted small">Tra bảo hành cần cả <b>mã lô hàng</b> và <b>mã món hàng</b></span></div>
      <?php foreach ($saleCodes as $lineId => $g): $ln = $lineOf[$lineId] ?? null; $first = $g['units'][0]; ?>
      <div class="sale-code-line">
        <div class="sale-code-head"><b><?= e($ln['ma_hang'] ?? '') ?></b> <?= e($ln['ten'] ?? '') ?>
          <span class="muted small">· lô <?= e($ln && $ln['so_lo'] !== 'NOLOT' ? $ln['so_lo'] : '—') ?> · bảo hành <?= (int)$first['months'] ?> tháng, đến <?= e(dt($first['expiry'], false)) ?></span></div>
        <div class="sale-code-lot"><span class="muted small">Mã lô hàng</span> <code class="code-pill"><?= e($g['lot']) ?></code></div>
        <div class="muted small sale-code-sub">Mã món hàng — mỗi chiếc một mã</div>
        <ul class="sale-code-units"><?php foreach ($g['units'] as $u): ?><li><code class="code-pill<?= $u['void'] ? ' void' : '' ?>"><?= e($u['code']) ?></code><?= $u['void'] ? ' <small class="muted">đã huỷ</small>' : '' ?></li><?php endforeach; ?></ul>
      </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($draft && $v['loai'] === 'ISSUE' && $canApprove): ?>
    <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/confirm" class="card" id="confirm-form" data-once data-guard="local" data-guard-save="Lưu tạm"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <h2>Giao hàng cho khách</h2>
      <?php require __DIR__ . '/../partials/load_summary.php'; ?>
      <?php $ready = array_values($vehicles); $deliveryMode = true; $formId = ''; $cust = $custInfo ?? []; $saleTotal = $sumNet + $sumTax; require __DIR__ . '/../partials/pay_box.php'; ?>
      <button class="btn-block"<?= $allOk ? '' : ' disabled title="Một số dòng không đủ tồn"' ?>><?= icon('check') ?>Xác nhận &amp; ghi sổ</button>
      <button class="btn-ghost btn-danger-ghost btn-block" formaction="/vouchers/<?= (int)$v['id'] ?>/cancel" formnovalidate data-confirm="Huỷ phiếu nháp này?">Huỷ phiếu</button>
    </form>
    <?php endif; ?>

    <?php if ($recvForm) { echo $actionHtml; } /* nhận hàng: nhập số thực nhận ở bảng trên rồi mới tới nút xác nhận */ ?>

    <?php if (!empty($quality)): $qBad = array_sum(array_column($quality, 'so_luong_loi')); ?>
    <section class="card flush">
      <div class="card-head pad"><h2>Kiểm tra chất lượng khi nhận</h2>
        <span><?= $qBad ? '<span class="tag tag-warn">' . n($qBad) . ' không đạt</span>' : '<span class="tag tag-ok">Đạt toàn bộ</span>' ?>
        <?php if (!empty($returnNote)): ?> <a href="/returns/<?= (int)$returnNote['id'] ?>">Phiếu trả <?= e($returnNote['ma']) ?></a><?php endif; ?>
        <?php if (!empty($replaces)): ?> <span class="muted">· cấp lại cho</span> <a href="/returns/<?= (int)$replaces['id'] ?>"><?= e($replaces['ma']) ?></a><?php endif; ?></span></div>
      <div class="table-wrap"><table class="dense">
        <thead><tr><th>Hàng hoá</th><th>Lô</th><th class="num">Nhận</th><th class="num">Đạt (nhập kho)</th><th class="num">Không đạt (trả NCC)</th><th>Tình trạng</th></tr></thead>
        <tbody>
        <?php foreach ($quality as $q): $bad = (int)$q['so_luong_loi']; ?>
          <tr><td><b><?= e($q['ma_hang']) ?></b> <?= e($q['ten']) ?></td><td><?= $q['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($q['so_lo']) ?></td>
            <td class="num"><?= n($q['so_luong_nhan']) ?></td><td class="num"><b><?= n((int)$q['so_luong_nhan'] - $bad) ?></b></td><td class="num <?= $bad ? 'neg' : '' ?>"><?= n($bad) ?></td>
            <td><?= $bad ? e(\StockFlow\Service\SupplierReturnService::REASONS[$q['ly_do']] ?? $q['ly_do']) . ($q['ghi_chu'] !== '' ? '<br><small class="muted">' . e($q['ghi_chu']) . '</small>' : '') : '<span class="muted">—</span>' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php if ($draft && $qBad): ?><p class="muted small pad">Khi xác nhận, tồn kho chỉ cộng phần đạt và hệ thống tự lập phiếu trả nhà cung cấp cho phần không đạt.</p><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($movements): ?>
    <section class="card flush">
      <div class="card-head pad"><h2>Đã ghi vào sổ phát sinh</h2><span class="muted small"><?= count($movements) ?> dòng · bất biến</span></div>
      <div class="table-wrap"><table class="dense">
        <thead><tr><th>Kho</th><th>Thời gian</th><th class="num">Biến động</th><th class="num">Tồn sau</th></tr></thead>
        <tbody><?php foreach ($movements as $m): ?><tr><td><span class="wh-code"><?= e($m['wh_code']) ?></span></td><td class="muted"><?= e(dt($m['ngay_ghi_so'])) ?></td>
          <td class="num <?= $m['bien_dong'] > 0 ? 'pos' : 'neg' ?>"><b><?= signed((int)$m['bien_dong']) ?></b></td><td class="num"><?= n($m['ton_sau']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    </section>
    <?php endif; ?>

    <?php if (!empty($receipt)): $lack = array_sum(array_column($receipt['rows'], 'short'));
      $why = ($lack && str_contains($receipt['note'], ' · ')) ? trim(preg_replace('/^Nhận thiếu: /u', '', explode(' · ', $receipt['note'])[0])) : ''; ?>
    <section class="card flush">
      <div class="card-head pad"><h2>Thực nhận tại kho đến</h2>
        <span class="muted small"><?= e($receipt['by'] ?? '—') ?> · <?= e(dt($receipt['when'])) ?> · <?= $lack ? '<b class="neg">thiếu ' . n($lack) . '</b>' : 'nhận đủ' ?></span></div>
      <div class="table-wrap"><table class="dense">
        <thead><tr><th>Hàng hoá</th><th>Lô</th><th class="num">Theo phiếu</th><th class="num">Thực nhận</th><th class="num">Thiếu</th></tr></thead>
        <tbody><?php foreach ($receipt['rows'] as $rr): ?><tr>
          <td><b><?= e($rr['ma_hang']) ?></b> <?= e($rr['ten']) ?></td>
          <td><?= $rr['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($rr['so_lo']) ?></td>
          <td class="num"><?= n($rr['expected']) ?> <small class="muted"><?= e($rr['unit']) ?></small></td>
          <td class="num"><b class="<?= $rr['short'] ? 'neg' : 'pos' ?>"><?= n($rr['got']) ?></b></td>
          <td class="num"><?= $rr['short'] ? '<b class="neg">−' . n($rr['short']) . '</b>' : '<span class="muted">—</span>' ?></td></tr><?php endforeach; ?></tbody></table></div>
      <?php if ($why): ?><p class="pad small"><b>Lý do thiếu:</b> <?= e($why) ?></p><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($canBreak || $canRecall):
      // các xe đang chạy của đợt (một xe nếu phiếu không chia nhiều xe): chọn đúng xe cần báo hỏng / thu hồi
      $liveCars = $convoy
        ? array_values(array_filter($convoy, fn($c) => $c['trang_thai'] === 'IN_TRANSIT'))
        : [['phieu_di_id' => $v['id'], 'bien_so' => $trip['bien_so'], 'tai_xe_ten' => $trip['tai_xe_ten'], 'hong_ly_do' => $breakdown['ly_do'] ?? null]];
      $okCars = array_values(array_filter($liveCars, fn($c) => $c['hong_ly_do'] === null));
      $brokenCars = array_values(array_filter($liveCars, fn($c) => $c['hong_ly_do'] !== null));
      $carLabel = fn($c) => $c['bien_so'] . ' — ' . ($c['tai_xe_ten'] ?? '');
      // ô «xe nào»: nhiều xe thì chọn, một xe thì ghi rõ biển số + tài xế
      $carPick = function (array $cars, string $label) use ($v, $carLabel): string {
        if (count($cars) === 1) {
          return '<p class="car-pick"><span class="muted">' . $label . '</span> <b>' . e($cars[0]['bien_so']) . '</b> <span class="muted">· ' . e($cars[0]['tai_xe_ten'] ?? '') . '</span><input type="hidden" name="target" value="' . (int)$cars[0]['phieu_di_id'] . '"></p>';
        }
        $o = '';
        foreach ($cars as $c) { $o .= '<option value="' . (int)$c['phieu_di_id'] . '"' . ((int)$c['phieu_di_id'] === (int)$v['id'] ? ' selected' : '') . '>' . e($carLabel($c)) . '</option>'; }
        return '<label>' . $label . '<select name="target" required>' . $o . '</select></label>';
      }; ?>
    <div class="ops-pair">
    <?php if ($canBreak): ?>
    <section class="card">
      <h2>Xe gặp sự cố?</h2>
      <?php foreach ($brokenCars as $bc): ?>
      <div class="flash flash-err small"><?= icon('alert') ?><span><b>Xe <?= e($bc['bien_so']) ?> đang hỏng</b> — <?= e($bc['hong_ly_do']) ?>. Hàng tới muộn, kho đến chưa nhận được.</span></div>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/resume" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="target" value="<?= (int)$bc['phieu_di_id'] ?>">
        <button class="btn-block"><?= icon('truck') ?>Xe <?= e($bc['bien_so']) ?> đã sửa xong, chạy tiếp</button></form>
      <?php endforeach; ?>
      <?php if ($okCars): ?>
      <p class="muted small">Xe hỏng giữa đường thì báo ở đây: hàng vẫn đang vận chuyển nhưng tới muộn, kho đến sẽ thấy cảnh báo<?= $convoy ? '; các xe khác cùng đợt vẫn chạy bình thường' : '' ?>.</p>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/breakdown" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <?= $carPick($okCars, 'Xe nào bị hỏng?') ?>
        <label>Lý do<select name="reason" required><option value="">Chọn lý do…</option><?php foreach (\StockFlow\Service\TransferService::BREAKDOWN_REASONS as $br): ?><option><?= e($br) ?></option><?php endforeach; ?></select></label>
        <label>Ghi chú (không bắt buộc)<input name="note" maxlength="120" autocomplete="off" placeholder="VD: thủng lốp đoạn đèo, đã gọi cứu hộ"></label>
        <button class="btn-danger btn-block" data-confirm="Báo xe này hỏng giữa đường? Hàng sẽ tới muộn và kho đến chưa nhận được cho tới khi xe chạy tiếp."><?= icon('alert') ?>Báo xe hỏng</button>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($canRecall): ?>
    <section class="card">
      <h2>Thu hồi chuyến</h2>
      <p class="muted small"><?= $convoy ? 'Chỉ thu hồi phần hàng của xe được chọn — các xe khác cùng đợt vẫn chạy. ' : '' ?>Xe còn trên đường, kho đến chưa nhận. Thu hồi thì hàng được cộng lại vào kho <?= e($v['wh_code']) ?>, xe quay về kho này và phiếu nhận ở kho đến bị huỷ.</p>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/recall" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <?= $carPick($liveCars, 'Thu hồi xe nào?') ?>
        <label>Lý do<select name="reason" required><option value="">Chọn lý do…</option><?php foreach (\StockFlow\Service\TransferService::RECALL_REASONS as $rr): ?><option><?= e($rr) ?></option><?php endforeach; ?></select></label>
        <label>Ghi chú (không bắt buộc)<input name="note" maxlength="100" autocomplete="off" placeholder="VD: khách đổi địa chỉ nhận"></label>
        <button class="btn-danger btn-block" data-confirm="Bạn chắc chắn muốn thu hồi xe này? Xe sẽ quay đầu về kho <?= e($v['wh_code']) ?>, hàng được cộng lại vào tồn kho và phiếu nhận ở kho đến bị huỷ." data-confirm-ok="Thu hồi, cho xe quay đầu"><?= icon('alert') ?>Thu hồi chuyến</button>
      </form>
    </section>
    <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <aside>
    <section class="card">
      <h2>Thông tin</h2>
      <dl class="kv">
        <?php if ($isXfer): ?>
        <dt><?= $v['loai'] === 'TRANSFER_IN' ? 'Kho chuyển đi' : 'Kho chuyển đến' ?></dt><dd><?= $v['cp_code'] ? '<span class="wh-code">' . e($v['cp_code']) . '</span> ' . e($v['cp_name']) : '—' ?></dd>
        <?php elseif ($v['loai'] === 'ADJUSTMENT'): ?>
        <dt>Lý do</dt><dd><?= e($v['ly_do'] ?: '—') ?></dd>
        <?php else: ?>
        <dt><?= $v['loai'] === 'RECEIPT' ? 'Nhà cung cấp' : 'Khách hàng' ?></dt><dd><?= e($v['doi_tac'] ?: '—') ?></dd>
        <?php endif; ?>
        <?php if ($v['ghi_chu'] !== ''): ?><dt>Ghi chú</dt><dd><?= e($v['ghi_chu']) ?></dd><?php endif; ?>
        <dt>Người lập</dt><dd><?= e($v['creator_name']) ?></dd>
        <?php if ($v['approver_name']): ?><dt><?= $cancelled ? 'Xử lý' : 'Xác nhận' ?></dt><dd><?= e($v['approver_name']) ?><?= $v['ngay_xac_nhan'] ? '<br><small class="muted">' . e(dt($v['ngay_xac_nhan'])) . '</small>' : '' ?></dd><?php endif; ?>
        <?php if ($pair): ?><dt>Phiếu đối ứng</dt><dd><a href="/vouchers/<?= (int)$pair['id'] ?>"><?= e($pair['ma']) ?></a></dd><?php endif; ?>
      </dl>
    </section>

    <?php if (!empty($delivery)): ?>
    <section class="card">
      <h2>Giao hàng</h2>
      <dl class="kv">
        <dt>Hình thức</dt><dd><span class="tag <?= $delivery['hinh_thuc'] === 'TU_LAY' ? 'tag-ok' : 'tag-info' ?>"><?= e(\StockFlow\Service\DeliveryService::MODES[$delivery['hinh_thuc']] ?? $delivery['hinh_thuc']) ?></span><?= $delivery['hinh_thuc'] === 'THUE_NGOAI' ? ' <b>' . e($delivery['don_vi_van_tai']) . '</b>' : '' ?></dd>
        <dt>Thanh toán</dt><dd><?php if ($delivery['thanh_toan'] === 'COD'): ?><span class="tag tag-warn">Thu khi nhận hàng</span> <b><?= e(n($delivery['so_tien'])) ?> đ</b><?php else: ?><span class="tag tag-ok">Đã thanh toán</span> <?= e(\StockFlow\Service\DeliveryService::METHODS[$delivery['phuong_thuc_tt']] ?? '') ?> <small class="muted"><?= e(n($delivery['so_tien'])) ?> đ</small><?php endif; ?></dd>
        <?php if ($delivery['hinh_thuc'] !== 'TU_LAY'): ?>
        <dt>Tình trạng</dt><dd><?= !empty($delivery['ngay_giao_den']) ? '<span class="tag tag-ok">Đã giao đến nơi</span><br><small class="muted">' . e(dt($delivery['ngay_giao_den'])) . '</small>' : '<span class="tag tag-info">Đang giao</span>' ?></dd>
        <dt>Giao đến</dt><dd><?= e($delivery['dia_chi']) ?><br><small class="muted">Người nhận: <?= e($delivery['nguoi_nhan']) ?> · <?= e($delivery['sdt_nhan']) ?></small></dd>
        <dt>Xe</dt><dd><b><?= e($delivery['bien_so']) ?></b><?= $delivery['loai_xe'] ? ' · ' . e($delivery['loai_xe']) : '' ?></dd>
        <dt>Tài xế</dt><dd><b><?= e($delivery['tai_xe_ten']) ?></b><br><small class="muted">CCCD <?= e($delivery['cccd']) ?><?= $delivery['so_dien_thoai'] ? ' · ' . e($delivery['so_dien_thoai']) : '' ?></small></dd>
        <dt>Tiền công</dt><dd><b><?= e(n($delivery['tien_tra'])) ?> đ</b> <small class="muted">· <?= e(n($delivery['km'])) ?> km × <?= e(n($delivery['don_gia_km'])) ?> đ/km = <?= e(n($delivery['tien_goi_y'])) ?> đ gợi ý</small><?= (int)$delivery['tien_tra'] !== (int)$delivery['tien_goi_y'] ? '<br><span class="tag tag-warn">Khác gợi ý</span>' . ($delivery['ghi_chu_gia'] !== '' ? ' <small class="muted">' . e($delivery['ghi_chu_gia']) . '</small>' : '') : '' ?></dd>
        <?php endif; ?>
      </dl>
    </section>
    <?php endif; ?>

    <?php if ($convoy): $meOut = (int)($v['loai'] === 'TRANSFER_OUT' ? $v['id'] : $v['phieu_nhap_xuat_lien_quan_id']); ?>
    <section class="card">
      <h2>Đợt điều chuyển <span class="count"><?= count($convoy) ?> xe</span></h2>
      <ul class="rows tight convoy-list">
        <?php foreach ($convoy as $c): $mine = (int)$c['phieu_di_id'] === $meOut;
              [$cLabel, $cTone] = match (true) {
                  $c['trang_thai'] === 'DELIVERED' => ['Đã nhận', 'tag-ok'], $c['trang_thai'] === 'RECALLED' => ['Đã thu hồi', 'tag-warn'], $c['trang_thai'] === 'LOST' => ['Mất hàng', 'tag-danger'],
                  $c['hong_ly_do'] !== null => ['Xe hỏng', 'tag-danger'], default => ['Đang chạy', 'tag-info'],
              }; ?>
        <li><span><b><?= e($c['bien_so'] ?? '—') ?></b> <small class="muted">· <?= e($c['tai_xe_ten'] ?? '') ?></small><br>
          <?php if ($mine): ?><small class="muted">Phiếu này</small>
          <?php else: $to = $v['loai'] === 'TRANSFER_OUT' ? ['id' => $c['phieu_di_id'], 'ma' => $c['ma_di']] : ['id' => $c['phieu_den_id'], 'ma' => $c['ma_den']]; ?>
          <a href="/vouchers/<?= (int)$to['id'] ?>"><small><?= e($to['ma']) ?></small></a><?php endif; ?></span>
          <span class="tag <?= $cTone ?>"><?= e($cLabel) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($trip): ?>
    <section class="card">
      <h2>Vận chuyển</h2>
      <dl class="kv">
        <?php if ($trip['hinh_thuc'] === 'THUE_NGOAI'): ?><dt>Hình thức</dt><dd><span class="tag tag-warn">Thuê ngoài</span> <b><?= e($trip['don_vi_van_tai']) ?></b></dd><?php endif; ?>
        <dt>Xe</dt><dd><b><?= e($trip['bien_so']) ?></b><?= $trip['loai_xe'] ? ' · ' . e($trip['loai_xe']) : '' ?></dd>
        <dt>Tài xế</dt><dd><b><?= e($trip['tai_xe_ten']) ?></b><br><small class="muted">CCCD <?= e($trip['cccd']) ?><?= $trip['so_dien_thoai'] ? ' · ' . e($trip['so_dien_thoai']) : '' ?></small></dd>
        <?php if ((int)$trip['km'] > 0): ?><dt>Tiền công</dt><dd><b><?= e(n($trip['tien_tra'])) ?> đ</b> <small class="muted">· <?= e(n($trip['km'])) ?> km × <?= e(n($trip['don_gia_km'])) ?> đ/km = <?= e(n($trip['tien_goi_y'])) ?> đ gợi ý</small><?= (int)$trip['tien_tra'] !== (int)$trip['tien_goi_y'] ? '<br><span class="tag tag-warn">Khác gợi ý</span>' . ($trip['ghi_chu_gia'] !== '' ? ' <small class="muted">' . e($trip['ghi_chu_gia']) . '</small>' : '') : '' ?></dd><?php endif; ?>
        <dt>Xuất kho</dt><dd><?= e(dt($trip['ngay_di'])) ?> · <?= e($trip['giao_ten']) ?><br><span class="tag tag-ok">Tài xế xác nhận đủ hàng</span></dd>
        <?php if ($breakdown): ?><dt>Tình trạng</dt><dd><span class="tag tag-danger">Xe hỏng giữa đường</span><br><small><?= e($breakdown['ly_do']) ?></small></dd><?php endif; ?>
        <dt>Kho đến</dt><dd>
          <?php if ($trip['trang_thai'] === 'RECALLED'): ?><span class="tag tag-warn">Đã thu hồi chuyến</span><br><small><?= e(preg_replace('/^Kho đi thu hồi chuyến: /u', '', $trip['sai_lech'])) ?></small>
          <?php elseif ($trip['trang_thai'] === 'LOST'): ?><span class="tag tag-danger">Đã xác nhận mất hàng</span><br><small><?= e($trip['sai_lech']) ?></small>
          <?php elseif ($trip['trang_thai'] === 'DELIVERED'): ?><?= $trip['sai_lech'] !== '' ? '<span class="tag tag-danger">Nhận thiếu</span>' : '<span class="tag tag-ok">Hàng đủ</span> <span class="tag tag-ok">Đúng tài xế, xe</span>' ?><br><small class="muted"><?= e(dt($trip['ngay_nhan'])) ?> · <?= e($trip['nhan_ten']) ?></small>
          <?php elseif ($trip['sai_lech'] !== ''): ?><span class="tag tag-danger">Chưa nhận được hàng</span><br><small><?= e($trip['sai_lech']) ?></small><br><small class="muted"><?= e($trip['nhan_ten'] ?? '') ?></small><?= $user->isAdmin ? '<br><a href="/fleet">Theo dõi ở Đội xe →</a>' : '' ?>
          <?php else: ?><span class="muted">Chưa nhận hàng</span><?php endif; ?></dd>
      </dl>
    </section>
    <?php endif; ?>

    <?php if (!empty($replies)): ?>
    <section class="card">
      <h2>Phản hồi từ quản trị <span class="count"><?= count($replies) ?></span></h2>
      <ul class="rows tight"><?php foreach ($replies as $rp): ?><li><span><?= e($rp['noi_dung']) ?><br><small class="muted"><?= e($rp['ho_ten'] ?? '—') ?> · <?= e(dt($rp['ngay_tao'])) ?></small></span></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>

    <?php if ($canCorrect && !$correction && $canAdjust): ?>
    <details class="card card-fold">
      <summary>Sửa sai phiếu này</summary>
      <p class="muted small">Phiếu đã ghi sổ không sửa hoặc xoá được. Lập phiếu điều chỉnh kèm lý do để hoàn lại số lượng<?= $isTransfer ? ' ở cả hai kho trong một giao dịch' : '' ?>; lịch sử cũ vẫn được giữ nguyên.</p>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/correct" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Lý do điều chỉnh<textarea name="reason" rows="3" maxlength="255" required placeholder="VD: Nhập nhầm số lượng, thực nhận 90"></textarea></label>
        <button class="btn-danger" data-confirm="Lập phiếu điều chỉnh để hoàn lại số lượng của phiếu này? Việc này không xoá lịch sử cũ.">Lập phiếu điều chỉnh</button>
      </form>
    </details>
    <?php endif; ?>

    <?php if ($trail): ?>
    <details class="card card-fold">
      <summary>Nhật ký thao tác <span class="count"><?= count($trail) ?></span></summary>
      <ul class="rows tight"><?php foreach ($trail as $a): ?><li><span><b><?= e(audit_label($a['hanh_dong'])) ?></b><br><small class="muted"><?= e($a['ho_ten'] ?? '—') ?> · <?= e(dt($a['ngay_tao'])) ?></small></span></li><?php endforeach; ?></ul>
    </details>
    <?php endif; ?>

    <?php if ($headActions): ?>
    <div class="side-actions">
      <?php if ($canApprove): ?>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/confirm" id="confirm-form" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button<?= $allOk ? '' : ' disabled title="Một số dòng không đủ tồn"' ?>><?= icon('check') ?>Xác nhận &amp; ghi sổ</button></form>
      <?php endif; ?>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/cancel" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn-ghost btn-danger-ghost" data-confirm="Huỷ phiếu nháp này?">Huỷ phiếu</button></form>
    </div>
    <?php endif; ?>
  </aside>
</div>
