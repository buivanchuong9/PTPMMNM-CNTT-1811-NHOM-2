-- Mã bán hàng để tra bảo hành và hoá đơn. Chỉ sinh LÚC BÁN (xác nhận phiếu xuất), không gắn sẵn vào lô hay mặt hàng.
--   ma_lo  = «mã lô hàng»: một mã cho mỗi dòng của phiếu xuất (các chiếc cùng dòng cùng mã) — cho biết lô nào, bảo hành bao lâu.
--   ma_mon = «mã món hàng»: mỗi chiếc bán ra một mã riêng, duy nhất toàn hệ thống.
-- Tra cứu cần cả hai mã. Bảo hành tính theo cột lo_hang.bao_hanh_thang tại lúc bán (lưu lại bao_hanh_thang / het_han ở đây nên đổi sau không ảnh hưởng hàng đã bán).
-- @@
CREATE TABLE IF NOT EXISTS san_pham_ban (
    id                        integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ma_lo                     varchar(20)  NOT NULL,
    ma_mon                    varchar(20)  NOT NULL,
    phieu_nhap_xuat_id        bigint       NOT NULL,
    chi_tiet_id               bigint       NOT NULL,
    mat_hang_id               integer      NOT NULL,
    lo_hang_id                integer      NOT NULL,
    kho_id                    integer      NOT NULL,
    ngay_mua                  date         NOT NULL,
    bao_hanh_thang            integer      NOT NULL DEFAULT 0,
    het_han                   date         NOT NULL,
    da_huy                    integer      NOT NULL DEFAULT 0,
    ngay_tao                  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT spb_phieu_fk FOREIGN KEY (phieu_nhap_xuat_id) REFERENCES phieu_nhap_xuat(id),
    CONSTRAINT spb_item_fk  FOREIGN KEY (mat_hang_id) REFERENCES mat_hang(id),
    CONSTRAINT spb_lo_fk    FOREIGN KEY (lo_hang_id) REFERENCES lo_hang(id),
    CONSTRAINT spb_kho_fk   FOREIGN KEY (kho_id) REFERENCES kho(id),
    UNIQUE (ma_mon),
    INDEX spb_by_codes (ma_lo, ma_mon),
    INDEX spb_by_voucher (phieu_nhap_xuat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
