import { getDatabase } from '@netlify/database';
import { drizzle } from 'drizzle-orm/netlify-db';
import * as schema from './schema';

export function createDatabase() {
    return drizzle({ client: getDatabase(), schema });
}

export type DemoDatabase = ReturnType<typeof createDatabase>;
