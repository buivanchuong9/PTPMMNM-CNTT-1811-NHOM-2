-- Đội xe & tài xế của hệ thống + chuyến vận chuyển cho phiếu điều chuyển.
-- Thông tin vận chuyển nằm ở bảng riêng (không nằm trên phiếu) vì phiếu đã xác nhận là bất biến.
-- @@
CREATE TABLE IF NOT EXISTS tai_xe (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ho_ten                    varchar(120) NOT NULL,
    cccd                      varchar(12)  NOT NULL,
    so_dien_thoai             varchar(15)  NOT NULL DEFAULT '',
    hoat_dong                 boolean      NOT NULL DEFAULT TRUE,
    diem                      integer      NOT NULL DEFAULT 100,
    luong                     integer      NOT NULL DEFAULT 0,
    kho_id                    integer      NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (cccd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
-- Xe: mỗi kho là «kho gốc» của bao nhiêu xe cũng được; mỗi xe có một tài xế phụ trách (tai_xe_id, mỗi tài xế chỉ một xe) — tài xế đi theo xe. Xe đứng ở một kho (kho_hien_tai_id) hoặc đang chạy tới kho khác (dang_den_kho_id) — đúng một trong hai.
CREATE TABLE IF NOT EXISTS xe (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bien_so                   varchar(15)  NOT NULL,
    loai_xe                   varchar(60)  NOT NULL DEFAULT '',
    kho_goc_id                integer      NOT NULL,
    kho_hien_tai_id           integer      NULL,
    dang_den_kho_id           integer      NULL,
    tai_xe_id                 integer      NULL,
    hoat_dong                 boolean      NOT NULL DEFAULT TRUE,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT xe_vi_tri CHECK ((kho_hien_tai_id IS NOT NULL AND dang_den_kho_id IS NULL) OR (kho_hien_tai_id IS NULL AND dang_den_kho_id IS NOT NULL)),
    CONSTRAINT xe_kho_goc_fk FOREIGN KEY (kho_goc_id) REFERENCES kho(id),
    CONSTRAINT xe_kho_ht_fk  FOREIGN KEY (kho_hien_tai_id) REFERENCES kho(id),
    CONSTRAINT xe_kho_den_fk FOREIGN KEY (dang_den_kho_id) REFERENCES kho(id),
    CONSTRAINT xe_tai_xe_fk  FOREIGN KEY (tai_xe_id) REFERENCES tai_xe(id),
    UNIQUE (bien_so),
    UNIQUE (tai_xe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
-- tai_xe.diem: điểm uy tín (mặc định 100, bị trừ khi tài xế chịu trách nhiệm mất hàng) · tai_xe.luong: lương tháng (đồng) · tai_xe.kho_id: kho quản lý tài xế (kho gốc của xe tài xế phụ trách).
-- Một chuyến = một phiếu điều chuyển đi + phiếu đến đối ứng. IN_TRANSIT: đang chạy · DELIVERED: kho đến đã nhận.
-- tai_xe_xac_nhan_du_hang: lúc xuất kho, tài xế đã xác nhận nhận đủ hàng.
-- nhan_du_hang / nhan_dung_tai_xe: kho đến kiểm tra lúc nhận (hàng đủ không, đúng tài xế và xe không).
-- RECALLED: kho đi thu hồi chuyến khi xe còn trên đường (hàng cộng lại kho đi, xe về kho đi, phiếu đến bị huỷ).
-- LOST: admin đã xác nhận mất hàng (xem that_thoat_hang) — phiếu đến bị huỷ, tồn kho đến không đổi.
-- sai_lech: nội dung kho đến báo khi hàng thiếu hoặc không đúng tài xế (chuyến vẫn IN_TRANSIT cho tới khi xử lý xong).
CREATE TABLE IF NOT EXISTS chuyen_van_chuyen (
    id                        bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phieu_di_id               bigint       NOT NULL,
    phieu_den_id              bigint       NOT NULL,
    xe_id                     integer      NOT NULL,
    tai_xe_id                 integer      NOT NULL,
    kho_di_id                 integer      NOT NULL,
    kho_den_id                integer      NOT NULL,
    trang_thai                enum('IN_TRANSIT','DELIVERED','LOST','RECALLED') NOT NULL DEFAULT 'IN_TRANSIT',
    tai_xe_xac_nhan_du_hang   boolean      NOT NULL DEFAULT FALSE,
    nguoi_giao_id             integer      NOT NULL,
    ngay_di                   timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nhan_du_hang              boolean      NULL,
    nhan_dung_tai_xe          boolean      NULL,
    sai_lech                  varchar(255) NOT NULL DEFAULT '',
    nguoi_nhan_id             integer      NULL,
    ngay_nhan                 timestamp    NULL,
    CONSTRAINT cvc_phieu_di_fk  FOREIGN KEY (phieu_di_id)  REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT cvc_phieu_den_fk FOREIGN KEY (phieu_den_id) REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT cvc_xe_fk        FOREIGN KEY (xe_id)        REFERENCES xe(id),
    CONSTRAINT cvc_tai_xe_fk    FOREIGN KEY (tai_xe_id)    REFERENCES tai_xe(id),
    CONSTRAINT cvc_kho_di_fk    FOREIGN KEY (kho_di_id)    REFERENCES kho(id),
    CONSTRAINT cvc_kho_den_fk   FOREIGN KEY (kho_den_id)   REFERENCES kho(id),
    CONSTRAINT cvc_nguoi_giao_fk FOREIGN KEY (nguoi_giao_id) REFERENCES nguoi_dung(id),
    CONSTRAINT cvc_nguoi_nhan_fk FOREIGN KEY (nguoi_nhan_id) REFERENCES nguoi_dung(id),
    UNIQUE (phieu_di_id),
    UNIQUE (phieu_den_id),
    INDEX cvc_by_status (trang_thai, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
