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
    case XemKiemKe = 'xem_kiem_ke';
    case XemDoiXe = 'xem_doi_xe';
    case XemBaoHanh = 'xem_bao_hanh';
    case XemKhoHuy = 'xem_kho_huy';
    // ── Tác nghiệp kho ──
    case LapPhieuNhap = 'lap_phieu_nhap';
    case LapPhieuXuat = 'lap_phieu_xuat';
    case LapPhieuDieuChuyen = 'lap_phieu_dieu_chuyen';
    case XacNhanPhieu = 'xac_nhan_phieu';
    case NhanHangDieuChuyen = 'nhan_hang_dieu_chuyen';
    case DieuChinhPhieu = 'dieu_chinh_phieu';
    case KiemKeThucHien = 'kiem_ke_thuc_hien';
    case KiemKeDuyet = 'kiem_ke_duyet';
    case ThemLo = 'them_lo';
    case DieuChinhGia = 'dieu_chinh_gia';
    case DatGiaGoc = 'dat_gia_goc';
    case QuanLyDoiXe = 'quan_ly_doi_xe';
    case GanMaVach = 'gan_ma_vach';
    case DuyetDeXuatHang = 'duyet_de_xuat_hang';
    case XuLyBaoHanh = 'xu_ly_bao_hanh';
    case LapPhieuHuy = 'lap_phieu_huy';
    case XacNhanHuy = 'xac_nhan_huy';
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
            self::XemKiemKe => 'Xem kiểm kê',
            self::XemDoiXe => 'Xem đội xe & tài xế',
            self::XemBaoHanh => 'Xem kho bảo hành',
            self::XemKhoHuy => 'Xem kho huỷ hàng',
            self::LapPhieuNhap => 'Lập phiếu nhập',
            self::LapPhieuXuat => 'Lập phiếu xuất',
            self::LapPhieuDieuChuyen => 'Lập phiếu điều chuyển',
            self::XacNhanPhieu => 'Xác nhận / huỷ phiếu nhập xuất',
            self::NhanHangDieuChuyen => 'Nhận hàng điều chuyển đến kho',
            self::DieuChinhPhieu => 'Sửa sai phiếu đã ghi sổ (lập phiếu điều chỉnh)',
            self::KiemKeThucHien => 'Kiểm kê: mở đợt, nhập số đếm',
            self::KiemKeDuyet => 'Kiểm kê: duyệt chênh lệch',
            self::ThemLo => 'Thêm lô hàng',
            self::DieuChinhGia => 'Điều chỉnh giá bán ra của kho mình',
            self::DatGiaGoc => 'Đặt giá gốc và thuế suất',
            self::QuanLyNhanVien => 'Thêm nhân viên kho',
            self::QuanLyKho => 'Quản lý kho',
            self::QuanLyMatHang => 'Quản lý mặt hàng, đơn vị tính',
            self::QuanLyTaiKhoan => 'Quản lý tài khoản',
            self::PhanQuyen => 'Phân quyền chức năng',
            self::QuanLyDoiXe => 'Quản lý đội xe & tài xế của kho mình',
            self::GanMaVach => 'Gán mã vạch, đề xuất mặt hàng mới',
            self::DuyetDeXuatHang => 'Duyệt đề xuất mặt hàng mới',
            self::XuLyBaoHanh => 'Nhận và xử lý bảo hành',
            self::LapPhieuHuy => 'Đề xuất huỷ hàng (hết hạn, vỡ, hỏng)',
            self::XacNhanHuy => 'Duyệt huỷ hàng và xác nhận đã tiêu huỷ',
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
            self::XemKiemKe => 'Xem các đợt kiểm kê và kết quả (chỉ đọc)',
            self::XemBaoHanh => 'Danh sách hàng khách gửi bảo hành và kết quả xử lý (chỉ đọc)',
            self::XemKhoHuy => 'Danh sách hàng chờ huỷ, đang ở kho huỷ, đã tiêu huỷ và giá trị thiệt hại (chỉ đọc)',
            self::XemDoiXe => 'Xem xe, tài xế, điểm & lương, chuyến chưa thấy hàng và thất thoát (chỉ đọc; quản lý kho chỉ thấy đội xe của kho mình)',
            self::LapPhieuNhap => 'Tạo phiếu nhập nháp tại kho của mình',
            self::LapPhieuXuat => 'Tạo phiếu xuất nháp tại kho của mình',
            self::LapPhieuDieuChuyen => 'Tạo phiếu điều chuyển từ kho của mình',
            self::XacNhanPhieu => 'Ghi sổ phiếu nháp hoặc huỷ phiếu',
            self::NhanHangDieuChuyen => 'Xác nhận đã nhận hàng từ kho khác chuyển tới kho của mình (cộng vào tồn)',
            self::DieuChinhPhieu => 'Lập phiếu điều chỉnh để hoàn lại số lượng khi một phiếu đã ghi sổ bị sai (lịch sử cũ được giữ nguyên)',
            self::KiemKeThucHien => 'Mở đợt kiểm kê, nhập số đếm thực tế, nộp duyệt',
            self::KiemKeDuyet => 'Duyệt chênh lệch và lập phiếu điều chỉnh',
            self::ThemLo => 'Thêm số lô, hạn dùng cho mặt hàng quản lý theo lô',
            self::DieuChinhGia => 'Đặt giá bán ra của kho mình (mỗi kho một giá; mỗi lần đổi ghi lý do và lưu lịch sử giá)',
            self::DatGiaGoc => 'Đặt giá gốc (giá vốn) và thuế suất của mặt hàng — dùng chung mọi kho, điều chuyển giữa kho lấy giá gốc này',
            self::QuanLyNhanVien => 'Tạo tài khoản nhân viên trong kho của mình (trang Kho & nhân sự)',
            self::QuanLyKho => 'Thêm kho mới, gắn quản lý và nhân viên cho kho',
            self::QuanLyMatHang => 'Thêm mặt hàng, đơn vị tính',
            self::QuanLyTaiKhoan => 'Tạo tài khoản, đổi kho / vai trò, khoá tài khoản',
            self::PhanQuyen => 'Bật/tắt chức năng cho từng vai trò',
            self::GanMaVach => 'Gán mã vạch cho mặt hàng, và gửi đề xuất mặt hàng mới khi quét ra mã chưa có trong hệ thống (mọi tài khoản có quyền xem đều tra cứu được bằng cách quét)',
            self::DuyetDeXuatHang => 'Chấp nhận (kèm giá gốc, thuế) hoặc từ chối đề xuất mặt hàng mới của quản lý kho',
            self::QuanLyDoiXe => 'Thêm, sửa xe và tài xế của kho mình; phản hồi chuyến chưa thấy hàng; xác nhận mất hàng và trừ điểm, lương tài xế',
            self::XuLyBaoHanh => 'Tiếp nhận hàng khách mang đến bảo hành, ghi kết quả (sửa xong, đổi mới, từ chối) và trả khách. Đổi mới sẽ trừ tồn kho',
            self::LapPhieuHuy => 'Chọn hàng/lô trong kho, ghi lý do (hết hạn, vỡ, hỏng, không bán được) để chờ quản lý duyệt. Chưa trừ tồn',
            self::XacNhanHuy => 'Duyệt đề xuất huỷ: hàng rời tồn và nằm ở kho huỷ; sau đó xác nhận đã tiêu huỷ thật',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::XemTongQuan, self::XemTonKho, self::XemPhieu, self::XemLichSu, self::XemDoiChieu, self::XemBaoCao, self::XemDanhMuc, self::XemKiemKe, self::XemDoiXe, self::XemBaoHanh, self::XemKhoHuy => 'Xem & tra cứu',
            self::LapPhieuNhap, self::LapPhieuXuat, self::LapPhieuDieuChuyen, self::XacNhanPhieu, self::NhanHangDieuChuyen, self::DieuChinhPhieu,
            self::KiemKeThucHien, self::KiemKeDuyet, self::ThemLo, self::DieuChinhGia, self::QuanLyDoiXe, self::GanMaVach, self::XuLyBaoHanh, self::LapPhieuHuy, self::XacNhanHuy => 'Tác nghiệp kho',
            self::QuanLyNhanVien => 'Nhân sự kho',
            self::QuanLyKho, self::QuanLyMatHang, self::QuanLyTaiKhoan, self::PhanQuyen, self::DatGiaGoc, self::DuyetDeXuatHang => 'Quản trị hệ thống',
        };
    }

    /**
     * Thao tác nhập / xuất / điều chuyển / kiểm kê hằng ngày — việc của quản lý và nhân viên kho.
     * Tài khoản quản trị hệ thống (chủ hệ thống) chỉ xem thống kê và quản lý, không thao tác các chức năng này.
     */
    public function operational(): bool
    {
        return in_array($this, [
            self::LapPhieuNhap, self::LapPhieuXuat, self::LapPhieuDieuChuyen, self::XacNhanPhieu, self::NhanHangDieuChuyen, self::KiemKeThucHien, self::KiemKeDuyet, self::DieuChinhGia, self::QuanLyDoiXe, self::GanMaVach,
            self::XuLyBaoHanh, self::LapPhieuHuy, self::XacNhanHuy,
        ], true);
    }

    /**
     * Quyền xem bắt buộc phải có để dùng được chức năng này: lập / xác nhận phiếu thì phải xem được phiếu, kiểm kê thì phải xem được kiểm kê…
     * Thiếu quyền xem thì chức năng đi kèm vô nghĩa (thao tác xong không xem được kết quả), nên bị coi là không có.
     */
    public function requires(): ?self
    {
        return match ($this) {
            self::LapPhieuNhap, self::LapPhieuXuat, self::LapPhieuDieuChuyen, self::XacNhanPhieu, self::NhanHangDieuChuyen, self::DieuChinhPhieu => self::XemPhieu,
            self::KiemKeThucHien, self::KiemKeDuyet => self::XemKiemKe,
            self::ThemLo => self::XemTonKho,
            self::QuanLyNhanVien, self::GanMaVach => self::XemDanhMuc,
            self::QuanLyDoiXe => self::XemDoiXe,
            self::XuLyBaoHanh => self::XemBaoHanh,
            self::LapPhieuHuy, self::XacNhanHuy => self::XemKhoHuy,
            default => null,
        };
    }

    /** Chức năng hệ thống: chỉ ADMIN dùng, không giao cho quản lý / nhân viên. */
    public function adminOnly(): bool
    {
        return in_array($this, [self::QuanLyKho, self::QuanLyMatHang, self::QuanLyTaiKhoan, self::PhanQuyen, self::DatGiaGoc, self::DuyetDeXuatHang], true);
    }

    /** Quyền mặc định của vai trò (khi chưa có dòng trong phan_quyen_vai_tro). */
    public function defaultFor(AccountRole $role): bool
    {
        return match ($role) {
            AccountRole::Admin => !$this->operational(),
            AccountRole::Manager => !$this->adminOnly(),
            AccountRole::Staff => in_array($this, [
                self::XemTongQuan, self::XemTonKho, self::XemPhieu, self::XemLichSu, self::XemDanhMuc, self::XemKiemKe,
                self::LapPhieuNhap, self::LapPhieuXuat, self::LapPhieuDieuChuyen, self::NhanHangDieuChuyen, self::KiemKeThucHien,
                self::XemBaoHanh, self::XemKhoHuy, self::XuLyBaoHanh, self::LapPhieuHuy,
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
