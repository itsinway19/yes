import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

test('Foundation monitor reports actual completed gates, PHP progress, failure and final cleanup separately', () => {
    const directory = mkdtempSync(path.join(tmpdir(), 'builderad-foundation-monitor-'));
    const log = path.join(directory, 'foundation.log');
    const prefix = `GOALS_SPA_FOUNDATION_RUN=${directory}\nGOALS_SPA_FOUNDATION_TOTAL=10\n`;
    const inspect = contents => {
        writeFileSync(log, prefix + contents);
        const result = spawnSync('pwsh', ['-NoProfile', '-File', 'scripts/Watch-Foundation.ps1', '-LogPath', log, '-Once'], { encoding: 'utf8' });
        assert.equal(result.status, 0, result.stderr);
        return result.stdout;
    };
    try {
        writeFileSync(path.join(directory, 'events.log'), 'Test Suite Loaded (4 tests)\nTest Finished (one)\nTest Failed (two)\nTest Finished (two)\n');
        const running = inspect('GOALS_SPA_GATE_START=PHP suite\n');
        assert.match(running, /RUNNING.*0\/10 \(0%\)/);
        assert.match(running, /PHP tests: 2\/4 \(50%\).*failed\/error events: 1/);
        const failed = inspect('GOALS_SPA_GATE_PASS=PHP suite\nGOALS_SPA_GATE_START=composer audit\nGOALS_SPA_GATE_FAIL=composer audit\nGOALS_SPA_PROCESSES=STOPPED; EXIT=1\n');
        assert.match(failed, /FAIL.*1\/10 \(10%\)/);
        assert.match(failed, /\[FAIL\] composer audit/);
        const passes = Array.from({ length: 10 }, (_, index) => `GOALS_SPA_GATE_PASS=gate ${index}`).join('\n');
        assert.match(inspect(passes), /RUNNING.*10\/10 \(100%\)/, 'Gate completion alone must not claim successful cleanup');
        assert.match(inspect(passes + '\nGOALS_SPA_PROCESSES=STOPPED; EXIT=0\n'), /PASS.*10\/10 \(100%\)/);
        assert.match(inspect(passes + '\nGOALS_SPA_PROCESSES=STOPPED; EXIT=1\n'), /FAIL.*10\/10 \(100%\)/, 'Cleanup failure stays failed even when all gates passed');
    } finally {
        assert.ok(path.resolve(directory).startsWith(path.resolve(tmpdir()) + path.sep));
        rmSync(directory, { recursive: true, force: true });
    }
});
