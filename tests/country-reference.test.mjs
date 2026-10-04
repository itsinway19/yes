import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

const root = new URL('../database/reference/countries/', import.meta.url);
const bytes = name => readFileSync(new URL(name, root));
const json = name => JSON.parse(bytes(name));
const hash = value => createHash('sha256').update(value).digest('hex');
const identityBytes = codes => `${[...codes].sort().join('\n')}\n`;
const labelBytes = rows => `${JSON.stringify(rows.map(({ code, name_en, name_ar }) => ({ code, name_en, name_ar })))}\n`;

test('identity_membership_matches_source_snapshot', () => {
    const codes = json('identities.json');
    const sourceCodes = json('sources/iso_3166-1.json')['3166-1'].map(row => row.alpha_2).sort();
    assert.deepEqual(codes, [...new Set(sourceCodes)]);
    assert.ok(codes.every(code => /^[A-Z]{2}$/.test(code)));
    const provenance = json('provenance.json');
    assert.equal(codes.length, provenance.IDENTITY_COUNT);
    assert.equal(hash(identityBytes(codes)), provenance.IDENTITY_DIGEST);
    assert.equal(hash(bytes('sources/iso_3166-1.json')), provenance.source_hashes['iso_3166-1.json']);
});

test('labels_match_accepted_sources', () => {
    const rows = json('labels.json');
    assert.deepEqual(rows.map(row => row.code), json('identities.json'));
    const english = json('sources/cldr-en-territories.json').main.en.localeDisplayNames.territories;
    const arabic = json('sources/cldr-ar-territories.json').main.ar.localeDisplayNames.territories;
    for (const row of rows) {
        assert.deepEqual(Object.keys(row), ['code', 'name_en', 'name_ar']);
        assert.equal(row.name_en, english[row.code]?.normalize('NFC'));
        assert.equal(row.name_ar, arabic[row.code]?.normalize('NFC'));
        assert.ok(row.name_en.trim() && row.name_ar.trim());
    }
    const provenance = json('provenance.json');
    assert.equal(hash(labelBytes(rows)), provenance.LOCALIZATION_DIGEST_OR_EQUIVALENT);
    for (const file of ['cldr-en-territories.json', 'cldr-ar-territories.json']) {
        assert.equal(hash(bytes(`sources/${file}`)), provenance.source_hashes[file]);
    }
});

test('identity_digest_excludes_labels', () => {
    const codes = json('identities.json');
    const rows = json('labels.json');
    const changed = structuredClone(rows);
    changed[0].name_ar += ' اختبار';
    assert.notEqual(hash(labelBytes(rows)), hash(labelBytes(changed)));
    assert.equal(hash(identityBytes(changed.map(row => row.code))), hash(identityBytes(codes)));
});

test('noncountry_codes_are_rejected', () => {
    const codes = json('identities.json');
    for (const code of ['GCC', 'Gulf', 'MENA', 'EU', 'UN', 'XK', 'ZZ', 'AA', 'SAU', 'sa']) {
        assert.equal(codes.includes(code), false, code);
    }
    for (const code of ['SA', 'AE', 'KW', 'JO']) assert.ok(codes.includes(code));
});
