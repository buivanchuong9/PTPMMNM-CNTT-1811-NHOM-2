<?php /** @var array $t @var bool $canConfirm @var bool $canCancel */
use StockFlow\Service\CustomerReturnService as C;
use StockFlow\Service\DisposalService as D;
[$label, $tone] = C::STATUS[$t['trang_thai']];
$qty = array_sum(array_column($t['lines'], 'so_luong'));
$bad = array_sum(array_column($t['lines'], 'so_luong_loi'));
?>
<header class="page-head">
  <div><p class="crumb"><a href="/customer-returns">← Khách trả hàng</a></p><h1><?= e($t['ma']) ?> <span class="tag tag-<?= e($tone) ?>"><?= e($label) ?></span></h1>
    <p class="muted"><span class="wh-code"><?= e($t['wh_code']) ?></span> <?= e($t['wh_name']) ?> · <?= e(dt($t['ngay_tao'])) ?> · <?= e($t['creator_name']) ?></p></div>
  <?php if ($t['trang_thai'] === 'DRAFT'): ?>
  <div class="actions">
    <?php if ($canConfirm): ?><form method="post" action="/customer-returns/<?= (int)$t['id'] ?>/confirm" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit"><?= icon('check') ?>Xác nhận &amp; nhập lại kho</button></form><?php endif; ?>
    <?php if ($canCancel): ?><form method="post" action="/customer-returns/<?= (int)$t['id'] ?>/cancel" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn-ghost btn-danger-ghost" data-confirm="Huỷ phiếu khách trả hàng này?">Huỷ phiếu</button></form><?php endif; ?>
  </div>
  <?php endif; ?>
</header>
<div class="grid-main">
  <div>
    <section class="card flush">
      <div class="card-head pad"><h2>Hàng khách trả <span class="count"><?= n(count($t['lines'])) ?></span></h2><span class="muted small">tổng <b><?= n($qty) ?></b><?= $bad ? ' · lỗi <b class="neg">' . n($bad) . '</b>' : '' ?></span></div>
      <div class="table-wrap"><table class="dense">
        <thead><tr><th>Hàng hoá</th><th>Lô</th><th class="num">Khách trả</th><th class="num">Trong đó lỗi</th><th>Lỗi do ai</th><th>Xử lý</th></tr></thead>
        <tbody>
        <?php foreach ($t['lines'] as $l): ?>
          <tr><td><b><?= e($l['ma_hang']) ?></b> <?= e($l['ten']) ?></td><td><?= $l['so_lo'] === 'NOLOT' ? '<span class="muted">—</span>' : e($l['so_lo']) ?></td>
            <td class="num"><b><?= n($l['so_luong']) ?></b> <small class="muted"><?= e($l['unit']) ?></small></td><td class="num <?= (int)$l['so_luong_loi'] ? 'neg' : '' ?>"><?= n($l['so_luong_loi']) ?></td>
            <td><?= (int)$l['so_luong_loi'] ? e(C::CAUSES[$l['ly_do_loi']] ?? $l['ly_do_loi']) : '<span class="muted">—</span>' ?></td>
            <td class="wrap small"><?php
              $c = $l['ly_do_loi']; $b = (int)$l['so_luong_loi'];
              if (!$b) { echo '<span class="muted">Nhập lại kho</span>'; }
              elseif ($c === 'KHACH') { echo 'Kho huỷ · trừ khách <b class="neg">' . n($l['tien_tru_khach']) . ' đ</b>'; }
              elseif ($c === 'VAN_CHUYEN') { echo 'Kho huỷ · ' . ($l['driver_name'] ? 'tài xế <b>' . e($l['driver_name']) . '</b> đền <b class="neg">' . n($l['tien_den_bu']) . ' đ</b>' : 'đền <b class="neg">' . n($l['tien_den_bu']) . ' đ</b> (chưa rõ tài xế)'); }
              elseif ($c === 'KHO') { echo 'Kho huỷ · ' . ($l['staff_name'] ? e($l['staff_name']) . ' đền <b class="neg">' . n($l['tien_den_bu']) . ' đ</b>' : 'kho chịu <b class="neg">' . n($l['tien_den_bu']) . ' đ</b> (chưa chọn người)'); }
              elseif ($c === 'NHA_SX') { echo $l['warranty_code'] ? 'Kho bảo hành · ' . e($l['warranty_code']) : 'Sang kho bảo hành khi xác nhận'; }
              else { echo 'Kho huỷ · công ty chịu'; }
            ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
  </div>
  <aside>
    <section class="card">
      <h2>Thông tin</h2>
      <dl class="kv">
        <dt>Khách</dt><dd><?= e($t['khach'] ?: '—') ?></dd>
        <dt>Phiếu xuất gốc</dt><dd><a href="/vouchers/<?= (int)$t['phieu_xuat_id'] ?>"><?= e($t['issue_code']) ?></a></dd>
        <?php if ($t['ly_do'] !== ''): ?><dt>Lý do trả</dt><dd><?= e($t['ly_do']) ?></dd><?php endif; ?>
        <?php if ($t['trang_thai'] === 'CONFIRMED'): ?><?php if ($t['phieu_nhap_id']): ?><dt>Nhập lại kho</dt><dd><a href="/vouchers/<?= (int)$t['phieu_nhap_id'] ?>"><?= e($t['receipt_code']) ?></a></dd><?php endif; ?>
        <dt>Xác nhận</dt><dd><?= e($t['approver_name'] ?? '—') ?><br><small class="muted"><?= e(dt($t['ngay_xac_nhan'])) ?></small></dd><?php endif; ?>
      </dl>
    </section>
    <section class="card">
      <h2>Tiền hoàn cho khách</h2>
      <dl class="kv">
        <dt>Tiền hàng khách trả</dt><dd><?= n($t['goods_value']) ?> đ</dd>
        <?php if ($t['deducted']): ?><dt>Trừ (khách làm hỏng)</dt><dd class="neg">− <?= n($t['deducted']) ?> đ</dd><?php endif; ?>
        <dt>Hoàn lại</dt><dd><b><?= n($t['tien_hoan']) ?> đ</b><br><small class="muted"><?= e(C::REFUND_METHODS[$t['hinh_thuc_hoan']] ?? '') ?><?= $t['trang_thai'] === 'CONFIRMED' ? ' · đã chốt khi xác nhận' : ' · chi khi quản lý xác nhận' ?></small></dd>
        <?php if ($t['compensation']): ?><dt>Bên chịu phải đền</dt><dd class="neg"><?= n($t['compensation']) ?> đ<br><small class="muted">theo giá vốn hàng hỏng, ghi trong bảng bên</small></dd><?php endif; ?>
      </dl>
    </section>
    <?php if ($t['disposals']): ?>
    <section class="card">
      <h2>Hàng lỗi → kho huỷ <span class="count"><?= n(count($t['disposals'])) ?></span></h2>
      <ul class="rows tight"><?php foreach ($t['disposals'] as $d): [$dl, $dt] = D::STATUS[$d['trang_thai']] ?? [$d['trang_thai'], 'muted']; ?><li><a href="/disposals"><?= e($d['ma']) ?></a><span class="tag tag-<?= e($dt) ?>"><?= e($dl) ?></span></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>
  </aside>
</div>
