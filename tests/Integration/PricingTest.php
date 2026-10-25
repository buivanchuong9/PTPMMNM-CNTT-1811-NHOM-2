<?php
declare(strict_types=1);
// Giá hàng hoá: giá gốc + thuế do quản trị đặt (dùng chung mọi kho) · giá bán RIÊNG TỪNG KHO do quản lý kho đó đặt ·
// phiếu lưu giá tại lúc lập · doanh thu / thuế / lãi gộp · giá trị tồn kho.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\PricingService;
use StockFlow\Service\VoucherRepository;
use StockFlow\Service\VoucherService;

$f = Fx::make();
$vs = $f->c->get(VoucherService::class);
$ps = $f->c->get(PricingService::class);
$repo = $f->c->get(VoucherRepository::class);
$item = $f->it['x']['item'];
$row = fn() => $f->db->one('SELECT gia_ban, gia_von, thue_suat FROM mat_hang WHERE id = ?', [$item]);
$receive = function (int $wh, $keeper, $approver, int $qty) use ($f, $vs) {
    $v = $vs->createDraft($keeper, VoucherType::Receipt, $wh, [$f->line('x', $qty)], 'NCC', '', null)['voucher'];
    $vs->confirm($approver, (int)$v['id']);
    return $v;
};

section('Giá gốc và thuế: chỉ quản trị hệ thống đặt');
throws(fn() => $ps->setCost($f->approverA, $item, 150, 10, 'thử'), Forbidden::class, 'quản lý kho không đặt được giá gốc');
throws(fn() => $ps->setCost($f->keeperA, $item, 150, 10, 'thử'), Forbidden::class, 'nhân viên kho không đặt được giá gốc');
throws(fn() => $ps->setCost($f->admin, $item, 150, 10, ''), DomainError::class, 'đặt giá không ghi lý do bị từ chối');
throws(fn() => $ps->setCost($f->admin, $item, 150, 7, 'sai thuế'), DomainError::class, 'thuế suất ngoài danh sách bị từ chối');
throws(fn() => $ps->setCost($f->admin, $item, 0, 10, 'giá 0'), DomainError::class, 'giá gốc bằng 0 bị từ chối');
$ps->setCost($f->admin, $item, 150, 10, 'Giá gốc đầu kỳ');
same([(int)$row()['gia_von'], (int)$row()['thue_suat']], [150, 10], 'quản trị đặt giá gốc 150, thuế 10%');
$h = $ps->historyOf($item);
same(array_column($h, 'loai'), ['GIA_VON'], 'lịch sử ghi lần đổi giá gốc (thuế vẫn 10% nên không ghi)');
$n = count($h);
$ps->setCost($f->admin, $item, 150, 10, '');
same(count($ps->historyOf($item)), $n, 'lưu lại cùng giá ⇒ không ghi lịch sử, không cần lý do');
same(PricingService::parseMoney('1.250.000'), 1250000, 'đọc số tiền có dấu chấm ngăn cách');
same(PricingService::parseMoney('12a'), null, 'số tiền sai ký tự bị từ chối');

section('Giá bán ra: mỗi kho một giá, chỉ quản lý của kho đó đặt');
same($ps->sellPrice($f->wa, $item), 0, 'kho chưa đặt giá ⇒ chưa có giá bán (không có giá mặc định)');
throws(fn() => $ps->setSellPrice($f->keeperA, $f->wa, $item, 500, 'thử'), Forbidden::class, 'nhân viên kho không đặt được giá bán');
throws(fn() => $ps->setSellPrice($f->admin, $f->wa, $item, 500, 'thử'), Forbidden::class, 'quản trị không đặt giá bán của kho (việc của quản lý kho)');
throws(fn() => $ps->setSellPrice($f->approverB, $f->wa, $item, 500, 'thử'), Forbidden::class, 'quản lý kho B không đặt được giá của kho A');
throws(fn() => $ps->setSellPrice($f->approverA, $f->wa, $item, 500, ''), DomainError::class, 'đổi giá không ghi lý do bị từ chối');
throws(fn() => $ps->setSellPrice($f->approverA, $f->wa, $item, 0, 'giá 0'), DomainError::class, 'giá bán bằng 0 bị từ chối');
$ps->setSellPrice($f->approverA, $f->wa, $item, 500, 'Giá niêm yết kho A');
$ps->setSellPrice($f->approverB, $f->wb, $item, 520, 'Giá niêm yết kho B');
same([$ps->sellPrice($f->wa, $item), $ps->sellPrice($f->wb, $item)], [500, 520], 'mỗi kho một giá bán: A 500, B 520');
same((int)$row()['gia_von'], 150, 'giá gốc dùng chung, không đổi theo kho');
$hA = $ps->historyForMany([$item], $f->wa)[$item] ?? [];
same([count($hA), $hA[0]['cu'] ?? null, $hA[0]['moi'] ?? null], [1, 0, 500], 'lịch sử giá bán kho A: lần đầu đặt 0 → 500');
same(count($ps->historyForMany([$item], $f->wb)[$item] ?? []), 1, 'kho B chỉ thấy lịch sử giá bán của kho B');
same(array_unique(array_column($ps->historyForMany([$item], null)[$item] ?? [], 'loai')), ['GIA_VON'], 'quản trị xem lịch sử giá gốc / thuế, không lẫn giá bán các kho');
$n = count($ps->historyOf($item));
$ps->setSellPrice($f->approverA, $f->wa, $item, 500, '');
same(count($ps->historyOf($item)), $n, 'lưu lại cùng giá ⇒ không ghi lịch sử');

section('Phiếu xuất lưu giá của kho lập phiếu, thuế và giá gốc tại lúc lập');
$receive($f->wa, $f->keeperA, $f->approverA, 20);
$receive($f->wb, $f->keeperB, $f->approverB, 20);
$issueA = $vs->createDraft($f->keeperA, VoucherType::Issue, $f->wa, [$f->line('x', 4)], 'Khách A', '', null)['voucher'];
$issueB = $vs->createDraft($f->keeperB, VoucherType::Issue, $f->wb, [$f->line('x', 3)], 'Khách B', '', null)['voucher'];
$lnA = $repo->lines((int)$issueA['id'])[0];
$lnB = $repo->lines((int)$issueB['id'])[0];
same([(int)$lnA['don_gia'], (int)$lnA['thue_suat'], (int)$lnA['gia_von']], [500, 10, 150], 'phiếu xuất kho A: giá bán 500, thuế 10%, giá gốc 150');
same((int)$lnB['don_gia'], 520, 'phiếu xuất kho B lấy giá của kho B: 520');
$ps->setSellPrice($f->approverA, $f->wa, $item, 600, 'Tăng giá');
same((int)$repo->lines((int)$issueA['id'])[0]['don_gia'], 500, 'đổi giá bán sau khi lập phiếu ⇒ phiếu cũ vẫn 500');
$vs->confirm($f->approverA, (int)$issueA['id']);
$vs->confirm($f->approverB, (int)$issueB['id']);
$rcpt = $repo->lines((int)$f->db->value("SELECT id FROM phieu_nhap_xuat WHERE loai = 'RECEIPT' AND kho_id = ? ORDER BY id DESC LIMIT 1", [$f->wa]))[0];
same((int)$rcpt['don_gia'], 150, 'phiếu nhập lấy giá gốc do quản trị đặt (nhân viên không nhập giá)');

section('Doanh thu, thuế, giá gốc hàng bán, lãi gộp (theo giá từng kho)');
$sA = $ps->sales([$f->wa], null, null)[0];
$sB = $ps->sales([$f->wb], null, null)[0];
same([(int)$sA['doanh_thu'], (int)$sA['thue'], (int)$sA['gia_von']], [2000, 200, 600], 'kho A: 4 × 500 = 2.000 · thuế 200 · giá gốc 4 × 150 = 600');
same([(int)$sB['doanh_thu'], (int)$sB['thue'], (int)$sB['gia_von']], [1560, 156, 450], 'kho B: 3 × 520 = 1.560 · thuế 156 · giá gốc 450');
$issue2 = $vs->createDraft($f->keeperA, VoucherType::Issue, $f->wa, [$f->line('x', 5)], 'Khách C', '', null)['voucher'];
$vs->confirm($f->approverA, (int)$issue2['id']);
same((int)$ps->sales([$f->wa], null, null)[0]['doanh_thu'], 2000 + 3000, 'phiếu sau tính theo giá mới 600');
$vs->correct($f->approverA, (int)$issue2['id'], 'Khách trả hàng');
same((int)$ps->sales([$f->wa], null, null)[0]['doanh_thu'], 2000, 'phiếu đã bị điều chỉnh đảo không tính vào doanh thu');
same(count($ps->sales([$f->wa], '2000-01-01', '2000-01-02')), 0, 'lọc theo ngày: khoảng không có phiếu ⇒ rỗng');

section('Giá trị tồn kho: giá gốc chung, giá bán theo từng kho');
$byWh = [];
foreach ($ps->valuation([$f->wa, $f->wb]) as $r) {
    if ((int)$r['mat_hang_id'] === $item) {
        $byWh[(int)$r['kho_id']] = $r;
    }
}
same([(int)$byWh[$f->wa]['so_luong'], (int)$byWh[$f->wb]['so_luong']], [16, 17], 'tồn kho A 20 − 4 = 16, kho B 20 − 3 = 17');
same([(int)$byWh[$f->wa]['gia_von'], (int)$byWh[$f->wb]['gia_von']], [150, 150], 'giá gốc như nhau ở hai kho');
same([(int)$byWh[$f->wa]['gia_ban'], (int)$byWh[$f->wb]['gia_ban']], [600, 520], 'giá bán mỗi kho một giá');
same(count($ps->valuation([])), 0, 'không có kho nào ⇒ rỗng');

section('Điều chuyển giữa kho giữ nguyên giá gốc do quản trị đặt');
$t = $f->c->get(\StockFlow\Service\TransferService::class);
$out = $t->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 6)], '', null)['voucher'];
$t->confirm($f->approverA, (int)$out['id']);
$f->arrive((int)$out['id']);
same((int)$row()['gia_von'], 150, 'chuyển hàng A → B không làm đổi giá gốc');
$val = [];
foreach ($ps->valuation([$f->wa, $f->wb]) as $r) {
    if ((int)$r['mat_hang_id'] === $item) {
        $val[(int)$r['kho_id']] = (int)$r['so_luong'] * (int)$r['gia_von'];
    }
}
same([$val[$f->wa], $val[$f->wb]], [10 * 150, 23 * 150], 'giá trị tồn sau điều chuyển: A 10 × 150, B 23 × 150');
