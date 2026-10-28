<?php /** @var array $t @var ?array $partner */
use StockFlow\Service\SupplierReturnService as R;
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($t['ma']) ?> — Phiếu trả hàng nhà cung cấp</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: #eceff3; color: #111; font: 14px/1.5 "Times New Roman", Times, serif; }
  .bar { position: sticky; top: 0; display: flex; gap: 10px; align-items: center; padding: 10px 16px; background: #fff; border-bottom: 1px solid #d9dde3; font-family: system-ui, "Segoe UI", sans-serif; }
  .bar span { margin-right: auto; }
  .bar a, .bar button { font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 8px; border: 1px solid #c9ced6; background: #fff; color: #222; text-decoration: none; cursor: pointer; }
  .bar button { background: #d6283f; border-color: #d6283f; color: #fff; }
  .sheet { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 14mm 16mm; background: #fff; box-shadow: 0 2px 16px rgba(0,0,0,.12); }
  .top { display: flex; justify-content: space-between; gap: 16px; } .top b { text-transform: uppercase; } .top .no { text-align: right; }
  h1 { margin: 14px 0 2px; text-align: center; font-size: 22px; text-transform: uppercase; }
  .date { margin: 0 0 12px; text-align: center; font-style: italic; }
  dl { display: grid; grid-template-columns: 130px 1fr; gap: 3px 10px; margin: 0 0 12px; } dt { color: #444; } dd { margin: 0; }
  table { width: 100%; border-collapse: collapse; } th, td { border: 1px solid #222; padding: 5px 7px; vertical-align: top; }
  th { background: #f1f1f1; font-size: 13px; } td.c { text-align: center; } td.r { text-align: right; white-space: nowrap; }
  .words { margin: 10px 0 0; }
  .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 26px; text-align: center; break-inside: avoid; }
  .sign b { display: block; } .sign i { color: #555; font-size: 12px; } .sign .space { height: 92px; } .sign .who { font-weight: 700; }
  @page { size: A4; margin: 0; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; box-shadow: none; width: auto; min-height: 0; } }
</style>
</head>
<body>
<div class="bar"><span></span><a href="/returns/<?= (int)$t['id'] ?>">← Về phiếu trả</a><button type="button" data-print>In phiếu</button></div>
<main class="sheet">
  <div class="top">
    <div><b><?= e(APP_NAME) ?></b><br>Kho: <?= e($t['wh_code']) ?> — <?= e($t['wh_name']) ?><?= $t['wh_addr'] !== '' ? '<br>' . e($t['wh_addr']) : '' ?></div>
    <div class="no"><b><?= e($t['ma']) ?></b><br><small>Theo phiếu nhập <?= e($t['receipt_code']) ?></small></div>
  </div>
  <h1>Phiếu trả hàng nhà cung cấp</h1>
  <p class="date">Ngày <?= e(dt($t['ngay_tao'], false)) ?></p>
  <dl>
    <dt>Nhà cung cấp:</dt><dd><b><?= e($t['doi_tac']) ?></b></dd>
    <?php if ($partner && ($partner['nguoi_lien_he'] !== '' || $partner['so_dien_thoai'] !== '')): ?><dt>Liên hệ:</dt><dd><?= e(trim($partner['nguoi_lien_he'] . ($partner['so_dien_thoai'] !== '' ? ' · ' . $partner['so_dien_thoai'] : ''), ' ·')) ?></dd><?php endif; ?>
    <?php if ($partner && $partner['dia_chi'] !== ''): ?><dt>Địa chỉ:</dt><dd><?= e($partner['dia_chi']) ?></dd><?php endif; ?>
    <dt>Lý do trả:</dt><dd>Hàng không đạt chất lượng khi kiểm tra lúc nhận. Đề nghị nhà cung cấp cấp lại đúng số lượng.</dd>
  </dl>
  <table>
    <thead><tr><th style="width:36px">STT</th><th>Tên hàng hoá</th><th style="width:80px">Lô</th><th style="width:50px">ĐVT</th><th style="width:80px">Số lượng trả</th><th>Tình trạng / ghi chú</th></tr></thead>
    <tbody>
    <?php foreach ($t['lines'] as $i => $l): ?>
      <tr><td class="c"><?= $i + 1 ?></td><td><b><?= e($l['ma_hang']) ?></b> <?= e($l['ten']) ?></td><td class="c"><?= $l['so_lo'] === 'NOLOT' ? '—' : e($l['so_lo']) ?></td><td class="c"><?= e($l['unit']) ?></td>
        <td class="r"><b><?= n($l['so_luong']) ?></b></td><td><?= e(R::REASONS[$l['ly_do']] ?? $l['ly_do']) ?><?= $l['ghi_chu'] !== '' ? ' — ' . e($l['ghi_chu']) : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="4" style="text-align:right"><b>Cộng</b></td><td class="r"><b><?= n(array_sum(array_column($t['lines'], 'so_luong'))) ?></b></td><td></td></tr></tfoot>
  </table>
  <div class="sign">
    <div><b>Người lập phiếu</b><i>(ký, ghi rõ họ tên)</i><div class="space"></div><span class="who"><?= e($t['creator_name']) ?></span></div>
    <div><b>Thủ kho / Quản lý kho</b><i>(ký, ghi rõ họ tên)</i><div class="space"></div></div>
    <div><b>Đại diện nhà cung cấp nhận lại</b><i>(ký, ghi rõ họ tên)</i><div class="space"></div></div>
  </div>
</main>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
