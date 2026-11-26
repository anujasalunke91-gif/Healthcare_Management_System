/**
 * MedCore Health - Client Controller
 * Handles Dropzone, Simplified Risk Assessment Workflow, Table Sorting,
 * Prescription Saving with Redirect, Appointments, and Localized Notifications.
 */

let prescriptionRowCount = 0;
let isSavingPrescription = false;

function tr(key, fallback) {
    if (window.I18N && Object.prototype.hasOwnProperty.call(window.I18N, key)) {
        return window.I18N[key];
    }
    return fallback !== undefined ? fallback : key;
}

document.addEventListener('DOMContentLoaded', function () {
    initializeDropzone();
    initializeDiagnosticForm();
    initializeTooltips();
});

function initializeTooltips() {
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const list = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        list.map(el => new bootstrap.Tooltip(el));
    }
}

function getBaseUrl() {
    if (typeof window.BASE_URL === 'string' && window.BASE_URL.trim() !== '') {
        return window.BASE_URL.replace(/\/+$/, '');
    }
    const path = window.location.pathname;
    const match = path.match(/^(.*?\/healthcare_platform)/i);
    if (match) {
        return window.location.origin + match[1];
    }
    return window.location.origin;
}

// ==========================================================================
// 1. Authentication Helpers
// ==========================================================================
function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        if (icon) {
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        }
    } else {
        input.type = 'password';
        if (icon) {
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    }
}

// ==========================================================================
// 2. Generic Table Sorting
// ==========================================================================
function sortTable(tableId, colIndex, sortType = 'text', forcedDir = null) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    const headers = table.querySelectorAll('thead th');
    const currentTh = headers[colIndex];
    let dir = forcedDir;

    if (!dir && currentTh) {
        dir = currentTh.classList.contains('sort-asc') ? 'desc' : 'asc';
    }
    dir = dir || 'asc';

    headers.forEach((th, idx) => {
        th.classList.remove('sort-asc', 'sort-desc');
        const ind = th.querySelector('.sort-indicator');
        if (ind) {
            ind.innerHTML = idx === colIndex
                ? (dir === 'asc' ? '<i class="bi bi-arrow-up"></i>' : '<i class="bi bi-arrow-down"></i>')
                : '<i class="bi bi-arrow-down-up"></i>';
        }
    });

    if (currentTh) {
        currentTh.classList.add(dir === 'asc' ? 'sort-asc' : 'sort-desc');
    }

    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a, b) => {
        const cellA = a.children[colIndex];
        const cellB = b.children[colIndex];
        const rawA = (cellA?.getAttribute('data-sort') ?? cellA?.innerText ?? '').trim();
        const rawB = (cellB?.getAttribute('data-sort') ?? cellB?.innerText ?? '').trim();

        let cmp = 0;
        if (sortType === 'number') {
            const numA = parseFloat(rawA.replace(/[^0-9.-]+/g, '')) || 0;
            const numB = parseFloat(rawB.replace(/[^0-9.-]+/g, '')) || 0;
            cmp = numA - numB;
        } else if (sortType === 'date') {
            const timeA = Date.parse(rawA) || 0;
            const timeB = Date.parse(rawB) || 0;
            cmp = timeA - timeB;
        } else {
            cmp = rawA.localeCompare(rawB, undefined, { sensitivity: 'base', numeric: true });
        }
        return dir === 'asc' ? cmp : -cmp;
    });

    rows.forEach(row => tbody.appendChild(row));
}

function sortTableBySelect(tableId, selectEl) {
    if (!selectEl || !selectEl.value) return;
    const parts = selectEl.value.split(':');
    const colIndex = parseInt(parts[0], 10);
    const sortType = parts[1] || 'text';
    const dir = parts[2] || 'asc';
    if (!isNaN(colIndex)) {
        sortTable(tableId, colIndex, sortType, dir);
    }
}

// ==========================================================================
// 3. Dropzone Medical Scan Upload
// ==========================================================================
function initializeDropzone() {
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('scanFile');
    if (!dropzone || !fileInput) return;

    dropzone.addEventListener('click', function (e) {
        if (!e.target.closest('button')) {
            fileInput.click();
        }
    });

    fileInput.addEventListener('change', function (e) {
        if (e.target.files.length > 0) {
            handleFileSelect(e.target.files[0]);
        }
    });

    dropzone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropzone.classList.add('dragover');
    });

    dropzone.addEventListener('dragleave', function (e) {
        e.preventDefault();
        dropzone.classList.remove('dragover');
    });

    dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            handleFileSelect(files[0]);
        }
    });
}

function handleFileSelect(file) {
    const validTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    if (!validTypes.includes(file.type)) {
        showToast(tr('msg_invalid_image', 'Please select a valid scan image (JPEG or PNG)'), 'error');
        return;
    }

    const maxSize = 15 * 1024 * 1024;
    if (file.size > maxSize) {
        showToast(tr('msg_file_too_large', 'File size exceeds 15MB limit'), 'error');
        return;
    }

    const reader = new FileReader();
    reader.onload = function (e) {
        const previewContainer = document.getElementById('previewContainer');
        const imagePreview = document.getElementById('imagePreview');
        const dropzoneContent = document.querySelector('.dropzone-content');
        const fileNameBadge = document.getElementById('fileNameBadge');

        if (imagePreview) imagePreview.src = e.target.result;
        if (fileNameBadge) fileNameBadge.textContent = file.name;
        if (previewContainer && dropzoneContent) {
            previewContainer.classList.remove('d-none');
            dropzoneContent.classList.add('d-none');
        }
    };
    reader.readAsDataURL(file);
}

function clearImage(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    const fileInput = document.getElementById('scanFile');
    const previewContainer = document.getElementById('previewContainer');
    const dropzoneContent = document.querySelector('.dropzone-content');

    if (fileInput) fileInput.value = '';
    if (previewContainer) previewContainer.classList.add('d-none');
    if (dropzoneContent) dropzoneContent.classList.remove('d-none');
}

async function loadSampleScan(imageSrc, sampleName, scanType) {
    try {
        const response = await fetch(imageSrc);
        const blob = await response.blob();
        const file = new File([blob], sampleName || 'sample_scan.jpg', { type: blob.type || 'image/jpeg' });

        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);

        const fileInput = document.getElementById('scanFile');
        if (fileInput) {
            fileInput.files = dataTransfer.files;
            handleFileSelect(file);
        }
        if (scanType) {
            const scanTypeSelect = document.getElementById('scan_type');
            if (scanTypeSelect) scanTypeSelect.value = scanType;
        }
        showToast(`${tr('msg_sample_loaded', 'Loaded sample scan')}: ${sampleName}`, 'success');
    } catch (err) {
        console.error('Failed to load sample scan:', err);
        showToast(tr('msg_sample_failed', 'Could not load sample scan file'), 'warning');
    }
}

// ==========================================================================
// 4. Simplified Risk Assessment Submission (Navigates to Result Page)
// ==========================================================================
function initializeDiagnosticForm() {
    const form = document.getElementById('diagnosticForm');
    if (!form) return;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        await submitDiagnosticForm();
    });
}

async function submitDiagnosticForm() {
    const form = document.getElementById('diagnosticForm');
    const patientId = document.getElementById('patient_id')?.value || '';
    const fileInput = document.getElementById('scanFile');

    if (!patientId || patientId === '0') {
        showToast(tr('msg_select_patient', 'Please select a patient first'), 'warning');
        document.getElementById('patientSelector')?.focus();
        return;
    }

    if (!fileInput.files || fileInput.files.length === 0) {
        showToast(tr('msg_upload_scan', 'Please upload a medical scan image (X-Ray, CT, or MRI)'), 'warning');
        document.getElementById('dropzone')?.scrollIntoView({ behavior: 'smooth' });
        return;
    }

    const analyzeBtn = document.getElementById('analyzeBtn');
    if (analyzeBtn) {
        analyzeBtn.disabled = true;
        analyzeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + escapeHtml(tr('msg_running_assessment', 'Processing Assessment...'));
    }

    const formData = new FormData(form);
    const baseUrl = getBaseUrl();

    try {
        const response = await fetch(baseUrl + '/doctor/process_ai.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success && result.data && result.data.diagnostic_id) {
            showToast(tr('msg_risk_completed', 'Risk assessment completed. Redirecting to results...'), 'success');
            window.location.href = baseUrl + '/doctor/assessment_result.php?id=' + encodeURIComponent(result.data.diagnostic_id);
        } else {
            showToast(result.data?.message || result.message || tr('msg_risk_failed', 'Risk assessment failed'), 'error');
            if (analyzeBtn) {
                analyzeBtn.disabled = false;
                analyzeBtn.innerHTML = '<i class="bi bi-activity me-2"></i>' + escapeHtml(tr('msg_run_assessment', 'Run Risk Assessment'));
            }
        }
    } catch (error) {
        console.error('Diagnostic submission error:', error);
        showToast(tr('msg_risk_error', 'An error occurred during risk assessment. Please try again.'), 'error');
        if (analyzeBtn) {
            analyzeBtn.disabled = false;
            analyzeBtn.innerHTML = '<i class="bi bi-activity me-2"></i>' + escapeHtml(tr('msg_run_assessment', 'Run Risk Assessment'));
        }
    }
}

// ==========================================================================
// 5. Patient Info Auto-Populate
// ==========================================================================
function loadPatientInfo() {
    const selector = document.getElementById('patientSelector');
    const patientInfo = document.getElementById('patientInfo');
    const placeholder = document.getElementById('patientInfoPlaceholder');
    const patientId = document.getElementById('patient_id');

    if (!selector) return;
    const selectedOption = selector.options[selector.selectedIndex];

    if (selector.value && selectedOption) {
        const dob = selectedOption.getAttribute('data-dob');
        const bloodGroup = selectedOption.getAttribute('data-blood-group');
        const chronic = selectedOption.getAttribute('data-chronic');
        const contact = selectedOption.getAttribute('data-contact');

        if (patientId) patientId.value = selector.value;
        const age = calculateAge(dob);

        const patientAge = document.getElementById('patientAge');
        const patientBloodGroup = document.getElementById('patientBloodGroup');
        const patientChronic = document.getElementById('patientChronic');
        const patientContact = document.getElementById('patientContact');

        if (patientAge) patientAge.textContent = age !== '--' ? age + ' ' + tr('label_yrs', 'yrs') : '--';
        if (patientBloodGroup) patientBloodGroup.textContent = bloodGroup || '--';
        if (patientChronic) patientChronic.textContent = chronic || tr('none_reported', 'None reported');
        if (patientContact) patientContact.textContent = contact || tr('not_recorded', 'Not recorded');

        if (patientInfo) patientInfo.classList.remove('d-none');
        if (placeholder) placeholder.classList.add('d-none');
    } else {
        if (patientInfo) patientInfo.classList.add('d-none');
        if (placeholder) placeholder.classList.remove('d-none');
        if (patientId) patientId.value = '';
    }
}

function calculateAge(dob) {
    if (!dob) return '--';
    const birthDate = new Date(dob);
    if (isNaN(birthDate.getTime())) return '--';
    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();
    const monthDiff = today.getMonth() - birthDate.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
        age--;
    }
    return age;
}

// ==========================================================================
// 6. Prescription Management (Navigates back to Patient List on success)
// ==========================================================================
function addPrescriptionRow() {
    const tbody = document.querySelector('#prescriptionTable tbody');
    if (!tbody) return;

    prescriptionRowCount++;
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <input type="text" class="form-control-modern form-control-sm"
                   name="medications[${prescriptionRowCount}][drug_name]"
                   placeholder="${escapeHtml(tr('ph_med_name', 'Medication name'))}" required>
        </td>
        <td>
            <input type="text" class="form-control-modern form-control-sm"
                   name="medications[${prescriptionRowCount}][dosage]"
                   placeholder="${escapeHtml(tr('ph_dosage', 'e.g. 500mg'))}" required>
        </td>
        <td>
            <input type="text" class="form-control-modern form-control-sm"
                   name="medications[${prescriptionRowCount}][frequency]"
                   placeholder="${escapeHtml(tr('ph_frequency', 'Twice daily'))}" required>
        </td>
        <td>
            <input type="text" class="form-control-modern form-control-sm"
                   name="medications[${prescriptionRowCount}][duration]"
                   placeholder="${escapeHtml(tr('ph_duration', '7 days'))}" required>
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="removePrescriptionRow(this)" title="${escapeHtml(tr('btn_remove_row', 'Remove row'))}">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(row);
}

function removePrescriptionRow(button) {
    const row = button.closest('tr');
    const tbody = row.closest('tbody');
    if (tbody.rows.length > 1) {
        row.remove();
    } else {
        showToast(tr('msg_one_medication_required', 'At least one medication entry is required'), 'warning');
    }
}

async function savePrescription() {
    if (isSavingPrescription) return;

    const diagnosticId = document.getElementById('diagnosticId')?.value;
    const patientId = document.getElementById('patient_id')?.value;
    const doctorId = document.querySelector('[name="doctor_id"]')?.value || '';
    const clinicalAdvice = document.getElementById('clinicalAdvice')?.value || '';
    const followupDate = document.getElementById('followupDate')?.value || '';
    const saveBtn = document.getElementById('savePrescriptionBtn');

    if (!diagnosticId) {
        showToast(tr('msg_run_assessment_first', 'Missing diagnostic record for this prescription'), 'warning');
        return;
    }

    const medications = [];
    document.querySelectorAll('#prescriptionTable tbody tr').forEach(row => {
        const drugName = row.querySelector('[name$="[drug_name]"]')?.value.trim();
        const dosage = row.querySelector('[name$="[dosage]"]')?.value.trim();
        const frequency = row.querySelector('[name$="[frequency]"]')?.value.trim();
        const duration = row.querySelector('[name$="[duration]"]')?.value.trim();

        if (drugName && dosage && frequency && duration) {
            medications.push({ drug_name: drugName, dosage, frequency, duration });
        }
    });

    if (medications.length === 0) {
        showToast(tr('msg_complete_medication', 'Please complete at least one medication row'), 'warning');
        return;
    }

    isSavingPrescription = true;
    const originalBtnHtml = saveBtn ? saveBtn.innerHTML : '';
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + escapeHtml(tr('msg_saving', 'Saving...'));
    }

    const formData = new FormData();
    formData.append('csrf_token', document.querySelector('[name="csrf_token"]').value);
    formData.append('diagnostic_id', diagnosticId);
    formData.append('patient_id', patientId);
    formData.append('doctor_id', doctorId);
    formData.append('clinical_advice', clinicalAdvice);
    formData.append('followup_date', followupDate);

    medications.forEach((med, index) => {
        formData.append(`medications[${index}][drug_name]`, med.drug_name);
        formData.append(`medications[${index}][dosage]`, med.dosage);
        formData.append(`medications[${index}][frequency]`, med.frequency);
        formData.append(`medications[${index}][duration]`, med.duration);
    });

    try {
        const response = await fetch(getBaseUrl() + '/doctor/save_prescription.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            showToast(tr('msg_rx_saved', 'Prescription saved! Returning to patient list...'), 'success');
            const dest = (result.data && result.data.redirect_url)
                ? result.data.redirect_url
                : (getBaseUrl() + '/doctor/patients.php?rx_saved=1');
            window.location.href = dest;
        } else {
            showToast(result.data?.message || result.message || tr('msg_rx_failed', 'Failed to save prescription'), 'error');
            isSavingPrescription = false;
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalBtnHtml;
            }
        }
    } catch (error) {
        console.error('Error saving prescription:', error);
        showToast(tr('msg_rx_error', 'An error occurred while saving prescription'), 'error');
        isSavingPrescription = false;
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalBtnHtml;
        }
    }
}

// ==========================================================================
// 7. Appointment Booking (Patient)
// ==========================================================================
async function bookAppointment() {
    const form = document.getElementById('appointmentForm');
    if (!form) return;
    const formData = new FormData(form);

    try {
        const response = await fetch(getBaseUrl() + '/patient/book_appointment.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            showToast(result.data?.message || tr('msg_appt_booked', 'Appointment booked successfully!'), 'success');
            const modalEl = document.getElementById('appointmentModal');
            if (modalEl) {
                (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).hide();
            }
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(result.data?.message || result.message || tr('msg_appt_failed', 'Failed to book appointment'), 'error');
        }
    } catch (error) {
        console.error('Book Appointment Error:', error);
        showToast(tr('msg_appt_error', 'An error occurred while booking appointment'), 'error');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

// ==========================================================================
// 8. Toast Notifications
// ==========================================================================
function showToast(message, type = 'info') {
    let container = document.getElementById('toastPlacement');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastPlacement';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }

    let bgClass = 'bg-primary text-white';
    let iconClass = 'bi-info-circle-fill';

    if (type === 'success') {
        bgClass = 'bg-success text-white';
        iconClass = 'bi-check-circle-fill';
    } else if (type === 'error') {
        bgClass = 'bg-danger text-white';
        iconClass = 'bi-exclamation-triangle-fill';
    } else if (type === 'warning') {
        bgClass = 'bg-warning text-dark';
        iconClass = 'bi-exclamation-circle-fill';
    }

    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center ${bgClass} border-0 shadow-lg mb-2`;
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');

    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2">
                <i class="bi ${iconClass} fs-5"></i>
                <span>${escapeHtml(message)}</span>
            </div>
            <button type="button" class="btn-close ${type === 'warning' ? '' : 'btn-close-white'} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;

    container.appendChild(toastEl);
    const bsToast = new bootstrap.Toast(toastEl, { delay: 4000 });
    bsToast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

window.tr = tr;
window.getBaseUrl = getBaseUrl;
window.togglePasswordVisibility = togglePasswordVisibility;
window.sortTable = sortTable;
window.sortTableBySelect = sortTableBySelect;
window.loadSampleScan = loadSampleScan;
window.loadPatientInfo = loadPatientInfo;
window.clearImage = clearImage;
window.addPrescriptionRow = addPrescriptionRow;
window.removePrescriptionRow = removePrescriptionRow;
window.savePrescription = savePrescription;
window.showToast = showToast;
window.bookAppointment = bookAppointment;
