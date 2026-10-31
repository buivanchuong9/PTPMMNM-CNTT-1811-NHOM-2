<?php /** @var array $issue @var list<array> $lines @var array $causes @var array $methods @var list<array> $drivers @var list<array> $staff @var ?array $driver */
use StockFlow\Service\CustomerReturnService as C;
?>
<header class="page-head"><div><p class="crumb"><a href="/vouchers/<?= (int)$issue['id'] ?>">← Phiếu <?= e($issue['ma']) ?></a></p><h1>Khách trả hàng</h1>
  <p class="muted"><?= e($issue['doi_tac'] ?: 'Khách lẻ') ?> · phiếu xuất <b><?= e($issue['ma']) ?></b> · kho <span class="wh-code"><?= e($issue['wh_code']) ?></span></p></div></header>
<form method="post" action="/customer-returns" class="card form" id="creturn-form" data-once>
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="voucher" value="<?= (int)$issue['id'] ?>">
  <div class="table-wrap"><table class="dense">
    <thead><tr><th>Hàng hoá</th><th>Lô</th><th class="num">Giá đã mua</th><th class="num">Đã bán</th><th class="num" style="width:90px">Khách trả</th><th class="num" style="width:90px">Trong đó lỗi</th><th style="width:210px">Lỗi do ai</th><th style="width:210px">Người chịu / trừ tiền</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $l): $left = max(0, (int)$l['sold'] - (int)$l['returned']); $unit = C::unitPrice($l['don_gia'] !== null ? (int)$l['don_gia'] : null, (int)($l['thue_suat'] ?? 0)); ?>
      <tr data-unit="<?= $unit ?>"><td><input type="hidden" name="key[]" value="<?= (int)$l['mat_hang_id'] ?>:<?= (int)$l['lo_hang_id'] ?>"><b><?= e($l['ma_hang']) ?></b> <?= e($l['ten']) ?></td>
        <td><?= $l['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($l['so_lo']) ?></td>
        <td class="num"><?= n($unit) ?></td>
        <td class="num"><?= n($l['sold']) ?><?= (int)$l['returned'] ? ' <small class="muted">(đã trả ' . n($l['returned']) . ')</small>' : '' ?></td>
        <td class="num"><?php if ($left > 0): ?><input type="number" name="qty[]" min="0" max="<?= $left ?>" value="0" inputmode="numeric" aria-label="Khách trả <?= e($l['ma_hang']) ?>"><?php else: ?><input type="hidden" name="qty[]" value="0"><span class="muted">đã trả hết</span><?php endif; ?></td>
        <td class="num"><input type="number" name="bad[]" min="0" max="<?= $left ?>" value="0" inputmode="numeric" aria-label="Lỗi <?= e($l['ma_hang']) ?>"<?= $left > 0 ? '' : ' disabled' ?>></td>
        <td><select name="why[]"<?= $left > 0 ? '' : ' disabled' ?> aria-label="Lỗi do ai"><option value="">— không lỗi —</option><?php foreach ($causes as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></td>
        <td>
          <select name="driver[]" data-for="VAN_CHUYEN" hidden aria-label="Tài xế chịu"><option value="">— tài xế<?= $driver ? ' (mặc định ' . e($driver['ho_ten']) . ')' : '' ?> —</option><?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $driver && (int)$driver['id'] === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['ho_ten']) ?></option><?php endforeach; ?></select>
          <select name="staff[]" data-for="KHO" hidden aria-label="Nhân viên kho chịu"><option value="">— nhân viên kho chịu —</option><?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['ho_ten']) ?></option><?php endforeach; ?></select>
          <input type="number" name="deduct[]" data-for="KHACH" hidden min="0" inputmode="numeric" placeholder="Trừ khách (để trống = đủ giá hàng hỏng)" aria-label="Tiền trừ khách">
          <span class="muted small" data-for="NHA_SX" hidden>Sang kho bảo hành, hoàn đủ tiền</span>
          <span class="muted small" data-for="KHAC" hidden>Công ty chịu, hoàn đủ tiền</span>
        </td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <div class="grid-2">
    <label>Hoàn tiền cho khách bằng<select name="refund_by"><?php foreach ($methods as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label>Lý do khách trả <span class="muted">(không bắt buộc)</span><input name="reason" maxlength="255" placeholder="Đổi ý, sai hàng, hàng lỗi…"></label>
  </div>
  <p class="refund-line">Hoàn lại cho khách: <b><span id="refund-total">0</span> đ</b> <span class="muted small">(giá khách đã mua, đã gồm thuế, trừ phần khách làm hỏng)</span></p>
  <div class="actions"><button type="submit">Lập phiếu khách trả hàng</button><a class="btn btn-ghost" href="/vouchers/<?= (int)$issue['id'] ?>">Quay lại</a></div>
</form>
