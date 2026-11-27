import { randomUUID } from 'node:crypto';
import { and, desc, eq, gte, isNull, ne } from 'drizzle-orm';
import { Hono, type Context } from 'hono';
import { bodyLimit } from 'hono/body-limit';
import { HTTPException } from 'hono/http-exception';
import { z } from 'zod';
import type { DemoDatabase } from '../../db/index';
import { appointments, assessments, bills, people, prescriptions, type Prediction } from '../../db/schema';
import { makeDocument } from './documents';
import { futureDay, ensureWorkspace, patientAssigned } from './workspace';

const roles = z.enum(['patient', 'doctor', 'admin']);
type Role = z.infer<typeof roles>;
type Viewer = { ownerId: string; role: Role; workspace: Awaited<ReturnType<typeof ensureWorkspace>> };
type Env = { Variables: { viewer: Viewer; db: DemoDatabase } };

export interface Services {
    database: () => DemoDatabase;
    identity: (request: Request) => Promise<string | null>;
    sample: () => Promise<Buffer>;
    normalize: (image: Buffer) => Promise<Buffer>;
    predict: (image: Buffer) => Promise<Prediction[]>;
    saveImage: (key: string, image: Buffer) => Promise<void>;
    readImage: (key: string) => Promise<ArrayBuffer | null>;
    deleteImage: (key: string) => Promise<void>;
}

const uuid = z.uuid();
const shortText = z.string().trim().min(1).max(80);
const notes = z.string().trim().max(500);
const age = z.number().int().min(0).max(125);
const fee = z.number().int().min(0).max(10_000_000);
const syntheticOnly = z.literal(true);
const maxImageBytes = 5 * 1024 ** 2;
const publicAssessment = ({ imageKey, ...record }: typeof assessments.$inferSelect) => record;

function fail(status: 400 | 401 | 403 | 404 | 409 | 413 | 422 | 429 | 502, message: string): never {
    throw new HTTPException(status, { message });
}

function parse<T>(schema: z.ZodType<T>, value: unknown): T {
    const result = schema.safeParse(value);
    if (!result.success) fail(422, 'Check the required fields and try again.');
    return result.data;
}

async function body<T>(context: Context<Env>, schema: z.ZodType<T>) {
    const value = await context.req.json().catch(() => fail(400, 'Expected a JSON request.'));
    return parse(schema, value);
}

function requireRole(viewer: Viewer, ...allowed: Role[]) {
    if (!allowed.includes(viewer.role)) fail(403, 'This action is unavailable in this portal.');
}

function visible(viewer: Viewer, record: { patientId: string; doctorId: string }) {
    return viewer.role === 'admin' || (viewer.role === 'patient' ? record.patientId === viewer.workspace.patient.id : record.doctorId === viewer.workspace.doctor.id);
}

function nameFor(viewer: Viewer, id: string) {
    return viewer.workspace.directory.find((person) => person.id === id)?.name ?? 'Demo record';
}

function imageResponse(data: ArrayBuffer) {
    return new Response(data, { headers: { 'Content-Type': 'image/png', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' } });
}

async function pdfResponse(filename: string, title: string, lines: string[]) {
    return new Response(Uint8Array.from(await makeDocument(title, lines)).buffer, { headers: {
        'Content-Type': 'application/pdf', 'Content-Disposition': `attachment; filename="${filename}.pdf"`, 'Cache-Control': 'no-store',
    } });
}

export function createDemoApi(services: Services) {
    const app = new Hono<Env>();
    app.onError((error, context) => {
        if (error instanceof HTTPException) return context.json({ error: error.message }, error.status);
        const databaseError = (error.cause ?? error) as { code?: string };
        if (databaseError.code === '23505') return context.json({ error: 'This time slot or record already exists.' }, 409);
        console.error('Demo request failed', { route: context.req.routePath, name: error.name });
        return context.json({ error: 'The demo could not complete this request. Please try again.' }, 500);
    });
    app.notFound((context) => context.json({ error: 'This demo route does not exist.' }, 404));
    app.use('/api/*', async (context, next) => {
        context.header('Cache-Control', 'private, no-store');
        context.header('X-Content-Type-Options', 'nosniff');
        if (context.req.method !== 'GET' && context.req.method !== 'HEAD' && context.req.header('origin') !== new URL(context.req.url).origin) {
            fail(403, 'Requests must come from this demo site.');
        }
        await next();
    });
    app.get('/api/health', (context) => context.json({ status: 'ok', mode: 'synthetic-demo' }));
    app.use('/api/*', bodyLimit({ maxSize: maxImageBytes + 64 * 1024, onError: () => fail(413, 'Choose an image smaller than 5 MB.') }));
    app.use('/api/*', async (context, next) => {
        const ownerId = await services.identity(context.req.raw);
        if (!ownerId) fail(401, 'Sign in to open your demo workspace.');
        const role = parse(roles, context.req.header('x-demo-role') ?? 'patient');
        const db = services.database();
        const workspace = await ensureWorkspace(db, ownerId);
        context.set('viewer', { ownerId, role, workspace });
        context.set('db', db);
        await next();
    });

    app.get('/api/workspace', async (context) => {
        const db = context.get('db');
        const viewer = context.get('viewer');
        const { ownerId, role, workspace } = viewer;
        const [visits, scans, medication, invoices] = await Promise.all([
            db.select().from(appointments).where(eq(appointments.ownerId, ownerId)).orderBy(desc(appointments.day), desc(appointments.time)),
            db.select().from(assessments).where(eq(assessments.ownerId, ownerId)).orderBy(desc(assessments.createdAt)),
            db.select().from(prescriptions).where(eq(prescriptions.ownerId, ownerId)).orderBy(desc(prescriptions.createdAt)),
            db.select().from(bills).where(eq(bills.ownerId, ownerId)).orderBy(desc(bills.createdAt)),
        ]);
        const ownVisits = visits.filter((visit) => visible(viewer, visit));
        const directory = workspace.directory.filter((person) => {
            if (role === 'admin') return true;
            if (role === 'doctor') return person.id === workspace.doctor.id || ownVisits.some((visit) => visit.patientId === person.id);
            return person.id === workspace.patient.id || person.persona === 'doctor';
        });
        return context.json({ role, patientId: workspace.patient.id, doctorId: workspace.doctor.id, today: futureDay(),
            people: directory, appointments: ownVisits, bills: invoices.filter((invoice) => visible(viewer, invoice)),
            assessments: scans.filter((scan) => visible(viewer, scan) && (role !== 'patient' || scan.reviewedAt)).map(publicAssessment),
            prescriptions: medication.filter((record) => visible(viewer, record)),
        });
    });

    app.post('/api/people', async (context) => {
        const viewer = context.get('viewer');
        requireRole(viewer, 'admin');
        const data = await body(context, z.object({ persona: z.enum(['patient', 'doctor']), name: shortText,
            age: age.optional(), specialty: shortText.optional(), feePaise: fee.optional(), synthetic: syntheticOnly }));
        const [person] = await context.get('db').insert(people).values({ ownerId: viewer.ownerId,
            persona: data.persona, name: data.name, age: data.persona === 'patient' ? data.age : null,
            specialty: data.persona === 'doctor' ? data.specialty : null, feePaise: data.persona === 'doctor' ? data.feePaise : 0,
        }).returning();
        return context.json(person, 201);
    });

    app.patch('/api/people/:id', async (context) => {
        const viewer = context.get('viewer');
        requireRole(viewer, 'admin', 'patient');
        const id = parse(uuid, context.req.param('id'));
        const person = viewer.workspace.directory.find((candidate) => candidate.id === id);
        if (!person || (viewer.role === 'patient' && id !== viewer.workspace.patient.id)) fail(404, 'Profile not found.');
        const data = await body(context, z.object({ name: shortText.optional(), age: age.optional(), notes: notes.optional(),
            specialty: shortText.optional(), feePaise: fee.optional(), archived: z.boolean().optional(), synthetic: syntheticOnly }));
        if (data.archived !== undefined && (viewer.role !== 'admin' || person.demoKey)) fail(403, 'Core demo profiles cannot be archived.');
        const changes = { name: data.name ?? person.name, age: person.persona === 'patient' ? data.age ?? person.age : null,
            notes: data.notes ?? person.notes, specialty: person.persona === 'doctor' && viewer.role === 'admin' ? data.specialty ?? person.specialty : person.specialty,
            feePaise: person.persona === 'doctor' && viewer.role === 'admin' ? data.feePaise ?? person.feePaise : person.feePaise,
            archivedAt: data.archived === undefined ? person.archivedAt : data.archived ? new Date() : null };
        const [updated] = await context.get('db').update(people).set(changes).where(and(eq(people.id, id), eq(people.ownerId, viewer.ownerId))).returning();
        return context.json(updated);
    });

    app.post('/api/appointments', async (context) => {
        const viewer = context.get('viewer');
        requireRole(viewer, 'patient', 'admin');
        const data = await body(context, z.object({ doctorId: uuid, patientId: uuid.optional(), day: z.iso.date(),
            time: z.string().regex(/^(0[89]|1[0-7]):(00|30)$/), reason: z.string().trim().min(1).max(300), synthetic: syntheticOnly }));
        if (data.day < futureDay() || data.day > futureDay(365)) fail(422, 'Pick a date within the next year.');
        const directory = viewer.workspace.directory;
        const doctor = directory.find((person) => person.id === data.doctorId && person.persona === 'doctor' && !person.archivedAt);
        const patient = viewer.role === 'patient' ? viewer.workspace.patient : directory.find((person) => person.id === data.patientId && person.persona === 'patient' && !person.archivedAt);
        if (!doctor || !patient) fail(404, 'Choose an active fictional doctor and patient.');
        const [visit] = await context.get('db').insert(appointments).values({ ownerId: viewer.ownerId, patientId: patient.id,
            doctorId: doctor.id, day: data.day, time: data.time, reason: data.reason }).returning();
        return context.json(visit, 201);
    });

    app.patch('/api/appointments/:id', async (context) => {
        const viewer = context.get('viewer');
        const id = parse(uuid, context.req.param('id'));
        const { status } = await body(context, z.object({ status: z.enum(['confirmed', 'completed', 'cancelled']) }));
        const updated = await context.get('db').transaction(async (transaction) => {
            const [visit] = await transaction.select().from(appointments).where(and(eq(appointments.id, id), eq(appointments.ownerId, viewer.ownerId))).for('update');
            if (!visit || !visible(viewer, visit)) fail(404, 'Appointment not found.');
            const from = visit.status;
            if (viewer.role === 'patient' && status !== 'cancelled') fail(403, 'Only the clinical or admin portal can confirm or complete appointments.');
            if (!(from === 'pending' && (status === 'confirmed' || status === 'cancelled')) && !(from === 'confirmed' && status === 'completed')) {
                fail(409, 'That appointment status change is not available.');
            }
            const [result] = await transaction.update(appointments).set({ status }).where(and(eq(appointments.id, id), eq(appointments.ownerId, viewer.ownerId))).returning();
            if (status === 'confirmed') {
                const doctor = viewer.workspace.directory.find((person) => person.id === visit.doctorId);
                if (!doctor || doctor.archivedAt) fail(409, 'The assigned doctor is no longer active.');
                await transaction.insert(bills).values({ ownerId: viewer.ownerId, patientId: visit.patientId, doctorId: visit.doctorId,
                    appointmentId: id, description: 'Demo consultation', amountPaise: doctor.feePaise }).onConflictDoNothing();
            }
            return result;
        });
        return context.json(updated);
    });

    app.patch('/api/bills/:id', async (context) => {
        const viewer = context.get('viewer');
        requireRole(viewer, 'admin');
        const id = parse(uuid, context.req.param('id'));
        const { status } = await body(context, z.object({ status: z.enum(['paid', 'unpaid']) }));
        const [invoice] = await context.get('db').update(bills).set({ status }).where(and(eq(bills.id, id), eq(bills.ownerId, viewer.ownerId))).returning();
        if (!invoice) fail(404, 'Bill not found.');
        return context.json(invoice);
    });

    app.get('/api/sample', async (context) => {
        requireRole(context.get('viewer'), 'doctor');
        return imageResponse(Uint8Array.from(await services.sample()).buffer);
    });

    app.post('/api/assessments', async (context) => {
        const viewer = context.get('viewer');
        requireRole(viewer, 'doctor');
        const db = context.get('db');
        const form = await context.req.formData().catch(() => fail(400, 'Expected scan form data.'));
        if (form.get('synthetic') !== 'true') fail(422, 'Confirm that this image and its notes are synthetic.');
        const patientId = parse(uuid, form.get('patientId'));
        const doctorId = viewer.workspace.doctor.id;
        if (!(await patientAssigned(db, viewer.ownerId, doctorId, patientId))) fail(404, 'Select a patient with a confirmed or completed visit.');
        const today = new Date(`${futureDay()}T00:00:00Z`);
        const count = await db.$count(assessments, and(eq(assessments.ownerId, viewer.ownerId), gte(assessments.createdAt, today)));
        if (count >= 12) fail(429, 'This workspace has reached today’s limit of 12 demo assessments.');
        const useSample = form.get('source') === 'sample';
        const file = form.get('file');
        if (!useSample && (!(file instanceof File) || file.size < 1 || file.size > maxImageBytes)) fail(422, 'Choose a synthetic PNG or JPEG under 5 MB.');
        const bytes = useSample ? await services.sample() : Buffer.from(await (file as File).arrayBuffer());
        const bloodPressure = form.get('bloodPressure');
        const glucose = form.get('glucose');
        const vitals = { bloodPressure: bloodPressure ? parse(z.string().regex(/^\d{2,3}\/\d{2,3}$/), bloodPressure) : undefined,
            glucose: glucose ? parse(z.coerce.number().int().min(1).max(1000), glucose) : undefined };
        const image = await services.normalize(bytes).catch(() => fail(422, 'Choose a valid synthetic PNG or JPEG under 16 megapixels.'));
        const predictions = await services.predict(image).catch(() => fail(502, 'The bundled model is unavailable. No assessment was created.'));
        const imageKey = `${viewer.ownerId}/${randomUUID()}.png`;
        await services.saveImage(imageKey, image);
        try {
            const [record] = await db.insert(assessments).values({ ownerId: viewer.ownerId, patientId, doctorId, imageKey,
                source: useSample ? 'sample' : 'upload', vitals, predictions }).returning();
            return context.json(publicAssessment(record), 201);
        } catch (error) {
            await services.deleteImage(imageKey).catch(() => undefined);
            throw error;
        }
    });

    app.patch('/api/assessments/:id', async (context) => {
        const viewer = context.get('viewer');
        requireRole(viewer, 'doctor');
        const id = parse(uuid, context.req.param('id'));
        const { note } = await body(context, z.object({ note: notes.min(1), synthetic: syntheticOnly }));
        const result = await context.get('db').transaction(async (transaction) => {
            const [record] = await transaction.update(assessments).set({ doctorNote: note, reviewedAt: new Date() }).where(and(
                eq(assessments.ownerId, viewer.ownerId), eq(assessments.id, id), eq(assessments.doctorId, viewer.workspace.doctor.id), isNull(assessments.reviewedAt),
            )).returning();
            if (!record) fail(404, 'Unreviewed assessment not found.');
            await transaction.insert(bills).values({ ownerId: viewer.ownerId, patientId: record.patientId, doctorId: record.doctorId,
                assessmentId: id, description: 'Demo scan review', amountPaise: 120000 }).onConflictDoNothing();
            return record;
        });
        return context.json(publicAssessment(result));
    });

    app.post('/api/prescriptions', async (context) => {
        const viewer = context.get('viewer');
        requireRole(viewer, 'doctor');
        const data = await body(context, z.object({ patientId: uuid, notes: notes.optional(), synthetic: syntheticOnly,
            entries: z.array(z.object({ name: shortText, directions: z.string().trim().min(1).max(160) })).min(1).max(10) }));
        const db = context.get('db');
        const doctorId = viewer.workspace.doctor.id;
        if (!(await patientAssigned(db, viewer.ownerId, doctorId, data.patientId))) fail(404, 'Select a patient with a confirmed or completed visit.');
        const [record] = await db.insert(prescriptions).values({ ownerId: viewer.ownerId, patientId: data.patientId, doctorId,
            notes: data.notes, entries: data.entries }).returning();
        return context.json(record, 201);
    });

    app.get('/api/assessments/:id/:artifact', async (context) => {
        const viewer = context.get('viewer');
        const id = parse(uuid, context.req.param('id'));
        const [record] = await context.get('db').select().from(assessments).where(and(eq(assessments.id, id), eq(assessments.ownerId, viewer.ownerId)));
        if (!record || !visible(viewer, record) || (viewer.role === 'patient' && !record.reviewedAt)) fail(404, 'Assessment not found.');
        if (context.req.param('artifact') === 'image') {
            const bytes = await services.readImage(record.imageKey);
            if (!bytes) fail(404, 'Image not found.');
            return imageResponse(bytes);
        }
        if (context.req.param('artifact') !== 'report') fail(404, 'Document not found.');
        return pdfResponse('demo-assessment', 'Scan assessment', [
            `Patient: ${nameFor(viewer, record.patientId)}`, `Doctor: ${nameFor(viewer, record.doctorId)}`, `Created: ${record.createdAt.toISOString()}`,
            `Review: ${record.reviewedAt ? 'Reviewed' : 'Pending'}`, 'Bundled model output for a synthetic image; not a diagnosis.',
            ...record.predictions.map((item) => `${item.label}: ${(item.probability * 100).toFixed(1)}% model score`),
            `Fictional blood pressure: ${record.vitals.bloodPressure ?? 'not entered'}`, `Fictional glucose: ${record.vitals.glucose ?? 'not entered'}`,
            `Review note: ${record.doctorNote ?? 'Not reviewed'}`,
        ]);
    });

    app.get('/api/bills/:id/pdf', async (context) => {
        const viewer = context.get('viewer');
        const id = parse(uuid, context.req.param('id'));
        const [invoice] = await context.get('db').select().from(bills).where(and(eq(bills.id, id), eq(bills.ownerId, viewer.ownerId)));
        if (!invoice || !visible(viewer, invoice)) fail(404, 'Bill not found.');
        return pdfResponse('demo-bill', 'Bill summary', [`Reference: ${invoice.id}`, `Patient: ${nameFor(viewer, invoice.patientId)}`,
            `Doctor: ${nameFor(viewer, invoice.doctorId)}`, invoice.description, `Amount: INR ${(invoice.amountPaise / 100).toFixed(2)}`, `Status: ${invoice.status}`]);
    });

    app.get('/api/appointments/:id/receipt', async (context) => {
        const viewer = context.get('viewer');
        const id = parse(uuid, context.req.param('id'));
        const [visit] = await context.get('db').select().from(appointments).where(and(eq(appointments.id, id), eq(appointments.ownerId, viewer.ownerId), ne(appointments.status, 'cancelled')));
        if (!visit || !visible(viewer, visit) || visit.status === 'pending') fail(404, 'A receipt is available only for confirmed visits.');
        return pdfResponse('demo-appointment', 'Appointment receipt', [`Reference: ${visit.id}`, `Patient: ${nameFor(viewer, visit.patientId)}`,
            `Doctor: ${nameFor(viewer, visit.doctorId)}`, `Appointment: ${visit.day} at ${visit.time}`, `Status: ${visit.status}`, `Reason: ${visit.reason}`]);
    });

    return app;
}
