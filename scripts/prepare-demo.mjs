import { execFileSync } from 'node:child_process';
import { copyFile, mkdir, rm, writeFile } from 'node:fs/promises';
import { build } from 'esbuild';

const html = execFileSync('php', ['scripts/netlify-preview.php', '--demo'], { encoding: 'utf8' });
if (!html.startsWith('<!DOCTYPE html>')) throw new Error('The public landing page did not render');

await rm('dist', { recursive: true, force: true });
await Promise.all(['assets/css', 'assets/js', 'portal'].map((directory) => mkdir(`dist/${directory}`, { recursive: true })));
await Promise.all([
    writeFile('dist/index.html', html),
    copyFile('healthcare_platform/assets/css/custom.css', 'dist/assets/css/custom.css'),
    copyFile('healthcare_platform/assets/js/main.js', 'dist/assets/js/main.js'),
    copyFile('demo/index.html', 'dist/portal/index.html'),
    copyFile('demo/portal.css', 'dist/portal/portal.css'),
    build({ entryPoints: ['demo/portal.js'], bundle: true, minify: true, target: 'es2022', format: 'esm', outfile: 'dist/portal/portal.js' }),
]);
