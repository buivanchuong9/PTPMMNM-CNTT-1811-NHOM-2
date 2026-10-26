-- Đề xuất mặt hàng mới: quản lý kho quét ra mã vạch chưa có trong hệ thống ⇒ điền thông tin hàng và gửi đề xuất cho quản trị.
-- PENDING chờ duyệt · APPROVED quản trị chấp nhận ⇒ mặt hàng được tạo (mat_hang_id) kèm mã vạch, giá gốc, nhà cung cấp; quản lý kho lập phiếu nhập sau đó
-- REJECTED bị từ chối (kèm ly_do) · CANCELLED quản lý tự rút lại. da_bao: quản lý đã thấy kết quả duyệt chưa (số nhỏ trên menu, chuông).
-- @@
CREATE TABLE IF NOT EXISTS de_xuat_mat_hang (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma_vach                   varchar(64)  NOT NULL,
    ma_hang                   varchar(40)  NOT NULL,
    ten                       varchar(160) NOT NULL,
    don_vi_tinh_id            integer      NOT NULL,
    quan_ly_lo                boolean      NOT NULL DEFAULT FALSE,
    danh_muc_id               integer      NOT NULL,
    nha_cung_cap_id           integer      NOT NULL,
    kho_id                    integer      NOT NULL,
    trang_thai                enum('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    nguoi_de_xuat_id          integer      NOT NULL,
    ly_do                     varchar(255) NOT NULL DEFAULT '',
    nguoi_xu_ly_id            integer      NULL,
    ngay_xu_ly                timestamp    NULL,
    mat_hang_id               integer      NULL,
    da_bao                    boolean      NOT NULL DEFAULT FALSE,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT dxmh_unit_fk   FOREIGN KEY (don_vi_tinh_id)   REFERENCES don_vi_tinh(id),
    CONSTRAINT dxmh_cat_fk    FOREIGN KEY (danh_muc_id)      REFERENCES danh_muc(id),
    CONSTRAINT dxmh_sup_fk    FOREIGN KEY (nha_cung_cap_id)  REFERENCES doi_tac(id),
    CONSTRAINT dxmh_kho_fk    FOREIGN KEY (kho_id)           REFERENCES kho(id),
    CONSTRAINT dxmh_user_fk   FOREIGN KEY (nguoi_de_xuat_id) REFERENCES nguoi_dung(id),
    CONSTRAINT dxmh_xl_fk     FOREIGN KEY (nguoi_xu_ly_id)   REFERENCES nguoi_dung(id),
    CONSTRAINT dxmh_item_fk   FOREIGN KEY (mat_hang_id)      REFERENCES mat_hang(id),
    INDEX dxmh_by_status (trang_thai, kho_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
