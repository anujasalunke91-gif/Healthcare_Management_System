import { sql } from 'drizzle-orm';
import { check, date, index, integer, jsonb, pgEnum, pgTable, text, timestamp, uniqueIndex, uuid, varchar } from 'drizzle-orm/pg-core';

export const personRole = pgEnum('demo_person_role', ['patient', 'doctor']);
export const appointmentStatus = pgEnum('demo_appointment_status', ['pending', 'confirmed', 'completed', 'cancelled']);
export const paymentStatus = pgEnum('demo_payment_status', ['unpaid', 'paid']);

export const workspaces = pgTable('demo_workspaces', {
    ownerId: text('owner_id').primaryKey(),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
});

export const people = pgTable('demo_people', {
    id: uuid().primaryKey().defaultRandom(),
    ownerId: text('owner_id').notNull().references(() => workspaces.ownerId, { onDelete: 'cascade' }),
    persona: personRole().notNull(),
    demoKey: text('demo_key'),
    name: varchar({ length: 80 }).notNull(),
    specialty: varchar({ length: 80 }),
    age: integer(),
    notes: varchar({ length: 500 }).notNull().default(''),
    feePaise: integer('fee_paise').notNull().default(0),
    archivedAt: timestamp('archived_at', { withTimezone: true }),
}, (table) => [
    uniqueIndex('demo_people_key').on(table.ownerId, table.demoKey),
    check('demo_people_fee', sql`${table.feePaise} >= 0`),
]);

export const appointments = pgTable('demo_appointments', {
    id: uuid().primaryKey().defaultRandom(),
    ownerId: text('owner_id').notNull().references(() => workspaces.ownerId, { onDelete: 'cascade' }),
    patientId: uuid('patient_id').notNull().references(() => people.id),
    doctorId: uuid('doctor_id').notNull().references(() => people.id),
    day: date().notNull(),
    time: varchar({ length: 5 }).notNull(),
    reason: varchar({ length: 300 }).notNull(),
    status: appointmentStatus().notNull().default('pending'),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
}, (table) => [
    index('demo_appointments_owner').on(table.ownerId),
    uniqueIndex('demo_appointments_slot').on(table.ownerId, table.doctorId, table.day, table.time)
        .where(sql`${table.status} <> 'cancelled'`),
]);

export type Prediction = { label: string; probability: number };

export const assessments = pgTable('demo_assessments', {
    id: uuid().primaryKey().defaultRandom(),
    ownerId: text('owner_id').notNull().references(() => workspaces.ownerId, { onDelete: 'cascade' }),
    patientId: uuid('patient_id').notNull().references(() => people.id),
    doctorId: uuid('doctor_id').notNull().references(() => people.id),
    imageKey: text('image_key').notNull(),
    source: varchar({ length: 10 }).notNull(),
    vitals: jsonb().$type<{ bloodPressure?: string; glucose?: number }>().notNull(),
    predictions: jsonb().$type<Prediction[]>().notNull(),
    doctorNote: varchar('doctor_note', { length: 500 }),
    reviewedAt: timestamp('reviewed_at', { withTimezone: true }),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
}, (table) => [index('demo_assessments_owner').on(table.ownerId)]);

export const prescriptions = pgTable('demo_prescriptions', {
    id: uuid().primaryKey().defaultRandom(),
    ownerId: text('owner_id').notNull().references(() => workspaces.ownerId, { onDelete: 'cascade' }),
    patientId: uuid('patient_id').notNull().references(() => people.id),
    doctorId: uuid('doctor_id').notNull().references(() => people.id),
    entries: jsonb().$type<{ name: string; directions: string }[]>().notNull(),
    notes: varchar({ length: 500 }).notNull().default(''),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
}, (table) => [index('demo_prescriptions_owner').on(table.ownerId)]);

export const bills = pgTable('demo_bills', {
    id: uuid().primaryKey().defaultRandom(),
    ownerId: text('owner_id').notNull().references(() => workspaces.ownerId, { onDelete: 'cascade' }),
    patientId: uuid('patient_id').notNull().references(() => people.id),
    doctorId: uuid('doctor_id').notNull().references(() => people.id),
    appointmentId: uuid('appointment_id').unique().references(() => appointments.id),
    assessmentId: uuid('assessment_id').unique().references(() => assessments.id),
    description: varchar({ length: 120 }).notNull(),
    amountPaise: integer('amount_paise').notNull(),
    status: paymentStatus().notNull().default('unpaid'),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
}, (table) => [
    index('demo_bills_owner').on(table.ownerId),
    check('demo_bills_amount', sql`${table.amountPaise} >= 0`),
]);
