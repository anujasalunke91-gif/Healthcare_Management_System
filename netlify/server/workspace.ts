import { and, eq } from 'drizzle-orm';
import type { DemoDatabase } from '../../db/index';
import { appointments, bills, people, workspaces } from '../../db/schema';

export function futureDay(days = 0) {
    return new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);
}

export async function ensureWorkspace(db: DemoDatabase, ownerId: string) {
    const find = () => db.select().from(people).where(eq(people.ownerId, ownerId));
    if (!(await db.select({ id: workspaces.ownerId }).from(workspaces).where(eq(workspaces.ownerId, ownerId))).length) {
        await db.transaction(async (transaction) => {
            const [created] = await transaction.insert(workspaces).values({ ownerId }).onConflictDoNothing().returning();
            if (!created) return;
            const [patient, doctor] = await transaction.insert(people).values([
                { ownerId, persona: 'patient', demoKey: 'self', name: 'Maya Desai (demo)', age: 41, notes: 'Fictional profile for testing the portal.' },
                { ownerId, persona: 'doctor', demoKey: 'self-doctor', name: 'Dr. Nikhil Rao (demo)', specialty: 'Radiology', feePaise: 65000 },
                { ownerId, persona: 'doctor', name: 'Dr. Tara Menon (demo)', specialty: 'General medicine', feePaise: 50000 },
            ]).returning();
            const [appointment] = await transaction.insert(appointments).values({
                ownerId, patientId: patient.id, doctorId: doctor.id, day: futureDay(2), time: '10:00',
                reason: 'Synthetic follow-up example', status: 'confirmed',
            }).returning();
            await transaction.insert(bills).values({ ownerId, patientId: patient.id, doctorId: doctor.id, appointmentId: appointment.id, description: 'Demo consultation', amountPaise: doctor.feePaise });
        });
    }
    const directory = await find();
    const patient = directory.find((person) => person.demoKey === 'self');
    const doctor = directory.find((person) => person.demoKey === 'self-doctor');
    if (!patient || !doctor) throw new Error('Incomplete demo workspace.');
    return { directory, patient, doctor };
}

export async function patientAssigned(db: DemoDatabase, ownerId: string, doctorId: string, patientId: string) {
    const visits = await db.select({ status: appointments.status }).from(appointments).where(and(
        eq(appointments.ownerId, ownerId), eq(appointments.doctorId, doctorId), eq(appointments.patientId, patientId),
    ));
    return visits.some((visit) => visit.status === 'confirmed' || visit.status === 'completed');
}
