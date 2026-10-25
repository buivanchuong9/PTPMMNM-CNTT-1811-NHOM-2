<?php
declare(strict_types=1);
// Chuông thông báo: gom việc chờ theo vai trò, dẫn đúng trang cần làm.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Service\NotificationService;
use StockFlow\Service\TransferService;

$f = Fx::make();
$f->receive($f->wa, 'x', 50);
$svc = $f->c->get(NotificationService::class);
$titles = fn($u) => array_column($svc->for($u), 'title');
$byTitle = fn($u, $t) => array_values(array_filter($svc->for($u), fn($n) => $n['title'] === $t))[0] ?? null;

section('Phiếu chờ xác nhận');
$f->c->get(TransferService::class)->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 10)], '', null);
same($byTitle($f->approverA, 'Phiếu chờ xác nhận')['count'] ?? 0, 1, 'quản lý kho A thấy 1 phiếu nháp chờ xác nhận');
same($byTitle($f->approverA, 'Phiếu chờ xác nhận')['href'] ?? '', '/vouchers?status=DRAFT', 'bấm vào mở đúng danh sách phiếu nháp');
same(in_array('Phiếu chờ xác nhận', $titles($f->approverB), true), false, 'kho B chưa có phiếu nào chờ');

section('Hàng điều chuyển chờ nhận');
$out = $f->c->get(\StockFlow\Service\VoucherRepository::class)->find((int)$f->db->value("SELECT id FROM phieu_nhap_xuat WHERE loai = 'TRANSFER_OUT' AND kho_id = ?", [$f->wa]));
$f->c->get(TransferService::class)->confirm($f->approverA, (int)$out['id']);
same($byTitle($f->approverB, 'Hàng điều chuyển chờ nhận')['count'] ?? 0, 1, 'kho B thấy 1 chuyến chờ nhận');
same(in_array('Phiếu chờ xác nhận', $titles($f->approverA), true), false, 'xác nhận xong thì thông báo của kho A mất');

section('Kiểm kê chờ duyệt');
same($titles($f->keeperB) === [] || !in_array('Đợt kiểm kê chờ duyệt', $titles($f->keeperB), true), true, 'nhân viên không có thông báo duyệt kiểm kê');

section('Tài khoản quản trị (admin) cũng có thông báo');
$f->c->get(TransferService::class)->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 5)], '', null);
$adminTitles = $titles($f->admin);
same(in_array('Phiếu chờ xác nhận', $adminTitles, true), true, 'admin thấy phiếu nháp chờ xác nhận');
same(in_array('Hàng điều chuyển chờ nhận', $adminTitles, true), true, 'admin thấy hàng điều chuyển chờ nhận');

section('Hoạt động mới của các kho (chỉ quản trị)');
$act0 = $svc->activity($f->admin);   // lần đầu: đặt mốc, chưa có gì «mới»
same($act0['new'], 0, 'lần đầu mở chuông: không đổ lịch sử cũ thành mới');
$f->c->get(TransferService::class)->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 2)], '', null);
$act1 = $svc->activity($f->admin);
same($act1['new'], 1, 'nhân viên vừa lập phiếu điều chuyển ⇒ 1 hoạt động mới');
same($act1['list'][0]['fresh'], true, 'dòng đầu được đánh dấu mới');
same(str_starts_with($act1['list'][0]['href'], '/vouchers/'), true, 'bấm vào mở đúng phiếu');
same($svc->all($f->approverA)['activity']['new'], 0, 'quản lý kho không có mục hoạt động của các kho');
$svc->markActivitySeen($f->admin);
same($svc->activity($f->admin)['new'], 0, 'đóng chuông ⇒ hết số mới');
same($svc->activity($f->admin)['list'][0]['fresh'], false, 'dòng cũ không còn đánh dấu mới');
