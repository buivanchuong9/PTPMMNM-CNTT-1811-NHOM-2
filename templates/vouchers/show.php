<?php /** @var array $v @var array $lines @var array $check @var array $movements @var ?array $pair @var ?array $correction @var ?array $original @var array $trail */
$steps = [['Lập phiếu', $v['created_at'], $v['creator_name']], ['Kiểm tra', null, null], ['Xác nhận & ghi sổ', $v['confirmed_at'], $v['approver_name']]];
$stage = match ($v['status']) { 'DRAFT' => 1, 'CONFIRMED' => 3, default => -1 };
$allOk = $check === [] || !in_array(false, array_column($check, 'ok'), true);
?>
<header class="page-head">
  <div><p class="crumb"><a href="/vouchers">← Chứng từ</a></p>
    <h1><?= e($v['code']) ?> <?= status_tag($v['status']) ?> <?= type_chip($v['type']) ?></h1>
    <p class="muted"><span class="wh-code"><?= e($v['wh_code']) ?></span> <?= e($v['wh_name']) ?>
      <?php if ($v['cp_code']): ?> <span class="arrow"><?= $v['type'] === 'TRANSFER_IN' ? '←' : '→' ?></span> <span class="wh-code"><?= e($v['cp_code']) ?></span> <?= e($v['cp_name']) ?><?php endif; ?></p></div>
</header>

<?php if ($stage >= 0): ?>
<ol class="track" style="--stage:<?= $stage ?>" aria-label="Tiến trình chứng từ">
  <?php foreach ($steps as $i => [$label, $at, $by]): $cls = $i < $stage ? 'done' : ($i === $stage ? 'current' : ''); ?>
  <li class="<?= $cls ?>"><span class="dot"><?= $i < $stage ? icon('check') : $i + 1 ?></span><b><?= e($label) ?></b><small><?= $at && $i !== 1 ? e(dt($at)) . ' · ' . e($by) : ($i === 1 ? ($stage === 1 ? 'Xem tồn hiện có bên dưới' : 'Đạt — kiểm tra khi ghi sổ') : 'Chưa thực hiện') ?></small></li>
  <?php endforeach; ?>
</ol>
<?php else: ?>
<div class="flash flash-muted"><?= icon('alert') ?><span>Phiếu đã bị huỷ khi còn là bản nháp; không có tác động đến tồn.</span></div>
<?php endif; ?>

<?php if ($original): ?><div class="flash flash-info"><?= icon('scale') ?><span>Chứng từ điều chỉnh đảo cho <a href="/vouchers/<?= (int)$original['id'] ?>"><b><?= e($original['code']) ?></b></a>. Lý do: <?= e($v['reason']) ?></span></div><?php endif; ?>
<?php if ($correction): ?><div class="flash flash-info"><?= icon('scale') ?><span>Chứng từ này đã được điều chỉnh bằng <a href="/vouchers/<?= (int)$correction['id'] ?>"><b><?= e($correction['code']) ?></b></a>. Lịch sử gốc được giữ nguyên.</span></div><?php endif; ?>

<div class="grid-main">
  <div>
    <section class="card flush">
      <div class="card-head pad"><h2>Dòng hàng</h2><?php if ($v['status'] === 'DRAFT'): ?><span class="muted small">Kiểm tra tồn khả dụng trước khi xác nhận</span><?php endif; ?></div>
      <div class="table-wrap"><table class="dense">
        <thead><tr><th>#</th><th>SKU</th><th>Tên hàng</th><th>Lô</th><th class="num">Số lượng</th><?php if ($v['status'] === 'DRAFT' && in_array($v['type'], ['ISSUE', 'TRANSFER_OUT'], true)): ?><th class="num">Tồn hiện có</th><th>Kiểm tra</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($lines as $i => $l): $c = $check[$i] ?? null; ?>
          <tr><td class="muted"><?= $i + 1 ?></td><td><b><?= e($l['sku']) ?></b></td><td><?= e($l['name']) ?><?= $l['note'] !== '' ? '<br><small class="muted">' . e($l['note']) . '</small>' : '' ?></td>
            <td><?= $l['batch_no'] === 'NOLOT' ? '<span class="muted">—</span>' : e($l['batch_no']) . ($l['expiry_date'] ? ' <small class="muted">HSD ' . e(dt($l['expiry_date'], false)) . '</small>' : '') ?></td>
            <td class="num"><b class="<?= $v['type'] === 'ADJUSTMENT' ? ($l['qty'] > 0 ? 'pos' : 'neg') : '' ?>"><?= $v['type'] === 'ADJUSTMENT' ? signed((int)$l['qty']) : n($l['qty']) ?></b> <small class="muted"><?= e($l['unit']) ?></small></td>
            <?php if ($c && in_array($v['type'], ['ISSUE', 'TRANSFER_OUT'], true)): ?><td class="num"><?= n($c['available']) ?></td><td><?= $c['ok'] ? '<span class="tag tag-ok">Đủ hàng</span>' : '<span class="tag tag-danger">Thiếu ' . n(abs($c['available'] - (int)$l['qty'])) . '</span>' ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>

    <?php if ($movements): ?>
    <section class="card flush">
      <div class="card-head pad"><h2>Đã ghi vào sổ phát sinh</h2><span class="muted small"><?= count($movements) ?> dòng · bất biến</span></div>
      <div class="table-wrap"><table class="dense">
        <thead><tr><th>#</th><th>Kho</th><th>Thời gian</th><th class="num">Biến động</th><th class="num">Tồn sau</th></tr></thead>
        <tbody><?php foreach ($movements as $m): ?><tr><td class="muted"><?= (int)$m['id'] ?></td><td><span class="wh-code"><?= e($m['wh_code']) ?></span></td><td class="muted"><?= e(dt($m['posted_at'])) ?></td>
          <td class="num <?= $m['qty_change'] > 0 ? 'pos' : 'neg' ?>"><b><?= signed((int)$m['qty_change']) ?></b></td><td class="num"><?= n($m['balance_after']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    </section>
    <?php endif; ?>
  </div>

  <aside>
    <section class="card">
      <h2>Thông tin</h2>
      <dl class="meta">
        <div><dt>Đối tác / lý do</dt><dd><?= e($v['partner'] ?: $v['reason'] ?: '—') ?></dd></div>
        <div><dt>Ghi chú</dt><dd><?= e($v['note'] ?: '—') ?></dd></div>
        <div><dt>Người lập</dt><dd><?= e($v['creator_name']) ?></dd></div>
        <div><dt>Người xác nhận</dt><dd><?= e($v['approver_name'] ?: '—') ?></dd></div>
        <?php if ($pair): ?><div><dt>Chứng từ đối ứng</dt><dd><a href="/vouchers/<?= (int)$pair['id'] ?>"><?= e($pair['code']) ?></a></dd></div><?php endif; ?>
      </dl>
    </section>

    <?php if ($v['status'] === 'DRAFT' && ($canApprove || $canDraft)): ?>
    <section class="card">
      <h2>Thao tác</h2>
      <?php if (!$allOk): ?><p class="flash flash-err small"><?= icon('alert') ?><span>Một số dòng không đủ tồn — không thể xác nhận cho đến khi tồn đủ.</span></p><?php endif; ?>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/confirm" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <?php if ($canApprove): ?><button class="btn-block"<?= $allOk ? '' : ' disabled' ?>><?= icon('check') ?>Xác nhận & ghi sổ</button>
        <?php else: ?><p class="muted small">Chỉ người có quyền «Người duyệt» tại kho <?= e($v['wh_code']) ?> mới xác nhận được.</p><?php endif; ?></form>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/cancel" class="mt"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn-danger btn-block" data-confirm="Huỷ phiếu nháp này?">Huỷ phiếu</button></form>
    </section>
    <?php endif; ?>

    <?php if ($canCorrect && !$correction && $canApprove): ?>
    <section class="card">
      <h2>Điều chỉnh chứng từ</h2>
      <p class="muted small">Chứng từ đã ghi sổ không được sửa hoặc xoá. Hãy lập chứng từ điều chỉnh đảo<?= $isTransfer ? ' (đảo cả hai kho trong một giao dịch)' : '' ?> kèm lý do.</p>
      <form method="post" action="/vouchers/<?= (int)$v['id'] ?>/correct" class="form" data-once><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Lý do điều chỉnh<textarea name="reason" rows="3" maxlength="255" required placeholder="VD: Nhập nhầm số lượng, thực nhận 90"></textarea></label>
        <button class="btn-danger" data-confirm="Lập chứng từ điều chỉnh đảo cho chứng từ này?">Lập chứng từ điều chỉnh</button>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($trail): ?>
    <section class="card"><h2>Nhật ký thao tác</h2>
      <ul class="rows tight"><?php foreach ($trail as $a): ?><li><span><b><?= e($a['action']) ?></b><br><small class="muted"><?= e($a['full_name'] ?? '—') ?> · <?= e(dt($a['created_at'])) ?></small></span></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>
  </aside>
</div>
