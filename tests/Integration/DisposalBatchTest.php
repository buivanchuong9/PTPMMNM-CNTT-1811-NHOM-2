<?php
declare(strict_types=1);
// Kho huỷ: nhiều lô lên CHUNG MỘT xe (một chuyến), tải cả đợt phải vừa xe; xác nhận đến nơi / cho xuống xe theo cả chuyến.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Service\DisposalService;
use StockFlow\Service\FleetService;

$f = Fx::make();
$d = $f->c->get(DisposalService::class);
$fleet = $f->c->get(FleetService::class);

$f->receive($f->wa, 'y', 30);
$f->receive($f->wa, 'x', 30);
$y = $f->it['y'];
$x = $f->it['x'];
$f->db->exec('UPDATE mat_hang SET khoi_luong_g = 10000 WHERE id IN (?, ?)', [$y['item'], $x['item']]);   // 10 kg một đơn vị
$veh = $fleet->addVehicleWithDriver($f->approverA, '29C-' . random_int(10000, 99999), 'Xe tải 2 tấn', $f->wa, 'Tài xế Chuyến', (string)random_int(100000000000, 999999999999), '0912345671');
$f->db->exec('UPDATE xe SET tai_trong_kg = 5000 WHERE id = ?', [$veh['vehicle_id']]);

// quản lý tự lập ⇒ duyệt luôn: hàng nằm trong kho huỷ
$a = $d->propose($f->approverA, $f->wa, $y['item'], $y['batch'], 2, 'VO_HONG', '')['id'];   // 20 kg
$b = $d->propose($f->approverA, $f->wa, $x['item'], $x['batch'], 3, 'VO_HONG', '')['id'];   // 30 kg
$c = $d->propose($f->approverA, $f->wa, $y['item'], $y['batch'], 1, 'KHAC', 'Ghi chú')['id'];

section('Gom nhiều lô lên một xe');
throws(fn() => $d->loadMany($f->approverA, [], $veh['vehicle_id'], $veh['driver_id'], true), DomainError::class, 'chưa tích lô nào');
throws(fn() => $d->loadMany($f->approverA, [$a, $b], 0, $veh['driver_id'], true), DomainError::class, 'chưa chọn xe');
throws(fn() => $d->loadMany($f->approverA, [$a, $b], $veh['vehicle_id'], $veh['driver_id'], false), DomainError::class, 'tài xế chưa xác nhận nhận hàng');
throws(fn() => $d->loadMany($f->keeperA, [$a, $b], $veh['vehicle_id'], $veh['driver_id'], true), Forbidden::class, 'nhân viên không xuất hàng đi tiêu huỷ');

$f->db->exec('UPDATE xe SET tai_trong_kg = 40 WHERE id = ?', [$veh['vehicle_id']]);   // mỗi lô chở được một mình (20, 30 kg) nhưng cả đợt 50 kg thì không
throws(fn() => $d->loadMany($f->approverA, [$a, $b], $veh['vehicle_id'], $veh['driver_id'], true), DomainError::class, 'tải CẢ đợt vượt sức chở thì bị chặn');
same([$d->find($a)['xe_id'], $d->find($b)['xe_id']], [null, null], 'bị chặn thì không lô nào lên xe');
same($d->loadMany($f->approverA, [$b], $veh['vehicle_id'], $veh['driver_id'], true), 1, 'một lô 30 kg vẫn lên được xe 40 kg');
$d->unloadMany($f->approverA, [$b]);

$f->db->exec('UPDATE xe SET tai_trong_kg = 5000 WHERE id = ?', [$veh['vehicle_id']]);
same($d->loadMany($f->approverA, [$a, $b, $a], $veh['vehicle_id'], $veh['driver_id'], true), 2, 'xe 5 tấn chở được cả hai lô (id trùng chỉ tính một lần)');
$ra = $d->find($a);
$rb = $d->find($b);
same([$ra['xe_id'] === $veh['vehicle_id'], $rb['xe_id'] === $veh['vehicle_id'], $ra['tai_xe_id'] === $rb['tai_xe_id']], [true, true, true], 'cùng một xe, cùng một tài xế');
same($ra['ngay_xuat'], $rb['ngay_xuat'], 'cùng một mốc xuất (cùng chuyến)');
same((int)$f->db->value("SELECT COUNT(*) FROM nhat_ky WHERE hanh_dong = 'disposal.load' AND doi_tuong_id IN (?, ?)", [$a, $b]) >= 2, true, 'nhật ký ghi từng lô');
throws(fn() => $d->loadMany($f->approverA, [$a, $c], $veh['vehicle_id'], $veh['driver_id'], true), DomainError::class, 'lô đã lên xe thì không gom lần hai');
same($d->find($c)['xe_id'], null, 'lô còn lại không bị đụng tới khi cả đợt bị từ chối');

section('Cả chuyến đến nơi, hoặc xuống xe');
throws(fn() => $d->destroyMany($f->approverA, [$a, $c]), DomainError::class, 'một lô chưa lên xe ⇒ cả đợt quay lại');
same([$d->find($a)['trang_thai'], $d->find($b)['trang_thai']], ['TRONG_KHO_HUY', 'TRONG_KHO_HUY'], 'không lô nào bị tiêu huỷ dở');
throws(fn() => $d->unloadMany($f->approverA, [$a, $c]), DomainError::class, 'cho xuống xe cũng phải đang trên xe');
same($d->unloadMany($f->approverA, [$a, $b]), 2, 'cả chuyến xuống xe');
same([$d->find($a)['xe_id'], $d->find($b)['xe_id']], [null, null], 'hàng quay lại kho huỷ');
$d->loadMany($f->approverA, [$a, $b], $veh['vehicle_id'], $veh['driver_id'], true);
throws(fn() => $d->destroyMany($f->keeperA, [$a, $b]), Forbidden::class, 'nhân viên không xác nhận đến nơi');
same($d->destroyMany($f->approverA, [$a, $b]), 2, 'cả chuyến đến nơi');
same([$d->find($a)['trang_thai'], $d->find($b)['trang_thai'], $d->find($c)['trang_thai']], ['DA_TIEU_HUY', 'DA_TIEU_HUY', 'TRONG_KHO_HUY'], 'chỉ các lô của chuyến được tiêu huỷ');

finish();
