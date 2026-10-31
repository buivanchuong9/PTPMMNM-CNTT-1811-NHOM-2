<?php /** @var array $b @var string $today */
$st = \StockFlow\Service\WarrantyService::STATUS[$b['trang_thai']][0] ?? $b['trang_thai'];
$returned = $b['trang_thai'] === 'DA_TRA';
$wholeLot = $b['san_pham_ban_id'] === null && !empty($b['ma_lo']);
$codes = $wholeLot ? 'Cả lô — mã lô hàng ' . $b['ma_lo'] : trim('Mã lô hàng ' . ($b['sale_lot'] ?? $b['ma_lo'] ?? '') . ' · mã món hàng ' . ($b['sale_item'] ?? ''), ' ·');
$received = $b['ngay_nhan'] ?: substr((string)$b['ngay_tao'], 0, 10);
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($b['ma']) ?> — Phiếu nhận bảo hành</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #eceff3; color: #111; font: 14px/1.5 "Times New Roman", Times, serif; }
  .bar { position: sticky; top: 0; z-index: 2; display: flex; gap: 10px; align-items: center; padding: 10px 16px; background: #fff; border-bottom: 1px solid #d9dde3; font-family: system-ui, "Segoe UI", sans-serif; }
  .bar a, .bar button { font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 8px; border: 1px solid #c9ced6; background: #fff; color: #222; text-decoration: none; cursor: pointer; }
  .bar button { margin-left: auto; background: #1e3a5f; border-color: #1e3a5f; color: #fff; }
  .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 14mm 16mm; background: #fff; box-shadow: 0 2px 16px rgba(0,0,0,.12); }
  .top { display: flex; justify-content: space-between; gap: 16px; }
  .top b { text-transform: uppercase; } .top small { color: #444; } .top .no { text-align: right; white-space: nowrap; }
  h1 { margin: 14px 0 2px; text-align: center; font-size: 22px; letter-spacing: .02em; text-transform: uppercase; }
  .date { margin: 0 0 14px; text-align: center; font-style: italic; }
  dl { display: grid; grid-template-columns: 170px 1fr; gap: 4px 10px; margin: 0 0 14px; }
  dt { color: #444; } dd { margin: 0; }
  h2 { margin: 16px 0 6px; font-size: 15px; text-transform: uppercase; border-bottom: 1px solid #222; }
  .code { font-family: Consolas, 'Courier New', monospace; font-weight: 700; letter-spacing: .04em; }
  .ok { color: #137a3c; font-weight: 700; } .bad { color: #b3261e; font-weight: 700; }
  .sign { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 26px; text-align: center; break-inside: avoid; }
  .sign b { display: block; } .sign i { color: #555; font-size: 12px; } .sign .space { height: 88px; }
  .note { margin-top: 14px; color: #444; font-size: 13px; }
  .foot { margin-top: 18px; color: #555; font-size: 12px; text-align: center; }
  @page { size: A4; margin: 0; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; box-shadow: none; width: auto; min-height: 0; } }
  @media (max-width: 800px) { .sheet { width: auto; margin: 0; padding: 16px; min-height: 0; } }
</style>
</head>
<body>
<div class="bar"><a href="/warranty">← Bảo hành</a><button type="button" data-print>In phiếu</button></div>
<main class="sheet">
  <div class="top">
    <div><b><?= e(APP_NAME) ?></b><br><small>Kho: <?= e($b['wh_code']) ?> — <?= e($b['wh_name']) ?><?= !empty($b['wh_addr']) ? '<br>' . e($b['wh_addr']) : '' ?></small></div>
    <div class="no"><b><?= e($b['ma']) ?></b><br><small>Biên nhận bảo hành</small></div>
  </div>
  <h1>Phiếu nhận hàng bảo hành</h1>
  <p class="date">Ngày <?= e(dt($received, false)) ?></p>

  <h2>Khách hàng</h2>
  <dl>
    <dt>Họ tên:</dt><dd><b><?= e($b['khach_ten']) ?></b></dd>
    <dt>Điện thoại:</dt><dd><?= e($b['khach_sdt'] ?: '—') ?></dd>
    <?php if (!empty($b['khach_dia_chi'])): ?><dt>Địa chỉ:</dt><dd><?= e($b['khach_dia_chi']) ?></dd><?php endif; ?>
  </dl>

  <h2>Hàng bảo hành</h2>
  <dl>
    <dt>Mặt hàng:</dt><dd><b><?= e($b['ma_hang']) ?></b> <?= e($b['item_name']) ?> × <?= e(n($b['so_luong'])) ?> <?= e($b['unit']) ?></dd>
    <dt>Mã bảo hành:</dt><dd class="code"><?= e($codes) ?></dd>
    <dt>Ngày mua:</dt><dd><?= e(dt($b['ngay_mua'], false)) ?></dd>
    <dt>Bảo hành đến:</dt><dd><?= e(dt($b['het_han'], false)) ?> — <?= (int)$b['con_han'] ? '<span class="ok">còn hạn bảo hành</span>' : '<span class="bad">đã hết hạn bảo hành (không đổi mới miễn phí)</span>' ?></dd>
    <dt>Tình trạng lỗi:</dt><dd><?= e($b['loi']) ?></dd>
    <?php if (!empty($b['nha_san_xuat'])): ?><dt>Nhà sản xuất:</dt><dd><?= e($b['nha_san_xuat']) ?></dd><?php endif; ?>
  </dl>

  <h2>Hẹn trả</h2>
  <dl>
    <dt>Ngày hẹn trả (ước lượng):</dt><dd><?= $b['ngay_hen_tra'] ? '<b>' . e(dt($b['ngay_hen_tra'], false)) . '</b>' : '—' ?></dd>
    <dt>Trạng thái hiện tại:</dt><dd><?= e($st) ?><?= $b['ket_qua'] !== '' ? ' — ' . e($b['ket_qua']) : '' ?></dd>
    <?php if ($returned): ?><dt>Đã trả khách:</dt><dd><?= e(dt($b['ngay_tra'])) ?></dd><?php endif; ?>
  </dl>

  <div class="sign">
    <div><b>Nhân viên nhận hàng</b><i>(ký, ghi rõ họ tên)</i><div class="space"></div><span><?= e($b['creator_name']) ?></span></div>
    <div><b>Khách hàng</b><i>(ký, ghi rõ họ tên)</i><div class="space"></div><span><?= e($b['khach_ten']) ?></span></div>
  </div>
  <?php if ($returned): ?>
  <div class="sign" style="margin-top:18px">
    <div><b>Nhân viên trả hàng</b><i>(ký, ghi rõ họ tên)</i><div class="space"></div><span><?= e($b['handler_name'] ?? '') ?></span></div>
    <div><b>Khách nhận lại hàng</b><i>(ký, ghi rõ họ tên)</i><div class="space"></div><span><?= e($b['khach_ten']) ?></span></div>
  </div>
  <?php endif; ?>
  <p class="note">Mang phiếu này khi đến nhận hàng. Hàng của khách không tính vào tồn kho.</p>
  <p class="foot"><?= e($b['ma']) ?> · in từ hệ thống <?= e(APP_NAME) ?></p>
</main>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
