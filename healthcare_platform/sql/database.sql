-- Intelligent Healthcare Management and Early Disease Risk Assessment Platform
-- Database Schema with Seed Data
-- MySQL 8.0+ with UTF8MB4 charset and strict mode

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Drop existing tables if they exist (for clean re-import)
DROP TABLE IF EXISTS `bills`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `prescriptions_and_reminders`;
DROP TABLE IF EXISTS `diagnostic_records`;
DROP TABLE IF EXISTS `appointments`;
DROP TABLE IF EXISTS `doctor_profiles`;
DROP TABLE IF EXISTS `patient_profiles`;
DROP TABLE IF EXISTS `users`;

-- Create users table
CREATE TABLE `users` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'doctor', 'patient') NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `email` (`email`),
    INDEX `idx_role` (`role`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create patient_profiles table
CREATE TABLE `patient_profiles` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `dob` DATE NOT NULL,
    `gender` ENUM('Male', 'Female', 'Other') NOT NULL,
    `blood_group` VARCHAR(5) NOT NULL,
    `chronic_conditions` TEXT,
    `contact_number` VARCHAR(20) NOT NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_blood_group` (`blood_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create doctor_profiles table
CREATE TABLE `doctor_profiles` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `specialization` VARCHAR(255) NOT NULL,
    `room_number` VARCHAR(50) NOT NULL,
    `consultation_fee` DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_specialization` (`specialization`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create appointments table
CREATE TABLE `appointments` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `patient_id` INT NOT NULL,
    `doctor_id` INT NOT NULL,
    `appointment_date` DATE NOT NULL,
    `appointment_time` TIME NOT NULL,
    `status` ENUM('pending', 'confirmed', 'completed', 'cancelled') DEFAULT 'pending',
    `symptom_notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`patient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_doctor_id` (`doctor_id`),
    INDEX `idx_appointment_date` (`appointment_date`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create diagnostic_records table
CREATE TABLE `diagnostic_records` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `patient_id` INT NOT NULL,
    `doctor_id` INT NOT NULL,
    `scan_type` ENUM('X-Ray', 'CT-Scan', 'MRI') NOT NULL,
    `scan_file_path` VARCHAR(500) NOT NULL,
    `heatmap_file_path` VARCHAR(500),
    `predicted_condition` VARCHAR(255),
    `predicted_stage` VARCHAR(50),
    `confidence_score` DECIMAL(5,2),
    `clinical_risk_score` DECIMAL(5,2),
    `vitals_snapshot` JSON,
    `status` ENUM('analyzed', 'reviewed_by_doctor') DEFAULT 'analyzed',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`patient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_doctor_id` (`doctor_id`),
    INDEX `idx_scan_type` (`scan_type`),
    INDEX `idx_predicted_condition` (`predicted_condition`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create prescriptions_and_reminders table
CREATE TABLE `prescriptions_and_reminders` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `diagnostic_id` INT NOT NULL,
    `patient_id` INT NOT NULL,
    `doctor_id` INT NOT NULL,
    `medication_list` JSON NOT NULL,
    `clinical_remedies_advice` TEXT,
    `next_followup_date` DATE,
    `reminder_status` ENUM('scheduled', 'dispatched') DEFAULT 'scheduled',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`diagnostic_id`) REFERENCES `diagnostic_records`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`patient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_diagnostic_id` (`diagnostic_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_doctor_id` (`doctor_id`),
    INDEX `idx_reminder_status` (`reminder_status`),
    INDEX `idx_next_followup_date` (`next_followup_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create activity_logs table
CREATE TABLE `activity_logs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT,
    `ip_address` VARCHAR(45),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create bills table
CREATE TABLE `bills` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `bill_number` VARCHAR(40) NOT NULL,
    `patient_id` INT NOT NULL,
    `doctor_id` INT NOT NULL,
    `appointment_id` INT NULL,
    `diagnostic_id` INT NULL,
    `description` VARCHAR(255) NOT NULL,
    `items_json` JSON NULL,
    `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_status` ENUM('unpaid', 'paid', 'partial', 'cancelled') NOT NULL DEFAULT 'unpaid',
    `bill_date` DATE NOT NULL,
    `paid_at` DATETIME NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_bill_number` (`bill_number`),
    INDEX `idx_bill_patient` (`patient_id`),
    INDEX `idx_bill_doctor` (`doctor_id`),
    INDEX `idx_bill_appointment` (`appointment_id`),
    INDEX `idx_bill_diagnostic` (`diagnostic_id`),
    INDEX `idx_bill_status` (`payment_status`),
    FOREIGN KEY (`patient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert seed data

-- Admin user (password: AdminPassword123!)
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`) VALUES
('System Administrator', 'admin@hospital.com', '$2y$10$oy2k8ehf.u6ClQv48VUI5OfEbgvV00NRw2PdUnMHIxsqrpKwi7pVG', 'admin');

-- Doctor 1: Dr. Sarah Johnson - Oncology (password: DoctorPassword123!)
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`) VALUES
('Dr. Sarah Johnson', 'dr.sarah@hospital.com', '$2y$10$qiOvsAiw8ZBATNyxYOrYQOSwpOqBu11yhK0y0eBjCf0Csra/d/f26', 'doctor');

INSERT INTO `doctor_profiles` (`user_id`, `specialization`, `room_number`, `consultation_fee`) VALUES
(2, 'Oncology', 'Room 301, Building A', 800.00);

-- Doctor 2: Dr. David Chen - Radiology (password: DoctorPassword123!)
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`) VALUES
('Dr. David Chen', 'dr.david@hospital.com', '$2y$10$qiOvsAiw8ZBATNyxYOrYQOSwpOqBu11yhK0y0eBjCf0Csra/d/f26', 'doctor');

INSERT INTO `doctor_profiles` (`user_id`, `specialization`, `room_number`, `consultation_fee`) VALUES
(3, 'Radiology', 'Room 205, Imaging Center', 700.00);

-- Patient 1: John Doe (password: PatientPassword123!)
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`) VALUES
('John Doe', 'john.doe@patient.com', '$2y$10$0h1x0W9citM3Vh9b4bhKJuUqCTrg1oWTxIrqlJr9CsbCo79Ibi002', 'patient');

INSERT INTO `patient_profiles` (`user_id`, `dob`, `gender`, `blood_group`, `chronic_conditions`, `contact_number`) VALUES
(4, '1985-03-15', 'Male', 'O+', 'Hypertension, Type 2 Diabetes', '+1-555-0101');

-- Patient 2: Jane Smith (password: PatientPassword123!)
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`) VALUES
('Jane Smith', 'jane.smith@patient.com', '$2y$10$0h1x0W9citM3Vh9b4bhKJuUqCTrg1oWTxIrqlJr9CsbCo79Ibi002', 'patient');

INSERT INTO `patient_profiles` (`user_id`, `dob`, `gender`, `blood_group`, `chronic_conditions`, `contact_number`) VALUES
(5, '1990-07-22', 'Female', 'A+', 'Asthma, Seasonal Allergies', '+1-555-0102');

-- Sample appointment data
INSERT INTO `appointments` (`patient_id`, `doctor_id`, `appointment_date`, `appointment_time`, `status`, `symptom_notes`) VALUES
(4, 2, '2026-10-20', '09:30:00', 'confirmed', 'Persistent cough, fatigue, occasional chest discomfort'),
(5, 3, '2026-10-21', '14:00:00', 'pending', 'Follow-up imaging scan for respiratory evaluation');

-- Sample billing data linked to appointments
INSERT INTO `bills` (`bill_number`, `patient_id`, `doctor_id`, `appointment_id`, `description`, `items_json`, `amount`, `payment_status`, `bill_date`) VALUES
('BILL-2026-0001', 4, 2, 1, 'Clinical Consultation — Oncology (Dr. Sarah Johnson)', '[{"item":"Specialist Consultation (Oncology)","category":"Consultation","qty":1,"unit_price":800.00,"total":800.00}]', 800.00, 'paid', '2026-10-20'),
('BILL-2026-0002', 5, 3, 2, 'Clinical Consultation — Radiology (Dr. David Chen)', '[{"item":"Specialist Consultation (Radiology)","category":"Consultation","qty":1,"unit_price":700.00,"total":700.00}]', 700.00, 'unpaid', '2026-10-21');

COMMIT;
