import { acceptInvite, getUser, handleAuthCallback, login, logout, requestPasswordRecovery, signup, updateUser } from '@netlify/identity';

const app = document.querySelector('#app');
const notice = document.querySelector('#notice');
const params = new URLSearchParams(location.search);
const roles = { patient: 'Patient', doctor: 'Doctor', admin: 'Administrator' };
const tabs = { overview: ['Overview', 'activity'], appointments: ['Appointments', 'calendar3'], profiles: ['Profiles', 'people'],
    assessments: ['Scan assessments', 'file-earmark-medical'], prescriptions: ['Prescriptions', 'clipboard2-pulse'], billing: ['Billing', 'receipt'] };
const currency = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 });
const money = (paise) => currency.format(paise / 100);
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
const isRole = (value) => Object.hasOwn(roles, value);
let role = isRole(params.get('role')) ? params.get('role') : 'patient';
let tab = 'overview';
let data;
let authView = params.get('view') === 'register' ? 'register' : 'login';
let invitation;
let pending = false;

function message(text = '', error = false) {
    notice.textContent = text;
    notice.hidden = !text;
    notice.classList.toggle('error', error);
    notice.setAttribute('role', error ? 'alert' : 'status');
}

async function api(path, { method = 'GET', body } = {}) {
    const form = body instanceof FormData;
    const response = await fetch(`/api/${path}`, { method, credentials: 'same-origin', headers: { 'X-Demo-Role': role,
        ...(body && !form ? { 'Content-Type': 'application/json' } : {}) }, body: body && !form ? JSON.stringify(body) : body });
    if (!response.ok) {
        const error = new Error((await response.json().catch(() => null))?.error ?? 'The demo service is unavailable. Try again shortly.');
        error.status = response.status;
        throw error;
    }
    return response;
}

async function loadWorkspace() {
    data = await (await api('workspace')).json();
    renderWorkspace();
}

function renderAuth(view = authView) {
    authView = view;
    const register = view === 'register';
    const recovery = view === 'recovery';
    const reset = view === 'reset' || view === 'invite';
    const title = register ? 'Create a demo account' : recovery ? 'Reset your password' : view === 'invite' ? 'Accept your invitation' : reset ? 'Set a new password' : 'Sign in to the demo';
    app.innerHTML = `<div class="auth-grid">
        <section class="auth-intro"><span class="eyebrow">Interactive healthcare demo</span><h1>One workspace, three perspectives.</h1>
        <p>Book a fictional visit, switch to the doctor to run the bundled scan model, then review the result or manage the bill. Each account starts with its own synthetic records.</p>
        <div class="role-list"><span>Patient portal</span><span>Doctor workstation</span><span>Admin console</span></div></section>
        <section class="demo-panel auth-panel"><h2>${title}</h2>
        <form class="demo-form" data-form="auth">
            ${reset ? '' : '<label>Email address<input name="email" type="email" autocomplete="email" required maxlength="254"></label>'}
            ${recovery ? '' : `<label>Password<input name="password" type="password" autocomplete="${register || reset ? 'new-password' : 'current-password'}" ${register || reset ? 'minlength="8"' : ''} required></label>`}
            <button class="demo-button primary" type="submit">${register ? 'Create account' : recovery ? 'Send reset link' : reset ? 'Save password' : 'Sign in'}</button>
        </form>
        ${view === 'login' ? '<button class="link-button" data-auth="register">Create an account</button><button class="link-button" data-auth="recovery">Forgot your password?</button>' : '<button class="link-button" data-auth="login">Back to sign in</button>'}
        </section></div>`;
}

const confirmSynthetic = '<label class="confirm"><input type="checkbox" name="synthetic" required> I am using fictional information and synthetic images only.</label>';
const submit = (label) => `<button class="demo-button primary" type="submit">${label}</button>`;
const action = (label, operation, id, value = '') => `<button type="button" class="demo-button" data-action="${operation}" data-id="${escape(id)}" data-value="${escape(value)}">${label}</button>`;
const panel = (title, content) => `<section class="demo-panel"><h2>${title}</h2>${content}</section>`;
const list = (items, empty) => items.length ? `<ul class="demo-list">${items.map((item) => `<li>${item}</li>`).join('')}</ul>` : `<p class="muted">${empty}</p>`;
const status = (value) => `<span class="state ${escape(value)}">${escape(value)}</span>`;
const person = (id) => escape(data.people.find((entry) => entry.id === id)?.name ?? 'Fictional profile');
const active = (kind) => data.people.filter((entry) => entry.persona === kind && !entry.archivedAt);
const options = (items) => `<option value="">Select a fictional profile</option>${items.map((item) => `<option value="${escape(item.id)}">${escape(item.name)}${item.specialty ? ` · ${escape(item.specialty)}` : ''}</option>`).join('')}`;
const timestamp = (value) => new Date(value).toLocaleDateString('en-IN', { dateStyle: 'medium' });
const patientChoices = () => active('patient').filter((item) => data.appointments.some((visit) => item.id === visit.patientId && ['confirmed', 'completed'].includes(visit.status)));

function overview() {
    const items = [['Appointments', data.appointments.length, 'appointments'], ['Scan assessments', data.assessments.length, 'assessments'],
        ['Prescriptions', data.prescriptions.length, 'prescriptions'], ['Unpaid demo bills', money(data.bills.filter((invoice) => invoice.status === 'unpaid').reduce((total, invoice) => total + invoice.amountPaise, 0)), 'billing']];
    return `<div class="summary-grid">${items.map(([name, number, target]) => panel(name, `<strong class="summary-value">${number}</strong><button class="link-button" data-tab="${target}">Open ${name.toLowerCase().replace('unpaid demo bills', 'billing')}</button>`)).join('')}</div>
    ${panel('Try the end-to-end workflow', '<p>As the patient, book a visit with Dr. Nikhil Rao. Switch to the doctor to confirm it, run the synthetic scan sample, and publish a review. In the administrator portal, mark the resulting demo bills as paid.</p><p class="muted">Role switching only changes the view inside your own fictional workspace. It grants no access to other accounts.</p>')}`;
}

function appointments() {
    const canBook = role !== 'doctor';
    const schedule = ['08', '09', '10', '11', '12', '13', '14', '15', '16', '17'].flatMap((hour) => ['00', '30'].map((minute) => `${hour}:${minute}`));
    const booking = `<form class="demo-form" data-form="book">
        ${role === 'admin' ? `<label>Patient<select name="patientId" required>${options(active('patient'))}</select></label>` : ''}
        <label>Doctor<select name="doctorId" required>${options(active('doctor'))}</select></label>
        <label>Date<input type="date" name="day" min="${escape(data.today)}" required></label>
        <label>Time<select name="time">${schedule.map((slot) => `<option>${slot}</option>`).join('')}</select></label>
        <label>Fictional reason<input name="reason" maxlength="300" placeholder="Demo follow-up" required></label>
        ${confirmSynthetic}${submit('Request appointment')}</form>`;
    const visits = data.appointments.map((visit) => {
        const actions = visit.status === 'pending'
            ? `${role !== 'patient' ? action('Confirm', 'appointment', visit.id, 'confirmed') : ''}${action('Cancel', 'appointment', visit.id, 'cancelled')}`
            : ['confirmed', 'completed'].includes(visit.status) ? `${action('Receipt PDF', 'receipt', visit.id)}${role !== 'patient' && visit.status === 'confirmed' ? action('Mark complete', 'appointment', visit.id, 'completed') : ''}` : '';
        return `<div class="item-head"><strong>${escape(visit.day)} · ${escape(visit.time)}</strong>${status(visit.status)}</div>
            <div class="muted">${person(visit.patientId)} · ${person(visit.doctorId)}<br>${escape(visit.reason)}</div>${actions ? `<div class="actions">${actions}</div>` : ''}`;
    });
    return `${canBook ? panel('Request an appointment', booking) : ''}${panel('Appointments', list(visits, 'No appointments to show.'))}`;
}

function profiles() {
    const profile = (entry) => `<form class="demo-form" data-form="profile" data-id="${escape(entry.id)}">
        <label>Fictional name<input name="name" maxlength="80" value="${escape(entry.name)}" required></label>
        ${entry.persona === 'patient' ? `<label>Age<input name="age" type="number" min="0" max="125" value="${escape(entry.age)}"></label>` : `<label>Specialty<input name="specialty" maxlength="80" value="${escape(entry.specialty)}"></label><label>Consultation fee (INR)<input name="fee" type="number" min="0" max="100000" step="0.01" value="${entry.feePaise / 100}"></label>`}
        <label>Fictional notes<textarea name="notes" maxlength="500">${escape(entry.notes)}</textarea></label>${confirmSynthetic}${submit('Save profile')}</form>`;
    if (role === 'patient') return panel('Your fictional profile', profile(data.people.find((entry) => entry.id === data.patientId)));
    const entries = (role === 'doctor' ? patientChoices() : data.people).map((entry) => `<div class="item-head"><strong>${escape(entry.name)}</strong>${status(entry.archivedAt ? 'archived' : entry.persona)}</div>
        <div class="muted">${escape(entry.specialty ?? (entry.age == null ? '' : `Age ${entry.age}`))}${entry.notes ? ` · ${escape(entry.notes)}` : ''}</div>
        ${role === 'admin' ? `<details class="demo-details"><summary>Edit profile</summary>${profile(entry)}</details>${entry.demoKey ? '' : `<div class="actions">${action(entry.archivedAt ? 'Restore' : 'Archive', 'archive', entry.id, entry.archivedAt ? 'false' : 'true')}</div>`}` : ''}`);
    const create = `<form class="demo-form" data-form="person"><label>Profile type<select name="persona"><option value="patient">Patient</option><option value="doctor">Doctor</option></select></label>
        <label>Fictional name<input name="name" maxlength="80" required></label><label>Specialty (for doctors)<input name="specialty" maxlength="80"></label>
        <label>Consultation fee in INR (for doctors)<input name="fee" type="number" min="0" max="100000" step="0.01"></label>${confirmSynthetic}${submit('Add profile')}</form>`;
    return `${role === 'admin' ? panel('Add a fictional profile', create) : ''}${panel(role === 'doctor' ? 'Assigned patients' : 'Workspace directory', list(entries, 'No fictional patients are assigned yet.'))}`;
}

function assessments() {
    const choices = patientChoices();
    const run = choices.length ? `<form class="demo-form" data-form="scan">
        <label>Patient<select name="patientId" required>${options(choices)}</select></label><label>Image source<select name="source" id="scan-source"><option value="sample">Built-in synthetic sample</option><option value="upload">Upload a synthetic PNG or JPEG</option></select></label>
        <label id="scan-file" hidden>Synthetic image (max 5 MB)<input name="file" type="file" accept="image/png,image/jpeg" disabled></label>
        <div class="actions">${action('Preview the synthetic sample', 'sample', '')}</div><div id="image-sample"></div>
        <div class="split-panels"><label>Fictional blood pressure (optional)<input name="bloodPressure" placeholder="120/80" pattern="[0-9]{2,3}/[0-9]{2,3}"></label><label>Fictional glucose (optional)<input name="glucose" type="number" min="1" max="1000"></label></div>
        <p class="demo-note">The model classifies patterns in the synthetic image. Scores are not a diagnosis, risk estimate, or treatment recommendation.</p>${confirmSynthetic}${submit('Run bundled model')}</form>` : '<p class="muted">Confirm an appointment before running an assessment for that fictional patient.</p>';
    const scans = data.assessments.map((scan) => `<div class="item-head"><strong>${person(scan.patientId)} · ${timestamp(scan.createdAt)}</strong>${status(scan.reviewedAt ? 'reviewed' : 'pending')}</div>
        <div class="muted">${scan.source === 'sample' ? 'Built-in synthetic sample' : 'Uploaded synthetic image'}</div>
        <ul>${scan.predictions.map(({ label, probability }) => `<li>${escape(label.replaceAll('_', ' '))} · ${(probability * 100).toFixed(1)}% model score</li>`).join('')}</ul>
        ${scan.doctorNote ? `<p class="demo-note">${escape(scan.doctorNote)}</p>` : ''}
        <div class="actions">${action('Show synthetic image', 'image', scan.id)}${action('Report PDF', 'report', scan.id)}</div><div id="image-${escape(scan.id)}"></div>
        ${role === 'doctor' && !scan.reviewedAt ? `<details class="demo-details"><summary>Publish review to patient</summary><form class="demo-form" data-form="review" data-id="${escape(scan.id)}"><label>Fictional review note<textarea name="note" maxlength="500" required></textarea></label>${confirmSynthetic}${submit('Publish review')}</form></details>` : ''}`);
    return `${role === 'doctor' ? panel('Run a synthetic scan', run) : ''}${panel('Scan assessments', list(scans, role === 'patient' ? 'Reviewed assessments appear here after the doctor publishes them.' : 'No assessments yet.'))}`;
}

const medicationFields = () => '<fieldset class="medication-fields"><legend>Fictional medication entry</legend><label>Item<input name="medication" maxlength="80" placeholder="Demo Medication A" required></label><label>Fictional directions<input name="directions" maxlength="160" placeholder="For demonstration only" required></label></fieldset>';

function prescriptions() {
    const create = patientChoices().length ? `<form class="demo-form" data-form="prescribe"><label>Patient<select name="patientId" required>${options(patientChoices())}</select></label>
        <div id="medications" class="demo-form">${medicationFields()}</div><div class="actions">${action('Add another entry', 'add-entry', '')}</div><label>Fictional notes<textarea name="notes" maxlength="500"></textarea></label>${confirmSynthetic}${submit('Save demo prescription')}</form>` : '<p class="muted">Confirm an appointment to add a prescription for that fictional patient.</p>';
    const records = data.prescriptions.map((record) => `<strong>${person(record.patientId)} · ${timestamp(record.createdAt)}</strong><div class="muted">${person(record.doctorId)}</div>
        <ul>${record.entries.map((entry) => `<li>${escape(entry.name)} — ${escape(entry.directions)}</li>`).join('')}</ul>${record.notes ? `<p>${escape(record.notes)}</p>` : ''}`);
    return `${role === 'doctor' ? panel('Write a fictional prescription', create) : ''}${panel('Prescription history', list(records, 'No fictional prescriptions yet.'))}`;
}

function billing() {
    const items = data.bills.map((invoice) => `<div class="item-head"><strong>${escape(invoice.description)} · ${money(invoice.amountPaise)}</strong>${status(invoice.status)}</div>
        <div class="muted">${person(invoice.patientId)} · ${person(invoice.doctorId)} · ${timestamp(invoice.createdAt)}</div>
        <div class="actions">${action('Bill PDF', 'bill', invoice.id)}${role === 'admin' ? action(invoice.status === 'paid' ? 'Mark unpaid' : 'Mark paid', 'bill-status', invoice.id, invoice.status === 'paid' ? 'unpaid' : 'paid') : ''}</div>`);
    return panel('Demo billing', `<p class="muted">No real payment is collected. Status changes are for testing only.</p>${list(items, 'There are no demo bills yet.')}`);
}

function renderWorkspace() {
    const views = { overview, appointments, profiles, assessments, prescriptions, billing };
    app.innerHTML = `<div class="workspace">
        <aside class="workspace-sidebar"><label for="role">Demo role</label><select id="role">${Object.entries(roles).map(([key, label]) => `<option value="${key}" ${role === key ? 'selected' : ''}>${label}</option>`).join('')}</select>
        <nav class="workspace-nav" aria-label="Workspace">${Object.entries(tabs).map(([key, [label, icon]]) => `<button data-tab="${key}" ${key === tab ? 'aria-current="page"' : ''}><i class="bi bi-${icon}" aria-hidden="true"></i>${label}</button>`).join('')}</nav>
        <button class="link-button" data-auth="logout">Sign out</button></aside>
        <div class="workspace-content"><header class="workspace-heading"><span class="eyebrow">${roles[role]} portal</span><h1>${tabs[tab][0]}</h1><p class="muted">Your private, synthetic demo workspace</p></header>${views[tab]()}</div></div>`;
}

function values(form) {
    const fields = new FormData(form);
    const json = Object.fromEntries(fields);
    if (fields.has('synthetic')) json.synthetic = true;
    for (const key of ['age', 'specialty', 'patientId']) if (json[key] === '') delete json[key];
    if (json.age !== undefined) json.age = Number(json.age);
    if (json.fee) json.feePaise = Math.round(Number(json.fee) * 100);
    delete json.fee;
    return { fields, json };
}

async function visitAuth(fields) {
    const email = fields.get('email');
    const password = fields.get('password');
    if (authView === 'recovery') {
        await requestPasswordRecovery(email);
        renderAuth('login');
        return message('Check your email for a recovery link.');
    }
    if (authView === 'reset') {
        await updateUser({ password });
        await loadWorkspace();
        return message('Password updated.');
    }
    if (authView === 'invite' && invitation) {
        await acceptInvite(invitation, password);
        invitation = undefined;
        return loadWorkspace();
    }
    if (authView === 'register') {
        const user = await signup(email, password);
        if (!user.emailVerified) {
            renderAuth('login');
            return message('Check your email for a confirmation link, then return here to sign in.');
        }
    } else {
        await login(email, password);
    }
    await loadWorkspace();
}

async function runTask(element, operation) {
    if (pending) return;
    pending = true;
    element.disabled = true;
    app.setAttribute('aria-busy', 'true');
    message();
    try {
        await operation();
    } catch (error) {
        if (error.status === 401 && data) { data = undefined; renderAuth('login'); }
        message(error.message || 'Something went wrong. Try again.', true);
    } finally {
        element.disabled = false;
        app.setAttribute('aria-busy', 'false');
        pending = false;
    }
}

app.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-form]');
    if (!form) return;
    event.preventDefault();
    void runTask(event.submitter ?? form.querySelector('button[type=submit]'), async () => {
        const { fields, json } = values(form);
        if (form.dataset.form === 'auth') return visitAuth(fields);
        if (form.dataset.form === 'scan') {
            fields.set('synthetic', 'true');
            await api('assessments', { method: 'POST', body: fields });
        } else {
            const paths = { book: 'appointments', person: 'people', profile: `people/${form.dataset.id}`, review: `assessments/${form.dataset.id}`, prescribe: 'prescriptions' };
            if (form.dataset.form === 'prescribe') {
                const directions = fields.getAll('directions');
                json.entries = fields.getAll('medication').map((name, index) => ({ name, directions: directions[index] }));
                delete json.medication;
                delete json.directions;
            }
            await api(paths[form.dataset.form], { method: ['profile', 'review'].includes(form.dataset.form) ? 'PATCH' : 'POST', body: json });
        }
        await loadWorkspace();
        message(form.dataset.form === 'scan' ? 'The bundled model finished. The assessment is ready for review.' : 'Saved in your demo workspace.');
    });
});

async function displayFile(button, operation, id) {
    const files = { receipt: [`appointments/${id}/receipt`, 'demo-appointment.pdf'], bill: [`bills/${id}/pdf`, 'demo-bill.pdf'], report: [`assessments/${id}/report`, 'demo-assessment.pdf'] };
    const image = operation === 'image' || operation === 'sample';
    const [path, name] = image ? [operation === 'sample' ? 'sample' : `assessments/${id}/image`] : files[operation];
    const url = URL.createObjectURL(await (await api(path)).blob());
    if (image) {
        const element = document.createElement('img');
        element.className = 'demo-scan';
        element.alt = 'Synthetic demo scan';
        element.onload = element.onerror = () => URL.revokeObjectURL(url);
        element.src = url;
        document.querySelector(`#image-${id || 'sample'}`).replaceChildren(element);
        button.textContent = 'Reload synthetic image';
    } else {
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
}

app.addEventListener('click', (event) => {
    const button = event.target.closest('button');
    if (!button || pending) return;
    const { tab: nextTab, auth, action: operation, id, value } = button.dataset;
    if (nextTab) { tab = nextTab; message(); renderWorkspace(); app.querySelector(`[data-tab="${tab}"]`)?.focus(); return; }
    if (auth && auth !== 'logout') { message(); renderAuth(auth); app.querySelector('input')?.focus(); return; }
    if (auth === 'logout') { void runTask(button, async () => { await logout(); data = undefined; role = 'patient'; tab = 'overview'; renderAuth('login'); }); return; }
    if (operation === 'add-entry') {
        const fields = document.querySelector('#medications');
        if (fields.children.length < 10) { fields.insertAdjacentHTML('beforeend', medicationFields()); fields.lastElementChild.querySelector('input').focus(); }
        button.disabled = fields.children.length >= 10;
        return;
    }
    if (!operation) return;
    void runTask(button, async () => {
        if (['receipt', 'bill', 'report', 'image', 'sample'].includes(operation)) return displayFile(button, operation, id);
        if (operation === 'appointment') await api(`appointments/${id}`, { method: 'PATCH', body: { status: value } });
        if (operation === 'bill-status') await api(`bills/${id}`, { method: 'PATCH', body: { status: value } });
        if (operation === 'archive') await api(`people/${id}`, { method: 'PATCH', body: { synthetic: true, archived: value === 'true' } });
        await loadWorkspace();
        message('Updated in your demo workspace.');
    });
});

app.addEventListener('change', (event) => {
    const element = event.target;
    if (element.id === 'role' && isRole(element.value)) {
        const previous = role;
        void runTask(element, async () => {
            role = element.value;
            try { await loadWorkspace(); } catch (error) { role = previous; element.value = previous; throw error; }
            app.querySelector('#role')?.focus();
        });
    }
    if (element.id === 'scan-source') {
        const input = document.querySelector('#scan-file');
        input.hidden = element.value !== 'upload';
        input.firstElementChild.required = !input.hidden;
        input.firstElementChild.disabled = input.hidden;
    }
});

try {
    const callback = await handleAuthCallback();
    if (callback?.type === 'recovery') renderAuth('reset');
    else if (callback?.type === 'invite' && callback.token) { invitation = callback.token; renderAuth('invite'); }
    else if (await getUser()) await loadWorkspace();
    else renderAuth();
} catch (error) {
    renderAuth('login');
    message(error.message || 'Unable to open the demo.', true);
} finally {
    app.setAttribute('aria-busy', 'false');
}
