import { readFile, writeFile, mkdir, readdir } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { artifactDirectory, verifyUpstream } from './build.mjs';

const root = process.cwd();
const manifestPath = path.join(artifactDirectory, 'provenance.json');
const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
const original = await verifyUpstream(root, manifest);
const names = (await readdir(path.join(artifactDirectory, 'source'))).sort();
const hash = value => createHash('sha256').update(value).digest('hex');
let diff = '';
manifest.patchHashes = {};
for (const name of names) {
    const before = path.join('storage/framework/inertia-n1/review/upstream', name).replaceAll('\\', '/');
    const after = path.relative(root, path.join(artifactDirectory, 'source', name)).replaceAll('\\', '/');
    await mkdir(path.dirname(before), { recursive: true });
    await writeFile(before, original.get(name) ?? '');
    let text;
    try { text = execFileSync('git', ['diff', '--no-index', '--no-ext-diff', '--text', '--', before, after], { encoding: 'utf8' }); }
    catch (error) { if (error.status !== 1) throw error; text = error.stdout; }
    text = text.replaceAll(before.replaceAll('\\', '/'), 'src/'+name).replaceAll(after, 'src/'+name);
    if (!original.has(name)) {
        text = text.replace(/^(diff --git[^\n]*\n)/, '$1new file mode 100644\n')
            .replace('index e69de29..', 'index 0000000..').replace('--- a/src/'+name, '--- /dev/null');
    }
    diff += text;
    manifest.patchHashes[name] = hash(await readFile(path.join(artifactDirectory, 'source', name)));
}
manifest.patchSha256 = hash(diff);
await writeFile(path.join(artifactDirectory, 'source.patch'), diff);
await writeFile(manifestPath, JSON.stringify(manifest, null, 2)+'\n');
console.log('Reviewable source patch and exact overlay hashes updated; upstream guards unchanged.');
