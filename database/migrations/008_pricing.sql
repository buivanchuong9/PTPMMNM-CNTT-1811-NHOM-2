-- Giá hàng hoá. Tiền là số nguyên đồng (VND), không dùng số thập phân.
-- Cột giá nằm sẵn trên mặt hàng (mat_hang.gia_ban · thue_suat · gia_von) và trên dòng phiếu (chi_tiet_phieu_nhap_xuat.don_gia · thue_suat · gia_von) —
-- các cột này được thêm bởi Migrator::ADDED_COLUMNS. Phiếu đã xác nhận bất biến, nên dòng phiếu LƯU LẠI giá và thuế tại lúc lập:
-- đổi giá sau này không làm lệch phiếu cũ. Mọi lần đổi giá / thuế / giá vốn ghi vào lich_su_gia.
-- @@
CREATE TABLE IF NOT EXISTS lich_su_gia (
    id                        bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mat_hang_id               integer      NOT NULL,
    loai                      enum('GIA_BAN','THUE_SUAT','GIA_VON') NOT NULL,
    gia_tri_cu                integer      NOT NULL,
    gia_tri_moi               integer      NOT NULL,
    ly_do                     varchar(255) NOT NULL DEFAULT '',
    nguoi_dung_id             integer      NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT lsg_item_fk FOREIGN KEY (mat_hang_id) REFERENCES mat_hang(id),
    CONSTRAINT lsg_user_fk FOREIGN KEY (nguoi_dung_id) REFERENCES nguoi_dung(id),
    INDEX lsg_by_item (mat_hang_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
