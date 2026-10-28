-- Kho bảo hành: khách mang hàng đã mua (mặt hàng có mat_hang.bao_hanh_thang > 0) quay lại kho để sửa / đổi.
-- trang_thai: TIEP_NHAN (đang giữ ở kho bảo hành) → DA_SUA | DOI_MOI | TU_CHOI (đã có kết quả) → DA_TRA (đã trả khách).
-- con_han: tại lúc tiếp nhận còn trong thời hạn bảo hành hay không (ngay_mua + bao_hanh_thang tháng).
-- phieu_nhap_xuat_id: phiếu điều chỉnh trừ tồn khi đổi mới (đơn vị đổi cho khách lấy từ tồn của kho).
-- @@
CREATE TABLE IF NOT EXISTS bao_hanh (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(30)  NOT NULL,
    kho_id                    integer      NOT NULL,
    mat_hang_id               integer      NOT NULL,
    so_luong                  integer      NOT NULL DEFAULT 1,
    khach_ten                 varchar(120) NOT NULL,
    khach_sdt                 varchar(20)  NOT NULL DEFAULT '',
    ngay_mua                  date         NOT NULL,
    het_han                   date         NOT NULL,
    con_han                   boolean      NOT NULL DEFAULT TRUE,
    loi                       varchar(255) NOT NULL,
    trang_thai                enum('TIEP_NHAN','DA_SUA','DOI_MOI','TU_CHOI','DA_TRA') NOT NULL DEFAULT 'TIEP_NHAN',
    ket_qua                   varchar(255) NOT NULL DEFAULT '',
    phieu_nhap_xuat_id        bigint       NULL,
    nguoi_lap_id              integer      NOT NULL,
    nguoi_xu_ly_id            integer      NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ngay_xu_ly                timestamp    NULL,
    ngay_tra                  timestamp    NULL,
    CONSTRAINT bh_qty_positive CHECK (so_luong >= 1),
    CONSTRAINT bh_kho_fk     FOREIGN KEY (kho_id)       REFERENCES kho(id),
    CONSTRAINT bh_item_fk    FOREIGN KEY (mat_hang_id)  REFERENCES mat_hang(id),
    CONSTRAINT bh_phieu_fk   FOREIGN KEY (phieu_nhap_xuat_id) REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT bh_lap_fk     FOREIGN KEY (nguoi_lap_id) REFERENCES nguoi_dung(id),
    CONSTRAINT bh_xuly_fk    FOREIGN KEY (nguoi_xu_ly_id) REFERENCES nguoi_dung(id),
    UNIQUE (ma),
    INDEX bh_by_kho (kho_id, trang_thai, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- Kho huỷ: hàng hết hạn, vỡ, hỏng, không bán được. CHO_DUYET (đề xuất, chưa động tới tồn) → TRONG_KHO_HUY (quản lý duyệt: trừ tồn bằng phiếu điều chỉnh,
-- hàng nằm chờ tiêu huỷ) → DA_TIEU_HUY. TU_CHOI = không huỷ nữa. gia_von = đơn giá vốn lúc lập, để tính giá trị thiệt hại.
-- @@
CREATE TABLE IF NOT EXISTS huy_hang (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(30)  NOT NULL,
    kho_id                    integer      NOT NULL,
    mat_hang_id               integer      NOT NULL,
    lo_hang_id                integer      NOT NULL,
    so_luong                  integer      NOT NULL,
    ly_do                     varchar(20)  NOT NULL DEFAULT 'KHAC',
    ghi_chu                   varchar(255) NOT NULL DEFAULT '',
    gia_von                   bigint       NOT NULL DEFAULT 0,
    trang_thai                enum('CHO_DUYET','TRONG_KHO_HUY','DA_TIEU_HUY','TU_CHOI') NOT NULL DEFAULT 'CHO_DUYET',
    phieu_nhap_xuat_id        bigint       NULL,
    nguoi_lap_id              integer      NOT NULL,
    nguoi_duyet_id            integer      NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ngay_duyet                timestamp    NULL,
    ngay_tieu_huy             timestamp    NULL,
    CONSTRAINT hh_qty_positive CHECK (so_luong >= 1),
    CONSTRAINT hh_kho_fk     FOREIGN KEY (kho_id)       REFERENCES kho(id),
    CONSTRAINT hh_item_fk    FOREIGN KEY (mat_hang_id)  REFERENCES mat_hang(id),
    CONSTRAINT hh_lo_fk      FOREIGN KEY (lo_hang_id)   REFERENCES lo_hang(id),
    CONSTRAINT hh_phieu_fk   FOREIGN KEY (phieu_nhap_xuat_id) REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT hh_lap_fk     FOREIGN KEY (nguoi_lap_id) REFERENCES nguoi_dung(id),
    CONSTRAINT hh_duyet_fk   FOREIGN KEY (nguoi_duyet_id) REFERENCES nguoi_dung(id),
    UNIQUE (ma),
    INDEX hh_by_kho (kho_id, trang_thai, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
