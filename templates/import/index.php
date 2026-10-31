<?php /** @var bool $canItems @var bool $canStock @var int $max */ ?>
<header class="page-head"><div><h1>Nhập từ file</h1></div></header>
<div class="cards-3" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
<?php if ($canItems): ?>
  <section class="card">
    <div class="card-head"><h2>Danh mục mặt hàng</h2><a href="/import/template?type=items"><?= icon('download') ?> File mẫu</a></div>
    <p class="muted small">Mỗi dòng một mặt hàng mới (tối đa <?= n($max) ?> dòng). Đơn vị tính và loại hàng phải có sẵn.</p>
    <form method="post" action="/import/preview" enctype="multipart/form-data" class="form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="type" value="items">
      <label>File CSV<input type="file" name="file" accept=".csv,text/csv" required></label>
      <div class="actions"><button type="submit">Xem trước</button></div>
    </form>
  </section>
<?php endif; ?>
<?php if ($canStock): ?>
  <section class="card">
    <div class="card-head"><h2>Tồn đầu kỳ</h2><a href="/import/template?type=stock"><?= icon('download') ?> File mẫu</a></div>
    <p class="muted small">Gom thành một phiếu nhập nháp của kho bạn để kiểm tra rồi xác nhận. Hàng theo lô cần số lô và hạn dùng.</p>
    <form method="post" action="/import/preview" enctype="multipart/form-data" class="form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="type" value="stock">
      <label>File CSV<input type="file" name="file" accept=".csv,text/csv" required></label>
      <div class="actions"><button type="submit">Xem trước</button></div>
    </form>
  </section>
<?php endif; ?>
</div>
<p class="muted small">Lưu từ Excel bằng «CSV UTF-8». Dòng lỗi được báo rõ ở bước xem trước và bị bỏ qua, các dòng đúng vẫn nhập được.</p>
