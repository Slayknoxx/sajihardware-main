CREATE DATABASE IF NOT EXISTS saji_payments
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE saji_payments;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  local_sale_id INT NOT NULL,
  receipt_no VARCHAR(80) NOT NULL,
  payment_reference VARCHAR(80) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  status ENUM('PENDING','STK_SENT','PAID','FAILED') NOT NULL DEFAULT 'PENDING',
  checkout_request_id VARCHAR(120) NOT NULL DEFAULT '',
  merchant_request_id VARCHAR(120) NOT NULL DEFAULT '',
  mpesa_receipt VARCHAR(120) NOT NULL DEFAULT '',
  result_code VARCHAR(40) NOT NULL DEFAULT '',
  result_description VARCHAR(255) NOT NULL DEFAULT '',
  stk_response_json JSON NULL,
  callback_json JSON NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_reference (payment_reference),
  KEY idx_local_sale_id (local_sale_id),
  KEY idx_checkout_request_id (checkout_request_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id BIGINT UNSIGNED NOT NULL,
  provider_reference VARCHAR(120) NOT NULL DEFAULT '',
  event_type VARCHAR(60) NOT NULL,
  result_code VARCHAR(40) NOT NULL DEFAULT '',
  result_description VARCHAR(255) NOT NULL DEFAULT '',
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_event (payment_id, provider_reference, event_type),
  KEY idx_payment_id (payment_id),
  CONSTRAINT fk_payment_events_payment
    FOREIGN KEY (payment_id) REFERENCES payments(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
