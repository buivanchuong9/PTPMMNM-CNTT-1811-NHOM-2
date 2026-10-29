-- Nhiều xe cùng chở một lô hàng điều chuyển («đợt điều chuyển») và xe hỏng giữa đường.
-- Mỗi xe vẫn là một cặp phiếu đi/đến riêng (nhận hàng, thu hồi, sổ cái chạy theo từng xe như cũ); bảng dưới chỉ gom các phiếu đi cùng đợt.
-- dot_id = id của phiếu đi đầu tiên trong đợt; thu_tu = thứ tự xe nối đuôi nhau (1 = xe dẫn đầu).
-- @@
CREATE TABLE IF NOT EXISTS dot_dieu_chuyen (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dot_id                    bigint       NOT NULL,
    phieu_di_id               bigint       NOT NULL,
    thu_tu                    integer      NOT NULL DEFAULT 1,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ddc_phieu_fk FOREIGN KEY (phieu_di_id) REFERENCES phieu_nhap_xuat(id),
    UNIQUE (phieu_di_id),
    INDEX ddc_by_dot (dot_id, thu_tu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
-- Xe hỏng giữa đường: hàng vẫn «đang vận chuyển» nhưng kho đến chưa nhận được cho tới khi xe chạy tiếp (ngay_sua có giá trị).
-- tien_do: vị trí xe dừng trên lộ trình (% quãng đường) — chỉ để vẽ trên bản đồ minh hoạ.
CREATE TABLE IF NOT EXISTS su_co_xe (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    chuyen_id                 bigint       NOT NULL,
    ly_do                     varchar(200) NOT NULL,
    tien_do                   integer      NOT NULL DEFAULT 50,
    nguoi_bao_id              integer      NOT NULL,
    ngay_hong                 timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nguoi_sua_id              integer      NULL,
    ngay_sua                  timestamp    NULL,
    CONSTRAINT scx_chuyen_fk FOREIGN KEY (chuyen_id) REFERENCES chuyen_van_chuyen(id),
    CONSTRAINT scx_nguoi_fk  FOREIGN KEY (nguoi_bao_id) REFERENCES nguoi_dung(id),
    INDEX scx_by_trip (chuyen_id, ngay_sua)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
