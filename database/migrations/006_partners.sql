-- Đối tác (nhà cung cấp / khách hàng) và nhà cung cấp của từng mặt hàng.
-- @@
CREATE TABLE IF NOT EXISTS doi_tac (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(30)  NOT NULL,
    ten                       varchar(160) NOT NULL,
    loai                      enum('SUPPLIER','CUSTOMER') NOT NULL DEFAULT 'SUPPLIER',
    nguoi_lien_he             varchar(120) NOT NULL DEFAULT '',
    so_dien_thoai             varchar(15)  NOT NULL DEFAULT '',
    email                     varchar(120) NOT NULL DEFAULT '',
    dia_chi                   varchar(255) NOT NULL DEFAULT '',
    ghi_chu                   varchar(255) NOT NULL DEFAULT '',
    hoat_dong                 boolean      NOT NULL DEFAULT TRUE,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (ma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS mat_hang_nha_cung_cap (
    mat_hang_id               integer      NOT NULL,
    doi_tac_id                integer      NOT NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (mat_hang_id, doi_tac_id),
    CONSTRAINT mhncc_item_fk    FOREIGN KEY (mat_hang_id) REFERENCES mat_hang(id),
    CONSTRAINT mhncc_partner_fk FOREIGN KEY (doi_tac_id)  REFERENCES doi_tac(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
