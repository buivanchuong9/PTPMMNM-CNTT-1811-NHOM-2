-- Tuỳ chọn (khuyến nghị): khoá bất biến ngay trong CSDL — không ai sửa/xoá sổ phát sinh hay chứng từ đã xác nhận,
-- kể cả khi chạy SQL trực tiếp. Cần quyền TRIGGER; nếu MySQL bật binlog thì cần log_bin_trust_function_creators=1
-- hoặc tài khoản có SUPER. Nếu không tạo được, `bin/stockflow migrate` sẽ cảnh báo và bỏ qua (ứng dụng vẫn đúng).
-- @@
DROP TRIGGER IF EXISTS stock_movements_no_update
-- @@
CREATE TRIGGER stock_movements_no_update BEFORE UPDATE ON stock_movements FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'
-- @@
DROP TRIGGER IF EXISTS stock_movements_no_delete
-- @@
CREATE TRIGGER stock_movements_no_delete BEFORE DELETE ON stock_movements FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'
-- @@
DROP TRIGGER IF EXISTS vouchers_no_delete
-- @@
CREATE TRIGGER vouchers_no_delete BEFORE DELETE ON vouchers FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'vouchers cannot be deleted; cancel or correct them instead'
-- @@
DROP TRIGGER IF EXISTS vouchers_confirmed_frozen
-- @@
CREATE TRIGGER vouchers_confirmed_frozen BEFORE UPDATE ON vouchers FOR EACH ROW
BEGIN
    IF OLD.status = 'CONFIRMED' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'confirmed vouchers are immutable; issue an adjustment voucher';
    END IF;
END
-- @@
DROP TRIGGER IF EXISTS voucher_lines_no_delete
-- @@
CREATE TRIGGER voucher_lines_no_delete BEFORE DELETE ON voucher_lines FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'voucher lines cannot be deleted'
-- @@
DROP TRIGGER IF EXISTS voucher_lines_no_update
-- @@
CREATE TRIGGER voucher_lines_no_update BEFORE UPDATE ON voucher_lines FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'voucher lines are immutable'
