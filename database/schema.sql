-- MySQL schema for the CallConnect backup/sync tool.

CREATE TABLE IF NOT EXISTS records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  external_id VARCHAR(190) NOT NULL,
  label VARCHAR(255) NOT NULL DEFAULT '',
  raw_json LONGTEXT NOT NULL,
  imported_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_records_external_id (external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parameters (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  remote_value TEXT NULL,
  local_value TEXT NULL,
  selected TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('in_sync','modified','pushed','failed') NOT NULL DEFAULT 'in_sync',
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_parameters_record_name (record_id, name),
  CONSTRAINT fk_parameters_record FOREIGN KEY (record_id) REFERENCES records (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  username VARCHAR(190) NOT NULL DEFAULT '',
  success TINYINT(1) NOT NULL DEFAULT 0,
  status_code INT NULL,
  message TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_login_log_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  external_id VARCHAR(190) NOT NULL DEFAULT '',
  payload_json LONGTEXT NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  status_code INT NULL,
  message TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_push_log_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
