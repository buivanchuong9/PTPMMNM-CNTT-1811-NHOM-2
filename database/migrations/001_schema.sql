-- StockFlow schema — MySQL >= 8.0.16 (CHECK constraints are enforced from this version).
-- Statements are separated by a line containing only "-- @@" (so trigger bodies may contain ';').
-- Idempotent: safe to run repeatedly.

-- Điều kiện ràng buộc quan trọng:
--   * stock_balances.quantity >= 0           → tồn không bao giờ âm, kể cả khi code ứng dụng có lỗi.
--   * stock_movements.voucher_line_id UNIQUE → một dòng chứng từ chỉ được ghi sổ đúng một lần.
--   * vouchers.idempotency_key UNIQUE        → gửi lại cùng một yêu cầu tạo phiếu không sinh phiếu mới.
--   * vouchers.correction_of UNIQUE          → một chứng từ chỉ bị điều chỉnh đảo một lần.
-- @@
CREATE TABLE IF NOT EXISTS users (
    id            integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username      varchar(40)  NOT NULL,
    full_name     varchar(120) NOT NULL,
    password_hash varchar(255) NOT NULL,
    is_admin      boolean      NOT NULL DEFAULT FALSE,
    active        boolean      NOT NULL DEFAULT TRUE,
    created_at    timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS warehouses (
    id         integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       varchar(20)  NOT NULL,
    name       varchar(120) NOT NULL,
    address    varchar(255) NOT NULL DEFAULT '',
    active     boolean      NOT NULL DEFAULT TRUE,
    created_at timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
-- Quyền theo từng kho. KEEPER: lập phiếu · APPROVER: xác nhận/điều chỉnh · COUNTER: kiểm kê.
CREATE TABLE IF NOT EXISTS warehouse_roles (
    user_id      integer NOT NULL,
    warehouse_id integer NOT NULL,
    role         enum('KEEPER','APPROVER','COUNTER') NOT NULL,
    PRIMARY KEY (user_id, warehouse_id, role),
    CONSTRAINT wr_user_fk FOREIGN KEY (user_id)      REFERENCES users(id),
    CONSTRAINT wr_wh_fk   FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS units (
    id   integer     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code varchar(20) NOT NULL,
    name varchar(60) NOT NULL,
    UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS items (
    id            integer      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sku           varchar(40)  NOT NULL,
    name          varchar(160) NOT NULL,
    unit_id       integer      NOT NULL,
    track_batch   boolean      NOT NULL DEFAULT FALSE,
    reorder_level integer      NOT NULL DEFAULT 0,
    active        boolean      NOT NULL DEFAULT TRUE,
    created_at    timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT items_reorder_nonneg CHECK (reorder_level >= 0),
    CONSTRAINT items_unit_fk FOREIGN KEY (unit_id) REFERENCES units(id),
    UNIQUE (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
-- Mặt hàng không quản lý theo lô dùng một lô mặc định 'NOLOT' để khoá tồn luôn có dạng (kho, hàng, lô).
CREATE TABLE IF NOT EXISTS batches (
    id          integer     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id     integer     NOT NULL,
    batch_no    varchar(40) NOT NULL,
    expiry_date date        NULL,
    created_at  timestamp   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT batches_item_fk FOREIGN KEY (item_id) REFERENCES items(id),
    UNIQUE (item_id, batch_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS sequences (
    name  varchar(30) NOT NULL PRIMARY KEY,
    value bigint      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS vouchers (
    id                bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code              varchar(30)  NOT NULL,
    type              enum('RECEIPT','ISSUE','TRANSFER_OUT','TRANSFER_IN','ADJUSTMENT') NOT NULL,
    status            enum('DRAFT','CONFIRMED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
    warehouse_id      integer      NOT NULL,
    counterpart_wh_id integer      NULL,
    ref_voucher_id    bigint       NULL,
    correction_of     bigint       NULL,
    idempotency_key   varchar(64)  NULL,
    partner           varchar(120) NOT NULL DEFAULT '',
    reason            varchar(255) NOT NULL DEFAULT '',
    note              varchar(255) NOT NULL DEFAULT '',
    created_by        integer      NOT NULL,
    confirmed_by      integer      NULL,
    created_at        timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at      timestamp    NULL,
    CONSTRAINT v_distinct_wh   CHECK (counterpart_wh_id IS NULL OR counterpart_wh_id <> warehouse_id),
    CONSTRAINT v_confirmed_ts  CHECK (status <> 'CONFIRMED' OR (confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL)),
    CONSTRAINT v_wh_fk         FOREIGN KEY (warehouse_id)      REFERENCES warehouses(id),
    CONSTRAINT v_cp_fk         FOREIGN KEY (counterpart_wh_id) REFERENCES warehouses(id),
    CONSTRAINT v_ref_fk        FOREIGN KEY (ref_voucher_id)    REFERENCES vouchers(id),
    CONSTRAINT v_corr_fk       FOREIGN KEY (correction_of)     REFERENCES vouchers(id),
    CONSTRAINT v_creator_fk    FOREIGN KEY (created_by)        REFERENCES users(id),
    CONSTRAINT v_confirmer_fk  FOREIGN KEY (confirmed_by)      REFERENCES users(id),
    UNIQUE (code),
    UNIQUE (idempotency_key),
    UNIQUE (correction_of),
    INDEX v_by_wh_status (warehouse_id, status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
-- qty > 0 với phiếu nhập/xuất/điều chuyển; có dấu (≠ 0) với phiếu điều chỉnh — ứng dụng kiểm tra theo loại phiếu.
CREATE TABLE IF NOT EXISTS voucher_lines (
    id         bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    voucher_id bigint       NOT NULL,
    item_id    integer      NOT NULL,
    batch_id   integer      NOT NULL,
    qty        integer      NOT NULL,
    note       varchar(255) NOT NULL DEFAULT '',
    CONSTRAINT vl_qty_nonzero CHECK (qty <> 0),
    CONSTRAINT vl_voucher_fk FOREIGN KEY (voucher_id) REFERENCES vouchers(id),
    CONSTRAINT vl_item_fk    FOREIGN KEY (item_id)    REFERENCES items(id),
    CONSTRAINT vl_batch_fk   FOREIGN KEY (batch_id)   REFERENCES batches(id),
    UNIQUE (voucher_id, item_id, batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
-- Số dư hiện hành, chỉ phục vụ khoá + chặn tồn âm. Nguồn sự thật là stock_movements (xem trang Đối chiếu).
CREATE TABLE IF NOT EXISTS stock_balances (
    warehouse_id integer NOT NULL,
    item_id      integer NOT NULL,
    batch_id     integer NOT NULL,
    quantity     integer NOT NULL DEFAULT 0,
    PRIMARY KEY (warehouse_id, item_id, batch_id),
    CONSTRAINT sb_nonneg CHECK (quantity >= 0),
    CONSTRAINT sb_wh_fk    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT sb_item_fk  FOREIGN KEY (item_id)      REFERENCES items(id),
    CONSTRAINT sb_batch_fk FOREIGN KEY (batch_id)     REFERENCES batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
-- Sổ phát sinh: bất biến, chỉ INSERT khi chứng từ được xác nhận.
CREATE TABLE IF NOT EXISTS stock_movements (
    id              bigint    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    voucher_id      bigint    NOT NULL,
    voucher_line_id bigint    NOT NULL,
    warehouse_id    integer   NOT NULL,
    item_id         integer   NOT NULL,
    batch_id        integer   NOT NULL,
    qty_change      integer   NOT NULL,
    balance_after   integer   NOT NULL,
    posted_at       timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT sm_change_nonzero CHECK (qty_change <> 0),
    CONSTRAINT sm_balance_nonneg CHECK (balance_after >= 0),
    CONSTRAINT sm_voucher_fk FOREIGN KEY (voucher_id)      REFERENCES vouchers(id),
    CONSTRAINT sm_line_fk    FOREIGN KEY (voucher_line_id) REFERENCES voucher_lines(id),
    CONSTRAINT sm_wh_fk      FOREIGN KEY (warehouse_id)    REFERENCES warehouses(id),
    CONSTRAINT sm_item_fk    FOREIGN KEY (item_id)         REFERENCES items(id),
    CONSTRAINT sm_batch_fk   FOREIGN KEY (batch_id)        REFERENCES batches(id),
    UNIQUE (voucher_line_id),
    INDEX sm_by_key (warehouse_id, item_id, batch_id),
    INDEX sm_by_voucher (voucher_id),
    INDEX sm_by_time (posted_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
-- Đợt kiểm kê: OPEN (đang đếm) → REVIEW (đã nhập đủ, chờ duyệt chênh lệch) → CLOSED (đã lập chứng từ điều chỉnh).
CREATE TABLE IF NOT EXISTS stock_counts (
    id                    bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code                  varchar(30)  NOT NULL,
    warehouse_id          integer      NOT NULL,
    status                enum('OPEN','REVIEW','CLOSED','CANCELLED') NOT NULL DEFAULT 'OPEN',
    note                  varchar(255) NOT NULL DEFAULT '',
    created_by            integer      NOT NULL,
    approved_by           integer      NULL,
    adjustment_voucher_id bigint       NULL,
    created_at            timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at          timestamp    NULL,
    closed_at             timestamp    NULL,
    CONSTRAINT sc_wh_fk      FOREIGN KEY (warehouse_id)          REFERENCES warehouses(id),
    CONSTRAINT sc_creator_fk FOREIGN KEY (created_by)            REFERENCES users(id),
    CONSTRAINT sc_approver_fk FOREIGN KEY (approved_by)          REFERENCES users(id),
    CONSTRAINT sc_adj_fk     FOREIGN KEY (adjustment_voucher_id) REFERENCES vouchers(id),
    UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS stock_count_lines (
    count_id    bigint       NOT NULL,
    item_id     integer      NOT NULL,
    batch_id    integer      NOT NULL,
    system_qty  integer      NOT NULL,
    counted_qty integer      NULL,
    reason      varchar(255) NOT NULL DEFAULT '',
    PRIMARY KEY (count_id, item_id, batch_id),
    CONSTRAINT scl_sys_nonneg CHECK (system_qty >= 0),
    CONSTRAINT scl_cnt_nonneg CHECK (counted_qty IS NULL OR counted_qty >= 0),
    CONSTRAINT scl_count_fk FOREIGN KEY (count_id) REFERENCES stock_counts(id),
    CONSTRAINT scl_item_fk  FOREIGN KEY (item_id)  REFERENCES items(id),
    CONSTRAINT scl_batch_fk FOREIGN KEY (batch_id) REFERENCES batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE IF NOT EXISTS audit_log (
    id         bigint       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    integer      NULL,
    action     varchar(40)  NOT NULL,
    entity     varchar(30)  NOT NULL,
    entity_id  bigint       NULL,
    detail     varchar(500) NOT NULL DEFAULT '',
    created_at timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX audit_by_entity (entity, entity_id),
    INDEX audit_by_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
