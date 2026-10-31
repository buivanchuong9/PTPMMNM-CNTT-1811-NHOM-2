<?php /** @var array $rows @var array $people @var string $q @var string $group @var int $who @var array $keep */ ?>
<header class="page-head">
  <div><h1>Nhật ký thao tác</h1></div>
  <div class="actions"><a class="btn btn-ghost" href="/audit?<?= e(http_build_query(['format' => 'csv'] + $keep)) ?>"><?= icon('download') ?>Xuất CSV</a></div>
</header>
<form class="filters" method="get">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Tìm người, phiếu, việc…" aria-label="Tìm kiếm">
  <select name="who"<?= $who ? ' class="on"' : '' ?> aria-label="Người thao tác" data-live-reload><option value="">Mọi người</option><?php foreach ($people as $id => $name): ?><option value="<?= (int)$id ?>"<?= $who === (int)$id ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select>
  <select name="group"<?= $group !== '' ? ' class="on"' : '' ?> aria-label="Nhóm việc" data-live-reload><option value="">Mọi nhóm</option><?php foreach (AUDIT_GROUPS as $k => $label): ?><option value="<?= e($k) ?>"<?= $group === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
  <?php if ($keep): ?><a class="btn btn-ghost" href="/audit">Xoá lọc</a><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Thời gian</th><th>Người thao tác</th><th>Việc đã làm</th><th>Chi tiết</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $a): ?>
    <tr>
      <td class="nw muted"><?= e(dt($a['ngay_tao'])) ?></td>
      <td class="nw"><b><?= e($a['ho_ten'] ?? 'Hệ thống') ?></b><?= !empty($a['kho_ma']) ? ' <span class="wh-code">' . e($a['kho_ma']) . '</span>' : '' ?></td>
      <td class="nw"><span class="tag tag-<?= ['voucher' => 'info', 'goods' => 'muted', 'warranty' => 'warn', 'disposal' => 'danger', 'fleet' => 'info', 'count' => 'ok', 'account' => 'muted'][audit_group($a['hanh_dong'])] ?>"><?= e(audit_label($a['hanh_dong'])) ?></span></td>
      <td class="wrap"><?php if ($a['doi_tuong'] === 'voucher' && $a['doi_tuong_id']): ?><a href="/vouchers/<?= (int)$a['doi_tuong_id'] ?>"><?= e(audit_detail($a)) ?></a><?php else: ?><?= e(audit_detail($a)) ?><?php endif; ?></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="4" class="empty">Không có thao tác phù hợp.</td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
