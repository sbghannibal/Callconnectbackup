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

-- Discovery: crawled CallConnect pages and everything that was extracted from them.

CREATE TABLE IF NOT EXISTS discovery_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  seeds TEXT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  pages INT UNSIGNED NOT NULL DEFAULT 0,
  fields INT UNSIGNED NOT NULL DEFAULT 0,
  message TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_discovery_runs_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discovery_pages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id INT UNSIGNED NOT NULL,
  url VARCHAR(1024) NOT NULL,
  path VARCHAR(190) NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  depth INT UNSIGNED NOT NULL DEFAULT 0,
  status_code INT NULL,
  content_type VARCHAR(190) NOT NULL DEFAULT '',
  tabs_json LONGTEXT NULL,
  links_json LONGTEXT NULL,
  forms_json LONGTEXT NULL,
  raw_html LONGTEXT NULL,
  fetched_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_discovery_pages_run_path (run_id, path),
  CONSTRAINT fk_discovery_pages_run FOREIGN KEY (run_id) REFERENCES discovery_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discovery_fields (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  page_id INT UNSIGNED NOT NULL,
  kind VARCHAR(32) NOT NULL DEFAULT 'detail',
  section VARCHAR(255) NOT NULL DEFAULT '',
  label VARCHAR(255) NOT NULL DEFAULT '',
  name VARCHAR(255) NOT NULL DEFAULT '',
  element_id VARCHAR(255) NOT NULL DEFAULT '',
  field_type VARCHAR(64) NOT NULL DEFAULT '',
  value TEXT NULL,
  selected TINYINT(1) NULL,
  options_json LONGTEXT NULL,
  PRIMARY KEY (id),
  KEY idx_discovery_fields_page (page_id),
  CONSTRAINT fk_discovery_fields_page FOREIGN KEY (page_id) REFERENCES discovery_pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
