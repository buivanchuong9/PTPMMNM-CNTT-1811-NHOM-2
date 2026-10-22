<?php
declare(strict_types=1);
// Phân quyền chức năng theo vai trò: lưu → có hiệu lực thật; quyền phụ thuộc quyền xem đi kèm; khôi phục mặc định.
require __DIR__ . '/../Support/bootstrap.php';

use StockFlow\Domain\AccountRole;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\Permission as P;
use StockFlow\Service\Authorizer;
use StockFlow\Service\StockCountService;

$f = Fx::make();
$auth = $f->c->get(Authorizer::class);
$counts = $f->c->get(StockCountService::class);

/** Ma trận đang hiệu lực → danh sách quyền bật theo vai trò (đúng định dạng saveMatrix nhận). */
$current = function () use ($auth): array {
    $out = [];
    foreach (AccountRole::configurable() as $r) {
        $out[$r->value] = array_keys(array_filter($auth->matrix()[$r->value]));
    }
    return $out;
};
$without = fn(array $list, string ...$drop) => array_values(array_diff($list, $drop));

section('Mặc định: mọi chức năng đi kèm đều có quyền xem tương ứng');
foreach (AccountRole::configurable() as $role) {
    foreach (P::cases() as $p) {
        if ($p->defaultFor($role) && $p->requires() !== null) {
            truthy($p->requires()->defaultFor($role), "{$role->label()}: «{$p->label()}» có sẵn quyền «{$p->requires()->label()}»");
        }
    }
}

section('Lưu phân quyền có hiệu lực thật');
truthy($auth->allows($f->keeperA, P::LapPhieuXuat), 'trước: nhân viên được lập phiếu xuất');
$m = $current();
$auth->saveMatrix($f->admin, ['STAFF' => $without($m['STAFF'], P::LapPhieuXuat->value), 'MANAGER' => $m['MANAGER']]);
truthy(!$auth->allows($f->keeperA, P::LapPhieuXuat), 'sau khi thu hồi: nhân viên không còn lập phiếu xuất');
truthy($auth->allows($f->keeperA, P::LapPhieuNhap), 'quyền khác không bị ảnh hưởng');
truthy($auth->allows($f->approverA, P::LapPhieuXuat), 'vai trò khác (quản lý) không bị ảnh hưởng');
throws(fn() => $auth->requirePermission($f->keeperA, P::LapPhieuXuat), Forbidden::class, 'máy chủ chặn khi thiếu quyền');
throws(fn() => $auth->saveMatrix($f->keeperA, ['STAFF' => []]), Forbidden::class, 'chỉ quản trị mới được lưu phân quyền');

section('Chức năng đi kèm cần quyền xem: không có quyền xem thì chức năng bị tắt');
$m = $current();
$auth->saveMatrix($f->admin, ['STAFF' => $without($m['STAFF'], P::XemPhieu->value), 'MANAGER' => $m['MANAGER']]);   // gửi dữ liệu mâu thuẫn: tắt xem phiếu nhưng vẫn bật lập phiếu nhập
truthy(!$auth->allows($f->keeperA, P::XemPhieu), 'nhân viên không còn xem phiếu');
truthy(!$auth->allows($f->keeperA, P::LapPhieuNhap), 'kéo theo: không còn lập phiếu nhập (không thể lập mà không xem được)');
same($f->db->value("SELECT duoc_phep FROM phan_quyen_vai_tro WHERE vai_tro = 'STAFF' AND quyen = ?", [P::LapPhieuNhap->value]), 0, 'giá trị lưu trong CSDL đã được chuẩn hoá');
truthy($auth->allows($f->approverA, P::LapPhieuNhap), 'quản lý vẫn bình thường');

section('Dữ liệu sửa tay trong CSDL cũng không lách được quyền xem');
$f->db->exec('DELETE FROM phan_quyen_vai_tro');
$f->db->exec("INSERT INTO phan_quyen_vai_tro (vai_tro, quyen, duoc_phep) VALUES ('STAFF', ?, 0), ('STAFF', ?, 1)", [P::XemKiemKe->value, P::KiemKeThucHien->value]);
$auth->forget();
truthy(!$auth->allows($f->keeperA, P::XemKiemKe) && !$auth->allows($f->keeperA, P::KiemKeThucHien), 'tắt «Xem kiểm kê» ⇒ «Thực hiện kiểm kê» cũng không còn hiệu lực');
throws(fn() => $counts->open($f->keeperA, $f->wa, ''), Forbidden::class, 'nhân viên không mở được đợt kiểm kê');
truthy($auth->allows($f->admin, P::XemKiemKe), 'tài khoản quản trị vẫn xem được kiểm kê (không bị ảnh hưởng)');

section('Bật lại cả hai thì dùng được');
$f->db->exec("UPDATE phan_quyen_vai_tro SET duoc_phep = 1 WHERE vai_tro = 'STAFF' AND quyen = ?", [P::XemKiemKe->value]);
$auth->forget();
truthy($auth->allows($f->keeperA, P::XemKiemKe) && $auth->allows($f->keeperA, P::KiemKeThucHien), 'có cả quyền xem và thực hiện ⇒ dùng được');
truthy($counts->open($f->keeperA, $f->wa, 'thử phân quyền') > 0, 'mở được đợt kiểm kê');

section('Khôi phục mặc định');
$auth->resetMatrix($f->admin);
truthy($auth->allows($f->keeperA, P::LapPhieuXuat) && $auth->allows($f->keeperA, P::XemPhieu) && $auth->allows($f->keeperA, P::LapPhieuNhap), 'mọi quyền mặc định của nhân viên trở lại');
same((int)$f->db->value('SELECT COUNT(*) FROM phan_quyen_vai_tro'), 0, 'không còn dòng tuỳ chỉnh');
throws(fn() => $auth->resetMatrix($f->keeperA), Forbidden::class, 'nhân viên không khôi phục được');
finish();
