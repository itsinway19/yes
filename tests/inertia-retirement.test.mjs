import test from 'node:test';
import assert from 'node:assert/strict';
import { additions, referenceCount } from '../scripts/inertia-retirement.mjs';
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

test('generated API documentation cannot create a false dependency but imports and type references remain guarded', () => {
    const path = 'resources/js/api/generated/application.d.ts';
    assert.equal(referenceCount(path, '/** Legacy HTML used Inertia. */ export interface Paths {}'), 0);
    assert.equal(referenceCount(path, '/** Legacy Inertia. */ import type { Page } from "@inertiajs/core";'), 1);
    assert.equal(referenceCount(path, 'export type Page = import("@inertiajs/core").Page;'), 1);
});
