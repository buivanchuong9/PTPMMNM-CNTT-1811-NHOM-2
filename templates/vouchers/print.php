<?php /** @var array $v @var array $lines @var array $wh @var ?array $partner @var bool $fresh @var ?array $trip */
$loai = $v['loai'];
$title = match ($loai) {
    'RECEIPT' => 'Phiếu nhập kho', 'ISSUE' => 'Phiếu xuất kho',
    'TRANSFER_OUT' => 'Phiếu điều chuyển kho — xuất đi', default => 'Phiếu điều chuyển kho — nhận hàng',
};
$priced = true;   // điều chuyển tính theo giá gốc chung của các kho
$taxed = in_array($loai, ['RECEIPT', 'ISSUE'], true);   // chỉ phiếu nhập / xuất có thuế GTGT
// người ký bên cột 3: bên ngoài kho giao / nhận hàng
$third = match ($loai) { 'RECEIPT' => 'Người giao hàng', 'ISSUE' => 'Người nhận hàng', 'TRANSFER_OUT' => 'Tài xế nhận hàng', default => 'Tài xế giao hàng' };
$partnerLabel = match ($loai) { 'RECEIPT' => 'Nhà cung cấp', 'ISSUE' => 'Khách hàng', default => $loai === 'TRANSFER_OUT' ? 'Kho nhận' : 'Kho gửi' };
// chuyến đã giao: thêm cột thực nhận tại kho đến (và cột thiếu nếu nhận thiếu) + lý do thiếu
$got = [];
foreach ($receipt['rows'] ?? [] as $rr) { $got[$rr['ma_hang'] . '|' . $rr['so_lo']] = $rr; }
$lack = array_sum(array_column($receipt['rows'] ?? [], 'short'));
$sumGot = array_sum(array_column($receipt['rows'] ?? [], 'got'));
$why = ($lack && !empty($receipt['note']) && str_contains($receipt['note'], ' · ')) ? trim(preg_replace('/^Nhận thiếu: /u', '', explode(' · ', $receipt['note'])[0])) : '';
// phiếu nhận hàng: tiền tính theo số THỰC NHẬN; các phiếu khác theo số trên phiếu
$qtyOf = fn(array $l): int => $loai === 'TRANSFER_IN' && isset($got[$l['ma_hang'] . '|' . $l['so_lo']]) ? (int)$got[$l['ma_hang'] . '|' . $l['so_lo']]['got'] : (int)$l['so_luong'];
$sumNet = $sumTax = $sumQty = 0;
foreach ($lines as $l) {
    $net = $qtyOf($l) * (int)($l['don_gia'] ?? 0);
    $sumNet += $net;
    $sumTax += $taxed ? (int)round($net * (int)$l['thue_suat'] / 100) : 0;
    $sumQty += (int)$l['so_luong'];
}
$when = $v['ngay_xac_nhan'] ?: $v['ngay_tao'];
$qcRows = $loai === 'RECEIPT' ? ($quality ?? []) : [];   // phiếu nhập: kết quả kiểm tra chất lượng từng dòng
$qcBad = array_sum(array_column($qcRows, 'so_luong_loi'));
$qcLabels = \StockFlow\Service\SupplierReturnService::REASONS;
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($v['ma']) ?> — <?= e($title) ?></title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #eceff3; color: #111; font: 14px/1.5 "Times New Roman", Times, serif; }
  .bar { position: sticky; top: 0; z-index: 2; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 10px 16px; background: #fff; border-bottom: 1px solid #d9dde3; font-family: system-ui, "Segoe UI", sans-serif; }
  .bar .ok { color: #137a3c; font-weight: 600; margin-right: auto; }
  .bar a, .bar button { font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 8px; border: 1px solid #c9ced6; background: #fff; color: #222; text-decoration: none; cursor: pointer; }
  .bar button { background: #1e3a5f; border-color: #1e3a5f; color: #fff; }
  .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 14mm 16mm; background: #fff; box-shadow: 0 2px 16px rgba(0,0,0,.12); }
  .top { display: flex; justify-content: space-between; gap: 16px; }
  .top b { font-size: 1.05em; text-transform: uppercase; }
  .top small, .meta { color: #444; }
  .top .no { text-align: right; white-space: nowrap; }
  h1 { margin: 14px 0 2px; text-align: center; font-size: 22px; letter-spacing: .02em; text-transform: uppercase; }
  .date { margin: 0 0 12px; text-align: center; font-style: italic; }
  dl { display: grid; grid-template-columns: 130px 1fr; gap: 3px 10px; margin: 0 0 12px; }
  dt { color: #444; } dd { margin: 0; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #222; padding: 5px 7px; vertical-align: top; }
  th { background: #f1f1f1; text-align: center; font-size: 13px; }
  td.c { text-align: center; } td.r { text-align: right; white-space: nowrap; }
  tfoot td { font-weight: 700; }
  .sum { margin: 10px 0 0 auto; width: 300px; }
  .sum div { display: flex; justify-content: space-between; gap: 20px; }
  .sum .grand { border-top: 1px solid #222; margin-top: 3px; padding-top: 3px; font-weight: 700; font-size: 15px; }
  .words { margin: 10px 0 0; }
  .warranty { margin-top: 16px; break-inside: avoid; }
  .warranty h3 { margin: 0 0 4px; font-size: 14px; }
  .warranty .wl { margin-top: 8px; }
  .warranty .lot { font-family: Consolas, 'Courier New', monospace; font-weight: 700; letter-spacing: .04em; }
  .warranty .units { display: grid; grid-template-columns: repeat(4, 1fr); gap: 2px 10px; margin-top: 3px; font-family: Consolas, 'Courier New', monospace; font-size: 12.5px; letter-spacing: .03em; }
  .warranty .note { color: #555; font-size: 12px; margin-top: 4px; }
  .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 26px; text-align: center; break-inside: avoid; }
  .sign b { display: block; } .sign i { color: #555; font-size: 12px; }
  .sign .space { height: 92px; }
  .sign .who { font-weight: 700; }
  .foot { margin-top: 18px; color: #555; font-size: 12px; text-align: center; }
  @page { size: A4; margin: 0; }
  @media print {
    body { background: #fff; }
    .bar { display: none; }
    .sheet { margin: 0; box-shadow: none; width: auto; min-height: 0; padding: 14mm 16mm; }
  }
  @media (max-width: 800px) { .sheet { width: auto; margin: 0; padding: 16px; min-height: 0; } .sign { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="bar">
  <?php if ($fresh): ?><span class="ok">✓ Đã xác nhận và ghi sổ — in phiếu để các bên ký</span><?php else: ?><span class="ok"></span><?php endif; ?>
  <a href="/vouchers/<?= (int)$v['id'] ?>">← Về phiếu</a>
  <button type="button" data-print>In phiếu</button>
</div>

<main class="sheet">
  <div class="top">
    <div><b><?= e(APP_NAME) ?></b><br><span class="meta">Kho: <?= e($wh['ma']) ?> — <?= e($wh['ten']) ?><?= $wh['dia_chi'] !== '' ? '<br>' . e($wh['dia_chi']) : '' ?></span></div>
    <div class="no"><b><?= e($v['ma']) ?></b><br><small>Mẫu số 01-VT (nội bộ)</small></div>
  </div>

  <h1><?= e($title) ?></h1>
  <p class="date">Ngày <?= e(dt($when, false)) ?>, <?= e(substr(dt($when), -5)) ?></p>

  <dl>
    <?php if ($loai === 'TRANSFER_OUT' || $loai === 'TRANSFER_IN'): ?>
    <dt><?= e($partnerLabel) ?>:</dt><dd><?= $v['cp_code'] ? e($v['cp_code'] . ' — ' . $v['cp_name']) : '—' ?></dd>
    <?php if ($trip): ?>
    <?php if ($trip['hinh_thuc'] === 'THUE_NGOAI'): ?><dt>Đơn vị vận tải:</dt><dd><b><?= e($trip['don_vi_van_tai']) ?></b> (thuê ngoài)</dd><?php endif; ?>
    <dt>Xe / tài xế:</dt><dd><?= e($trip['bien_so']) ?><?= $trip['loai_xe'] ? ' (' . e($trip['loai_xe']) . ')' : '' ?> — <?= e($trip['tai_xe_ten']) ?> · CCCD <?= e($trip['cccd']) ?><?= $trip['so_dien_thoai'] ? ' · ' . e($trip['so_dien_thoai']) : '' ?></dd>
    <?php if ((int)$trip['km'] > 0): ?><dt>Quãng đường:</dt><dd><?= e(n($trip['km'])) ?> km<?= $trip['hinh_thuc'] === 'THUE_NGOAI' ? ' · tiền thuê vận chuyển ' . e(n($trip['tien_tra'])) . ' đ' : '' ?></dd><?php endif; ?>
    <?php endif; ?>
    <?php else: ?>
    <dt><?= e($partnerLabel) ?>:</dt><dd><b><?= e($v['doi_tac'] ?: '—') ?></b></dd>
    <?php if ($partner && ($partner['nguoi_lien_he'] !== '' || $partner['so_dien_thoai'] !== '')): ?><dt>Liên hệ:</dt><dd><?= e(trim($partner['nguoi_lien_he'] . ($partner['so_dien_thoai'] !== '' ? ' · ' . $partner['so_dien_thoai'] : ''), ' ·')) ?></dd><?php endif; ?>
    <?php if ($partner && $partner['dia_chi'] !== ''): ?><dt>Địa chỉ:</dt><dd><?= e($partner['dia_chi']) ?></dd><?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($delivery)): ?>
    <dt>Giao hàng:</dt><dd><b><?= e(\StockFlow\Service\DeliveryService::MODES[$delivery['hinh_thuc']] ?? $delivery['hinh_thuc']) ?></b><?= $delivery['hinh_thuc'] === 'THUE_NGOAI' ? ' — ' . e($delivery['don_vi_van_tai']) : '' ?></dd>
    <dt>Thanh toán:</dt><dd><?php if ($delivery['thanh_toan'] === 'COD'): ?><b>Thu tiền khi nhận hàng: <?= e(n($delivery['so_tien'])) ?> đ</b><?php else: ?>Khách đã thanh toán<?= !empty($delivery['phuong_thuc_tt']) ? ' (' . e(\StockFlow\Service\DeliveryService::METHODS[$delivery['phuong_thuc_tt']] ?? '') . ')' : '' ?><?php endif; ?></dd>
    <?php if ($delivery['hinh_thuc'] !== 'TU_LAY'): ?>
    <dt>Giao đến:</dt><dd><?= e($delivery['dia_chi']) ?> — người nhận <?= e($delivery['nguoi_nhan']) ?> · <?= e($delivery['sdt_nhan']) ?></dd>
    <dt>Xe / tài xế:</dt><dd><?= e($delivery['bien_so']) ?><?= $delivery['loai_xe'] ? ' (' . e($delivery['loai_xe']) . ')' : '' ?> — <?= e($delivery['tai_xe_ten']) ?> · CCCD <?= e($delivery['cccd']) ?><?= $delivery['so_dien_thoai'] ? ' · ' . e($delivery['so_dien_thoai']) : '' ?></dd>
    <?php endif; endif; ?>
    <?php if ($v['ghi_chu'] !== ''): ?><dt>Ghi chú:</dt><dd><?= e($v['ghi_chu']) ?></dd><?php endif; ?>
  </dl>

  <table>
    <thead><tr><th style="width:36px">STT</th><th>Tên hàng hoá</th><th style="width:90px">Lô / HSD</th><th style="width:50px">ĐVT</th><th style="width:74px"><?= $receipt ? 'Theo phiếu' : ($qcRows ? 'Nhập kho' : 'Số lượng') ?></th><?php if ($receipt): ?><th style="width:74px">Thực nhận</th><?php if ($lack): ?><th style="width:60px">Thiếu</th><?php endif; endif; ?>
      <?php if ($priced): ?><th style="width:90px"><?= $taxed ? 'Đơn giá' : 'Giá gốc' ?></th><th style="width:100px">Thành tiền</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($lines as $i => $l): $ln = $qtyOf($l) * (int)($l['don_gia'] ?? 0); ?>
      <tr><td class="c"><?= $i + 1 ?></td>
        <td><b><?= e($l['ma_hang']) ?></b> <?= e($l['ten']) ?></td>
        <td class="c"><?= $l['so_lo'] === 'NOLOT' ? '—' : e($l['so_lo']) . ($l['han_dung'] ? '<br><small>' . e(dt($l['han_dung'], false)) . '</small>' : '') ?></td>
        <td class="c"><?= e($l['unit']) ?></td>
        <td class="r"><?= n($l['so_luong']) ?></td>
        <?php if ($receipt): $g = $got[$l['ma_hang'] . '|' . $l['so_lo']] ?? null; ?><td class="r"><b><?= n($g['got'] ?? $l['so_luong']) ?></b></td><?php if ($lack): ?><td class="r"><?= !empty($g['short']) ? '−' . n($g['short']) : '—' ?></td><?php endif; endif; ?>
        <?php if ($priced): ?><td class="r"><?= n((int)($l['don_gia'] ?? 0)) ?></td><td class="r"><?= n($ln) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="4" style="text-align:right">Cộng</td><td class="r"><?= n($sumQty) ?></td><?php if ($receipt): ?><td class="r"><?= n($sumGot) ?></td><?php if ($lack): ?><td class="r">−<?= n($lack) ?></td><?php endif; endif; ?><?php if ($priced): ?><td></td><td class="r"><?= n($sumNet) ?></td><?php endif; ?></tr></tfoot>
  </table>

  <?php if ($receipt): ?>
  <p class="words"><b>Thực nhận tại kho đến:</b> <?= e($receipt['by'] ?? '—') ?> · <?= e(dt($receipt['when'])) ?> · <?= $lack ? '<b>thiếu ' . n($lack) . '</b>' : 'nhận đủ' ?><?= $why !== '' ? '<br><b>Lý do thiếu:</b> ' . e($why) : '' ?></p>
  <?php endif; ?>

  <?php if ($qcRows): ?>
  <p class="words" style="margin-top:14px"><b>Kết quả kiểm tra chất lượng khi nhận hàng</b></p>
  <table>
    <thead><tr><th style="width:36px">STT</th><th>Tên hàng hoá</th><th style="width:80px">Lô</th><th style="width:70px">Nhận</th><th style="width:70px">Đạt<br>(nhập kho)</th><th style="width:70px">Không đạt<br>(trả NCC)</th><th>Tình trạng / ghi chú</th><th style="width:78px">Kết luận</th></tr></thead>
    <tbody>
    <?php foreach ($qcRows as $i => $q): $bad = (int)$q['so_luong_loi']; $ok = (int)$q['so_luong_nhan'] - $bad; ?>
      <tr><td class="c"><?= $i + 1 ?></td><td><b><?= e($q['ma_hang']) ?></b> <?= e($q['ten']) ?></td><td class="c"><?= $q['so_lo'] === 'NOLOT' ? '—' : e($q['so_lo']) ?></td>
        <td class="r"><?= n($q['so_luong_nhan']) ?></td><td class="r"><b><?= n($ok) ?></b></td><td class="r"><?= $bad ? '<b>' . n($bad) . '</b>' : '0' ?></td>
        <td><?= $bad ? e($qcLabels[$q['ly_do']] ?? $q['ly_do']) . ($q['ghi_chu'] !== '' ? ' — ' . e($q['ghi_chu']) : '') : '—' ?></td>
        <td class="c"><b><?= $bad === 0 ? 'Đạt' : ($ok === 0 ? 'Không đạt' : 'Đạt một phần') ?></b></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="3" style="text-align:right">Cộng</td><td class="r"><?= n(array_sum(array_column($qcRows, 'so_luong_nhan'))) ?></td><td class="r"><?= n(array_sum(array_column($qcRows, 'so_luong_nhan')) - $qcBad) ?></td><td class="r"><?= n($qcBad) ?></td><td colspan="2"></td></tr></tfoot>
  </table>
  <?php if ($qcBad && !empty($returnNote)): ?><p class="words"><b>Hàng không đạt chất lượng:</b> đã lập phiếu trả nhà cung cấp <b><?= e($returnNote['ma']) ?></b>, chờ nhà cung cấp cấp lại đúng số lượng. Tồn kho chỉ cộng phần đạt.</p><?php endif; ?>
  <?php if (!empty($replaces)): ?><p class="words"><b>Hàng cấp lại</b> cho phiếu trả <b><?= e($replaces['ma']) ?></b>.</p><?php endif; ?>
  <?php endif; ?>

  <?php if ($priced && $sumNet > 0): ?>
  <div class="sum">
    <?php if ($taxed): ?>
    <div><span>Tiền hàng (chưa thuế)</span><span><?= n($sumNet) ?> đ</span></div>
    <div><span>Thuế GTGT</span><span><?= n($sumTax) ?> đ</span></div>
    <?php endif; ?>
    <div class="grand"><span><?= $taxed ? 'Tổng thanh toán' : 'Tổng giá trị hàng chuyển' ?></span><span><?= n($sumNet + $sumTax) ?> đ</span></div>
  </div>
  <p class="words"><i>Bằng chữ: <?= e(vnd_words($sumNet + $sumTax)) ?>.</i></p>
  <?php endif; ?>

  <?php if (!empty($saleCodes)): $lineOf = array_column($lines, null, 'id'); ?>
  <div class="warranty">
    <h3>Mã bảo hành — giữ phiếu này để tra bảo hành</h3>
    <?php foreach ($saleCodes as $lineId => $g): $ln = $lineOf[$lineId] ?? null; $first = $g['units'][0]; ?>
    <div class="wl"><b><?= e($ln['ma_hang'] ?? '') ?></b> <?= e($ln['ten'] ?? '') ?> — bảo hành <?= (int)$first['months'] ?> tháng, đến <?= e(dt($first['expiry'], false)) ?><br>
      Mã lô hàng: <span class="lot"><?= e($g['lot']) ?></span> · <?= n(count($g['units'])) ?> mã món hàng (mỗi chiếc một mã):
      <div class="units"><?php foreach ($g['units'] as $u): ?><span><?= e($u['code']) ?></span><?php endforeach; ?></div></div>
    <?php endforeach; ?>
    <div class="note">Tra bảo hành cần cả mã lô hàng và mã món hàng của chiếc hàng.</div>
  </div>
  <?php endif; ?>

  <div class="sign"<?= $qcRows ? ' style="grid-template-columns:repeat(4,1fr)"' : '' ?>>
    <div><b>Người lập phiếu</b><i>(ký, ghi rõ họ tên)</i><div class="space"></div><span class="who"><?= e($v['creator_name']) ?></span></div>
    <div><b>Thủ kho / Quản lý kho</b><i>(ký, ghi rõ họ tên)</i><div class="space"></div><span class="who"><?= e($v['approver_name'] ?? '') ?></span></div>
    <?php if ($qcRows): ?><div><b>Người kiểm tra chất lượng</b><i>(ký, ghi rõ họ tên)</i><div class="space"></div><span class="who"></span></div><?php endif; ?>
    <div><b><?= e($third) ?></b><i>(ký, ghi rõ họ tên)</i><div class="space"></div><span class="who"><?= $trip && in_array($loai, ['TRANSFER_OUT', 'TRANSFER_IN'], true) ? e($trip['tai_xe_ten']) : (!empty($delivery['ngay_giao_den']) ? e($delivery['nguoi_nhan']) : '') ?></span></div>
  </div>
  <p class="foot">Phiếu đã ghi sổ lúc <?= e(dt($when)) ?> · <?= e($v['ma']) ?> · in từ hệ thống <?= e(APP_NAME) ?></p>
</main>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
