-- Giá bán RIÊNG TỪNG KHO. Giá gốc (giá vốn) và thuế suất là của quản trị hệ thống, dùng chung mọi kho (điều chuyển giữa kho lấy luôn giá gốc này);
-- quản lý từng kho tự đặt giá bán ra của kho mình. Kho chưa đặt giá thì dùng giá bán mặc định ở mat_hang.gia_ban.
-- (lich_su_gia.kho_id — kho của lần đổi giá bán — được thêm bởi Migrator::ADDED_COLUMNS.)
-- @@
CREATE TABLE IF NOT EXISTS gia_ban_kho (
    kho_id                    integer      NOT NULL,
    mat_hang_id               integer      NOT NULL,
    gia_ban                   integer      NOT NULL,
    cap_nhat                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT gbk_gia_duong CHECK (gia_ban > 0),
    CONSTRAINT gbk_kho_fk FOREIGN KEY (kho_id) REFERENCES kho(id),
    CONSTRAINT gbk_item_fk FOREIGN KEY (mat_hang_id) REFERENCES mat_hang(id),
    PRIMARY KEY (kho_id, mat_hang_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
