-- Khách trả hàng: khách mang hàng đã mua về trả. Chọn phiếu xuất bán gốc, ghi số trả và số lỗi từng dòng.
--   DRAFT → CONFIRMED (quản lý kho xác nhận: lập phiếu nhập cộng lại tồn kho; phần lỗi tự đề xuất / chuyển sang kho huỷ) | CANCELLED.
--   so_luong = số khách trả; so_luong_loi = trong đó phần lỗi (không bán lại được).
-- @@
CREATE TABLE IF NOT EXISTS tra_hang_khach (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(30)  NOT NULL,
    phieu_xuat_id             bigint       NOT NULL,
    kho_id                    integer      NOT NULL,
    khach                     varchar(120) NOT NULL DEFAULT '',
    ly_do                     varchar(255) NOT NULL DEFAULT '',
    trang_thai                enum('DRAFT','CONFIRMED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
    phieu_nhap_id             bigint       NULL,
    nguoi_lap_id              integer      NOT NULL,
    nguoi_xac_nhan_id         integer      NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ngay_xac_nhan             timestamp    NULL,
    CONSTRAINT thk_xuat_fk   FOREIGN KEY (phieu_xuat_id)  REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT thk_kho_fk    FOREIGN KEY (kho_id)         REFERENCES kho(id),
    CONSTRAINT thk_nhap_fk   FOREIGN KEY (phieu_nhap_id)  REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT thk_lap_fk    FOREIGN KEY (nguoi_lap_id)   REFERENCES nguoi_dung(id),
    UNIQUE (ma),
    INDEX thk_by_xuat (phieu_xuat_id),
    INDEX thk_by_kho (kho_id, trang_thai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS chi_tiet_tra_hang_khach (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tra_hang_id               integer      NOT NULL,
    mat_hang_id               integer      NOT NULL,
    lo_hang_id                integer      NOT NULL,
    so_luong                  integer      NOT NULL,
    so_luong_loi              integer      NOT NULL DEFAULT 0,
    ly_do_loi                 varchar(20)  NOT NULL DEFAULT '',
    CONSTRAINT cthk_qty CHECK (so_luong >= 1 AND so_luong_loi >= 0 AND so_luong_loi <= so_luong),
    CONSTRAINT cthk_tra_fk   FOREIGN KEY (tra_hang_id)  REFERENCES tra_hang_khach(id),
    CONSTRAINT cthk_item_fk  FOREIGN KEY (mat_hang_id)  REFERENCES mat_hang(id),
    CONSTRAINT cthk_lot_fk   FOREIGN KEY (lo_hang_id)   REFERENCES lo_hang(id),
    INDEX cthk_by_tra (tra_hang_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
-- Đơn đặt hàng nhà cung cấp (quản lý kho lập, quản trị chỉ xem).
--   NHAP → DA_GUI (đã gửi nhà cung cấp) → NHAN_MOT_PHAN → DA_NHAN (tự cập nhật khi phiếu nhập gắn với đơn được ghi sổ) | DA_DONG (không nhận thêm) | HUY (huỷ trước khi nhận).
CREATE TABLE IF NOT EXISTS don_dat_hang (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(30)  NOT NULL,
    kho_id                    integer      NOT NULL,
    doi_tac                   varchar(120) NOT NULL,
    trang_thai                enum('NHAP','DA_GUI','NHAN_MOT_PHAN','DA_NHAN','DA_DONG','HUY') NOT NULL DEFAULT 'NHAP',
    ngay_du_kien              date         NULL,
    ghi_chu                   varchar(255) NOT NULL DEFAULT '',
    nguoi_lap_id              integer      NOT NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ngay_gui                  timestamp    NULL,
    ngay_dong                 timestamp    NULL,
    CONSTRAINT ddh_kho_fk    FOREIGN KEY (kho_id)       REFERENCES kho(id),
    CONSTRAINT ddh_lap_fk    FOREIGN KEY (nguoi_lap_id) REFERENCES nguoi_dung(id),
    UNIQUE (ma),
    INDEX ddh_by_kho (kho_id, trang_thai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS chi_tiet_don_dat_hang (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    don_id                    integer      NOT NULL,
    mat_hang_id               integer      NOT NULL,
    so_luong                  integer      NOT NULL,
    so_luong_da_nhan          integer      NOT NULL DEFAULT 0,
    CONSTRAINT ctddh_qty CHECK (so_luong >= 1 AND so_luong_da_nhan >= 0),
    CONSTRAINT ctddh_don_fk  FOREIGN KEY (don_id)      REFERENCES don_dat_hang(id),
    CONSTRAINT ctddh_item_fk FOREIGN KEY (mat_hang_id) REFERENCES mat_hang(id),
    INDEX ctddh_by_don (don_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
