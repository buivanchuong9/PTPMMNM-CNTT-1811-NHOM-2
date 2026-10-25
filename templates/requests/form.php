<?php /** @var array $mine @var array $source @var array $pick @var array $stock @var string $idem */ ?>
<header class="page-head"><div><h1>Yêu cầu chuyển hàng</h1></div></header>
<?php if (!$source): ?>
  <section class="card"><p class="empty">Chưa có kho nào khác để xin hàng.</p></section>
<?php else: ?>
<form method="post" action="/requests" class="card form" data-once>
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="idem" value="<?= e($idem) ?>">
  <div class="route">
    <?= str_replace('class="wh-fixed"', 'class="wh-fixed wh-from"', wh_fixed($source, 'Xin hàng từ kho', 'from_wh')) ?>
    <span class="route-arrow" aria-hidden="true"><?= icon('truck') ?></span>
    <?= str_replace('class="wh-fixed"', 'class="wh-fixed wh-to"', wh_fixed($mine, 'Về kho của bạn', 'to_wh')) ?>
  </div>
  <label>Ghi chú<input name="note" maxlength="255" placeholder="Lý do cần hàng, thời gian cần…"></label>
  <?php $checkStock = true; require __DIR__ . '/../partials/lines.php'; ?>
  <p class="muted small"><?= $user->role->value === 'MANAGER' ? 'Yêu cầu sẽ gửi thẳng đến quản lý kho cấp hàng.' : 'Quản lý kho của bạn xác nhận trước, rồi yêu cầu mới được gửi đến kho cấp hàng.' ?></p>
  <div class="actions"><button type="submit">Gửi yêu cầu</button><a class="btn btn-ghost" href="/requests">Huỷ</a></div>
</form>
<?php endif; ?>
