import { readFile, readdir } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const read = path => readFile(resolve(root, path), 'utf8');
export function forbiddenRuntime(source) {
    return /(?:from\s*|import\s*\(|require\s*\()\s*['"](?:@inertiajs\/|[^'"]*compat\/inertia-n1)|\buse\s+Inertia\\|\bInertia::|createInertiaApp\s*\(/.test(source);
}
export async function verifyRuntime({ assets = false } = {}) {
    const paths = execFileSync('git', ['ls-files', '--cached', '--others', '--exclude-standard', '-z', '--',
        'app', 'bootstrap', 'resources', 'routes', 'config', 'vite*.js'], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
    for (const path of new Set(paths)) {
        if (!/\.(php|vue|ts|js|mjs)$/.test(path)) continue;
        let source;
        try { source = await read(path); } catch (error) { if (error.code === 'ENOENT') continue; throw error; }
        if (forbiddenRuntime(source) || /@inertia(?:Head)?\b/.test(source)) throw Error(`Retired production runtime reference: ${path}`);
    }
    const npm = JSON.parse(await read('package-lock.json'));
    if (Object.keys(npm.packages).some(path => /(?:^|\/)node_modules\/@inertiajs\//.test(path))) throw Error('Retired npm package restored');
    const composer = JSON.parse(await read('composer.lock'));
    if ([...composer.packages, ...composer['packages-dev']].some(pkg => pkg.name.startsWith('inertiajs/'))) throw Error('Retired Composer package restored');
    for (const path of ['package.json', 'composer.json']) {
        const manifest = JSON.parse(await read(path));
        for (const group of ['dependencies', 'devDependencies', 'require', 'require-dev', 'overrides']) {
            if (Object.keys(manifest[group] ?? {}).some(name => /^@inertiajs\/|^inertiajs\//.test(name))) throw Error(`Retired dependency restored: ${path}`);
        }
    }
    const config = await read('vite.config.js');
    if (!config.includes("input: 'resources/js/spa/main.ts'") || /inertia-n1|buildArtifact|@inertiajs/.test(config)) throw Error('Production build must use only the unified SPA');
    if (assets) {
        const manifest = JSON.parse(await read('public/build/goals-spa/manifest.json'));
        const entries = Object.values(manifest).filter(entry => entry.isEntry);
        if (entries.length !== 1 || entries[0].src !== 'resources/js/spa/main.ts') throw Error('Unexpected production entry');
        for (const entry of Object.values(manifest)) {
            if (!entry.file.endsWith('.js')) continue;
            if (/createInertiaApp|@inertiajs|inertia-n1|NavigationToken/.test(await read('public/build/goals-spa/' + entry.file))) throw Error(`Retired runtime in asset: ${entry.file}`);
        }
        const publicFiles = await readdir(resolve(root, 'public/build'));
        if (publicFiles.some(path => path !== 'goals-spa')) throw Error('Unexpected legacy build output');
    }
    return 'Runtime retirement guard PASS';
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) console.log(await verifyRuntime({ assets: process.argv.includes('--assets') }));
