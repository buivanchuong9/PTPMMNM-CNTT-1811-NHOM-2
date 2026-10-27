<?php
declare(strict_types=1);
// Thu hồi chuyến: quản lý kho đi đảo chuyến khi xe còn trên đường — hàng cộng lại kho đi, phiếu đến bị huỷ, xe về kho đi; Đối chiếu tồn vẫn khớp.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\FleetService;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherRepository;

$f = Fx::make();
$fleet = $f->c->get(FleetService::class);
$ts = $f->c->get(TransferService::class);
$repo = $f->c->get(VoucherRepository::class);
$xe = 0;
$ship = function (int $qx) use ($f, $ts, $repo, &$xe, $fleet): array {   // mỗi chuyến một xe + tài xế riêng
    $driver = $fleet->addDriver($f->approverA, 'Tài xế thu hồi', (string)random_int(100000000000, 999999999999), '0912345678');
    $xe = $fleet->addVehicle($f->approverA, '30H-' . random_int(10000, 99999), 'Xe tải', $f->wa, $driver);
    $f->receive($f->wa, 'x', $qx);
    $out = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', $qx)], 'chuyến thử', null)['voucher'];
    $ts->dispatch($f->approverA, (int)$out['id'], $xe, true);
    return [(int)$out['id'], (int)$repo->pairOf($repo->find((int)$out['id']))['id']];
};
$trip = fn(int $outId) => $f->db->one('SELECT * FROM chuyen_van_chuyen WHERE phieu_di_id = ?', [$outId]);

section('Thu hồi chuyến khi xe còn trên đường');
[$out, $in] = $ship(8);
$xe1 = $xe;
$ax = $f->stock($f->wa, 'x');
$bx = $f->stock($f->wb, 'x');
throws(fn() => $ts->recall($f->approverA, $out, '  '), DomainError::class, 'phải ghi lý do');
throws(fn() => $ts->recall($f->approverB, $out, 'Giao nhầm'), Forbidden::class, 'kho đến không thu hồi được chuyến của kho đi');
throws(fn() => $ts->recall($f->admin, $out, 'Giao nhầm'), Forbidden::class, 'admin chỉ xem, không thu hồi');
$adj = $ts->recall($f->approverA, $out, 'Giao nhầm kho đến')['voucher'];
same($f->stock($f->wa, 'x'), $ax + 8, 'hàng cộng lại vào tồn kho đi');
same($f->stock($f->wb, 'x'), $bx, 'tồn kho đến không đổi');
same([$trip($out)['trang_thai'], $repo->find($in)['trang_thai'], $adj['loai'], $adj['trang_thai']], ['RECALLED', 'CANCELLED', 'ADJUSTMENT', 'CONFIRMED'], 'chuyến RECALLED, phiếu đến huỷ, có phiếu điều chỉnh');
$v = $f->db->one('SELECT kho_hien_tai_id, dang_den_kho_id FROM xe WHERE id = ?', [$xe1]);
same([(int)$v['kho_hien_tai_id'], $v['dang_den_kho_id']], [$f->wa, null], 'xe quay về kho đi');
throws(fn() => $ts->recall($f->approverA, $out, 'Lần hai'), DomainError::class, 'không thu hồi hai lần');
throws(fn() => $ts->receive($f->approverB, $in), DomainError::class, 'kho đến không nhận được phiếu đã bị huỷ');

section('Kho đến đã nhận / đã báo chưa nhận được hàng thì không thu hồi được');
[$out2, $in2] = $ship(5);
$ts->receive($f->approverB, $in2);
throws(fn() => $ts->recall($f->approverA, $out2, 'Muộn rồi'), DomainError::class, 'đã nhận hàng ⇒ từ chối');
[$out3, $in3] = $ship(4);
$ts->reportNotReceived($f->approverB, $in3, 'Không liên lạc được với tài xế');
throws(fn() => $ts->recall($f->approverA, $out3, 'Gọi lại xe'), DomainError::class, 'đã báo chưa nhận được hàng ⇒ chờ quản trị xử lý');
finish();
