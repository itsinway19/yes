import { readFile, writeFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const baselinePath = resolve(root, 'contracts/inertia-retirement.json');
const marker = /inertia/gi;
const directories = ['app', 'bootstrap', 'config', 'routes', 'resources', 'compat'];
const manifests = ['package.json', 'package-lock.json', 'composer.json', 'composer.lock'];

export async function inventory() {
    const paths = execFileSync('git', ['ls-files', '--cached', '--others', '--exclude-standard', '-z', '--',
        ...directories, ...manifests, 'vite*.js', 'vite*.ts', 'vite*.mjs'], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
    const result = {};
    for (const path of paths.sort()) {
        if (/\.(png|jpg|woff2?|ico)$/.test(path)) continue;
        let source;
        try { source = await readFile(resolve(root, path), 'utf8'); }
        catch (error) { if (error.code === 'ENOENT') continue; throw error; }
        if (path === 'package.json' || path === 'composer.json') {
            const manifest = JSON.parse(source);
            source = JSON.stringify(Object.fromEntries(['dependencies', 'devDependencies', 'overrides', 'require', 'require-dev']
                .filter(key => manifest[key]).map(key => [key, manifest[key]])));
        }
        const count = [...source.matchAll(marker)].length;
        if (count) result[path] = count;
    }
    return result;
}

export function additions(baseline, candidate) {
    return Object.entries(candidate).filter(([path, count]) => count > (baseline[path] ?? 0));
}

async function main() {
    const candidate = await inventory();
    if (process.argv.includes('--initialize')) {
        try {
            await readFile(baselinePath);
        } catch (error) {
            if (error.code !== 'ENOENT') throw error;
            await writeFile(baselinePath, JSON.stringify(candidate, null, 2) + '\n');
            return;
        }
        throw Error('The retirement ceiling already exists; it may only decrease.');
    }
    const baseline = JSON.parse(await readFile(baselinePath, 'utf8'));
    const increased = additions(baseline, candidate);
    if (increased.length) throw Error(`Inertia growth is forbidden:\n${increased.map(([path, count]) => `${path}: ${baseline[path] ?? 0} -> ${count}`).join('\n')}`);
    if (process.argv.includes('--ratchet')) await writeFile(baselinePath, JSON.stringify(candidate, null, 2) + '\n');
    console.log(`Inertia ceiling PASS: ${Object.keys(candidate).length} remaining files. This is not migration acceptance.`);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main();
