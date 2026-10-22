<main class="error-page">
  <img class="error-logo" src="/assets/img/logo-mark.png" alt="" width="64" height="64">
  <p class="error-code"><?= (int)$code ?></p>
  <h1><?= match ((int)$code) { 403 => 'Không đủ quyền', 404 => 'Không tìm thấy', 422 => 'Không thực hiện được', default => 'Có lỗi xảy ra' } ?></h1>
  <p class="muted"><?= e($message) ?></p>
  <p><a class="btn" href="/">Về trang chủ</a></p>
</main>
