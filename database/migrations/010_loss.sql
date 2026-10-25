-- Thất thoát hàng trên đường: admin đóng chuyến «chưa thấy hàng» và ghi nhận ai chịu, vì sao, mất bao nhiêu.
-- loai: MAT_CHUYEN (cả chuyến không thấy hàng) · NHAN_THIEU (kho đến nhận thiếu một phần).
-- nguoi_chiu: TAI_XE (tài xế chịu: bị trừ điểm + lương) · CONG_TY (không trừ tài xế).
-- gia_tri: giá trị hàng mất theo giá gốc · tien_tru_luong, diem_tru: phần trừ vào lương / điểm của tài xế (0 nếu kho chịu).
-- @@
CREATE TABLE IF NOT EXISTS that_thoat_hang (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    chuyen_id                 bigint       NOT NULL,
    tai_xe_id                 integer      NOT NULL,
    loai                      varchar(12)  NOT NULL DEFAULT 'MAT_CHUYEN',
    nguoi_chiu                enum('TAI_XE','CONG_TY') NOT NULL DEFAULT 'TAI_XE',
    ly_do                     varchar(255) NOT NULL,
    gia_tri                   bigint       NOT NULL DEFAULT 0,
    tien_tru_luong            bigint       NOT NULL DEFAULT 0,
    diem_tru                  integer      NOT NULL DEFAULT 0,
    nguoi_xac_nhan_id         integer      NOT NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT tt_chuyen_fk   FOREIGN KEY (chuyen_id)  REFERENCES chuyen_van_chuyen(id),
    CONSTRAINT tt_tai_xe_fk   FOREIGN KEY (tai_xe_id)  REFERENCES tai_xe(id),
    CONSTRAINT tt_nguoi_fk    FOREIGN KEY (nguoi_xac_nhan_id) REFERENCES nguoi_dung(id),
    UNIQUE (chuyen_id),
    INDEX tt_by_driver (tai_xe_id, ngay_tao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
