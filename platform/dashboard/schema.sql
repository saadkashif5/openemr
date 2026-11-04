-- Super-admin dashboard database (oe_platform). Safe to run repeatedly.
-- It holds the company team's logins, the queue of provisioning jobs and the audit log.
-- It holds no patient data and no practice database credentials.

CREATE TABLE IF NOT EXISTS operators (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner', 'operator', 'viewer') NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    failed_logins INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_operators_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type ENUM('create', 'reset_admin', 'destroy', 'build_golden') NOT NULL,
    domain VARCHAR(100) NULL,
    payload TEXT NULL,
    status ENUM('queued', 'running', 'succeeded', 'failed') NOT NULL DEFAULT 'queued',
    requested_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    exit_code INT NULL,
    result TEXT NULL,
    error TEXT NULL,
    secret_file VARCHAR(255) NULL,
    secret_revealed_at DATETIME NULL,
    secret_revealed_by INT UNSIGNED NULL,
    KEY idx_jobs_status (status),
    KEY idx_jobs_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    operator_id INT UNSIGNED NULL,
    operator_email VARCHAR(190) NULL,
    action VARCHAR(60) NOT NULL,
    target VARCHAR(190) NULL,
    detail TEXT NULL,
    ip VARCHAR(45) NULL,
    KEY idx_audit_at (at),
    KEY idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
