USE hungry_potters;

-- Existing orders have no trustworthy payment history. New orders are tracked.
ALTER TABLE orders
    ADD COLUMN payment_history_state ENUM('legacy_unknown', 'reconciled', 'tracked')
        NOT NULL DEFAULT 'legacy_unknown' AFTER total;
ALTER TABLE orders
    MODIFY COLUMN payment_history_state ENUM('legacy_unknown', 'reconciled', 'tracked')
        NOT NULL DEFAULT 'tracked';

CREATE TABLE registers (
    id TINYINT UNSIGNED PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    current_shift_id BIGINT UNSIGNED DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE register_shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    register_id TINYINT UNSIGNED NOT NULL,
    opened_by INT UNSIGNED NOT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opening_float DECIMAL(10,2) NOT NULL,
    closed_by INT UNSIGNED DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    expected_cash DECIMAL(10,2) DEFAULT NULL,
    counted_cash DECIMAL(10,2) DEFAULT NULL,
    variance DECIMAL(10,2) DEFAULT NULL,
    close_note VARCHAR(255) DEFAULT NULL,
    CONSTRAINT fk_shifts_register FOREIGN KEY (register_id) REFERENCES registers(id),
    CONSTRAINT fk_shifts_opened_by FOREIGN KEY (opened_by) REFERENCES users(id),
    CONSTRAINT fk_shifts_closed_by FOREIGN KEY (closed_by) REFERENCES users(id),
    INDEX idx_shifts_opened (opened_at)
) ENGINE=InnoDB;

INSERT INTO registers (id, code) VALUES (1, 'POS-01');

CREATE TABLE payment_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    shift_id BIGINT UNSIGNED DEFAULT NULL,
    kind ENUM('payment', 'refund', 'failed') NOT NULL,
    method ENUM('cash', 'card', 'ewallet') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    tendered_amount DECIMAL(10,2) DEFAULT NULL,
    change_amount DECIMAL(10,2) DEFAULT NULL,
    reference VARCHAR(100) DEFAULT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    refund_of_id BIGINT UNSIGNED DEFAULT NULL,
    refund_kind ENUM('cancellation', 'order_reduction', 'goodwill') DEFAULT NULL,
    source ENUM('live', 'historical') NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by INT UNSIGNED NOT NULL,
    request_key CHAR(32) NOT NULL UNIQUE,
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_payments_shift FOREIGN KEY (shift_id) REFERENCES register_shifts(id),
    CONSTRAINT fk_payments_user FOREIGN KEY (recorded_by) REFERENCES users(id),
    CONSTRAINT fk_payments_refund_of FOREIGN KEY (refund_of_id) REFERENCES payment_transactions(id),
    INDEX idx_payments_order (order_id, occurred_at),
    INDEX idx_payments_shift (shift_id, kind, method),
    INDEX idx_payments_occurred (occurred_at, source)
) ENGINE=InnoDB;
