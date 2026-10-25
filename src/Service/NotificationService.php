<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * Trung tâm thông báo (chuông trên thanh đầu trang): gom mọi việc đang chờ tài khoản xử lý, mỗi dòng dẫn thẳng đến trang cần làm.
 * Tính từ trạng thái hiện tại của dữ liệu (không lưu «đã đọc»): xử lý xong việc nào thì thông báo đó tự mất.
 */
final class NotificationService
{
    public function __construct(private Db $db, private Authorizer $auth, private FleetService $fleet) {}

    /** Hành động trong nhật ký ⇒ câu mô tả. Hành động không có ở đây (đổi ảnh, đổi mật khẩu của chính mình…) không báo. */
    private const ACTIVITY = [
        'voucher.create' => 'lập phiếu', 'voucher.confirm' => 'xác nhận phiếu', 'voucher.cancel' => 'huỷ phiếu', 'voucher.correct' => 'lập phiếu điều chỉnh',
        'transfer.create' => 'lập phiếu điều chuyển', 'transfer.dispatch' => 'xuất kho điều chuyển', 'transfer.vehicle' => 'giao xe chở hàng', 'transfer.receive' => 'nhận hàng điều chuyển',
        'transfer.short' => 'nhận thiếu hàng', 'transfer.missing' => 'báo chưa nhận được hàng', 'transfer.reply' => 'phản hồi chuyến hàng', 'transfer.correct' => 'điều chỉnh điều chuyển',
        'count.open' => 'mở đợt kiểm kê', 'count.submit' => 'nộp đợt kiểm kê chờ duyệt', 'count.approve' => 'duyệt đợt kiểm kê', 'count.reject' => 'trả lại đợt kiểm kê', 'count.cancel' => 'huỷ đợt kiểm kê',
        'request.create' => 'lập yêu cầu chuyển hàng', 'request.approve' => 'xác nhận yêu cầu chuyển hàng', 'request.accept' => 'chấp nhận yêu cầu chuyển hàng', 'request.reject' => 'từ chối yêu cầu chuyển hàng', 'request.cancel' => 'huỷ yêu cầu chuyển hàng',
        'item.create' => 'thêm mặt hàng', 'item.update' => 'sửa mặt hàng', 'batch.create' => 'thêm lô hàng', 'category.create' => 'thêm danh mục',
        'partner.create' => 'thêm đối tác', 'partner.update' => 'sửa đối tác', 'warehouse.create' => 'thêm kho', 'warehouse.update' => 'sửa kho', 'warehouse.address' => 'đổi địa chỉ kho',
        'user.create' => 'tạo tài khoản', 'user.update' => 'sửa tài khoản', 'user.lock' => 'khoá tài khoản', 'user.unlock' => 'mở khoá tài khoản',
        'vehicle.create' => 'thêm xe', 'vehicle.update' => 'sửa xe', 'vehicle.relocate' => 'chuyển xe sang kho khác', 'driver.create' => 'thêm tài xế', 'driver.update' => 'sửa tài xế',
    ];
    /** Đối tượng trong nhật ký ⇒ trang đích (đuôi «/» nghĩa là nối thêm id). */
    private const ACTIVITY_PAGE = ['voucher' => '/vouchers/', 'count' => '/counts/', 'request' => '/requests', 'item' => '/inventory', 'batch' => '/inventory', 'category' => '/inventory',
        'partner' => '/partners', 'warehouse' => '/warehouses', 'user' => '/users', 'vehicle' => '/fleet', 'driver' => '/fleet'];

    /** Mọi thứ cho chuông: việc đang chờ + (chỉ quản trị) hoạt động mới của các kho. */
    public function all(User $user): array
    {
        return ['tasks' => $this->for($user), 'activity' => $user->isAdmin ? $this->activity($user) : ['new' => 0, 'list' => []]];
    }

    /**
     * Hoạt động của người khác trong nhật ký thao tác kể từ lần quản trị xem chuông gần nhất (mốc lưu ở cai_dat, như «đã xem» của menu).
     * @return array{new:int,list:list<array{text:string,who:string,at:string,href:string,fresh:bool}>}
     */
    public function activity(User $user): array
    {
        $seen = $this->db->value("SELECT gia_tri FROM cai_dat WHERE pham_vi = 'user' AND doi_tuong_id = ? AND khoa = 'bell.activity'", [$user->id]);
        if ($seen === null || $seen === false) {   // lần đầu: đặt mốc ở hiện tại, không đổ cả lịch sử cũ thành «mới»
            $seen = $this->maxId();
            $this->putSeen($user, (int)$seen);
        }
        $keys = array_keys(self::ACTIVITY);
        $in = implode(',', array_fill(0, count($keys), '?'));
        $rows = $this->db->all(
            "SELECT a.id, a.hanh_dong, a.doi_tuong, a.doi_tuong_id, a.chi_tiet, a.ngay_tao, u.ho_ten FROM nhat_ky a LEFT JOIN nguoi_dung u ON u.id = a.nguoi_dung_id
             WHERE (a.nguoi_dung_id IS NULL OR a.nguoi_dung_id <> ?) AND a.hanh_dong IN ($in) ORDER BY a.id DESC LIMIT 15",
            [$user->id, ...$keys]
        );
        $new = (int)$this->db->value("SELECT COUNT(*) FROM nhat_ky WHERE id > ? AND (nguoi_dung_id IS NULL OR nguoi_dung_id <> ?) AND hanh_dong IN ($in)", [(int)$seen, $user->id, ...$keys]);
        $list = array_map(function (array $a) use ($seen): array {
            $page = self::ACTIVITY_PAGE[$a['doi_tuong']] ?? '/history';
            $detail = trim((string)$a['chi_tiet']);
            return [
                'text' => self::ACTIVITY[$a['hanh_dong']] . ($detail !== '' ? ' · ' . mb_strimwidth($detail, 0, 70, '…') : ''),
                'who' => (string)($a['ho_ten'] ?? 'Hệ thống'),
                'at' => dt_short($a['ngay_tao']),
                'href' => str_ends_with($page, '/') ? $page . (int)$a['doi_tuong_id'] : $page,
                'fresh' => (int)$a['id'] > (int)$seen,
            ];
        }, $rows);
        return ['new' => $new, 'list' => $list];
    }

    /** Quản trị đã xem hết hoạt động hiện có ⇒ dời mốc lên. */
    public function markActivitySeen(User $user): void
    {
        $this->putSeen($user, $this->maxId());
    }

    private function maxId(): int
    {
        return (int)$this->db->value('SELECT COALESCE(MAX(id), 0) FROM nhat_ky');
    }

    private function putSeen(User $user, int $id): void
    {
        $exists = $this->db->value("SELECT COUNT(*) FROM cai_dat WHERE pham_vi = 'user' AND doi_tuong_id = ? AND khoa = 'bell.activity'", [$user->id]);
        $exists
            ? $this->db->exec("UPDATE cai_dat SET gia_tri = ?, cap_nhat = CURRENT_TIMESTAMP WHERE pham_vi = 'user' AND doi_tuong_id = ? AND khoa = 'bell.activity'", [(string)$id, $user->id])
            : $this->db->exec("INSERT INTO cai_dat (pham_vi, doi_tuong_id, khoa, gia_tri) VALUES ('user', ?, 'bell.activity', ?)", [$user->id, (string)$id]);
    }

    /** @return list<array{icon:string,tone:string,title:string,note:string,count:int,href:string}> */
    public function for(User $user): array
    {
        $scope = $this->auth->visibleWarehouseIds($user);
        $out = [];
        $add = function (string $icon, string $tone, string $title, string $note, int $count, string $href) use (&$out): void {
            if ($count > 0) {
                $out[] = ['icon' => $icon, 'tone' => $tone, 'title' => $title, 'note' => $note, 'count' => $count, 'href' => $href];
            }
        };
        $in = $scope ? implode(',', array_fill(0, count($scope), '?')) : '';

        if ($scope && $this->auth->allows($user, Permission::XemPhieu)) {
            if ($user->isAdmin || $this->auth->allows($user, Permission::XacNhanPhieu)) {
                $n = (int)$this->db->value("SELECT COUNT(*) FROM phieu_nhap_xuat WHERE trang_thai = 'DRAFT' AND loai <> 'TRANSFER_IN' AND kho_id IN ($in)", $scope);
                $add('file', 'warn', 'Phiếu chờ xác nhận', 'Phiếu nhập, xuất, điều chuyển đi ở trạng thái nháp', $n, '/vouchers?status=DRAFT');
            }
            if ($user->isAdmin || $this->auth->allows($user, Permission::NhanHangDieuChuyen)) {
                $n = (int)$this->db->value("SELECT COUNT(*) FROM phieu_nhap_xuat WHERE trang_thai = 'DRAFT' AND loai = 'TRANSFER_IN' AND kho_id IN ($in)", $scope);
                $add('truck', 'info', 'Hàng điều chuyển chờ nhận', 'Hàng đang trên đường tới kho, chờ xác nhận nhận', $n, '/vouchers?type=TRANSFER_IN&status=DRAFT');
                // Kho đến đã báo chưa thấy hàng và quản trị đã phản hồi ⇒ báo cho kho đến đọc phản hồi.
                $rows = $this->db->all(
                    "SELECT c.phieu_den_id FROM chuyen_van_chuyen c WHERE c.trang_thai = 'IN_TRANSIT' AND c.sai_lech <> '' AND c.kho_den_id IN ($in)
                       AND EXISTS (SELECT 1 FROM nhat_ky a WHERE a.hanh_dong = 'transfer.reply' AND a.doi_tuong = 'voucher' AND a.doi_tuong_id = c.phieu_den_id)",
                    $scope
                );
                $add('alert', 'info', 'Quản trị đã phản hồi', 'Phản hồi về chuyến bạn báo chưa nhận được hàng', count($rows),
                    count($rows) === 1 ? '/vouchers/' . (int)$rows[0]['phieu_den_id'] : '/vouchers?type=TRANSFER_IN&status=DRAFT');
            }
        }
        if ($this->auth->allows($user, Permission::QuanLyDoiXe)) {
            $add('alert', 'bad', 'Chuyến chưa thấy hàng', 'Kho đến báo chưa nhận được hàng — cần phản hồi', $this->fleet->openDiscrepancies(), '/fleet');
        }
        if ($scope && ($user->isAdmin || $this->auth->allows($user, Permission::KiemKeDuyet))) {
            $n = (int)$this->db->value("SELECT COUNT(*) FROM dot_kiem_ke WHERE trang_thai = 'REVIEW' AND kho_id IN ($in)", $scope);
            $add('clipboard', 'warn', 'Đợt kiểm kê chờ duyệt', 'Có chênh lệch cần duyệt', $n, '/counts?status=REVIEW');
        }
        if ($scope && $this->auth->allows($user, Permission::KiemKeThucHien)) {
            $n = (int)$this->db->value("SELECT COUNT(*) FROM dot_kiem_ke WHERE trang_thai = 'OPEN' AND kho_id IN ($in)", $scope);
            $add('clipboard', 'info', 'Đợt kiểm kê đang đếm', 'Nhập số đếm rồi nộp duyệt', $n, '/counts?status=OPEN');
        }
        if ($user->warehouseId !== null && ($this->auth->allows($user, Permission::LapPhieuDieuChuyen) || $this->auth->allows($user, Permission::XacNhanPhieu))) {
            $wh = (int)$user->warehouseId;
            if ($this->auth->allows($user, Permission::XacNhanPhieu)) {
                $add('truck', 'warn', 'Yêu cầu chuyển hàng chờ xác nhận', 'Nhân viên kho bạn xin hàng từ kho khác',
                    (int)$this->db->value("SELECT COUNT(*) FROM yeu_cau_chuyen_hang WHERE kho_nhan_id = ? AND trang_thai = 'WAIT_OWN'", [$wh]), '/requests?status=WAIT_OWN');
                $add('truck', 'warn', 'Kho khác xin hàng của bạn', 'Chấp nhận hoặc từ chối kèm lý do',
                    (int)$this->db->value("SELECT COUNT(*) FROM yeu_cau_chuyen_hang WHERE kho_cap_id = ? AND trang_thai = 'WAIT_SOURCE'", [$wh]), '/requests?status=WAIT_SOURCE');
            }
            $add('alert', 'bad', 'Yêu cầu chuyển hàng bị từ chối', 'Xem lý do từ kho cấp hàng',
                (int)$this->db->value("SELECT COUNT(*) FROM yeu_cau_chuyen_hang WHERE kho_nhan_id = ? AND trang_thai = 'REJECTED' AND da_bao = 0", [$wh]), '/requests?status=REJECTED');
        }
        if ($user->isAdmin) {   // quản trị theo dõi mọi yêu cầu chuyển hàng đang chạy (chỉ xem)
            $n = (int)$this->db->value("SELECT COUNT(*) FROM yeu_cau_chuyen_hang WHERE trang_thai IN ('WAIT_OWN', 'WAIT_SOURCE')");
            $add('truck', 'info', 'Yêu cầu chuyển hàng đang chờ xử lý', 'Các kho đang xin hàng lẫn nhau (chỉ xem)', $n, '/requests');
        }
        return $out;
    }
}
