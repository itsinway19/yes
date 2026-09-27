import assert from 'node:assert/strict';
import { test } from 'node:test';
import { generatePassword } from '../resources/js/security/generatePassword.ts';

test('produces 24-character passwords within policy using secure randomness', () => {
    const samples = new Set();
    for (let i = 0; i < 100; i++) {
        const value = generatePassword({ min: 15, max: 128 });
        assert.equal(value.length, 24);
        assert.match(value, /^[A-Za-z0-9!@#$%^&*()_=+\-]+$/);
        samples.add(value);
    }
    assert.equal(samples.size, 100);
});

test('refuses a policy that cannot accept 24 characters', () => {
    assert.throws(() => generatePassword({ min: 25, max: 128 }));
    assert.throws(() => generatePassword({ min: 15, max: 23 }));
});

test('discards values outside the unbiased range', (t) => {
    let calls = 0;
    t.mock.method(globalThis.crypto, 'getRandomValues', (bytes) => {
        bytes.fill(calls++ === 0 ? 255 : 0);
        return bytes;
    });
    assert.equal(generatePassword({ min: 15, max: 128 }), 'A'.repeat(24));
    assert.equal(calls, 2);
});
