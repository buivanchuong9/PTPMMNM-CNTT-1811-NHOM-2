<?php /** @var array $o @var bool $canMake @var bool $canReceive */
use StockFlow\Service\OrderService as O;
[$label, $tone] = O::STATUS[$o['trang_thai']];
$receivable = in_array($o['trang_thai'], O::RECEIVABLE, true);
$qty = array_sum(array_column($o['lines'], 'so_luong'));
$money = array_sum(array_map(fn($l) => (int)$l['gia_von'] * (int)$l['so_luong'], $o['lines']));   // tạm tính theo giá gốc, chưa phải giá nhà cung cấp báo
$got = array_sum(array_map(fn($l) => min((int)$l['so_luong_da_nhan'], (int)$l['so_luong']), $o['lines']));
?>
<header class="page-head">
  <div><p class="crumb"><a href="/orders">← Đặt hàng nhà cung cấp</a></p><h1><?= e($o['ma']) ?> <span class="tag tag-<?= e($tone) ?>"><?= e($label) ?></span></h1>
    <p class="muted"><span class="wh-code"><?= e($o['wh_code']) ?></span> <?= e($o['wh_name']) ?> · <?= e(dt($o['ngay_tao'])) ?> · <?= e($o['creator_name']) ?></p></div>
  <div class="actions">
    <?php if ($canMake && $o['trang_thai'] === 'NHAP'): ?>
      <form method="post" action="/orders/<?= (int)$o['id'] ?>/send" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button type="submit"><?= icon('check') ?>Đã gửi nhà cung cấp</button></form>
    <?php endif; ?>
    <?php if ($canReceive && $receivable): ?><a class="btn" href="/vouchers/new?type=RECEIPT&amp;po=<?= (int)$o['id'] ?>"><?= icon('in') ?>Lập phiếu nhập từ đơn</a><?php endif; ?>
    <?php if ($canMake && in_array($o['trang_thai'], ['NHAP', 'DA_GUI', 'NHAN_MOT_PHAN'], true)): ?>
      <form method="post" action="/orders/<?= (int)$o['id'] ?>/close" class="inline-form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn-ghost btn-danger-ghost" data-confirm="<?= $o['trang_thai'] === 'NHAN_MOT_PHAN' ? 'Đóng đơn: không nhận thêm hàng theo đơn này?' : 'Huỷ đơn đặt hàng này?' ?>"><?= $o['trang_thai'] === 'NHAN_MOT_PHAN' ? 'Đóng đơn' : 'Huỷ đơn' ?></button></form>
    <?php endif; ?>
  </div>
</header>
<div class="grid-main">
  <div>
    <section class="card flush">
      <div class="card-head pad"><h2>Mặt hàng đặt <span class="count"><?= n(count($o['lines'])) ?></span></h2><span class="muted small">đã nhận <b><?= n($got) ?></b> / <?= n($qty) ?></span></div>
      <div class="table-wrap"><table class="dense">
        <thead><tr><th>Hàng hoá</th><th class="num">Đặt</th><th class="num">Đã nhận</th><th class="num">Còn thiếu</th><th class="num">Tạm tính</th></tr></thead>
        <tbody>
        <?php foreach ($o['lines'] as $l): $left = max(0, (int)$l['so_luong'] - (int)$l['so_luong_da_nhan']); ?>
          <tr><td><b><?= e($l['ma_hang']) ?></b> <?= e($l['ten']) ?></td><td class="num"><?= n($l['so_luong']) ?> <small class="muted"><?= e($l['unit']) ?></small></td>
            <td class="num"><b><?= n($l['so_luong_da_nhan']) ?></b></td><td class="num <?= $left ? 'neg' : '' ?>"><?= $left ? n($left) : '<span class="muted">0</span>' ?></td>
            <td class="num"><?= (int)$l['gia_von'] > 0 ? n((int)$l['gia_von'] * (int)$l['so_luong']) . ' <small class="muted">đ</small>' : '<span class="muted">—</span>' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($money > 0): ?><tfoot><tr><td colspan="4" class="num muted small">Tạm tính theo giá gốc của mặt hàng (chưa gồm thuế, chưa phải giá nhà cung cấp báo)</td><td class="num"><b><?= n($money) ?> đ</b></td></tr></tfoot><?php endif; ?>
      </table></div>
    </section>
  </div>
  <aside>
    <section class="card">
      <h2>Thông tin</h2>
      <dl class="kv">
        <dt>Nhà cung cấp</dt><dd><?= e($o['doi_tac']) ?></dd>
        <?php $sp = $o['supplier'] ?? null; if ($sp): foreach (['nguoi_lien_he' => 'Người liên hệ', 'so_dien_thoai' => 'Điện thoại', 'email' => 'Email', 'dia_chi' => 'Địa chỉ'] as $k => $lb): if ($sp[$k] !== ''): ?>
        <dt><?= e($lb) ?></dt><dd><?= e($sp[$k]) ?></dd>
        <?php endif; endforeach; endif; ?>
        <dt>Dự kiến nhận</dt><dd><?= $o['ngay_du_kien'] ? e(dt($o['ngay_du_kien'], false)) : '—' ?></dd>
        <?php if ($o['ghi_chu'] !== ''): ?><dt>Ghi chú</dt><dd><?= e($o['ghi_chu']) ?></dd><?php endif; ?>
        <?php if ($o['ngay_gui']): ?><dt>Đã gửi</dt><dd><?= e(dt($o['ngay_gui'])) ?></dd><?php endif; ?>
        <?php if ($o['ngay_dong']): ?><dt>Đóng lúc</dt><dd><?= e(dt($o['ngay_dong'])) ?></dd><?php endif; ?>
      </dl>
    </section>
    <?php if ($o['receipts']): ?>
    <section class="card">
      <h2>Phiếu nhập theo đơn <span class="count"><?= n(count($o['receipts'])) ?></span></h2>
      <ul class="rows tight"><?php foreach ($o['receipts'] as $rc): ?><li><a href="/vouchers/<?= (int)$rc['id'] ?>"><?= e($rc['ma']) ?></a><?= status_tag($rc['trang_thai']) ?></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>
  </aside>
</div>
