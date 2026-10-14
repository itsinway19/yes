import { readFile, writeFile, mkdir, readdir } from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { rolldown } from 'rolldown';

export const artifactDirectory = path.dirname(fileURLToPath(import.meta.url));
const hash = value => createHash('sha256').update(value).digest('hex');
export async function verifyUpstream(root, manifest) {
    manifest ??= JSON.parse(await readFile(path.join(artifactDirectory, 'provenance.json'), 'utf8'));
    for (const [name, expected] of Object.entries(manifest.packages)) {
        const base = path.join(root, 'node_modules/@inertiajs', name);
        const pkg = JSON.parse(await readFile(path.join(base, 'package.json'), 'utf8'));
        if (pkg.version !== expected.version || pkg.version !== '3.7.1') throw new Error(`N1 version drift: ${name}`);
        for (const [file, digest] of Object.entries(expected.files)) {
            if (hash(await readFile(path.join(base, file))) !== digest) throw new Error(`N1 source drift: ${name}/${file}`);
        }
    }
    const builder = JSON.parse(await readFile(path.join(root, 'node_modules/rolldown/package.json'), 'utf8'));
    if (builder.version !== manifest.builderVersion) throw new Error('N1 builder version drift');
    const map = JSON.parse(await readFile(path.join(root, 'node_modules/@inertiajs/core/dist/index.js.map'), 'utf8'));
    const sources = new Map();
    for (let i = 0; i < map.sources.length; i++) {
        const name = map.sources[i].replace(/^\.\.\/src\//, '');
        if (name === map.sources[i] || name.includes('..') || hash(map.sourcesContent[i]) !== manifest.sources[name]) throw new Error(`N1 source map drift: ${name}`);
        sources.set(name, map.sourcesContent[i]);
    }
    if (sources.size !== Object.keys(manifest.sources).length) throw new Error('N1 upstream source set drift');
    return sources;
}

export async function buildArtifact({ root = process.cwd(), output = path.join(root, 'storage/framework/inertia-n1'), patched = true, testExports = false } = {}) {
    const sources = await verifyUpstream(root);
    // Published sourcemaps omit this type-only source; the matching declaration
    // is independently hash-pinned and emits no runtime implementation.
    sources.set('types.ts', await readFile(path.join(root, 'node_modules/@inertiajs/core/types/types.d.ts'), 'utf8'));
    const sourceRoot = path.join(output, 'src');
    await mkdir(sourceRoot, { recursive: true });
    if (patched) {
        await verifySourcePatch();
        const overlays = path.join(artifactDirectory, 'source');
        for (const name of await readdir(overlays).catch(error => error.code === 'ENOENT' ? [] : Promise.reject(error))) {
            if (!name.endsWith('.ts') || name.includes('..')) throw new Error('Invalid N1 overlay');
            sources.set(name, await readFile(path.join(overlays, name), 'utf8'));
        }
    }
    if (testExports) sources.set('index.ts', sources.get('index.ts') + '\nexport { history } from "./history";\nexport { page } from "./page";\nexport { default as Queue } from "./queue";\nexport { Request } from "./request";\n');
    if (testExports && patched) sources.set('index.ts', sources.get('index.ts') + '\nexport { navigation } from "./navigation";\nexport { eventHandler } from "./eventHandler";\nexport { Response } from "./response";\nexport { InitialVisit } from "./initialVisit";\nexport { navigationType } from "./navigationType";\n');
    if (testExports && patched) sources.set('index.ts', sources.get('index.ts') + '\nexport { prefetchedRequests } from "./prefetched";\n');
    for (const [name, text] of sources) {
        const destination = path.join(sourceRoot, name);
        await mkdir(path.dirname(destination), { recursive: true });
        await writeFile(destination, text);
    }
    const bundle = await rolldown({ cwd: sourceRoot, input: path.join(sourceRoot, 'index.ts'), external: id => !id.startsWith('.') && !path.isAbsolute(id), treeshake: true });
    const generated = await bundle.generate({ format: 'esm', sourcemap: false, minify: false });
    await bundle.close();
    if (generated.output.length !== 1 || generated.output[0].type !== 'chunk') throw new Error('N1 artifact must be one ESM module');
    const code = generated.output[0].code;
    const target = path.join(output, 'core.mjs');
    await writeFile(target, code);
    await writeFile(path.join(output, 'build-manifest.json'), JSON.stringify({ version: '3.7.1', patched, testExports, sourceHashes: Object.fromEntries([...sources].map(([name, text]) => [name, hash(text)])), artifactSha256: hash(code) }, null, 2));
    return { target, code, sha256: hash(code) };
}

export async function verifySourcePatch(manifest) {
    manifest ??= JSON.parse(await readFile(path.join(artifactDirectory, 'provenance.json'), 'utf8'));
    const expected = Object.keys(manifest.patchHashes ?? {}).sort();
    const actual = (await readdir(path.join(artifactDirectory, 'source'))).sort();
    if (!expected.length || JSON.stringify(actual) !== JSON.stringify(expected)) throw new Error('N1 patch file-set drift');
    for (const name of expected) {
        if (hash(await readFile(path.join(artifactDirectory, 'source', name))) !== manifest.patchHashes[name]) throw new Error(`N1 patch source drift: ${name}`);
    }
    if (hash(await readFile(path.join(artifactDirectory, 'source.patch'))) !== manifest.patchSha256) throw new Error('N1 review patch drift');
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const result = await buildArtifact({ patched: !process.argv.includes('--upstream') });
    process.stdout.write(`Inertia N1 artifact verified: ${result.sha256}\n`);
}
