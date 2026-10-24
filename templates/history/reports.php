<header class="page-head"><div><h1>Báo cáo</h1></div></header>
<div class="cards-3">
  <a class="card report" href="/reports/stock.csv"><span class="kpi-ic"><?= icon('layers') ?></span><h2>Tồn kho theo lô</h2><p class="muted">Kho · SKU · lô · hạn dùng · tồn, tính từ sổ phát sinh.</p><span class="btn btn-ghost"><?= icon('download') ?>Tải CSV</span></a>
  <a class="card report" href="/reports/movements.csv"><span class="kpi-ic"><?= icon('clock') ?></span><h2>Sổ phát sinh</h2><p class="muted">Toàn bộ dòng phát sinh (tối đa 5.000 dòng gần nhất), kèm phiếu nhập xuất và lý do.</p><span class="btn btn-ghost"><?= icon('download') ?>Tải CSV</span></a>
  <a class="card report" href="/reports/reconcile.csv"><span class="kpi-ic"><?= icon('scale') ?></span><h2>Đối chiếu tồn</h2><p class="muted">Số dư so với tổng sổ phát sinh cho từng vị trí kho.</p><span class="btn btn-ghost"><?= icon('download') ?>Tải CSV</span></a>
</div>

<section class="card flush">
  <div class="card-head pad"><h2>Từng kho: tồn &amp; xuất nhập</h2><span class="muted small">30 ngày gần nhất · tính từ sổ phát sinh</span></div>
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Kho</th><th class="num">Tồn hiện tại</th><th class="num">Nhập kho</th><th class="num">Xuất kho</th><th class="num">Chuyển đi</th><th class="num">Chuyển đến</th><th class="num">Điều chỉnh</th><th class="num">Tổng vào</th><th class="num">Tổng ra</th><th class="num">Phiếu chờ</th></tr></thead>
    <tbody>
    <?php $tot = ['units' => 0, 'nhap' => 0, 'xuat' => 0, 'chuyen_di' => 0, 'chuyen_den' => 0, 'dieu_chinh' => 0, 'vao' => 0, 'ra' => 0, 'drafts' => 0];
    foreach ($warehouses as $w): $f = $flow[$w['id']] ?? ['nhap' => 0, 'xuat' => 0, 'chuyen_di' => 0, 'chuyen_den' => 0, 'dieu_chinh' => 0, 'vao' => 0, 'ra' => 0];
      $tot['units'] += (int)$w['units']; $tot['drafts'] += (int)$w['drafts'];
      foreach (['nhap', 'xuat', 'chuyen_di', 'chuyen_den', 'dieu_chinh', 'vao', 'ra'] as $k) { $tot[$k] += $f[$k]; } ?>
      <tr>
        <td><a href="/inventory?warehouse=<?= (int)$w['id'] ?>"><span class="wh-code"><?= e($w['ma']) ?></span> <b><?= e($w['ten']) ?></b></a></td>
        <td class="num"><b><?= n($w['units']) ?></b></td>
        <td class="num pos"><?= n($f['nhap']) ?></td><td class="num neg"><?= n($f['xuat']) ?></td>
        <td class="num"><?= n($f['chuyen_di']) ?></td><td class="num"><?= n($f['chuyen_den']) ?></td>
        <td class="num"><?= $f['dieu_chinh'] ? signed((int)$f['dieu_chinh']) : '<span class="muted">0</span>' ?></td>
        <td class="num pos"><b><?= n($f['vao']) ?></b></td><td class="num neg"><b><?= n($f['ra']) ?></b></td>
        <td class="num"><?= (int)$w['drafts'] ? '<span class="tag tag-warn">' . n($w['drafts']) . '</span>' : '<span class="muted">0</span>' ?></td>
      </tr>
    <?php endforeach; if (!$warehouses): ?><tr><td colspan="10" class="empty">Chưa có kho nào.</td></tr>
    <?php else: ?>
      <tr class="total-row"><td><b>Tổng cộng</b></td><td class="num"><b><?= n($tot['units']) ?></b></td><td class="num"><?= n($tot['nhap']) ?></td><td class="num"><?= n($tot['xuat']) ?></td>
        <td class="num"><?= n($tot['chuyen_di']) ?></td><td class="num"><?= n($tot['chuyen_den']) ?></td><td class="num"><?= $tot['dieu_chinh'] ? signed((int)$tot['dieu_chinh']) : '0' ?></td>
        <td class="num"><b><?= n($tot['vao']) ?></b></td><td class="num"><b><?= n($tot['ra']) ?></b></td><td class="num"><?= n($tot['drafts']) ?></td></tr>
    <?php endif; ?>
    </tbody></table></div>
  <p class="muted small flow-note">Nhập kho / Xuất kho: phiếu nhập, phiếu xuất ngoài hệ thống · Chuyển đi / đến: điều chuyển giữa các kho · Tổng vào = nhập + chuyển đến · Tổng ra = xuất + chuyển đi.</p>
</section>
