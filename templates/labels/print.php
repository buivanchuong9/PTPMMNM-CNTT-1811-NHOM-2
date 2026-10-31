<?php /** @var list<array> $labels @var string $format */
$thermal = $format === 'thermal';
$short = fn(string $s, int $n) => mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tem mã vạch — <?= count($labels) ?> tem</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #eceff3; color: #000; font: 11px/1.25 system-ui, "Segoe UI", Arial, sans-serif; }
  .bar { position: sticky; top: 0; z-index: 2; display: flex; gap: 10px; align-items: center; padding: 10px 16px; background: #fff; border-bottom: 1px solid #d9dde3; font-size: 14px; }
  .bar a, .bar button { font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 8px; border: 1px solid #c9ced6; background: #fff; color: #222; text-decoration: none; cursor: pointer; }
  .bar button { margin-left: auto; background: #1e3a5f; border-color: #1e3a5f; color: #fff; }
  .bar span { color: #555; }
<?php if ($thermal): ?>
  @page { size: 50mm 30mm; margin: 0; }
  .sheet { display: block; width: 50mm; margin: 12px auto; }
  .lb { width: 50mm; height: 30mm; padding: 1.6mm 2mm; background: #fff; break-after: page; margin-bottom: 4mm; }
<?php else: ?>
  @page { size: A4; margin: 0; }
  .sheet { display: grid; grid-template-columns: repeat(3, 70mm); grid-auto-rows: 37mm; width: 210mm; margin: 12px auto; background: #fff; box-shadow: 0 2px 16px rgba(0,0,0,.12); }
  .lb { width: 70mm; height: 37mm; padding: 3mm 4mm; border: 1px dashed #cfd4db; overflow: hidden; }
<?php endif; ?>
  .lb { display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; }
  .lb-name { font-weight: 700; font-size: <?= $thermal ? '8.5px' : '10.5px' ?>; line-height: 1.15; max-height: 2.3em; overflow: hidden; }
  .lb-bc { height: <?= $thermal ? '10mm' : '13mm' ?>; margin: 1mm 0; }
  .bc { display: block; width: 100%; height: 100%; }
  .lb-row { display: flex; justify-content: space-between; gap: 4px; font-size: <?= $thermal ? '7.5px' : '9px' ?>; }
  .lb-code { font-family: Consolas, "Courier New", monospace; font-weight: 700; letter-spacing: .04em; }
  .lb-lot { font-size: <?= $thermal ? '7.5px' : '9px' ?>; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; box-shadow: none; } .lb { border-color: transparent; margin-bottom: 0; } }
</style>
</head>
<body>
<div class="bar"><a href="/labels">← In tem</a><span><?= count($labels) ?> tem · <?= $thermal ? 'tem cuộn 50×30 mm' : 'tờ A4 chia ô (3×8)' ?></span><button type="button" data-print>In</button></div>
<main class="sheet">
<?php foreach ($labels as $l): ?>
  <div class="lb">
    <div class="lb-name"><?= e($short((string)$l['name'], 60)) ?></div>
    <div class="lb-bc"><?= \StockFlow\Core\Barcode::svg((string)$l['code']) ?></div>
    <div class="lb-row"><span class="lb-code"><?= e($l['code']) ?></span><b><?= e($l['sku']) ?></b></div>
    <?php if (!empty($l['lot'])): ?><div class="lb-lot">Lô <b><?= e($l['lot']) ?></b><?= $l['exp'] ? ' · HSD <b>' . e(dt($l['exp'], false)) . '</b>' : '' ?></div><?php endif; ?>
  </div>
<?php endforeach; ?>
</main>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
