-- Giao hàng cho khách khi xuất bán: khách tự đến lấy (TU_LAY), giao bằng xe của kho (NOI_BO) hoặc thuê đơn vị vận tải ngoài (THUE_NGOAI).
-- Một phiếu xuất tối đa một bản ghi giao hàng, lập khi phiếu xuất được xác nhận. Tiền công tính như chuyến điều chuyển (km × đơn giá/km của kho, có thể nhập số khác);
-- xe của kho đi giao được tính vào lương tài xế theo km. xe_id / tai_xe_id của đơn vị thuê ngoài là hồ sơ xe, tài xế đánh dấu thue_ngoai.
-- @@
CREATE TABLE IF NOT EXISTS giao_hang (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phieu_nhap_xuat_id        bigint       NOT NULL,
    kho_id                    integer      NOT NULL,
    hinh_thuc                 varchar(10)  NOT NULL DEFAULT 'TU_LAY',
    xe_id                     integer      NULL,
    tai_xe_id                 integer      NULL,
    km                        integer      NOT NULL DEFAULT 0,
    don_gia_km                integer      NOT NULL DEFAULT 0,
    tien_goi_y                bigint       NOT NULL DEFAULT 0,
    tien_tra                  bigint       NOT NULL DEFAULT 0,
    don_vi_van_tai            varchar(120) NOT NULL DEFAULT '',
    dia_chi                   varchar(255) NOT NULL DEFAULT '',
    ghi_chu_gia               varchar(255) NOT NULL DEFAULT '',
    nguoi_giao_id             integer      NOT NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT gh_phieu_fk   FOREIGN KEY (phieu_nhap_xuat_id) REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT gh_kho_fk     FOREIGN KEY (kho_id)       REFERENCES kho(id),
    CONSTRAINT gh_xe_fk      FOREIGN KEY (xe_id)        REFERENCES xe(id),
    CONSTRAINT gh_tai_xe_fk  FOREIGN KEY (tai_xe_id)    REFERENCES tai_xe(id),
    CONSTRAINT gh_nguoi_fk   FOREIGN KEY (nguoi_giao_id) REFERENCES nguoi_dung(id),
    UNIQUE (phieu_nhap_xuat_id),
    INDEX gh_by_driver (tai_xe_id, ngay_tao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
