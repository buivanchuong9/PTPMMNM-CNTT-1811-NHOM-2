<?php /** @var array $matrix @var array<string,list<\StockFlow\Domain\Permission>> $groups */
use StockFlow\Domain\AccountRole;
$cols = AccountRole::configurable();
?>
<header class="page-head"><div><h1>Phân quyền chức năng</h1></div></header>
<form method="post" action="/roles" class="card flush">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <div class="table-wrap"><table class="matrix">
    <thead><tr><th>Chức năng</th><th class="mx"><span class="tag tag-danger">Quản trị hệ thống</span><small class="muted">xem &amp; quản lý</small></th>
      <?php foreach ($cols as $c): ?><th class="mx"><span class="tag tag-<?= e($c->tone()) ?>"><?= e($c->label()) ?></span></th><?php endforeach; ?></tr></thead>
    <?php foreach ($groups as $group => $perms): ?>
    <tbody>
      <tr class="grp"><td colspan="<?= 2 + count($cols) ?>"><?= e($group) ?></td></tr>
      <?php foreach ($perms as $p): ?>
      <tr>
        <td><b><?= e($p->label()) ?></b><br><small class="muted"><?= e($p->description()) ?></small><?php if ($p->requires()): ?><br><small class="dep">Cần có quyền «<?= e($p->requires()->label()) ?>»</small><?php endif; ?></td>
        <td class="mx"><?php if ($p->operational()): ?><span class="muted" title="Chủ hệ thống chỉ xem và quản lý, không thao tác nhập / xuất">chỉ xem</span><?php else: ?><span class="lock" title="Quản trị hệ thống luôn có quyền này"><?= icon('check') ?></span><?php endif; ?></td>
        <?php foreach ($cols as $c): ?>
          <td class="mx">
            <?php if ($p->adminOnly()): ?><span class="muted" title="Chức năng hệ thống — chỉ quản trị viên">—</span>
            <?php else: ?><label class="switch"><input type="checkbox" name="quyen[<?= e($c->value) ?>][]" value="<?= e($p->value) ?>" data-perm="<?= e($p->value) ?>"<?= $p->requires() ? ' data-requires="' . e($p->requires()->value) . '"' : '' ?><?= $matrix[$c->value][$p->value] ? ' checked' : '' ?> aria-label="<?= e($p->label() . ' — ' . $c->label()) ?>"><span></span></label><?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php endforeach; ?>
  </table></div>
  <p class="muted small pad" data-roles-note>Một số chức năng cần quyền xem đi kèm (ví dụ lập phiếu cần xem được phiếu): bật chức năng sẽ tự bật quyền xem; tắt quyền xem sẽ tự tắt các chức năng đi kèm.</p>
  <div class="actions pad"><button type="submit"><?= icon('check') ?>Lưu phân quyền</button>
    <button type="submit" class="btn-ghost" formaction="/roles/reset" formnovalidate data-confirm="Đưa phân quyền về mặc định của hệ thống?">Khôi phục mặc định</button></div>
</form>
