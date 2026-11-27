import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import { test } from 'node:test';
import { PGlite } from '@electric-sql/pglite';
import { drizzle } from 'drizzle-orm/pglite';
import type { DemoDatabase } from '../db/index';
import { createDemoApi } from '../netlify/server/api';
import { normalizeImage, sampleImage } from '../netlify/server/model';
import { futureDay } from '../netlify/server/workspace';

test('database migration and role-linked demo workflows', async (context) => {
    const engine = new PGlite();
    context.after(() => engine.close());
    const migrationsDir = new URL('../netlify/database/migrations/', import.meta.url);
    const migrations = (await readdir(migrationsDir, { withFileTypes: true })).filter((item) => item.isDirectory()).map((item) => item.name).sort();
    assert.ok(migrations.length);
    for (const migration of migrations) await engine.exec(await readFile(new URL(`${migration}/migration.sql`, migrationsDir), 'utf8'));
    const db = drizzle({ client: engine }) as unknown as DemoDatabase;
    const images = new Map<string, ArrayBuffer>();
    const app = createDemoApi({
        database: () => db,
        identity: async (request) => request.headers.get('X-Test-Identity'),
        sample: sampleImage,
        normalize: normalizeImage,
        predict: async () => [{ label: 'synthetic pattern', probability: 0.8 }],
        async saveImage(key, image) { images.set(key, Uint8Array.from(image).buffer); },
        async readImage(key) { return images.get(key) ?? null; },
        async deleteImage(key) { images.delete(key); },
    });
    const origin = 'https://medcore.example';
    const user = 'demo-test-alice';
    function request(path: string, role = 'patient', options: { method?: string; body?: unknown; actor?: string; origin?: string } = {}) {
        const form = options.body instanceof FormData;
        return app.request(`${origin}/api/${path}`, {
            method: options.method ?? (options.body ? 'POST' : 'GET'),
            headers: { 'X-Demo-Role': role, 'X-Test-Identity': options.actor ?? user, Origin: options.origin ?? origin,
                ...(options.body && !form ? { 'Content-Type': 'application/json' } : {}) },
            body: form ? options.body as FormData : options.body ? JSON.stringify(options.body) : undefined,
        });
    }
    async function json(response: Response, status = 200) {
        const result = await response.json();
        assert.equal(response.status, status, result.error);
        return result;
    }
    async function pdf(response: Response) {
        assert.equal(response.status, 200);
        assert.equal(response.headers.get('Content-Type'), 'application/pdf');
        assert.match(await response.text(), /^%PDF-/);
    }

    await json(await request('health', 'patient', { actor: '' }));
    await json(await request('workspace', 'patient', { actor: '' }), 401);
    const patientWorkspace = await json(await request('workspace'));
    const { patientId, doctorId } = patientWorkspace;
    assert.equal(patientWorkspace.appointments.length, 1);
    await json(await request(`people/${patientId}`, 'patient', { method: 'PATCH', body: { age: 42, synthetic: true }, origin: 'https://unrelated.example' }), 403);
    await json(await request(`people/${patientId}`, 'patient', { method: 'PATCH', body: { age: 42, synthetic: true } }));
    const entry = await json(await request('people', 'admin', { body: { name: 'New fictional patient', persona: 'patient', synthetic: true } }), 201);
    await json(await request(`people/${entry.id}`, 'admin', { method: 'PATCH', body: { archived: true, synthetic: true } }));
    const booking = { doctorId, day: futureDay(8), time: '11:00', reason: 'Fictional follow-up', synthetic: true };
    const visit = await json(await request('appointments', 'patient', { body: booking }), 201);
    await json(await request('appointments', 'patient', { body: booking }), 409);
    await json(await request(`appointments/${visit.id}`, 'patient', { method: 'PATCH', body: { status: 'confirmed' } }), 403);
    await json(await request(`appointments/${visit.id}`, 'admin', { method: 'PATCH', body: { status: 'confirmed' }, actor: 'demo-test-bob' }), 404);
    await json(await request(`appointments/${visit.id}`, 'doctor', { method: 'PATCH', body: { status: 'confirmed' } }));
    await pdf(await request(`appointments/${visit.id}/receipt`));

    const form = new FormData();
    form.set('patientId', patientId);
    form.set('source', 'sample');
    form.set('synthetic', 'true');
    form.set('bloodPressure', '120/80');
    const scan = await json(await request('assessments', 'doctor', { body: form }), 201);
    assert.equal(scan.imageKey, undefined);
    assert.equal(images.size, 1);
    assert.equal((await json(await request('workspace'))).assessments.length, 0);
    await json(await request(`assessments/${scan.id}/image`, 'doctor', { actor: 'demo-test-bob' }), 404);
    await json(await request(`assessments/${scan.id}/image`), 404);
    await json(await request(`assessments/${scan.id}`, 'doctor', { method: 'PATCH', body: { note: 'Synthetic demonstration reviewed.', synthetic: true } }));
    const image = await request(`assessments/${scan.id}/image`);
    assert.equal(image.headers.get('Content-Type'), 'image/png');
    assert.equal(image.status, 200);
    await pdf(await request(`assessments/${scan.id}/report`));

    await json(await request('prescriptions', 'doctor', { body: { patientId, synthetic: true, entries: [{ name: 'Demo item', directions: 'Simulation only' }] } }), 201);
    const workspace = await json(await request('workspace'));
    assert.equal(workspace.prescriptions.length, 1);
    assert.equal(workspace.assessments.length, 1);
    assert.equal(workspace.bills.length, 3);
    const invoice = workspace.bills.find((candidate: { appointmentId?: string }) => candidate.appointmentId === visit.id);
    await json(await request(`bills/${invoice.id}`, 'admin', { method: 'PATCH', body: { status: 'paid' } }));
    await pdf(await request(`bills/${invoice.id}/pdf`));
    const isolated = await json(await request('workspace', 'admin', { actor: 'demo-test-bob' }));
    assert.equal(isolated.prescriptions.length, 0);
    assert.notEqual(isolated.patientId, patientId);
});

test('image preprocessing rejects non-image bytes and emits a PNG sample', async () => {
    await assert.rejects(normalizeImage(Buffer.from('not an image')));
    const image = await normalizeImage(await sampleImage());
    assert.equal(image.subarray(1, 4).toString(), 'PNG');
    assert.equal(image.readUInt32BE(16), 224);
    assert.equal(image.readUInt32BE(20), 224);
});
