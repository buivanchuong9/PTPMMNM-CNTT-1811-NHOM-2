<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Core\Db;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\User;

/**
 * Giao hàng cho khách khi xuất bán. Đơn nhỏ: khách tự đến lấy. Đơn lớn: giao tận nơi bằng xe của kho (trả công tài xế theo km)
 * hoặc thuê đơn vị vận tải ngoài (bắt buộc đủ thông tin đơn vị, tài xế, xe). Ghi nhận khi phiếu xuất được xác nhận.
 */
final class DeliveryService
{
    public const PAYMENT = ['DA_TT' => 'Khách đã thanh toán', 'COD' => 'Thu tiền khi nhận hàng'];
    public const METHODS = ['TIEN_MAT' => 'Tiền mặt', 'CHUYEN_KHOAN' => 'Chuyển khoản'];
    public const MODES = ['TU_LAY' => 'Khách tự đến lấy', 'NOI_BO' => 'Giao bằng xe của kho', 'THUE_NGOAI' => 'Thuê đơn vị vận tải ngoài'];

    public function __construct(private Db $db, private FleetService $fleet, private AuditLog $audit, private TransportService $transport, private Authorizer $auth) {}

    /**
     * Gọi trong giao dịch ghi sổ phiếu xuất. $d: hinh_thuc, dia_chi, vehicle_id, driver_ok, km, tien, ghi_chu, ngoai[company, driver, cccd, phone, plate, type].
     * @param array<string,mixed> $voucher phiếu xuất (có id, kho_id, ma)
     */
    public function record(User $user, array $voucher, array $d): void
    {
        $mode = isset(self::MODES[$d['hinh_thuc'] ?? '']) ? $d['hinh_thuc'] : 'TU_LAY';
        $wh = (int)$voucher['kho_id'];
        $paid = $d['thanh_toan'] ?? '';
        if (!isset(self::PAYMENT[$paid])) {
            throw new DomainError('Chọn tình trạng thanh toán: khách đã thanh toán hay thu tiền khi nhận hàng');
        }
        $method = (string)($d['phuong_thuc_tt'] ?? '');
        if ($paid === 'DA_TT' && !isset(self::METHODS[$method])) {
            throw new DomainError('Khách đã thanh toán: chọn tiền mặt hay chuyển khoản');
        }
        $method = $paid === 'DA_TT' ? $method : '';
        $amount = $this->saleTotal((int)$voucher['id']);
        if ($mode === 'TU_LAY') {
            $this->db->exec('INSERT INTO giao_hang (phieu_nhap_xuat_id, kho_id, hinh_thuc, thanh_toan, phuong_thuc_tt, so_tien, nguoi_giao_id) VALUES (?,?,?,?,?,?,?)',
                [$voucher['id'], $wh, 'TU_LAY', $paid, $method, $amount, $user->id]);
            $this->audit->record($user->id, 'voucher.delivery', 'voucher', (int)$voucher['id'], 'khách tự lấy · ' . self::PAYMENT[$paid] . ' · ' . number_format($amount, 0, ',', '.') . ' đ');
            return;
        }
        $address = trim(preg_replace('/\s+/u', ' ', (string)($d['dia_chi'] ?? '')) ?? '');
        if ($address === '' || mb_strlen($address) > 255) {
            throw new DomainError('Giao hàng tận nơi: nhập địa chỉ giao hàng (tối đa 255 ký tự)');
        }
        $receiver = trim(preg_replace('/\s+/u', ' ', (string)($d['nguoi_nhan'] ?? '')) ?? '');
        $phone = preg_replace('/[\s.]/', '', (string)($d['sdt_nhan'] ?? '')) ?? '';
        if ($receiver === '' || mb_strlen($receiver) > 120) {
            throw new DomainError('Giao hàng tận nơi: nhập tên người nhận hàng');
        }
        if (!preg_match('/^0[0-9]{9}$/', $phone)) {
            throw new DomainError('Giao hàng tận nơi: nhập số điện thoại người nhận (10 chữ số, bắt đầu bằng 0)');
        }
        $p = $this->fleet->tripPay($wh, null, ['hinh_thuc' => $mode] + $d);
        if (empty($d['driver_ok'])) {
            throw new DomainError($mode === 'THUE_NGOAI' ? 'Tài xế của đơn vị vận tải phải xác nhận đã nhận đủ hàng' : 'Tài xế phải xác nhận đã nhận đủ hàng trước khi giao');
        }
        if ($mode === 'THUE_NGOAI') {
            $vehicleId = $this->fleet->registerExternal($wh, $d['ngoai'] ?? []);   // đủ thông tin bên thứ ba mới được bàn giao hàng
        } else {
            $vehicleId = (int)($d['vehicle_id'] ?? 0);
            if (!$vehicleId) {
                throw new DomainError('Chọn xe giao hàng');
            }
        }
        $xe = $this->db->one('SELECT * FROM xe WHERE id = ? FOR UPDATE', [$vehicleId]);
        if (!$xe || !$xe['hoat_dong']) {
            throw new DomainError('Xe không tồn tại hoặc đã ngừng hoạt động');
        }
        if ($mode === 'NOI_BO' && ((int)$xe['thue_ngoai'] || (int)($xe['kho_hien_tai_id'] ?? 0) !== $wh)) {
            throw new DomainError('Xe ' . $xe['bien_so'] . ' hiện không ở kho này — chọn xe đang đứng tại kho');
        }
        $driverId = (int)($d['driver_id'] ?? 0);
        if ($mode === 'NOI_BO' && $driverId) {
            $tx = $this->fleet->assignableDriver($driverId, $wh);   // tài xế chọn theo chuyến, không gắn cố định với xe
        } else {
            $tx = $xe['tai_xe_id'] ? $this->db->one('SELECT * FROM tai_xe WHERE id = ?', [(int)$xe['tai_xe_id']]) : null;
            if (!$tx || !$tx['hoat_dong']) {
                throw new DomainError('Chọn tài xế lái xe ' . $xe['bien_so']);
            }
        }
        $this->transport->assertCanCarry($vehicleId, $this->db->all('SELECT mat_hang_id, so_luong FROM chi_tiet_phieu_nhap_xuat WHERE phieu_nhap_xuat_id = ?', [(int)$voucher['id']]), (int)$tx['id']);
        $this->db->exec(
            'INSERT INTO giao_hang (phieu_nhap_xuat_id, kho_id, hinh_thuc, xe_id, tai_xe_id, km, don_gia_km, tien_goi_y, tien_tra, don_vi_van_tai, dia_chi, nguoi_nhan, sdt_nhan, ghi_chu_gia, nguoi_giao_id, thanh_toan, phuong_thuc_tt, so_tien)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$voucher['id'], $wh, $mode, $vehicleId, (int)$tx['id'], $p['km'], $p['don_gia_km'], $p['tien_goi_y'], $p['tien_tra'], $p['don_vi_van_tai'], $address, $receiver, $phone, $p['ghi_chu_gia'], $user->id, $paid, $method, $amount]
        );
        $this->audit->record($user->id, 'voucher.delivery', 'voucher', (int)$voucher['id'],
            ($mode === 'THUE_NGOAI' ? 'thuê ngoài ' . $p['don_vi_van_tai'] : 'xe kho') . " · {$xe['bien_so']} · {$tx['ho_ten']} · {$p['km']} km · " . number_format($p['tien_tra'], 0, ',', '.') . ' đ');
    }

    /** Tổng tiền khách phải trả của phiếu xuất (tiền hàng + thuế GTGT, làm tròn từng dòng như trên phiếu in). */
    private function saleTotal(int $voucherId): int
    {
        $sum = 0;
        foreach ($this->db->all('SELECT so_luong, don_gia, thue_suat FROM chi_tiet_phieu_nhap_xuat WHERE phieu_nhap_xuat_id = ?', [$voucherId]) as $l) {
            $net = (int)$l['so_luong'] * (int)($l['don_gia'] ?? 0);
            $sum += $net + (int)round($net * (int)$l['thue_suat'] / 100);
        }
        return $sum;
    }

    /**
     * Ghi nhận tài xế đã giao hàng tới nơi (nút demo trên phiếu xuất): người có chức năng xác nhận phiếu ở kho phát hành. Chỉ đơn giao tận nơi; gọi lại không ghi đè thời điểm đầu.
     * @return bool true nếu vừa ghi nhận, false nếu đã ghi nhận từ trước
     */
    public function markArrived(User $user, int $voucherId): bool
    {
        $v = $this->db->one('SELECT id, kho_id, ma, loai, trang_thai FROM phieu_nhap_xuat WHERE id = ?', [$voucherId]) ?? throw new DomainError('Không tìm thấy phiếu');
        $this->auth->require($user, (int)$v['kho_id'], \StockFlow\Domain\Permission::XacNhanPhieu);
        $g = $this->db->one('SELECT id, hinh_thuc, ngay_giao_den FROM giao_hang WHERE phieu_nhap_xuat_id = ?', [$voucherId]);
        if (!$g || $g['hinh_thuc'] === 'TU_LAY') {
            throw new DomainError('Phiếu này không có giao hàng tận nơi');
        }
        if ($g['ngay_giao_den'] !== null) {
            return false;
        }
        $this->db->exec('UPDATE giao_hang SET ngay_giao_den = CURRENT_TIMESTAMP WHERE id = ? AND ngay_giao_den IS NULL', [(int)$g['id']]);
        $this->audit->record($user->id, 'voucher.delivered', 'voucher', $voucherId, $v['ma'] . ' · tài xế đã giao đến nơi');
        return true;
    }

    /** Thông tin giao hàng của một phiếu xuất (kèm xe, tài xế); null nếu chưa ghi nhận. */
    public function of(int $voucherId): ?array
    {
        return $this->db->one(
            'SELECT g.*, x.bien_so, x.loai_xe, x.kieu_xe, x.dung_tich_cc, t.ho_ten AS tai_xe_ten, t.cccd, t.so_dien_thoai
             FROM giao_hang g LEFT JOIN xe x ON x.id = g.xe_id LEFT JOIN tai_xe t ON t.id = g.tai_xe_id WHERE g.phieu_nhap_xuat_id = ?',
            [$voucherId]
        );
    }
}
