-- Yêu cầu chuyển hàng giữa các kho: kho cần hàng (kho nhận) xin kho còn hàng (kho cấp) chuyển sang.
-- WAIT_OWN: nhân viên đã lập, chờ quản lý kho mình xác nhận · WAIT_SOURCE: chờ quản lý kho cấp chấp nhận / từ chối
-- ACCEPTED: đã chấp nhận ⇒ hệ thống mở phiếu điều chuyển đi (nháp) ở kho cấp, đi tiếp quy trình chọn xe, kiểm đếm, xuất kho
-- REJECTED: kho cấp từ chối (kèm lý do) · CANCELLED: kho nhận tự huỷ / quản lý kho nhận không xác nhận.
-- da_bao: quản lý kho nhận đã thấy thông báo bị từ chối chưa.
-- @@
CREATE TABLE IF NOT EXISTS yeu_cau_chuyen_hang (
    id                        bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(30)  NOT NULL,
    kho_nhan_id               integer      NOT NULL,
    kho_cap_id                integer      NOT NULL,
    trang_thai                enum('WAIT_OWN','WAIT_SOURCE','ACCEPTED','REJECTED','CANCELLED') NOT NULL DEFAULT 'WAIT_OWN',
    ghi_chu                   varchar(255) NOT NULL DEFAULT '',
    nguoi_lap_id              integer      NOT NULL,
    nguoi_duyet_id            integer      NULL,
    ngay_duyet                timestamp    NULL,
    nguoi_xu_ly_id            integer      NULL,
    ngay_xu_ly                timestamp    NULL,
    ly_do                     varchar(255) NOT NULL DEFAULT '',
    da_bao                    boolean      NOT NULL DEFAULT FALSE,
    phieu_id                  bigint       NULL,
    khoa_chong_trung          char(32)     NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT yccH_khac_kho CHECK (kho_nhan_id <> kho_cap_id),
    CONSTRAINT yccH_kho_nhan_fk FOREIGN KEY (kho_nhan_id) REFERENCES kho(id),
    CONSTRAINT yccH_kho_cap_fk  FOREIGN KEY (kho_cap_id)  REFERENCES kho(id),
    CONSTRAINT yccH_lap_fk      FOREIGN KEY (nguoi_lap_id) REFERENCES nguoi_dung(id),
    CONSTRAINT yccH_duyet_fk    FOREIGN KEY (nguoi_duyet_id) REFERENCES nguoi_dung(id),
    CONSTRAINT yccH_xu_ly_fk    FOREIGN KEY (nguoi_xu_ly_id) REFERENCES nguoi_dung(id),
    CONSTRAINT yccH_phieu_fk    FOREIGN KEY (phieu_id) REFERENCES phieu_nhap_xuat(id),
    UNIQUE (ma),
    UNIQUE (khoa_chong_trung),
    INDEX yccH_nhan (kho_nhan_id, trang_thai),
    INDEX yccH_cap (kho_cap_id, trang_thai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS yeu_cau_chuyen_hang_dong (
    id                        bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    yeu_cau_id                bigint       NOT NULL,
    mat_hang_id               integer      NOT NULL,
    lo_hang_id                integer      NOT NULL,
    so_luong                  integer      NOT NULL,
    CONSTRAINT yccHd_sl CHECK (so_luong > 0),
    CONSTRAINT yccHd_yc_fk  FOREIGN KEY (yeu_cau_id)  REFERENCES yeu_cau_chuyen_hang(id),
    CONSTRAINT yccHd_mh_fk  FOREIGN KEY (mat_hang_id) REFERENCES mat_hang(id),
    CONSTRAINT yccHd_lo_fk  FOREIGN KEY (lo_hang_id)  REFERENCES lo_hang(id),
    UNIQUE (yeu_cau_id, mat_hang_id, lo_hang_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
