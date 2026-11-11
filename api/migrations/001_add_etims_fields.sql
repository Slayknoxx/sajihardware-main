-- Add ETIMS/VAT compliance fields to sales table
-- Run this migration to add KRA compliance tracking

-- Add new columns to sales table
ALTER TABLE sales ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(12,2) DEFAULT 0;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(5,2) DEFAULT 16;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS etims_status VARCHAR(50) DEFAULT 'PENDING' CHECK(etims_status IN ('PENDING', 'SUBMITTED', 'ACKNOWLEDGED', 'FAILED', 'EXEMPTED'));
ALTER TABLE sales ADD COLUMN IF NOT EXISTS etims_transaction_id VARCHAR(100);
ALTER TABLE sales ADD COLUMN IF NOT EXISTS etims_unique_id VARCHAR(50);
ALTER TABLE sales ADD COLUMN IF NOT EXISTS etims_qr_code TEXT;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS etims_qr_url VARCHAR(255);
ALTER TABLE sales ADD COLUMN IF NOT EXISTS kra_pin VARCHAR(20);
ALTER TABLE sales ADD COLUMN IF NOT EXISTS invoice_type VARCHAR(50) DEFAULT 'NORMAL' CHECK(invoice_type IN ('NORMAL', 'CREDIT_NOTE', 'DEBIT_NOTE', 'EXEMPT'));
ALTER TABLE sales ADD COLUMN IF NOT EXISTS etims_error_message TEXT;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS etims_submitted_at DATETIME;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS receipt_signature VARCHAR(255);

-- Create ETIMS configuration table
CREATE TABLE IF NOT EXISTS etims_config (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kra_pin VARCHAR(20) NOT NULL,
  serial_number VARCHAR(50) NOT NULL,
  verification_key VARCHAR(255) NOT NULL,
  device_id VARCHAR(100) NOT NULL UNIQUE,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Create VAT summary table for reporting
CREATE TABLE IF NOT EXISTS vat_summary (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  period_year INTEGER NOT NULL,
  period_month INTEGER NOT NULL,
  total_sales DECIMAL(15,2) DEFAULT 0,
  taxable_sales DECIMAL(15,2) DEFAULT 0,
  total_vat DECIMAL(15,2) DEFAULT 0,
  exempt_sales DECIMAL(15,2) DEFAULT 0,
  etims_submitted INTEGER DEFAULT 0,
  etims_acknowledged INTEGER DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(period_year, period_month)
);

-- Create ETIMS audit log
CREATE TABLE IF NOT EXISTS etims_audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  sale_id INTEGER NOT NULL,
  action VARCHAR(50) NOT NULL,
  status_before VARCHAR(50),
  status_after VARCHAR(50),
  response_json JSON,
  error_message TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sale_id) REFERENCES sales(id)
);
