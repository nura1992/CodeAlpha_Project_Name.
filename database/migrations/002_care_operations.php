<?php

/**
 * Migration 002 — care operations schema:
 * MediID patient records, MediLink providers and appointments.
 *
 * Later migrations (controlled steps, per the blueprint) will add:
 * encounters/prescriptions/lab_results/consents (clinical), bills/payments/
 * ledger (MediFund), integrations/webhooks (MediBridge) and AI tables.
 */

$mysql = [
    "CREATE TABLE patients (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        patient_code VARCHAR(20) NOT NULL UNIQUE,
        user_id INT UNSIGNED NULL UNIQUE,
        primary_organization_id INT UNSIGNED NULL,
        first_name VARCHAR(80) NOT NULL,
        last_name VARCHAR(80) NOT NULL,
        date_of_birth DATE NULL,
        sex ENUM('female','male','other') NULL,
        phone VARCHAR(20) NULL,
        blood_group VARCHAR(3) NULL,
        allergies VARCHAR(500) NULL,
        emergency_contact VARCHAR(160) NULL,
        consent_recorded_at DATETIME NULL,
        status ENUM('active','archived') NOT NULL DEFAULT 'active',
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_patients_org (primary_organization_id),
        INDEX idx_patients_name (last_name, first_name),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (primary_organization_id) REFERENCES organizations(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE providers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NULL,
        full_name VARCHAR(120) NOT NULL,
        title VARCHAR(40) NULL,
        specialty VARCHAR(80) NOT NULL,
        email VARCHAR(190) NULL,
        phone VARCHAR(20) NULL,
        location VARCHAR(120) NULL,
        bio VARCHAR(1000) NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_providers_org (organization_id),
        INDEX idx_providers_specialty (specialty),
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE appointments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reference VARCHAR(20) NOT NULL UNIQUE,
        patient_id INT UNSIGNED NOT NULL,
        provider_id INT UNSIGNED NOT NULL,
        organization_id INT UNSIGNED NOT NULL,
        scheduled_at DATETIME NOT NULL,
        duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 30,
        status ENUM('booked','confirmed','rescheduled','completed','cancelled','declined','no_show') NOT NULL DEFAULT 'booked',
        reason VARCHAR(500) NULL,
        notes VARCHAR(500) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_appt_patient (patient_id),
        INDEX idx_appt_provider (provider_id),
        INDEX idx_appt_org (organization_id),
        INDEX idx_appt_schedule (scheduled_at),
        FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
        FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$sqlite = [
    "CREATE TABLE patients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_code TEXT NOT NULL UNIQUE,
        user_id INTEGER NULL UNIQUE REFERENCES users(id) ON DELETE SET NULL,
        primary_organization_id INTEGER NULL REFERENCES organizations(id) ON DELETE SET NULL,
        first_name TEXT NOT NULL,
        last_name TEXT NOT NULL,
        date_of_birth TEXT NULL,
        sex TEXT NULL,
        phone TEXT NULL,
        blood_group TEXT NULL,
        allergies TEXT NULL,
        emergency_contact TEXT NULL,
        consent_recorded_at TEXT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        created_by INTEGER NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )",
    "CREATE INDEX idx_patients_org ON patients (primary_organization_id)",
    "CREATE INDEX idx_patients_name ON patients (last_name, first_name)",

    "CREATE TABLE providers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
        user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
        full_name TEXT NOT NULL,
        title TEXT NULL,
        specialty TEXT NOT NULL,
        email TEXT NULL,
        phone TEXT NULL,
        location TEXT NULL,
        bio TEXT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )",
    "CREATE INDEX idx_providers_org ON providers (organization_id)",
    "CREATE INDEX idx_providers_specialty ON providers (specialty)",

    "CREATE TABLE appointments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reference TEXT NOT NULL UNIQUE,
        patient_id INTEGER NOT NULL REFERENCES patients(id) ON DELETE CASCADE,
        provider_id INTEGER NOT NULL REFERENCES providers(id) ON DELETE CASCADE,
        organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
        scheduled_at TEXT NOT NULL,
        duration_min INTEGER NOT NULL DEFAULT 30,
        status TEXT NOT NULL DEFAULT 'booked',
        reason TEXT NULL,
        notes TEXT NULL,
        created_by INTEGER NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )",
    "CREATE INDEX idx_appt_patient ON appointments (patient_id)",
    "CREATE INDEX idx_appt_provider ON appointments (provider_id)",
    "CREATE INDEX idx_appt_org ON appointments (organization_id)",
    "CREATE INDEX idx_appt_schedule ON appointments (scheduled_at)",
];

return ['mysql' => $mysql, 'sqlite' => $sqlite];
