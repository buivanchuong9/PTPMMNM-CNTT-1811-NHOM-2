-- Tiền công vận chuyển theo km: mỗi chuyến ghi quãng đường (km), đơn giá/km, số tiền gợi ý (km × đơn giá của kho đi) và số tiền THỰC TRẢ (có thể khác gợi ý).
-- Các cột trên chuyen_van_chuyen (hinh_thuc · km · don_gia_km · tien_goi_y · tien_tra · don_vi_van_tai · ghi_chu_gia), kho.gia_km_noi_bo / gia_km_thue_ngoai
-- và xe.thue_ngoai / tai_xe.thue_ngoai được thêm bởi Migrator::ADDED_COLUMNS.
-- hinh_thuc: NOI_BO (xe + tài xế của kho, trả công theo km) · THUE_NGOAI (đơn vị vận tải bên thứ ba: xe và tài xế thuê ngoài được lưu như xe/tài xế đánh dấu thue_ngoai, không hiện trong đội xe của kho).
-- khoang_cach_kho: quãng đường giữa hai kho (km), lưu một chiều với kho_a_id < kho_b_id — dùng chung cho chiều đi và về.
-- @@
CREATE TABLE IF NOT EXISTS khoang_cach_kho (
    kho_a_id                  integer      NOT NULL,
    kho_b_id                  integer      NOT NULL,
    km                        integer      NOT NULL,
    ngay_cap_nhat             timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (kho_a_id, kho_b_id),
    CONSTRAINT kck_order CHECK (kho_a_id < kho_b_id),
    CONSTRAINT kck_km_positive CHECK (km >= 1),
    CONSTRAINT kck_a_fk FOREIGN KEY (kho_a_id) REFERENCES kho(id),
    CONSTRAINT kck_b_fk FOREIGN KEY (kho_b_id) REFERENCES kho(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
