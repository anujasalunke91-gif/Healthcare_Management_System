CREATE TYPE "demo_appointment_status" AS ENUM('pending', 'confirmed', 'completed', 'cancelled');--> statement-breakpoint
CREATE TYPE "demo_payment_status" AS ENUM('unpaid', 'paid');--> statement-breakpoint
CREATE TYPE "demo_person_role" AS ENUM('patient', 'doctor');--> statement-breakpoint
CREATE TABLE "demo_appointments" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"owner_id" text NOT NULL,
	"patient_id" uuid NOT NULL,
	"doctor_id" uuid NOT NULL,
	"day" date NOT NULL,
	"time" varchar(5) NOT NULL,
	"reason" varchar(300) NOT NULL,
	"status" "demo_appointment_status" DEFAULT 'pending'::"demo_appointment_status" NOT NULL,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE "demo_assessments" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"owner_id" text NOT NULL,
	"patient_id" uuid NOT NULL,
	"doctor_id" uuid NOT NULL,
	"image_key" text NOT NULL,
	"source" varchar(10) NOT NULL,
	"vitals" jsonb NOT NULL,
	"predictions" jsonb NOT NULL,
	"doctor_note" varchar(500),
	"reviewed_at" timestamp with time zone,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE "demo_bills" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"owner_id" text NOT NULL,
	"patient_id" uuid NOT NULL,
	"doctor_id" uuid NOT NULL,
	"appointment_id" uuid UNIQUE,
	"assessment_id" uuid UNIQUE,
	"description" varchar(120) NOT NULL,
	"amount_paise" integer NOT NULL,
	"status" "demo_payment_status" DEFAULT 'unpaid'::"demo_payment_status" NOT NULL,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	CONSTRAINT "demo_bills_amount" CHECK ("amount_paise" >= 0)
);
--> statement-breakpoint
CREATE TABLE "demo_people" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"owner_id" text NOT NULL,
	"persona" "demo_person_role" NOT NULL,
	"demo_key" text,
	"name" varchar(80) NOT NULL,
	"specialty" varchar(80),
	"age" integer,
	"notes" varchar(500) DEFAULT '' NOT NULL,
	"fee_paise" integer DEFAULT 0 NOT NULL,
	"archived_at" timestamp with time zone,
	CONSTRAINT "demo_people_fee" CHECK ("fee_paise" >= 0)
);
--> statement-breakpoint
CREATE TABLE "demo_prescriptions" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"owner_id" text NOT NULL,
	"patient_id" uuid NOT NULL,
	"doctor_id" uuid NOT NULL,
	"entries" jsonb NOT NULL,
	"notes" varchar(500) DEFAULT '' NOT NULL,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE "demo_workspaces" (
	"owner_id" text PRIMARY KEY,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE INDEX "demo_appointments_owner" ON "demo_appointments" ("owner_id");--> statement-breakpoint
CREATE UNIQUE INDEX "demo_appointments_slot" ON "demo_appointments" ("owner_id","doctor_id","day","time") WHERE "status" <> 'cancelled';--> statement-breakpoint
CREATE INDEX "demo_assessments_owner" ON "demo_assessments" ("owner_id");--> statement-breakpoint
CREATE INDEX "demo_bills_owner" ON "demo_bills" ("owner_id");--> statement-breakpoint
CREATE UNIQUE INDEX "demo_people_key" ON "demo_people" ("owner_id","demo_key");--> statement-breakpoint
CREATE INDEX "demo_prescriptions_owner" ON "demo_prescriptions" ("owner_id");--> statement-breakpoint
ALTER TABLE "demo_appointments" ADD CONSTRAINT "demo_appointments_owner_id_demo_workspaces_owner_id_fkey" FOREIGN KEY ("owner_id") REFERENCES "demo_workspaces"("owner_id") ON DELETE CASCADE;--> statement-breakpoint
ALTER TABLE "demo_appointments" ADD CONSTRAINT "demo_appointments_patient_id_demo_people_id_fkey" FOREIGN KEY ("patient_id") REFERENCES "demo_people"("id");--> statement-breakpoint
ALTER TABLE "demo_appointments" ADD CONSTRAINT "demo_appointments_doctor_id_demo_people_id_fkey" FOREIGN KEY ("doctor_id") REFERENCES "demo_people"("id");--> statement-breakpoint
ALTER TABLE "demo_assessments" ADD CONSTRAINT "demo_assessments_owner_id_demo_workspaces_owner_id_fkey" FOREIGN KEY ("owner_id") REFERENCES "demo_workspaces"("owner_id") ON DELETE CASCADE;--> statement-breakpoint
ALTER TABLE "demo_assessments" ADD CONSTRAINT "demo_assessments_patient_id_demo_people_id_fkey" FOREIGN KEY ("patient_id") REFERENCES "demo_people"("id");--> statement-breakpoint
ALTER TABLE "demo_assessments" ADD CONSTRAINT "demo_assessments_doctor_id_demo_people_id_fkey" FOREIGN KEY ("doctor_id") REFERENCES "demo_people"("id");--> statement-breakpoint
ALTER TABLE "demo_bills" ADD CONSTRAINT "demo_bills_owner_id_demo_workspaces_owner_id_fkey" FOREIGN KEY ("owner_id") REFERENCES "demo_workspaces"("owner_id") ON DELETE CASCADE;--> statement-breakpoint
ALTER TABLE "demo_bills" ADD CONSTRAINT "demo_bills_patient_id_demo_people_id_fkey" FOREIGN KEY ("patient_id") REFERENCES "demo_people"("id");--> statement-breakpoint
ALTER TABLE "demo_bills" ADD CONSTRAINT "demo_bills_doctor_id_demo_people_id_fkey" FOREIGN KEY ("doctor_id") REFERENCES "demo_people"("id");--> statement-breakpoint
ALTER TABLE "demo_bills" ADD CONSTRAINT "demo_bills_appointment_id_demo_appointments_id_fkey" FOREIGN KEY ("appointment_id") REFERENCES "demo_appointments"("id");--> statement-breakpoint
ALTER TABLE "demo_bills" ADD CONSTRAINT "demo_bills_assessment_id_demo_assessments_id_fkey" FOREIGN KEY ("assessment_id") REFERENCES "demo_assessments"("id");--> statement-breakpoint
ALTER TABLE "demo_people" ADD CONSTRAINT "demo_people_owner_id_demo_workspaces_owner_id_fkey" FOREIGN KEY ("owner_id") REFERENCES "demo_workspaces"("owner_id") ON DELETE CASCADE;--> statement-breakpoint
ALTER TABLE "demo_prescriptions" ADD CONSTRAINT "demo_prescriptions_owner_id_demo_workspaces_owner_id_fkey" FOREIGN KEY ("owner_id") REFERENCES "demo_workspaces"("owner_id") ON DELETE CASCADE;--> statement-breakpoint
ALTER TABLE "demo_prescriptions" ADD CONSTRAINT "demo_prescriptions_patient_id_demo_people_id_fkey" FOREIGN KEY ("patient_id") REFERENCES "demo_people"("id");--> statement-breakpoint
ALTER TABLE "demo_prescriptions" ADD CONSTRAINT "demo_prescriptions_doctor_id_demo_people_id_fkey" FOREIGN KEY ("doctor_id") REFERENCES "demo_people"("id");