import test from 'node:test';
import assert from 'node:assert/strict';
import { additions } from '../scripts/inertia-retirement.mjs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('the real CLI executes its guard and refuses to overwrite the ceiling', () => {
    const script = fileURLToPath(new URL('../scripts/inertia-retirement.mjs', import.meta.url));
    assert.match(execFileSync(process.execPath, [script], { encoding: 'utf8' }), /Inertia ceiling PASS/);
    assert.throws(() => execFileSync(process.execPath, [script, '--initialize'], { stdio: 'pipe' }), /Command failed/);
});

test('retirement rejects a new dependency file even when another file is removed', () => {
    assert.deepEqual(additions({ 'old.vue': 4 }, { 'new.vue': 1 }), [['new.vue', 1]]);
});

test('retirement rejects growth in existing files and permits removal', () => {
    assert.deepEqual(additions({ 'existing.php': 2 }, { 'existing.php': 3 }), [['existing.php', 3]]);
    assert.deepEqual(additions({ 'existing.php': 2 }, { 'existing.php': 1 }), []);
    assert.deepEqual(additions({ 'existing.php': 2 }, {}), []);
});
