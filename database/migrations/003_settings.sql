-- Cài đặt theo phạm vi: 'system' (toàn hệ thống, doi_tuong_id = 0) và 'user' (tuỳ chọn cá nhân, doi_tuong_id = id tài khoản).
-- Thiếu dòng ⇒ dùng giá trị mặc định trong SettingsService.
CREATE TABLE IF NOT EXISTS cai_dat (
    pham_vi                   varchar(10)  NOT NULL,
    doi_tuong_id              integer      NOT NULL DEFAULT 0,
    khoa                      varchar(40)  NOT NULL,
    gia_tri                   varchar(255) NOT NULL,
    cap_nhat                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (pham_vi, doi_tuong_id, khoa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
