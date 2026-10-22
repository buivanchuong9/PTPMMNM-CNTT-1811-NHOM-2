<?php
declare(strict_types=1);

namespace StockFlow\Domain;

/**
 * Danh sách chức năng của hệ thống. Mỗi chức năng được «gắn» cho vai trò qua bảng phan_quyen_vai_tro
 * (mặc định ở defaultFor()). Thu hồi quyền chỉ ẩn/chặn chức năng với vai trò đó, không xoá chức năng.
 */
enum Permission: string
{
    // ── Xem & tra cứu ──
    case XemTongQuan = 'xem_tong_quan';
    case XemTonKho = 'xem_ton_kho';
    case XemPhieu = 'xem_phieu_nhap_xuat';
    case XemLichSu = 'xem_lich_su';
    case XemDoiChieu = 'xem_doi_chieu';
    case XemBaoCao = 'xem_bao_cao';
    case XemDanhMuc = 'xem_danh_muc';
    // ── Tác nghiệp kho ──
    case LapPhieuNhap = 'lap_phieu_nhap';
    case LapPhieuXuat = 'lap_phieu_xuat';
    case LapPhieuDieuChuyen = 'lap_phieu_dieu_chuyen';
    case XacNhanPhieu = 'xac_nhan_phieu';
    case NhanHangDieuChuyen = 'nhan_hang_dieu_chuyen';
    case DieuChinhPhieu = 'dieu_chinh_phieu';
    case ThemLo = 'them_lo';
    // ── Nhân sự kho ──
    case QuanLyNhanVien = 'quan_ly_nhan_vien';
    // ── Quản trị hệ thống (chỉ ADMIN) ──
    case QuanLyKho = 'quan_ly_kho';
    case QuanLyMatHang = 'quan_ly_mat_hang';
    case QuanLyTaiKhoan = 'quan_ly_tai_khoan';
    case PhanQuyen = 'phan_quyen';

    public function label(): string
    {
        return match ($this) {
            self::XemTongQuan => 'Xem tổng quan',
            self::XemTonKho => 'Xem tồn kho',
            self::XemPhieu => 'Xem phiếu nhập xuất',
            self::XemLichSu => 'Xem lịch sử phát sinh',
            self::XemDoiChieu => 'Đối chiếu tồn',
            self::XemBaoCao => 'Báo cáo & xuất CSV',
            self::XemDanhMuc => 'Xem danh mục kho, mặt hàng',
            self::LapPhieuNhap => 'Lập phiếu nhập',
            self::LapPhieuXuat => 'Lập phiếu xuất',
            self::LapPhieuDieuChuyen => 'Lập phiếu điều chuyển',
            self::XacNhanPhieu => 'Xác nhận / huỷ phiếu nhập xuất',
            self::NhanHangDieuChuyen => 'Nhận hàng điều chuyển đến kho',
            self::DieuChinhPhieu => 'Điều chỉnh (đảo) phiếu nhập xuất',
            self::ThemLo => 'Thêm lô hàng',
            self::QuanLyNhanVien => 'Thêm nhân viên kho',
            self::QuanLyKho => 'Quản lý kho',
            self::QuanLyMatHang => 'Quản lý mặt hàng, đơn vị tính',
            self::QuanLyTaiKhoan => 'Quản lý tài khoản',
            self::PhanQuyen => 'Phân quyền chức năng',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::XemTongQuan => 'Trang tổng quan, biểu đồ, cảnh báo sắp hết hàng',
            self::XemTonKho => 'Tra cứu tồn theo mặt hàng / lô',
            self::XemPhieu => 'Danh sách và chi tiết phiếu nhập, xuất, điều chuyển',
            self::XemLichSu => 'Sổ phát sinh bất biến',
            self::XemDoiChieu => 'So khớp số dư với sổ phát sinh',
            self::XemBaoCao => 'Tải báo cáo tồn, phát sinh, đối chiếu',
            self::XemDanhMuc => 'Xem danh sách kho và mặt hàng',
            self::LapPhieuNhap => 'Tạo phiếu nhập nháp tại kho của mình',
            self::LapPhieuXuat => 'Tạo phiếu xuất nháp tại kho của mình',
            self::LapPhieuDieuChuyen => 'Tạo phiếu điều chuyển từ kho của mình',
            self::XacNhanPhieu => 'Ghi sổ phiếu nháp hoặc huỷ phiếu',
            self::NhanHangDieuChuyen => 'Xác nhận đã nhận hàng từ kho khác chuyển tới kho của mình (cộng vào tồn)',
            self::DieuChinhPhieu => 'Lập phiếu nhập xuất đảo để sửa sai phiếu nhập xuất đã ghi sổ',
            self::ThemLo => 'Thêm số lô, hạn dùng cho mặt hàng quản lý theo lô',
            self::QuanLyNhanVien => 'Tạo tài khoản nhân viên trong kho của mình (trang Kho & nhân sự)',
            self::QuanLyKho => 'Thêm kho mới, gắn quản lý và nhân viên cho kho',
            self::QuanLyMatHang => 'Thêm mặt hàng, đơn vị tính',
            self::QuanLyTaiKhoan => 'Tạo tài khoản, đổi kho / vai trò, khoá tài khoản',
            self::PhanQuyen => 'Bật/tắt chức năng cho từng vai trò',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::XemTongQuan, self::XemTonKho, self::XemPhieu, self::XemLichSu, self::XemDoiChieu, self::XemBaoCao, self::XemDanhMuc => 'Xem & tra cứu',
            self::LapPhieuNhap, self::LapPhieuXuat, self::LapPhieuDieuChuyen, self::XacNhanPhieu, self::NhanHangDieuChuyen, self::DieuChinhPhieu,
            self::ThemLo => 'Tác nghiệp kho',
            self::QuanLyNhanVien => 'Nhân sự kho',
            self::QuanLyKho, self::QuanLyMatHang, self::QuanLyTaiKhoan, self::PhanQuyen => 'Quản trị hệ thống',
        };
    }

    /**
     * Thao tác nhập / xuất / điều chuyển hằng ngày — việc của quản lý và nhân viên kho.
     * Tài khoản quản trị hệ thống (chủ hệ thống) chỉ xem thống kê và quản lý, không thao tác các chức năng này.
     */
    public function operational(): bool
    {
        return in_array($this, [
            self::LapPhieuNhap, self::LapPhieuXuat, self::LapPhieuDieuChuyen, self::XacNhanPhieu, self::NhanHangDieuChuyen,
        ], true);
    }

    /** Chức năng hệ thống: chỉ ADMIN dùng, không giao cho quản lý / nhân viên. */
    public function adminOnly(): bool
    {
        return in_array($this, [self::QuanLyKho, self::QuanLyMatHang, self::QuanLyTaiKhoan, self::PhanQuyen], true);
    }

    /** Quyền mặc định của vai trò (khi chưa có dòng trong phan_quyen_vai_tro). */
    public function defaultFor(AccountRole $role): bool
    {
        return match ($role) {
            AccountRole::Admin => !$this->operational(),
            AccountRole::Manager => !$this->adminOnly(),
            AccountRole::Staff => in_array($this, [
                self::XemTongQuan, self::XemTonKho, self::XemPhieu, self::XemLichSu, self::XemDanhMuc,
                self::LapPhieuNhap, self::LapPhieuXuat, self::LapPhieuDieuChuyen, self::NhanHangDieuChuyen,
            ], true),
        };
    }

    /** @return array<string,list<self>> chức năng gom theo nhóm (giữ thứ tự khai báo) */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::cases() as $p) {
            $out[$p->group()][] = $p;
        }
        return $out;
    }
}
