<?php

/**
 * Migration 001 — shared platform schema:
 * identity, RBAC, organizations, security + audit tables.
 *
 * Each item is a raw SQL string. Statements run inside tables that already
 * use InnoDB (MySQL) and always use prepared-statement-compatible DDL.
 * SQLite is supported for the zero-config demo environment.
 */

$mysql = [
    "CREATE TABLE users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        phone VARCHAR(20) NULL,
        password_hash VARCHAR(255) NOT NULL,
        status ENUM('active','suspended','deactivated') NOT NULL DEFAULT 'active',
        email_verified_at DATETIME NULL,
        email_verify_token CHAR(64) NULL,
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_users_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE roles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(60) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        description VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE permissions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(80) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        grp VARCHAR(60) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE role_permissions (
        role_id INT UNSIGNED NOT NULL,
        permission_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (role_id, permission_id),
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE user_roles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        role_id INT UNSIGNED NOT NULL,
        organization_id INT UNSIGNED NULL,
        UNIQUE KEY uq_user_role (user_id, role_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE organizations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        type ENUM('hospital','clinic','laboratory','pharmacy','other') NOT NULL DEFAULT 'clinic',
        email VARCHAR(190) NULL,
        phone VARCHAR(20) NULL,
        address VARCHAR(255) NULL,
        city VARCHAR(80) NULL,
        state VARCHAR(80) NULL,
        country VARCHAR(80) NOT NULL DEFAULT 'Nigeria',
        status ENUM('pending','approved','suspended') NOT NULL DEFAULT 'approved',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_org_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE organization_members (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        job_title VARCHAR(80) NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_org_member (organization_id, user_id),
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE login_attempts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        attempted_at DATETIME NOT NULL,
        INDEX idx_attempt_lookup (email, attempted_at),
        INDEX idx_attempt_ip (ip, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE password_resets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_reset_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        action VARCHAR(80) NOT NULL,
        entity_type VARCHAR(60) NOT NULL,
        entity_id BIGINT UNSIGNED NULL,
        details TEXT NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_audit_entity (entity_type, entity_id),
        INDEX idx_audit_action (action),
        INDEX idx_audit_created (created_at),
        INDEX idx_audit_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(160) NOT NULL,
        body TEXT NULL,
        type ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
        link VARCHAR(255) NULL,
        read_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_notif_user (user_id, read_at),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE system_settings (
        `key` VARCHAR(80) PRIMARY KEY,
        value TEXT NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$sqlite = [
    "CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        phone TEXT NULL,
        password_hash TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        email_verified_at TEXT NULL,
        email_verify_token TEXT NULL,
        last_login_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )",
    "CREATE INDEX idx_users_status ON users (status)",

    "CREATE TABLE roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        description TEXT NULL
    )",

    "CREATE TABLE permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        grp TEXT NOT NULL
    )",

    "CREATE TABLE role_permissions (
        role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
        permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
        PRIMARY KEY (role_id, permission_id)
    )",

    "CREATE TABLE user_roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
        organization_id INTEGER NULL,
        UNIQUE (user_id, role_id)
    )",

    "CREATE TABLE organizations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT 'clinic',
        email TEXT NULL,
        phone TEXT NULL,
        address TEXT NULL,
        city TEXT NULL,
        state TEXT NULL,
        country TEXT NOT NULL DEFAULT 'Nigeria',
        status TEXT NOT NULL DEFAULT 'approved',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )",
    "CREATE INDEX idx_org_status ON organizations (status)",

    "CREATE TABLE organization_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        job_title TEXT NULL,
        is_primary INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        UNIQUE (organization_id, user_id)
    )",

    "CREATE TABLE login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        ip TEXT NOT NULL,
        success INTEGER NOT NULL DEFAULT 0,
        attempted_at TEXT NOT NULL
    )",
    "CREATE INDEX idx_attempt_lookup ON login_attempts (email, attempted_at)",
    "CREATE INDEX idx_attempt_ip ON login_attempts (ip, attempted_at)",

    "CREATE TABLE password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        token_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        used_at TEXT NULL,
        created_ip TEXT NULL,
        created_at TEXT NOT NULL
    )",
    "CREATE INDEX idx_reset_email ON password_resets (email)",

    "CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        action TEXT NOT NULL,
        entity_type TEXT NOT NULL,
        entity_id INTEGER NULL,
        details TEXT NULL,
        ip TEXT NULL,
        user_agent TEXT NULL,
        created_at TEXT NOT NULL
    )",
    "CREATE INDEX idx_audit_entity ON audit_logs (entity_type, entity_id)",
    "CREATE INDEX idx_audit_action ON audit_logs (action)",
    "CREATE INDEX idx_audit_created ON audit_logs (created_at)",
    "CREATE INDEX idx_audit_user ON audit_logs (user_id)",

    "CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        title TEXT NOT NULL,
        body TEXT NULL,
        type TEXT NOT NULL DEFAULT 'info',
        link TEXT NULL,
        read_at TEXT NULL,
        created_at TEXT NOT NULL
    )",
    "CREATE INDEX idx_notif_user ON notifications (user_id, read_at)",

    "CREATE TABLE system_settings (
        key TEXT PRIMARY KEY,
        value TEXT NULL,
        updated_at TEXT NULL
    )",
];

return ['mysql' => $mysql, 'sqlite' => $sqlite];
