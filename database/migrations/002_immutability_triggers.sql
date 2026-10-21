-- Tuỳ chọn (khuyến nghị): khoá bất biến ngay trong CSDL — không ai sửa/xoá sổ phát sinh hay chứng từ đã xác nhận,
-- kể cả khi chạy SQL trực tiếp. Cần quyền TRIGGER; nếu MySQL bật binlog thì cần log_bin_trust_function_creators=1
-- hoặc tài khoản có SUPER. Nếu không tạo được, `bin/stockflow migrate` sẽ cảnh báo và bỏ qua (ứng dụng vẫn đúng).
-- @@
DROP TRIGGER IF EXISTS so_phat_sinh_khong_sua
-- @@
CREATE TRIGGER so_phat_sinh_khong_sua BEFORE UPDATE ON so_phat_sinh FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'
-- @@
DROP TRIGGER IF EXISTS so_phat_sinh_khong_xoa
-- @@
CREATE TRIGGER so_phat_sinh_khong_xoa BEFORE DELETE ON so_phat_sinh FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'
-- @@
DROP TRIGGER IF EXISTS chung_tu_khong_xoa
-- @@
CREATE TRIGGER chung_tu_khong_xoa BEFORE DELETE ON chung_tu FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'vouchers cannot be deleted; cancel or correct them instead'
-- @@
DROP TRIGGER IF EXISTS chung_tu_da_duyet_khoa
-- @@
CREATE TRIGGER chung_tu_da_duyet_khoa BEFORE UPDATE ON chung_tu FOR EACH ROW
BEGIN
    IF OLD.status = 'CONFIRMED' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'confirmed vouchers are immutable; issue an adjustment voucher';
    END IF;
END
-- @@
DROP TRIGGER IF EXISTS chi_tiet_chung_tu_khong_xoa
-- @@
CREATE TRIGGER chi_tiet_chung_tu_khong_xoa BEFORE DELETE ON chi_tiet_chung_tu FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'voucher lines cannot be deleted'
-- @@
DROP TRIGGER IF EXISTS chi_tiet_chung_tu_khong_sua
-- @@
CREATE TRIGGER chi_tiet_chung_tu_khong_sua BEFORE UPDATE ON chi_tiet_chung_tu FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'voucher lines are immutable'
