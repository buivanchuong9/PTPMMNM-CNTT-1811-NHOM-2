<?php /** @var array $post @var string $partner @var int $lineCount @var int $totalQty */
$hidden = function (string $name, mixed $value): string { return '<input type="hidden" name="' . e($name) . '" value="' . e((string)$value) . '">'; };
?>
<header class="page-head"><div><p class="crumb"><a href="/vouchers/new?type=ISSUE">← Quay lại bước 1: chọn hàng</a></p><h1>Phiếu xuất mới</h1><p class="muted">Bước 2/2 — Thông tin khách hàng mới</p></div></header>
<form method="post" action="/vouchers" class="card form" data-once>
  <?= $hidden('csrf', $csrf) ?><?= $hidden('type', 'ISSUE') ?><?= $hidden('idem', $post['idem'] ?? '') ?><?= $hidden('warehouse_id', $post['warehouse_id'] ?? '') ?><?= $hidden('partner', $partner) ?><?= $hidden('note', $post['note'] ?? '') ?>
  <?php foreach (($post['line'] ?? []) as $i => $k): ?><?= $hidden('line[]', $k) ?><?= $hidden('qty[]', $post['qty'][$i] ?? '') ?><?php endforeach; ?>
  <div class="new-customer-sum"><span class="tag tag-warn">Khách hàng mới</span> <b><?= e($partner) ?></b>
    <span class="muted"> chưa có trong danh sách đối tác · phiếu gồm <?= n($lineCount) ?> dòng, tổng <?= n($totalQty) ?> đơn vị</span></div>
  <div class="row-3">
    <label>Người liên hệ<input name="cust_contact" maxlength="120" autocomplete="off" required autofocus value="<?= e($post['cust_contact'] ?? '') ?>"></label>
    <label>Số điện thoại<input name="cust_phone" inputmode="tel" maxlength="15" pattern="[0-9 .+\-]{8,15}" title="8–15 chữ số" autocomplete="off" required value="<?= e($post['cust_phone'] ?? '') ?>"></label>
  </div>
  <p class="muted small" style="margin:0"><b>Địa chỉ khách hàng</b> — chọn tỉnh, phường theo gợi ý; lưu một lần, các lần xuất sau chọn khách là tự điền địa chỉ giao hàng.</p>
  <?php $addr = ['street' => $post['street'] ?? '', 'ward' => $post['ward'] ?? '', 'province' => $post['province'] ?? '']; $addrRequired = true; include __DIR__ . '/../partials/address_fields.php'; ?>
  <div class="actions"><button type="submit">Lưu khách hàng &amp; lập phiếu xuất</button><a class="btn btn-ghost" href="/vouchers/new?type=ISSUE">Quay lại</a></div>
</form>
