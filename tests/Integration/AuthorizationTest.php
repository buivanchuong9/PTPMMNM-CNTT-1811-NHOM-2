<?php
declare(strict_types=1);
// Phép thử 4 (tầng dịch vụ): tài khoản gắn với kho A không thao tác được trên kho B; quyền chức năng theo vai trò.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\Forbidden;
use StockFlow\Domain\AccountRole;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Permission;
use StockFlow\Domain\VoucherType;
use StockFlow\Service\Authorizer;
use StockFlow\Service\CatalogService;
use StockFlow\Service\StockCountService;
use StockFlow\Service\StockQuery;
use StockFlow\Service\TransferService;
use StockFlow\Service\VoucherService;

$f = Fx::make();
$vs = $f->c->get(VoucherService::class);
$ts = $f->c->get(TransferService::class);
$cs = $f->c->get(StockCountService::class);
$auth = $f->c->get(Authorizer::class);
$q = $f->c->get(StockQuery::class);
$f->receive($f->wa, 'x', 20);
$f->receive($f->wb, 'x', 20);
$vb = $f->draftIssue($f->wb, 'x', 5);
$countB = $cs->open($f->keeperB, $f->wb, '');
$mB = $f->movements($f->wb);

section('Nhân viên kho A (tài khoản gắn kho A) thao tác lên kho B bằng yêu cầu trực tiếp');
throws(fn() => $vs->createDraft($f->keeperA, VoucherType::Issue, $f->wb, [$f->line('x', 1)], '', '', null), Forbidden::class, 'lập phiếu XUẤT kho B bị từ chối');
throws(fn() => $vs->createDraft($f->keeperA, VoucherType::Receipt, $f->wb, [$f->line('x', 1)], '', '', null), Forbidden::class, 'lập phiếu nhập kho B bị từ chối');
throws(fn() => $vs->confirm($f->approverA, (int)$vb['id']), Forbidden::class, 'người duyệt A không xác nhận được phiếu của B');
throws(fn() => $vs->cancel($f->keeperA, (int)$vb['id']), Forbidden::class, 'không huỷ được phiếu của B');
throws(fn() => $ts->createDraft($f->keeperA, $f->wb, $f->wa, [$f->line('x', 1)], '', null), Forbidden::class, 'không lập điều chuyển ĐI từ kho B (rút hàng khỏi B)');
throws(fn() => $cs->open($f->keeperA, $f->wb, ''), Forbidden::class, 'không mở kiểm kê kho B');
throws(fn() => $cs->save($f->keeperA, $countB, []), Forbidden::class, 'không nhập số đếm kho B');
throws(fn() => $cs->approve($f->approverA, $countB), Forbidden::class, 'không duyệt kiểm kê kho B');
throws(fn() => $auth->requireVisible($f->keeperA, $f->wb), Forbidden::class, 'không xem được kho B');
same($f->movements($f->wb), $mB, 'sổ phát sinh kho B không đổi sau mọi yêu cầu trên');
same($f->stock($f->wb, 'x'), 20, 'tồn kho B nguyên vẹn');

section('Phân tách quyền theo vai trò trong cùng kho');
$confirmedA = $f->receive($f->wa, 'y', 3);
throws(fn() => $vs->confirm($f->keeperA, (int)$f->draftIssue($f->wa, 'x', 1)['id']), Forbidden::class, 'nhân viên (chưa được giao chức năng xác nhận) không tự xác nhận phiếu');
throws(fn() => $vs->correct($f->keeperA, (int)$confirmedA['id'], 'thử'), Forbidden::class, 'nhân viên không điều chỉnh phiếu nhập xuất');
throws(fn() => $cs->approve($f->keeperA, $cs->open($f->keeperA, $f->wa, '')), Forbidden::class, 'nhân viên không duyệt kiểm kê');
truthy($vs->createDraft($f->approverA, VoucherType::Issue, $f->wa, [$f->line('x', 1)], '', '', null)['created'], 'quản lý kho lập được phiếu ở kho của mình');
truthy($auth->allows($f->approverA, Permission::XacNhanPhieu) && !$auth->allows($f->keeperA, Permission::XacNhanPhieu), 'quản lý có chức năng xác nhận, nhân viên thì không');
truthy(!$auth->allows($f->approverA, Permission::QuanLyTaiKhoan) && !$auth->allows($f->approverA, Permission::PhanQuyen), 'chức năng hệ thống không giao được cho quản lý');

section('Đường điều chỉnh không phải cửa hậu');
$t = $ts->createDraft($f->keeperA, $f->wa, $f->wb, [$f->line('x', 4)], '', null)['voucher'];
$ts->confirm($f->approverA, (int)$t['id']);
throws(fn() => $vs->correct($f->approverA, (int)$t['id'], 'thử đảo điều chuyển'), Forbidden::class, 'người duyệt chỉ ở A không đảo được điều chuyển chạm kho B');
same($f->stock($f->wb, 'x'), 24, 'kho B không bị rút hàng qua đường đảo');
$vb2 = $f->receive($f->wb, 'y', 5);
throws(fn() => $vs->correct($f->approverA, (int)$vb2['id'], 'x'), Forbidden::class, 'không điều chỉnh phiếu nhập xuất của kho B');

section('Quyền xem (đọc) cũng theo kho');
$visA = $auth->visibleWarehouseIds($f->keeperA);
same($visA, [$f->wa], 'chỉ thấy kho A');
$hist = $q->history($visA);
truthy($hist !== [] && count(array_unique(array_column($hist, 'kho_id'))) === 1 && (int)$hist[0]['kho_id'] === $f->wa, 'lịch sử chỉ gồm phát sinh kho A');
same(array_unique(array_map(fn($r) => (int)$r['kho_id'], $q->onHand($visA))), [$f->wa], 'tồn kho chỉ gồm kho A');
same($q->onHand([]), [], 'không có kho nào ⇒ không có dữ liệu');
truthy($auth->allows($f->both, Permission::XemTonKho) && $auth->allows($f->both, Permission::QuanLyTaiKhoan) && $auth->allows($f->both, Permission::PhanQuyen), 'chủ hệ thống xem thống kê và quản lý tài khoản / phân quyền');
section('Chủ hệ thống chỉ xem và quản lý — không thao tác nhập / xuất / kiểm kê');
$draftA = $f->draftIssue($f->wa, 'x', 1);
throws(fn() => $vs->createDraft($f->both, VoucherType::Receipt, $f->wa, [$f->line('x', 1)], '', '', null), Forbidden::class, 'không lập phiếu nhập');
throws(fn() => $vs->createDraft($f->both, VoucherType::Issue, $f->wa, [$f->line('x', 1)], '', '', null), Forbidden::class, 'không lập phiếu xuất');
throws(fn() => $ts->createDraft($f->both, $f->wa, $f->wb, [$f->line('x', 1)], '', null), Forbidden::class, 'không lập điều chuyển');
throws(fn() => $vs->confirm($f->both, (int)$draftA['id']), Forbidden::class, 'không xác nhận phiếu');
throws(fn() => $cs->open($f->both, $f->wa, ''), Forbidden::class, 'không mở kiểm kê');
truthy(!$auth->allows($f->both, Permission::LapPhieuXuat) && !$auth->allows($f->both, Permission::XacNhanPhieu) && !$auth->allows($f->both, Permission::KiemKeThucHien), 'chức năng tác nghiệp không thuộc chủ hệ thống');
truthy($auth->allows($f->both, Permission::DieuChinhPhieu), 'chủ hệ thống vẫn có quyền giám sát: đảo phiếu nhập xuất sai (kể cả điều chuyển liên kho)');
$flow = $q->flowByWarehouse([$f->wa, $f->wb], 30);
truthy(isset($flow[$f->wa]) && $flow[$f->wa]['nhap'] >= 20 && $flow[$f->wa]['vao'] >= $flow[$f->wa]['nhap'], 'thống kê xuất nhập từng kho (nhập ≥ 20 ở kho A)');
same(count($q->dailyFlow([$f->wa, $f->wb], 14)), 14, 'chuỗi 14 ngày đủ ngày');
section('Quyền xem (đọc) cũng theo kho — tiếp'); 
same(array_values(array_intersect([$f->wa, $f->wb], $auth->visibleWarehouseIds($f->both))), [$f->wa, $f->wb], 'tài khoản cấp cao thấy mọi kho');

section('Quản trị danh mục & tài khoản chỉ dành cho tài khoản cấp cao');
$cat = $f->c->get(CatalogService::class);
throws(fn() => $cat->addWarehouse($f->keeperA, 'ZZ9', 'x', ''), Forbidden::class, 'nhân viên kho không tạo được kho');
throws(fn() => $cat->createAccount($f->keeperA, 'hacker_' . $f->tag, 'x', 'password1', AccountRole::Admin, null), Forbidden::class, 'nhân viên không tạo được tài khoản');
throws(fn() => $cat->createAccount($f->approverA, 'hacker2_' . $f->tag, 'x', 'password1', AccountRole::Staff, $f->wa), Forbidden::class, 'quản lý kho cũng không tạo được tài khoản');
throws(fn() => $cat->updateAccount($f->approverA, $f->keeperA->id, AccountRole::Manager, $f->wa), Forbidden::class, 'quản lý không tự nâng quyền nhân viên');
throws(fn() => $auth->saveMatrix($f->approverA, ['STAFF' => ['xem_ton_kho']]), Forbidden::class, 'quản lý không sửa được phân quyền');

section('Mỗi kho đúng một quản lý, nhiều nhân viên; tài khoản gắn đúng một kho');
$mgr2 = fn() => $cat->createAccount($f->admin, 'mgr2_' . $f->tag, 'Quản lý thứ hai', 'password1', AccountRole::Manager, $f->wa);
throws($mgr2, DomainError::class, 'kho A đã có quản lý ⇒ không tạo quản lý thứ hai');
$nv = $cat->createAccount($f->admin, 'nv2_' . $f->tag, 'Nhân viên thứ hai', 'password1', AccountRole::Staff, $f->wa);
truthy($nv > 0, 'một kho có nhiều nhân viên');
throws(fn() => $cat->createAccount($f->admin, 'nokho_' . $f->tag, 'Không kho', 'password1', AccountRole::Staff, null), DomainError::class, 'nhân viên bắt buộc gắn kho');
throws(fn() => $f->db->insert("INSERT INTO nguoi_dung (ten_dang_nhap, ho_ten, mat_khau_ma_hoa, vai_tro, kho_id, quan_ly_kho_id) VALUES (?,?,?,?,?,?)",
    ['dup_' . $f->tag, 'x', 'x', 'MANAGER', $f->wa, $f->wa]), \Throwable::class, 'CSDL cũng từ chối quản lý thứ hai (UNIQUE quan_ly_kho_id), kể cả khi bỏ qua ứng dụng');
throws(fn() => $f->db->insert("INSERT INTO nguoi_dung (ten_dang_nhap, ho_ten, mat_khau_ma_hoa, vai_tro, kho_id) VALUES (?,?,?,?,?)",
    ['adm_' . $f->tag, 'x', 'x', 'ADMIN', $f->wa]), \Throwable::class, 'CSDL từ chối tài khoản cấp cao có gắn kho (CHECK)');
// đổi kho / vai trò: quản lý A xuống nhân viên rồi nhân viên B lên quản lý kho A
$cat->updateAccount($f->admin, $f->approverA->id, AccountRole::Staff, $f->wa);
$cat->updateAccount($f->admin, $f->keeperA->id, AccountRole::Manager, $f->wa);
$dir = array_column($cat->warehouseDirectory(), null, 'id')[$f->wa];
same([$dir['quan_ly']['ten_dang_nhap'], count($dir['nhan_vien'])], [$f->keeperA->username, 2], 'danh sách kho: quản lý mới + 2 nhân viên (approverA, nv2)');
$cat->updateAccount($f->admin, $f->keeperA->id, AccountRole::Staff, $f->wa);
$cat->updateAccount($f->admin, $f->approverA->id, AccountRole::Manager, $f->wa);
throws(fn() => $cat->updateAccount($f->admin, $f->admin->id, AccountRole::Staff, $f->wa), DomainError::class, 'không tự hạ vai trò của chính mình');
$cat->setAccountActive($f->admin, $nv, false);
$auth->forget();
same((int)$f->db->value('SELECT hoat_dong FROM nguoi_dung WHERE id = ?', [$nv]), 0, 'khoá tài khoản (giữ lịch sử)');
$cat->setAccountActive($f->admin, $nv, true);

section('Thu hồi / bật lại chức năng theo vai trò: chức năng vẫn còn, chỉ bị chặn');
$auth->resetMatrix($f->admin);
truthy($auth->allows($f->keeperA, Permission::LapPhieuXuat), 'mặc định: nhân viên được lập phiếu xuất');
$granted = ['MANAGER' => [], 'STAFF' => []];
foreach (AccountRole::configurable() as $role) {
    foreach (Permission::cases() as $perm) {
        if (!$perm->adminOnly() && $perm->defaultFor($role) && !($role === AccountRole::Staff && $perm === Permission::LapPhieuXuat)) {
            $granted[$role->value][] = $perm->value;
        }
    }
}
$auth->saveMatrix($f->admin, $granted);
throws(fn() => $vs->createDraft($f->keeperA, VoucherType::Issue, $f->wa, [$f->line('x', 1)], '', '', null), Forbidden::class, 'thu hồi «Lập phiếu xuất» của nhân viên ⇒ bị chặn');
truthy($vs->createDraft($f->keeperA, VoucherType::Receipt, $f->wa, [$f->line('x', 1)], '', '', null)['created'], 'các chức năng khác của nhân viên vẫn dùng được');
truthy($vs->createDraft($f->approverA, VoucherType::Issue, $f->wa, [$f->line('x', 1)], '', '', null)['created'], 'quản lý vẫn lập được phiếu xuất (chức năng không bị xoá)');
truthy(!$auth->allows($f->admin, Permission::LapPhieuXuat) && $auth->allows($f->both, Permission::PhanQuyen), 'chủ hệ thống không có chức năng tác nghiệp và vẫn giữ quyền quản lý, không bị thu hồi');
$auth->saveMatrix($f->admin, ['MANAGER' => $granted['MANAGER'], 'STAFF' => array_merge($granted['STAFF'], ['lap_phieu_xuat'])]);
truthy($vs->createDraft($f->keeperA, VoucherType::Issue, $f->wa, [$f->line('x', 1)], '', '', null)['created'], 'bật lại quyền ⇒ dùng lại được ngay');
$auth->resetMatrix($f->admin);
truthy($auth->allows($f->keeperA, Permission::LapPhieuXuat) && !$auth->allows($f->keeperA, Permission::XacNhanPhieu), 'khôi phục mặc định');
section('Quản lý kho thêm nhân viên cho kho của mình');
$khoOf = fn(int $id) => (int)$f->db->value('SELECT kho_id FROM nguoi_dung WHERE id = ?', [$id]);
$n3 = $cat->createStaff($f->approverA, 'nv3_' . $f->tag, 'Nhân viên thứ ba', 'password1');
same([$khoOf($n3), $f->db->value('SELECT vai_tro FROM nguoi_dung WHERE id = ?', [$n3])], [$f->wa, 'STAFF'], 'nhân viên mới thuộc kho của quản lý, vai trò STAFF');
$n4 = $cat->createStaff($f->approverA, 'nv4_' . $f->tag, 'Nhân viên thứ tư', 'password1', $f->wb);
same($khoOf($n4), $f->wa, 'quản lý truyền kho B vẫn chỉ tạo được ở kho của mình (A)');
throws(fn() => $cat->createStaff($f->keeperA, 'nv5_' . $f->tag, 'x', 'password1'), Forbidden::class, 'nhân viên không thêm được nhân viên');
throws(fn() => $cat->createStaff($f->approverA, 'nv3_' . $f->tag, 'Trùng tên', 'password1'), DomainError::class, 'tên đăng nhập trùng bị từ chối');
throws(fn() => $cat->createStaff($f->approverA, 'ab', 'x', 'password1'), DomainError::class, 'tên đăng nhập quá ngắn bị từ chối');
$n6 = $cat->createStaff($f->admin, 'nv6_' . $f->tag, 'Nhân viên kho B', 'password1', $f->wb);
same($khoOf($n6), $f->wb, 'tài khoản cấp cao chọn được kho bất kỳ');
throws(fn() => $cat->createStaff($f->admin, 'nv7_' . $f->tag, 'x', 'password1'), DomainError::class, 'tài khoản cấp cao phải chọn kho');
$dirA = array_column($cat->warehouseDirectory(), null, 'id')[$f->wa];
truthy(count($dirA['nhan_vien']) >= 4 && isset($dirA['nhan_vien'][0]['ngay_tao'], $dirA['nhan_vien'][0]['ho_ten']), 'danh sách kho liệt kê đầy đủ từng nhân viên (họ tên, ngày tạo)');
$granted2 = ['MANAGER' => [], 'STAFF' => []];
foreach (AccountRole::configurable() as $role) {
    foreach (Permission::cases() as $perm) {
        if (!$perm->adminOnly() && $perm->defaultFor($role) && $perm !== Permission::QuanLyNhanVien) {
            $granted2[$role->value][] = $perm->value;
        }
    }
}
$auth->saveMatrix($f->admin, $granted2);
throws(fn() => $cat->createStaff($f->approverA, 'nv8_' . $f->tag, 'x', 'password1'), Forbidden::class, 'thu hồi «Thêm nhân viên kho» của quản lý ⇒ bị chặn');
$auth->resetMatrix($f->admin);
truthy($cat->createStaff($f->approverA, 'nv8_' . $f->tag, 'Nhân viên thứ tám', 'password1') > 0, 'khôi phục mặc định ⇒ quản lý thêm lại được');

finish();
