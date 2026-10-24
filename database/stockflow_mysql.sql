-- StockFlow — tạo CSDL + toàn bộ bảng + trigger (MySQL 8.0.16+ / MariaDB 10.4 của XAMPP)
-- Cách dùng: phpMyAdmin → tab Import → chọn file này,  hoặc:  mysql -u root < database/stockflow_mysql.sql
-- File này được sinh từ database/migrations/*.sql — nếu sửa migration thì sinh lại.

CREATE DATABASE IF NOT EXISTS stockflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE stockflow;

-- ===== 001_schema.sql =====
-- StockFlow schema — MySQL >= 8.0.16 (CHECK constraints are enforced from this version).
-- Statements are separated by a line containing only "-- @@" (so trigger bodies may contain ';').
-- Idempotent: safe to run repeatedly.
-- Điều kiện ràng buộc quan trọng:
--   * ton_kho.so_luong >= 0           → tồn không bao giờ âm, kể cả khi code ứng dụng có lỗi.
--   * so_phat_sinh.chi_tiet_phieu_nhap_xuat_id UNIQUE → một dòng phiếu nhập xuất chỉ được ghi sổ đúng một lần.
--   * phieu_nhap_xuat.khoa_chong_trung UNIQUE        → gửi lại cùng một yêu cầu tạo phiếu không sinh phiếu mới.
--   * phieu_nhap_xuat.dieu_chinh_cho_id UNIQUE          → một phiếu nhập xuất chỉ bị điều chỉnh đảo một lần.;

CREATE TABLE IF NOT EXISTS kho (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(20)  NOT NULL,
    ten                       varchar(120) NOT NULL,
    dia_chi                   varchar(255) NOT NULL DEFAULT '',
    hoat_dong                 boolean      NOT NULL DEFAULT TRUE,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (ma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tài khoản: ADMIN (cấp cao, toàn quyền, không gắn kho) · MANAGER (quản lý kho — mỗi kho đúng 1) · STAFF (nhân viên kho — nhiều).
-- Mỗi tài khoản con gắn với đúng một kho (kho_id). quan_ly_kho_id chỉ có giá trị với MANAGER và bằng kho_id;
-- UNIQUE(quan_ly_kho_id) khiến CSDL từ chối kho thứ hai có quản lý thứ hai (NULL không bị coi là trùng).
CREATE TABLE IF NOT EXISTS nguoi_dung (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ten_dang_nhap             varchar(40)  NOT NULL,
    ho_ten                    varchar(120) NOT NULL,
    mat_khau_ma_hoa           varchar(255) NOT NULL,
    vai_tro                   enum('ADMIN','MANAGER','STAFF') NOT NULL DEFAULT 'STAFF',
    kho_id                    integer      NULL,
    quan_ly_kho_id            integer      NULL,
    hoat_dong                 boolean      NOT NULL DEFAULT TRUE,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    anh_dai_dien              varchar(255) NULL,
    CONSTRAINT nd_kho_theo_vai_tro CHECK ((vai_tro = 'ADMIN' AND kho_id IS NULL) OR (vai_tro <> 'ADMIN' AND kho_id IS NOT NULL)),
    CONSTRAINT nd_quan_ly_hop_le   CHECK ((vai_tro = 'MANAGER' AND quan_ly_kho_id = kho_id) OR (vai_tro <> 'MANAGER' AND quan_ly_kho_id IS NULL)),
    CONSTRAINT nd_kho_fk FOREIGN KEY (kho_id) REFERENCES kho(id),
    UNIQUE (ten_dang_nhap),
    UNIQUE (quan_ly_kho_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chức năng nào được dùng theo vai trò (MANAGER / STAFF). ADMIN luôn toàn quyền nên không có dòng ở đây.
-- Thiếu dòng ⇒ dùng mặc định trong mã nguồn; quản trị viên bật/tắt ở trang «Phân quyền chức năng».
-- Thu hồi quyền chỉ ẩn/chặn chức năng với vai trò đó — chức năng vẫn nguyên vẹn trong hệ thống.
CREATE TABLE IF NOT EXISTS phan_quyen_vai_tro (
    vai_tro                   enum('MANAGER','STAFF') NOT NULL,
    quyen                     varchar(40) NOT NULL,
    duoc_phep                 boolean     NOT NULL,
    PRIMARY KEY (vai_tro, quyen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS don_vi_tinh (
    id                        integer     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(20) NOT NULL,
    ten                       varchar(60) NOT NULL,
    UNIQUE (ma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mat_hang (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma_hang                   varchar(40)  NOT NULL,
    ten                       varchar(160) NOT NULL,
    don_vi_tinh_id            integer      NOT NULL,
    quan_ly_lo                boolean      NOT NULL DEFAULT FALSE,
    quy_cach_lo               integer      NOT NULL DEFAULT 1,
    dinh_muc_toi_thieu        integer      NOT NULL DEFAULT 0,
    hoat_dong                 boolean      NOT NULL DEFAULT TRUE,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT items_reorder_nonneg CHECK (dinh_muc_toi_thieu >= 0),
    CONSTRAINT items_pack_positive  CHECK (quy_cach_lo >= 1),
    CONSTRAINT items_unit_fk FOREIGN KEY (don_vi_tinh_id) REFERENCES don_vi_tinh(id),
    UNIQUE (ma_hang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mặt hàng không quản lý theo lô dùng một lô mặc định 'NOLOT' để khoá tồn luôn có dạng (kho, hàng, lô).
CREATE TABLE IF NOT EXISTS lo_hang (
    id                        integer     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mat_hang_id               integer     NOT NULL,
    so_lo                     varchar(40) NOT NULL,
    han_dung                  date        NULL,
    ngay_tao                  timestamp   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT batches_item_fk FOREIGN KEY (mat_hang_id) REFERENCES mat_hang(id),
    UNIQUE (mat_hang_id, so_lo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bo_dem (
    ten                       varchar(30) NOT NULL PRIMARY KEY,
    gia_tri                   bigint      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS phieu_nhap_xuat (
    id                        bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(30)  NOT NULL,
    loai                      enum('RECEIPT','ISSUE','TRANSFER_OUT','TRANSFER_IN','ADJUSTMENT') NOT NULL,
    trang_thai                enum('DRAFT','CONFIRMED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
    kho_id                    integer      NOT NULL,
    kho_doi_ung_id            integer      NULL,
    phieu_nhap_xuat_lien_quan_id     bigint       NULL,
    dieu_chinh_cho_id         bigint       NULL,
    khoa_chong_trung          varchar(64)  NULL,
    doi_tac                   varchar(120) NOT NULL DEFAULT '',
    ly_do                     varchar(255) NOT NULL DEFAULT '',
    ghi_chu                   varchar(255) NOT NULL DEFAULT '',
    nguoi_lap_id              integer      NOT NULL,
    nguoi_xac_nhan_id         integer      NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ngay_xac_nhan             timestamp    NULL,
    CONSTRAINT v_distinct_wh   CHECK (kho_doi_ung_id IS NULL OR kho_doi_ung_id <> kho_id),
    CONSTRAINT v_confirmed_ts  CHECK (trang_thai <> 'CONFIRMED' OR (ngay_xac_nhan IS NOT NULL AND nguoi_xac_nhan_id IS NOT NULL)),
    CONSTRAINT v_wh_fk         FOREIGN KEY (kho_id)      REFERENCES kho(id),
    CONSTRAINT v_cp_fk         FOREIGN KEY (kho_doi_ung_id) REFERENCES kho(id),
    CONSTRAINT v_ref_fk        FOREIGN KEY (phieu_nhap_xuat_lien_quan_id)    REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT v_corr_fk       FOREIGN KEY (dieu_chinh_cho_id)     REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT v_creator_fk    FOREIGN KEY (nguoi_lap_id)        REFERENCES nguoi_dung(id),
    CONSTRAINT v_confirmer_fk  FOREIGN KEY (nguoi_xac_nhan_id)      REFERENCES nguoi_dung(id),
    UNIQUE (ma),
    UNIQUE (khoa_chong_trung),
    UNIQUE (dieu_chinh_cho_id),
    INDEX v_by_wh_status (kho_id, trang_thai, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- qty > 0 với phiếu nhập/xuất/điều chuyển; có dấu (≠ 0) với phiếu điều chỉnh — ứng dụng kiểm tra theo loại phiếu.
CREATE TABLE IF NOT EXISTS chi_tiet_phieu_nhap_xuat (
    id                        bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phieu_nhap_xuat_id               bigint       NOT NULL,
    mat_hang_id               integer      NOT NULL,
    lo_hang_id                integer      NOT NULL,
    so_luong                  integer      NOT NULL,
    ghi_chu                   varchar(255) NOT NULL DEFAULT '',
    CONSTRAINT vl_qty_nonzero CHECK (so_luong <> 0),
    CONSTRAINT vl_voucher_fk FOREIGN KEY (phieu_nhap_xuat_id) REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT vl_item_fk    FOREIGN KEY (mat_hang_id)    REFERENCES mat_hang(id),
    CONSTRAINT vl_batch_fk   FOREIGN KEY (lo_hang_id)   REFERENCES lo_hang(id),
    UNIQUE (phieu_nhap_xuat_id, mat_hang_id, lo_hang_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Số dư hiện hành, chỉ phục vụ khoá + chặn tồn âm. Nguồn sự thật là so_phat_sinh (xem trang Đối chiếu).
CREATE TABLE IF NOT EXISTS ton_kho (
    kho_id                    integer NOT NULL,
    mat_hang_id               integer NOT NULL,
    lo_hang_id                integer NOT NULL,
    so_luong                  integer NOT NULL DEFAULT 0,
    PRIMARY KEY (kho_id, mat_hang_id, lo_hang_id),
    CONSTRAINT sb_nonneg CHECK (so_luong >= 0),
    CONSTRAINT sb_wh_fk    FOREIGN KEY (kho_id) REFERENCES kho(id),
    CONSTRAINT sb_item_fk  FOREIGN KEY (mat_hang_id)      REFERENCES mat_hang(id),
    CONSTRAINT sb_batch_fk FOREIGN KEY (lo_hang_id)     REFERENCES lo_hang(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sổ phát sinh: bất biến, chỉ INSERT khi phiếu nhập xuất được xác nhận.
CREATE TABLE IF NOT EXISTS so_phat_sinh (
    id                        bigint    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phieu_nhap_xuat_id               bigint    NOT NULL,
    chi_tiet_phieu_nhap_xuat_id      bigint    NOT NULL,
    kho_id                    integer   NOT NULL,
    mat_hang_id               integer   NOT NULL,
    lo_hang_id                integer   NOT NULL,
    bien_dong                 integer   NOT NULL,
    ton_sau                   integer   NOT NULL,
    ngay_ghi_so               timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT sm_change_nonzero CHECK (bien_dong <> 0),
    CONSTRAINT sm_balance_nonneg CHECK (ton_sau >= 0),
    CONSTRAINT sm_voucher_fk FOREIGN KEY (phieu_nhap_xuat_id)      REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT sm_line_fk    FOREIGN KEY (chi_tiet_phieu_nhap_xuat_id) REFERENCES chi_tiet_phieu_nhap_xuat(id),
    CONSTRAINT sm_wh_fk      FOREIGN KEY (kho_id)    REFERENCES kho(id),
    CONSTRAINT sm_item_fk    FOREIGN KEY (mat_hang_id)         REFERENCES mat_hang(id),
    CONSTRAINT sm_batch_fk   FOREIGN KEY (lo_hang_id)        REFERENCES lo_hang(id),
    UNIQUE (chi_tiet_phieu_nhap_xuat_id),
    INDEX sm_by_key (kho_id, mat_hang_id, lo_hang_id),
    INDEX sm_by_voucher (phieu_nhap_xuat_id),
    INDEX sm_by_time (ngay_ghi_so, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Đợt kiểm kê: OPEN (đang đếm) → REVIEW (đã nhập đủ, chờ duyệt chênh lệch) → CLOSED (đã lập phiếu nhập xuất điều chỉnh).
CREATE TABLE IF NOT EXISTS dot_kiem_ke (
    id                        bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(30)  NOT NULL,
    kho_id                    integer      NOT NULL,
    trang_thai                enum('OPEN','REVIEW','CLOSED','CANCELLED') NOT NULL DEFAULT 'OPEN',
    ghi_chu                   varchar(255) NOT NULL DEFAULT '',
    nguoi_lap_id              integer      NOT NULL,
    nguoi_duyet_id            integer      NULL,
    phieu_nhap_xuat_dieu_chinh_id    bigint       NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ngay_nop                  timestamp    NULL,
    ngay_dong                 timestamp    NULL,
    CONSTRAINT sc_wh_fk      FOREIGN KEY (kho_id)          REFERENCES kho(id),
    CONSTRAINT sc_creator_fk FOREIGN KEY (nguoi_lap_id)            REFERENCES nguoi_dung(id),
    CONSTRAINT sc_approver_fk FOREIGN KEY (nguoi_duyet_id)          REFERENCES nguoi_dung(id),
    CONSTRAINT sc_adj_fk     FOREIGN KEY (phieu_nhap_xuat_dieu_chinh_id) REFERENCES phieu_nhap_xuat(id),
    UNIQUE (ma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chi_tiet_kiem_ke (
    dot_kiem_ke_id            bigint       NOT NULL,
    mat_hang_id               integer      NOT NULL,
    lo_hang_id                integer      NOT NULL,
    so_luong_so_sach          integer      NOT NULL,
    so_luong_thuc_dem         integer      NULL,
    ly_do                     varchar(255) NOT NULL DEFAULT '',
    PRIMARY KEY (dot_kiem_ke_id, mat_hang_id, lo_hang_id),
    CONSTRAINT scl_sys_nonneg CHECK (so_luong_so_sach >= 0),
    CONSTRAINT scl_cnt_nonneg CHECK (so_luong_thuc_dem IS NULL OR so_luong_thuc_dem >= 0),
    CONSTRAINT scl_count_fk FOREIGN KEY (dot_kiem_ke_id) REFERENCES dot_kiem_ke(id),
    CONSTRAINT scl_item_fk  FOREIGN KEY (mat_hang_id)  REFERENCES mat_hang(id),
    CONSTRAINT scl_batch_fk FOREIGN KEY (lo_hang_id) REFERENCES lo_hang(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nhat_ky (
    id                        bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nguoi_dung_id             integer      NULL,
    hanh_dong                 varchar(40)  NOT NULL,
    doi_tuong                 varchar(30)  NOT NULL,
    doi_tuong_id              bigint       NULL,
    chi_tiet                  varchar(500) NOT NULL DEFAULT '',
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX audit_by_entity (doi_tuong, doi_tuong_id),
    INDEX audit_by_time (ngay_tao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 002_immutability_triggers.sql =====
-- Tuỳ chọn (khuyến nghị): khoá bất biến ngay trong CSDL — không ai sửa/xoá sổ phát sinh hay phiếu nhập xuất đã xác nhận,
-- kể cả khi chạy SQL trực tiếp. Cần quyền TRIGGER; nếu MySQL bật binlog thì cần log_bin_trust_function_creators=1
-- hoặc tài khoản có SUPER. Nếu không tạo được, `bin/stockflow migrate` sẽ cảnh báo và bỏ qua (ứng dụng vẫn đúng).

DROP TRIGGER IF EXISTS so_phat_sinh_khong_sua;

DELIMITER $$
CREATE TRIGGER so_phat_sinh_khong_sua BEFORE UPDATE ON so_phat_sinh FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'$$
DELIMITER ;

DROP TRIGGER IF EXISTS so_phat_sinh_khong_xoa;

DELIMITER $$
CREATE TRIGGER so_phat_sinh_khong_xoa BEFORE DELETE ON so_phat_sinh FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'$$
DELIMITER ;

DROP TRIGGER IF EXISTS phieu_nhap_xuat_khong_xoa;

DELIMITER $$
CREATE TRIGGER phieu_nhap_xuat_khong_xoa BEFORE DELETE ON phieu_nhap_xuat FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'vouchers cannot be deleted; cancel or correct them instead'$$
DELIMITER ;

DROP TRIGGER IF EXISTS phieu_nhap_xuat_da_duyet_khoa;

DELIMITER $$
CREATE TRIGGER phieu_nhap_xuat_da_duyet_khoa BEFORE UPDATE ON phieu_nhap_xuat FOR EACH ROW
BEGIN
    IF OLD.trang_thai = 'CONFIRMED' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'confirmed vouchers are immutable; issue an adjustment voucher';
    END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS chi_tiet_phieu_nhap_xuat_khong_xoa;

DELIMITER $$
CREATE TRIGGER chi_tiet_phieu_nhap_xuat_khong_xoa BEFORE DELETE ON chi_tiet_phieu_nhap_xuat FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'voucher lines cannot be deleted'$$
DELIMITER ;

DROP TRIGGER IF EXISTS chi_tiet_phieu_nhap_xuat_khong_sua;

DELIMITER $$
CREATE TRIGGER chi_tiet_phieu_nhap_xuat_khong_sua BEFORE UPDATE ON chi_tiet_phieu_nhap_xuat FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'voucher lines are immutable'$$
DELIMITER ;

-- ===== 003_settings.sql =====
-- Cài đặt theo phạm vi: 'system' (toàn hệ thống, doi_tuong_id = 0) và 'user' (tuỳ chọn cá nhân, doi_tuong_id = id tài khoản).
-- Thiếu dòng ⇒ dùng giá trị mặc định trong SettingsService.
CREATE TABLE IF NOT EXISTS cai_dat (
    pham_vi                   varchar(10)  NOT NULL,
    doi_tuong_id              integer      NOT NULL DEFAULT 0,
    khoa                      varchar(40)  NOT NULL,
    gia_tri                   varchar(255) NOT NULL,
    cap_nhat                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (pham_vi, doi_tuong_id, khoa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 004_fleet.sql =====
-- Đội xe & tài xế của hệ thống + chuyến vận chuyển cho phiếu điều chuyển.
-- Thông tin vận chuyển nằm ở bảng riêng (không nằm trên phiếu) vì phiếu đã xác nhận là bất biến.;

CREATE TABLE IF NOT EXISTS tai_xe (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ho_ten                    varchar(120) NOT NULL,
    cccd                      varchar(12)  NOT NULL,
    so_dien_thoai             varchar(15)  NOT NULL DEFAULT '',
    hoat_dong                 boolean      NOT NULL DEFAULT TRUE,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (cccd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Xe: mỗi kho là «kho gốc» của 1–2 xe (ứng dụng kiểm tra); mỗi xe có một tài xế phụ trách (tai_xe_id, mỗi tài xế chỉ một xe) — tài xế đi theo xe. Xe đứng ở một kho (kho_hien_tai_id) hoặc đang chạy tới kho khác (dang_den_kho_id) — đúng một trong hai.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Một chuyến = một phiếu điều chuyển đi + phiếu đến đối ứng. IN_TRANSIT: đang chạy · DELIVERED: kho đến đã nhận.
-- tai_xe_xac_nhan_du_hang: lúc xuất kho, tài xế đã xác nhận nhận đủ hàng.
-- nhan_du_hang / nhan_dung_tai_xe: kho đến kiểm tra lúc nhận (hàng đủ không, đúng tài xế và xe không).
-- sai_lech: nội dung kho đến báo khi hàng thiếu hoặc không đúng tài xế (chuyến vẫn IN_TRANSIT cho tới khi xử lý xong).
CREATE TABLE IF NOT EXISTS chuyen_van_chuyen (
    id                        bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phieu_di_id               bigint       NOT NULL,
    phieu_den_id              bigint       NOT NULL,
    xe_id                     integer      NOT NULL,
    tai_xe_id                 integer      NOT NULL,
    kho_di_id                 integer      NOT NULL,
    kho_den_id                integer      NOT NULL,
    trang_thai                enum('IN_TRANSIT','DELIVERED') NOT NULL DEFAULT 'IN_TRANSIT',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ===== 005_categories.sql =====
-- Danh mục loại hàng hoá (mat_hang.danh_muc_id: NULL = chưa phân loại).
CREATE TABLE IF NOT EXISTS danh_muc (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma                        varchar(30)  NOT NULL,
    ten                       varchar(100) NOT NULL,
    hoat_dong                 boolean      NOT NULL DEFAULT TRUE,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (ma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE mat_hang ADD COLUMN danh_muc_id integer NULL;


-- ===== 006_partners.sql =====
-- Đối tác (nhà cung cấp / khách hàng) và nhà cung cấp của từng mặt hàng.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mat_hang_nha_cung_cap (
    mat_hang_id               integer      NOT NULL,
    doi_tac_id                integer      NOT NULL,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (mat_hang_id, doi_tac_id),
    CONSTRAINT mhncc_item_fk    FOREIGN KEY (mat_hang_id) REFERENCES mat_hang(id),
    CONSTRAINT mhncc_partner_fk FOREIGN KEY (doi_tac_id)  REFERENCES doi_tac(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
