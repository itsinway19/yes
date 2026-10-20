import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL('../' + path, import.meta.url), 'utf8').replaceAll('\r\n', '\n');
const scripts = JSON.parse(read('package.json')).scripts;
const files = command => {
    assert.match(command, /^node --test (tests\/\S+\.test\.mjs ?)+$/);
    return command.split(' ').slice(2);
};

test('complete JavaScript command covers focused selections once, without changing their modes', () => {
    const complete = files(scripts.test);
    assert.equal(new Set(complete).size, complete.length);
    for (const [name, command] of Object.entries(scripts)) {
        if (!name.startsWith('test:')) continue;
        for (const file of files(command)) assert.ok(complete.includes(file), `${name}: missing ${file}`);
    }
    for (const file of complete) {
        const source = read(file);
        assert.ok(source.length > 0, file);
        assert.doesNotMatch(source, /import\s+(?:[^;\n]*?from\s+)?['"][^'"]+\.test\.mjs['"]/, `${file} must not register another selected file's tests`);
    }
});

test('hosted Foundation retains mandatory gates with one complete test and production build', () => {
    const workflow = read('.github/workflows/foundation.yml');
    const gate = workflow.split('- name: Verify Foundation\n')[1];
    assert.ok(gate);
    for (const command of ['node scripts/inertia-retirement.mjs', 'composer validate --strict', 'composer audit',
        'npm audit --audit-level=high', 'php artisan test --compact', 'npm test', 'npm run api:check',
        'php vendor/bin/pint --test', 'npm run build', 'node scripts/runtime-retirement.mjs --assets']) {
        assert.equal(gate.split('\n').filter(line => line.trim() === command).length, 1, command);
    }
    assert.doesNotMatch(gate, /npm run (?:test:|build:)|continue-on-error|\|\|\s*true/);
    assert.ok(gate.indexOf('npm run build') < gate.indexOf('node scripts/runtime-retirement.mjs --assets'));
    assert.match(workflow, /php artisan security:assert-disposable-test-db/);
});

test('Windows Foundation keeps serial tests, guarded PHP, failure checks and final asset validation', () => {
    const runner = read('scripts/Test-GoalsSpa.ps1');
    const gate = runner.split("if($Phase -eq 'Foundation'){")[1].split("if($Phase -in @('Focused'")[0];
    assert.match(gate, /builderad\.ps1 test --compact/);
    assert.match(gate, /'npm','test'/);
    assert.match(gate, /\.scripts\.test/);
    assert.match(gate, /node\.exe' --test-concurrency=1 @proofTestArguments/);
    assert.doesNotMatch(gate, /npm-cli\.js/);
    assert.match(gate, /'npm','run','inertia:check'/);
    assert.equal(gate.match(/'npm','run','build'/g)?.length, 1);
    assert.doesNotMatch(gate, /build:goals-spa|test:spa-foundation/);
    assert.match(gate, /if\(\$LASTEXITCODE -ne 0\)\{throw "Foundation gate failed:/);
    assert.match(gate, /runtime-retirement\.mjs --assets/);
    assert.match(gate, /if\(\$LASTEXITCODE -ne 0\)\{throw 'Foundation production asset guard failed'/);
    assert.match(read('scripts/builderad.ps1'), /'test'\s*\{\s*Assert-DisposableTestDatabase \$php/);
});

test('production alias remains equivalent and instrumented proof build stays distinct', () => {
    assert.equal(scripts.build, 'vue-tsc && vite build');
    assert.equal(scripts['build:goals-spa'], 'npm run build');
    assert.equal(scripts['build:goals-spa:proof'], 'vue-tsc && vite build --mode proof');
});
