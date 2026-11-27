import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { InferenceSession, Tensor } from 'onnxruntime-node';
import sharp from 'sharp';

const size = 224;
const artifacts = join(import.meta.dirname, 'artifacts');

export const syntheticScan = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="448" height="448" viewBox="0 0 448 448">
<defs><radialGradient id="field"><stop stop-color="#969696"/><stop offset="1" stop-color="#222"/></radialGradient></defs>
<rect width="448" height="448" fill="#101010"/><ellipse cx="224" cy="224" rx="148" ry="177" fill="url(#field)" stroke="#aaa" stroke-width="6"/>
<ellipse cx="172" cy="217" rx="39" ry="57" fill="#383838"/><ellipse cx="276" cy="217" rx="39" ry="57" fill="#383838"/>
<circle cx="272" cy="193" r="15" fill="#b4b4b4"/><path d="M224 101v246" stroke="#949494" stroke-opacity=".5" stroke-width="4"/></svg>`);

export function sampleImage() {
    return sharp(syntheticScan).png().toBuffer();
}

export async function normalizeImage(bytes: Buffer) {
    const image = sharp(bytes, { limitInputPixels: 16_000_000, failOn: 'warning', animated: false });
    const { format } = await image.metadata();
    if (format !== 'png' && format !== 'jpeg') throw new TypeError('Choose a valid PNG or JPEG image.');
    return image.resize(size, size, { fit: 'fill', kernel: 'linear' }).removeAlpha().toColourspace('srgb').png().toBuffer();
}

let classifier: ReturnType<typeof loadClassifier> | undefined;

async function loadClassifier() {
    const [session, labels] = await Promise.all([
        InferenceSession.create(join(artifacts, 'multi-cancer.onnx'), {
            executionProviders: ['cpu'], intraOpNumThreads: 1, interOpNumThreads: 1, executionMode: 'sequential',
        }),
        readFile(join(artifacts, 'class_names.txt'), 'utf8').then((text) => text.trim().split(/\r?\n/)),
    ]);
    return { session, labels };
}

export async function classifyImage(image: Buffer) {
    classifier ??= loadClassifier().catch((error) => {
        classifier = undefined;
        throw error;
    });
    const { session, labels } = await classifier;
    const pixels = await sharp(image).raw().toBuffer();
    const results = await session.run({ [session.inputNames[0]]: new Tensor('float32', Float32Array.from(pixels), [1, size, size, 3]) });
    const scores = results[session.outputNames[0]].data as Float32Array;
    if (scores.length !== labels.length || !scores.every(Number.isFinite)) throw new Error('Model output did not match the class catalog.');
    return labels.map((label, index) => ({ label, probability: scores[index] })).sort((left, right) => right.probability - left.probability).slice(0, 3);
}
