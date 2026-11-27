import { getStore } from '@netlify/blobs';
import { getUser } from '@netlify/identity';
import { getContext, type Config } from '@netlify/server';
import { createDatabase } from '../../db/index';
import { createDemoApi } from './api';
import { classifyImage, normalizeImage, sampleImage } from './model';

let database: ReturnType<typeof createDatabase> | undefined;
const scans = () => getStore({ name: 'demo-scan-images', consistency: 'strong' });

const app = createDemoApi({
    database: () => database ??= createDatabase(),
    async identity() {
        const context = getContext();
        const jwt = context.cookies.get('nf_jwt');
        if (!jwt) return null;
        const user = await getUser();
        if (user?.id) return user.id;
        const endpoint = new URL('/.netlify/identity/user', context.site.url);
        if (endpoint.protocol !== 'https:') endpoint.protocol = 'https:';
        const response = await fetch(endpoint, {
            headers: { Authorization: `Bearer ${jwt}` }, redirect: 'error', signal: AbortSignal.timeout(5000),
        });
        if (!response.ok) return null;
        const verified = await response.json() as { id?: string };
        return typeof verified.id === 'string' ? verified.id : null;
    },
    sample: sampleImage,
    normalize: normalizeImage,
    predict: classifyImage,
    async saveImage(key, image) { await scans().set(key, new Blob([Uint8Array.from(image)])); },
    readImage: (key) => scans().get(key, { type: 'arrayBuffer' }),
    deleteImage: (key) => scans().delete(key),
});

export default (request: Request) => app.fetch(request);
export const config: Config = { includedFiles: [
    'artifacts/*.onnx', 'artifacts/class_names.txt',
    '!../../node_modules/onnxruntime-node/bin/napi-v6/darwin/**',
    '!../../node_modules/onnxruntime-node/bin/napi-v6/win32/**',
] };
