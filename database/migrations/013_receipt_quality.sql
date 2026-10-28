-- Kiểm tra chất lượng khi nhận hàng từ nhà cung cấp.
-- nhap_loi: mỗi dòng phiếu nhập một bản ghi: số lượng NHẬN, số lượng KHÔNG ĐẠT (trả lại nhà cung cấp) và lý do. Dòng phiếu nhập chỉ giữ số ĐẠT (nhận − không đạt)
-- nên sổ cái, giá trị, báo cáo giữ nguyên; dòng không đạt toàn bộ không có dòng phiếu nên cần bảng này để còn dấu vết.
-- @@
CREATE TABLE IF NOT EXISTS nhap_loi (
    id                        bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phieu_nhap_xuat_id        bigint       NOT NULL,
    mat_hang_id               integer      NOT NULL,
    lo_hang_id                integer      NOT NULL,
    so_luong_nhan             integer      NOT NULL,
    so_luong_loi              integer      NOT NULL DEFAULT 0,
    ly_do                     varchar(20)  NOT NULL DEFAULT '',
    ghi_chu                   varchar(255) NOT NULL DEFAULT '',
    CONSTRAINT nl_qty_valid  CHECK (so_luong_nhan >= 1 AND so_luong_loi >= 0 AND so_luong_loi <= so_luong_nhan),
    CONSTRAINT nl_phieu_fk   FOREIGN KEY (phieu_nhap_xuat_id) REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT nl_item_fk    FOREIGN KEY (mat_hang_id)  REFERENCES mat_hang(id),
    CONSTRAINT nl_lo_fk      FOREIGN KEY (lo_hang_id)   REFERENCES lo_hang(id),
    UNIQUE (phieu_nhap_xuat_id, mat_hang_id, lo_hang_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- Phiếu trả hàng nhà cung cấp: tạo tự động khi phiếu nhập có hàng không đạt được ghi sổ. CHO_CAP_LAI = chờ nhà cung cấp cấp lại hàng;
-- khi nhập phiếu «cấp lại» (phieu_nhap_xuat.cap_lai_cho_id) đủ số lượng thì DA_CAP_LAI. HUY = nhà cung cấp không cấp lại nữa.
-- @@
CREATE TABLE IF NOT EXISTS tra_hang_ncc (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(30)  NOT NULL,
    phieu_nhap_xuat_id        bigint       NOT NULL,
    kho_id                    integer      NOT NULL,
    doi_tac                   varchar(120) NOT NULL,
    trang_thai                enum('CHO_CAP_LAI','DA_CAP_LAI','HUY') NOT NULL DEFAULT 'CHO_CAP_LAI',
    ghi_chu                   varchar(255) NOT NULL DEFAULT '',
    nguoi_lap_id              integer      NOT NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ngay_dong                 timestamp    NULL,
    CONSTRAINT th_phieu_fk   FOREIGN KEY (phieu_nhap_xuat_id) REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT th_kho_fk     FOREIGN KEY (kho_id)       REFERENCES kho(id),
    CONSTRAINT th_lap_fk     FOREIGN KEY (nguoi_lap_id) REFERENCES nguoi_dung(id),
    UNIQUE (ma),
    UNIQUE (phieu_nhap_xuat_id),
    INDEX th_by_kho (kho_id, trang_thai, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS chi_tiet_tra_hang (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tra_hang_id               integer      NOT NULL,
    mat_hang_id               integer      NOT NULL,
    lo_hang_id                integer      NOT NULL,
    so_luong                  integer      NOT NULL,
    so_luong_da_cap           integer      NOT NULL DEFAULT 0,
    ly_do                     varchar(20)  NOT NULL DEFAULT '',
    ghi_chu                   varchar(255) NOT NULL DEFAULT '',
    CONSTRAINT cth_qty_valid CHECK (so_luong >= 1 AND so_luong_da_cap >= 0 AND so_luong_da_cap <= so_luong),
    CONSTRAINT cth_tra_fk    FOREIGN KEY (tra_hang_id)  REFERENCES tra_hang_ncc(id),
    CONSTRAINT cth_item_fk   FOREIGN KEY (mat_hang_id)  REFERENCES mat_hang(id),
    CONSTRAINT cth_lo_fk     FOREIGN KEY (lo_hang_id)   REFERENCES lo_hang(id),
    UNIQUE (tra_hang_id, mat_hang_id, lo_hang_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
