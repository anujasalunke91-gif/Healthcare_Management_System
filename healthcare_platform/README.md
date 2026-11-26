# MedCore Health — Healthcare Management & Clinical Risk Assessment Platform

A role-based healthcare management web application for managing appointments, patient profiles, diagnostic risk assessments, prescriptions, receipts, and billing.

## Technology Stack

- **Backend**: PHP 8.2+ (PDO prepared statements, CSRF protection, role-based access control)
- **Database**: MySQL 8.0+ (`utf8mb4`)
- **Frontend**: HTML5, CSS3, Vanilla JavaScript (ES6+), Bootstrap 5.3, Bootstrap Icons, Chart.js
- **Optional AI Microservice**: Python 3.10+ (FastAPI, PyTorch/Torchvision, Pillow) with built-in deterministic fallback in PHP

## Portable Setup Instructions

### 1. Database Setup

```bash
mysql -u root -p
CREATE DATABASE healthcare_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;

mysql -u root -p healthcare_platform < healthcare_platform/sql/database.sql
```

### 2. Configure Database & Service Settings

Copy `healthcare_platform/config/config.example.php` to `healthcare_platform/config/config.local.php` and enter your local MySQL credentials:

```php
return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'healthcare_platform',
    'DB_USER' => 'root',
    'DB_PASS' => 'your_mysql_password',
    'DB_CHARSET' => 'utf8mb4',
    'AI_SERVICE_URL' => 'http://127.0.0.1:8000',
];
```

You can also set environment variables (`HP_DB_HOST`, `HP_DB_NAME`, `HP_DB_USER`, `HP_DB_PASS`, `HP_AI_SERVICE_URL`).

### 3. Run the Application

#### Windows (Portable Launcher)
Double-click `start_project.bat` from the project root. It launches PHP's built-in server on `http://localhost:8080` using relative paths.

#### Manual / Linux / macOS
```bash
php -S localhost:8080 -t healthcare_platform
```

Ensure `healthcare_platform/uploads/scans` and `healthcare_platform/uploads/reports` are writable (`chmod 755`).

### 4. Optional Python AI Service

```bash
cd healthcare_platform/ai_service
python -m venv venv
# Activate venv, then:
pip install -r requirements.txt
python app.py
```

The platform automatically uses built-in clinical risk evaluation if the Python service is offline.

## Core Modules

- **Multilingual UI**: English (default), Marathi (`मराठी`), and Hindi (`हिन्दी`) language switcher in the navigation bar.
- **Currency**: All consultations, bills, receipts, and expenses are formatted in Indian Rupees (`₹ INR`).
- **Admin Portal**: KPI analytics, sortable Patient & Doctor directories, inline Role management dropdown, User CRUD, Appointment Record management, Billing & Invoices.
- **Doctor Portal**: Interactive KPI cards, dedicated My Patients list with complete patient medical history, simplified 2-step Medical Scan & Vitals Risk Assessment workflow, Prescription builder (returning to My Patients on save), Appointment approval & management, Read-only Patient Bills viewer.
- **Patient Portal**: Overview dashboard with Total Appointment Amount summary card, editable Age & Medical Profile, Appointment booking & confirmed-only downloadable Appointment Receipts, Diagnostic Risk Reports with medical scan images, Prescriptions, Bills & Expenses statements.
