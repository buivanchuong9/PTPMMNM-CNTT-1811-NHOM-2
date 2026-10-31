<?php /** @var array $res @var array $moves @var array $stockOf @var string $checkedAt */
use StockFlow\Service\StockQuery;
$ok = $res['mismatches'] === 0;
$at = gmdate('H:i d/m/Y', strtotime($checkedAt . ' UTC') + 7 * 3600);   // giờ Việt Nam
$tabs = ['' => 'Tất cả', 'bad' => 'Chênh lệch', 'ok' => 'Khớp'];
$tones = ['' => 'all', 'bad' => 'bad', 'ok' => 'ok'];
$qs = fn(array $over) => http_build_query(array_filter(array_merge(['q' => $q, 'warehouse' => $wh, 'category' => $cat === -1 ? 'none' : $cat, 'status' => $status,
    'range' => $rangeSel === 'custom' ? '' : $rangeSel, 'from' => $rangeSel === 'custom' ? $from : null, 'to' => $rangeSel === 'custom' ? $to : null], $over), fn($v) => $v !== null && $v !== '' && $v !== 0));
$filtered = $q !== '' || $wh || $cat || $status || $rangeSel;
?>
<header class="page-head">
  <div><h1>Đối chiếu tồn</h1></div>
  <?php if ($auth->allows($user, \StockFlow\Domain\Permission::XemBaoCao)): ?><div class="actions"><a class="btn btn-ghost" href="/reports/reconcile.csv"><?= icon('download') ?>Xuất CSV</a></div><?php endif; ?>
</header>
<div class="verdict <?= $ok ? 'verdict-ok' : 'verdict-bad' ?>">
  <?= icon($ok ? 'shield' : 'alert') ?>
  <div>
    <b><?= $ok ? 'KHỚP — tồn đối chiếu được với sổ phát sinh' : 'CHÊNH LỆCH — ' . n($res['mismatches']) . ' điểm không khớp' ?></b><br>
    <span class="muted">Đã kiểm tra <b><?= n($res['checked']) ?></b> vị trí (kho · hàng · lô) và <b><?= n($res['voucher_lines']) ?></b> dòng phiếu đã xác nhận<?= $res['voucher_mismatches'] ? ': <b class="neg">' . n(count($res['voucher_mismatches'])) . ' dòng phiếu không khớp sổ</b>' : ': tất cả khớp sổ' ?>.</span>
    <?php if ($ok): ?><br><span class="muted">Số tồn đang lưu đúng bằng tổng các lần nhập, xuất đã ghi sổ: không mất và không thừa hàng so với sổ ghi chép.</span><?php endif; ?>
    <br><small class="muted">Kiểm tra lúc <?= e($at) ?> (giờ Việt Nam)</small>
  </div>
  <a class="btn btn-ghost" href="/reconcile?<?= e($qs([])) ?>" title="Tính lại từ sổ phát sinh ngay bây giờ"><?= icon('scale') ?>Kiểm tra lại</a>
</div>
<?php if (!$ok): ?>
<section class="card" style="margin-bottom:16px">
  <h2>Nên làm gì khi có chênh lệch</h2>
  <ol class="muted" style="margin:6px 0 0;padding-left:20px;line-height:1.6">
    <li>Sổ phát sinh là bản ghi gốc, không sửa được. Số <b>tồn đang lưu</b> mới là số có thể sai.</li>
    <li>Lọc tab «Chênh lệch», bấm <b>Chi tiết</b> ở dòng lệch để xem các lần nhập, xuất cấu thành số tồn và so với phiếu.</li>
    <li>Nguyên nhân thường gặp: số tồn bị sửa trực tiếp trong cơ sở dữ liệu, hoặc một thao tác bị ngắt giữa chừng khi hệ thống gặp sự cố.</li>
    <li>Không tự sửa số bằng tay: báo quản trị hệ thống để xử lý và ghi lại lý do.</li>
  </ol>
</section>
<?php endif; ?>
<?php if ($res['voucher_mismatches']): ?>
<section class="card flush"><div class="card-head pad"><h2>Dòng phiếu không khớp sổ</h2><span class="muted small">phiếu đã xác nhận nhưng thiếu dòng sổ hoặc sổ ghi sai số lượng</span></div><div class="table-wrap"><table class="dense"><thead><tr><th>Phiếu</th><th>Dòng</th><th class="num">SL phiếu</th><th class="num">Sổ</th></tr></thead><tbody>
<?php foreach ($res['voucher_mismatches'] as $m): ?><tr><td><?= e($m['ma']) ?></td><td><?= (int)$m['line_id'] ?></td><td class="num"><?= n($m['so_luong']) ?></td><td class="num"><?= $m['bien_dong'] === null ? '—' : n($m['bien_dong']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<nav class="tabs" aria-label="Kết quả đối chiếu" data-live="tabs">
  <?php foreach ($tabs as $k => $label): ?>
  <a href="/reconcile?<?= e($qs(['status' => $k, 'page' => null])) ?>" data-tone="<?= $tones[$k] ?>"<?= $status === $k ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= n($counts[$k]) ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="filters" method="get">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input type="search" name="q"<?= $q !== '' ? ' class="on"' : '' ?> maxlength="50" data-search-clean value="<?= e($q) ?>" placeholder="Tìm SKU, tên hàng hoặc số lô…" aria-label="Tìm vị trí kho">
  <?php if ($user->isAdmin && count($warehouses) > 1): ?><select name="warehouse"<?= $wh ? ' class="on"' : '' ?> aria-label="Kho"><?= wh_options($warehouses, $wh, 'Mọi kho') ?></select>
  <?php elseif ($warehouses): ?><span class="tag tag-info"><?= e($warehouses[0]['ma'] . ' — ' . $warehouses[0]['ten']) ?></span><?php endif; ?>
  <select name="category"<?= $cat ? ' class="on"' : '' ?> aria-label="Danh mục hàng hoá"><option value="">Mọi danh mục</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $cat === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['ten']) ?></option><?php endforeach; ?><option value="none"<?= $cat === -1 ? ' selected' : '' ?>>Chưa phân loại</option></select>
  <span class="muted small" title="Chỉ giữ vị trí có lần nhập, xuất cuối cùng nằm trong khoảng này">Phát sinh cuối:</span>
  <?php include __DIR__ . '/../partials/range_filter.php'; ?>
  <?php if ($filtered): ?><a class="btn btn-ghost" href="/reconcile">Xoá lọc</a><?php endif; ?>
</form>
<section class="card flush" data-live="results"><div class="table-wrap"><table class="dense">
  <thead><tr><th>Kho</th><th>Hàng hoá</th><th>Lô</th>
    <th class="num" title="Số tồn hệ thống đang lưu cho vị trí này (bảng số dư)">Tồn đang lưu</th>
    <th class="num" title="Cộng tất cả lần nhập, xuất đã ghi sổ của vị trí này (sổ phát sinh). Hai số này phải bằng nhau">Cộng từ sổ ghi chép</th>
    <th class="num" title="Tồn đang lưu trừ cộng từ sổ. Bằng 0 là khớp">Chênh lệch</th>
    <th class="num" title="Định mức tồn tối thiểu của mặt hàng; so với tổng tồn mọi lô của mặt hàng ở kho đó">Tồn tối thiểu</th>
    <th title="Hạn dùng của lô (theo số ngày cảnh báo sắp hết hạn)">Tình trạng hàng</th>
    <th class="num" title="Tồn đang lưu × giá gốc">Giá trị tồn</th>
    <th title="Lần nhập, xuất gần nhất của vị trí">Phát sinh cuối</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($res['rows'] as $r):
      $diff = (int)$r['balance'] - (int)$r['ledger'];
      $lot = $r['so_lo'] === 'NOLOT' ? '' : $r['so_lo'];
      $key = $r['kho_id'] . ':' . $r['mat_hang_id'] . ':' . $r['lo_hang_id'];
      $min = (int)$r['dinh_muc_toi_thieu'];
      $itemStock = $stockOf[$r['kho_id'] . ':' . $r['mat_hang_id']] ?? 0;
      $ex = $lot !== '' && $r['han_dung'] && (int)$r['balance'] > 0 ? StockQuery::expiryState($r['han_dung']) : null;
      $mv = $moves[$key] ?? [];
      $histUrl = '/history?' . http_build_query(array_filter(['warehouse' => $r['kho_id'], 'item' => $r['mat_hang_id'], 'q' => $lot])); ?>
    <tr class="<?= $diff ? 'row-bad' : '' ?>"><td><span class="wh-code"><?= e($r['wh_code']) ?></span></td>
      <td><b><?= hl($r['ma_hang'], $q) ?></b> <?= hl($r['ten'], $q) ?><?= $r['category'] ? '<br><small class="muted">' . e($r['category']) . '</small>' : '' ?></td>
      <td><?= $lot === '' ? '<span class="muted">—</span>' : hl($lot, $q) . ($r['han_dung'] ? '<br><small class="muted">HSD ' . e(dt($r['han_dung'], false)) . '</small>' : '') ?></td>
      <td class="num"><?= n($r['balance']) ?> <small class="muted"><?= e($r['unit']) ?></small></td><td class="num"><?= n($r['ledger']) ?></td>
      <td class="num"><?= $diff ? '<b class="neg">' . signed($diff) . '</b>' : '<span class="pos">✓</span>' ?></td>
      <td class="num"><?php if ($min > 0): ?><?= n($min) ?><?= $itemStock <= $min ? '<br><span class="tag tag-warn" title="Tổng tồn ' . n($itemStock) . ' ' . e($r['unit']) . ' ở kho này, không quá định mức">Sắp hết</span>' : '' ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td><?= $ex ? expiry_tag($ex) : '<span class="muted">—</span>' ?></td>
      <td class="num money"><?= (int)$r['gia_von'] > 0 && (int)$r['balance'] > 0 ? n((int)$r['balance'] * (int)$r['gia_von']) . ' <small class="muted">đ</small>' : '<span class="muted">—</span>' ?></td>
      <td class="muted"><?= $r['last_move'] ? e(dt_short($r['last_move'])) : '—' ?></td>
      <td class="nw"><button type="button" class="btn-ghost btn-sm" data-row-toggle aria-expanded="<?= $diff ? 'true' : 'false' ?>"><?= $diff ? 'Thu gọn' : 'Chi tiết' ?></button></td></tr>
    <tr class="detail-row"<?= $diff ? '' : ' hidden' ?>><td colspan="11">
      <p style="margin:0 0 8px"><?php if ($diff): ?>Tồn đang lưu <b><?= n($r['balance']) ?></b>, cộng từ sổ <b><?= n($r['ledger']) ?></b> ⇒ <b class="neg">chênh lệch <?= signed($diff) ?></b>: số đang lưu <?= $diff > 0 ? 'nhiều hơn' : 'ít hơn' ?> sổ ghi chép <?= n(abs($diff)) ?> <?= e($r['unit']) ?>.
        <?php else: ?>Tồn đang lưu <b><?= n($r['balance']) ?></b> = cộng từ sổ <b><?= n($r['ledger']) ?></b> <span class="pos">✓</span> — dưới đây là các lần nhập, xuất cấu thành số tồn.<?php endif; ?></p>
      <?php if ($mv): ?>
      <table class="dense"><thead><tr><th>Thời gian</th><th>Phiếu</th><th>Loại</th><th>Đối tác / lý do</th><th class="num">Nhập / xuất</th><th class="num">Tồn sau dòng này</th></tr></thead><tbody>
        <?php foreach ($mv as $m): ?><tr><td class="nw muted"><?= e(dt_short($m['ngay_ghi_so'])) ?></td><td><a href="/vouchers/<?= (int)$m['voucher_id'] ?>"><?= e($m['voucher_code']) ?></a></td><td><?= type_chip($m['loai']) ?></td>
          <td class="muted"><?= e($m['doi_tac'] ?: ($m['ly_do'] ?: '—')) ?></td><td class="num"><b class="<?= (int)$m['bien_dong'] < 0 ? 'neg' : 'pos' ?>"><?= signed((int)$m['bien_dong']) ?></b></td><td class="num"><?= n($m['ton_sau']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php else: ?><p class="muted" style="margin:0">Vị trí này chưa có dòng sổ nào.</p><?php endif; ?>
      <p style="margin:8px 0 0"><a href="<?= e($histUrl) ?>"><?= (int)$r['n_moves'] > count($mv) ? 'Xem tất cả ' . n($r['n_moves']) . ' dòng sổ →' : 'Xem trong Lịch sử phát sinh →' ?></a></p>
    </td></tr>
  <?php endforeach; if (!$res['rows']): ?><tr><td colspan="11" class="empty"><?= $filtered ? 'Không có vị trí nào phù hợp bộ lọc.' : 'Chưa có vị trí nào để đối chiếu.' ?></td></tr><?php endif; ?>
  </tbody></table></div><?= pager($pg) ?></section>
