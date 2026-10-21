<header class="page-head"><div><h1>Báo cáo</h1><p class="muted">Xuất CSV (UTF-8, mở được bằng Excel). Chỉ gồm các kho bạn được truy cập.</p></div></header>
<div class="cards-3">
  <a class="card report" href="/reports/stock.csv"><span class="kpi-ic"><?= icon('layers') ?></span><h2>Tồn kho theo lô</h2><p class="muted">Kho · SKU · lô · hạn dùng · tồn, tính từ sổ phát sinh.</p><span class="btn btn-ghost"><?= icon('download') ?>Tải CSV</span></a>
  <a class="card report" href="/reports/movements.csv"><span class="kpi-ic"><?= icon('clock') ?></span><h2>Sổ phát sinh</h2><p class="muted">Toàn bộ dòng phát sinh (tối đa 5.000 dòng gần nhất), kèm chứng từ và lý do.</p><span class="btn btn-ghost"><?= icon('download') ?>Tải CSV</span></a>
  <a class="card report" href="/reports/reconcile.csv"><span class="kpi-ic"><?= icon('scale') ?></span><h2>Đối chiếu tồn</h2><p class="muted">Số dư so với tổng sổ phát sinh cho từng vị trí kho.</p><span class="btn btn-ghost"><?= icon('download') ?>Tải CSV</span></a>
</div>
