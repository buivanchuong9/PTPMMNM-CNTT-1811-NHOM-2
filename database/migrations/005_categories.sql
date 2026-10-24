-- Danh mục loại hàng hoá (mat_hang.danh_muc_id được thêm bởi Migrator::ADDED_COLUMNS, NULL = chưa phân loại).
-- @@
CREATE TABLE IF NOT EXISTS danh_muc (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(30)  NOT NULL,
    ten                       varchar(100) NOT NULL,
    hoat_dong                 boolean      NOT NULL DEFAULT TRUE,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (ma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
