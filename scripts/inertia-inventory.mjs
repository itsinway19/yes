import { readFile, readdir, writeFile } from 'node:fs/promises';
import { resolve, dirname, posix } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const read = path => readFile(resolve(root, path), 'utf8');
const migration = JSON.parse(await read('contracts/spa-migration.json'));
const migratedRoutes = new Map(migration.journeys.flatMap(journey => journey.routes.map(route => [route, journey])));
async function walk(directory) {
    const entries = await readdir(resolve(root, directory), { withFileTypes: true });
    return (await Promise.all(entries.map(entry => entry.isDirectory()
        ? walk(`${directory}/${entry.name}`) : [`${directory}/${entry.name}`]))).flat();
}

function closingBrace(source, start) {
    let depth = 0, quote = '', lineComment = false, blockComment = false;
    for (let index = start; index < source.length; index++) {
        const char = source[index], next = source[index + 1];
        if (lineComment) { if (char === '\n') lineComment = false; continue; }
        if (blockComment) { if (char === '*' && next === '/') { blockComment = false; index++; } continue; }
        if (quote) { if (char === '\\') index++; else if (char === quote) quote = ''; continue; }
        if (char === '/' && next === '/') { lineComment = true; index++; continue; }
        if (char === '/' && next === '*') { blockComment = true; index++; continue; }
        if (char === "'" || char === '"') { quote = char; continue; }
        if (char === '{') depth++;
        if (char === '}' && --depth === 0) return index;
    }
    throw Error('Unclosed PHP block');
}

const controllers = new Map();
for (const path of await walk('app/Http/Controllers')) {
    if (!path.endsWith('.php')) continue;
    const source = await read(path);
    const namespace = source.match(/namespace\s+([^;]+);/)?.[1];
    const name = source.match(/(?:final\s+)?class\s+(\w+)/)?.[1];
    const methods = {};
    for (const match of source.matchAll(/(?:public|protected|private)\s+function\s+(\w+)\s*\(/g)) {
        const start = source.indexOf('{', match.index);
        methods[match[1]] = source.slice(start, closingBrace(source, start) + 1);
    }
    controllers.set(`${namespace}\\${name}`, { path, source, methods });
}

function methodBody(owner, method, visited = new Set()) {
    if (!owner || visited.has(method)) return '';
    visited.add(method);
    const body = owner.methods[method] ?? '';
    return body + [...body.matchAll(/\$this->(\w+)\(/g)].map(match => methodBody(owner, match[1], visited)).join('\n');
}

const routes = [];
for (const path of (await walk('routes')).filter(path => path.endsWith('.php'))) {
    const source = await read(path);
    const imports = Object.fromEntries([...source.matchAll(/use\s+(App\\[^;]+);/g)]
        .map(match => [match[1].split('\\').at(-1), match[1]]));
    const groups = [...source.matchAll(/Route::middleware\(([\s\S]*?)\)->group\(function\s*\([^)]*\)\s*\{/g)]
        .map(match => ({ start: match.index, end: closingBrace(source, match.index + match[0].length - 1), expression: match[1].trim() }));
    for (const match of source.matchAll(/Route::(get|post|put|patch|delete|options|any|match)\(\s*(['"])([^'"]+)\2\s*,/g)) {
        const tail = source.slice(match.index);
        const action = tail.match(/^Route::\w+\([^,]+,\s*(?:\[\s*(\w+)::class\s*,\s*['"](\w+)['"]\s*\]|(\w+)::class)/);
        const inline = !action;
        const opening = inline ? source.indexOf('{', match.index + match[0].length) : -1;
        const end = source.indexOf(';', inline && opening !== -1 ? closingBrace(source, opening) : match.index);
        const declaration = source.slice(match.index, end + 1);
        const controller = action ? imports[action[1] ?? action[3]] : undefined;
        const method = action?.[2] ?? '__invoke';
        const owner = controllers.get(controller);
        const body = inline ? declaration : methodBody(owner, method);
        const pages = [...body.matchAll(/Inertia::render\(\s*['"]([^'"]+)['"]/g)].map(render => render[1]);
        routes.push({
            method: match[1].toUpperCase(), uri: '/' + match[3].replace(/^\//, ''),
            name: declaration.match(/->name\(['"]([^'"]+)['"]\)/)?.[1] ?? null,
            source: `${path}:${source.slice(0, match.index).split('\n').length}`,
            authorization: [...groups.filter(group => group.start < match.index && group.end > match.index).map(group => group.expression),
                ...[...declaration.matchAll(/->middleware\(([\s\S]*?)\)(?=->|\s*;)/g)].map(middleware => middleware[1])],
            action: controller ? `${controller}@${method}` : 'Closure',
            businessOwners: owner ? [...owner.source.matchAll(/use\s+(App\\(?:Business|Commercial|Foundation|Security|Sources|Providers)\\[^;]+);/g)].map(imported => imported[1]) : [],
            pages,
            legacyBehavior: /Inertia::(?:render|location|clearHistory|encryptHistory)/.test(body) ? 'INERTIA'
                : /new SpaPage\(/.test(body) ? 'SPA_RESOURCE' : 'HTTP_OR_DELEGATED',
            spaBoundary: controller?.endsWith('GoalsSpaController') ? 'OPT_IN_EXISTING_GOALS' : 'NOT_MIGRATED',
            apiContract: /GoalsSpaController|AdvertiserGoalPreferencesController/.test(controller ?? '') ? 'contracts/goals.openapi.json' : 'NOT_CONTRACTED',
        });
    }
}

const sources = new Map();
for (const path of (await walk('resources/js')).filter(path => /\.(vue|ts|js)$/.test(path))) sources.set(path, await read(path));
const edges = {};
for (const [path, source] of sources) {
    edges[path] = [...source.matchAll(/(?:from\s*|import\s*\()\s*['"]([^'"]+)['"]/g)].map(match => {
        const target = match[1].startsWith('@/') ? `resources/js/${match[1].slice(2)}`
            : match[1].startsWith('.') ? posix.normalize(posix.join(posix.dirname(path), match[1])) : match[1];
        return [target, `${target}.ts`, `${target}.js`, `${target}.vue`, `${target}/index.ts`].find(candidate => sources.has(candidate)) ?? target;
    });
}
const support = {};
for (const directory of ['app/Http/Middleware', 'resources/views', 'tests', 'scripts', 'compat']) {
    for (const path of await walk(directory)) {
        if (!/\.(php|vue|ts|js|mjs|ps1|json)$/.test(path)) continue;
        const source = await read(path);
        const count = [...source.matchAll(/inertia|goals-spa|X-Builderad-Document|clientVisibility/gi)].length;
        if (count) support[path] = count;
    }
}

const runtime = {};
if (process.argv.includes('--runtime')) {
    runtime.application = JSON.parse(execFileSync(process.env.BUILDERAD_PHP ?? 'php',
        ['artisan', 'route:list', '--json', '--except-vendor'], {
            cwd: root, encoding: 'utf8', env: process.env,
        }));
    for (const route of routes) {
        route.runtime = Object.fromEntries(Object.entries(runtime).map(([variant, registered]) => [variant,
            registered.filter(entry => '/' + entry.uri.replace(/^\//, '') === route.uri
                && entry.method.split('|').includes(route.method))]));
    }
}
const matrixRoutes = Object.keys(runtime).length ? [...new Map(Object.values(runtime).flat().map(entry => {
    const uri = '/' + entry.uri.replace(/^\//, '');
    const candidates = routes.filter(route => entry.method.split('|').includes(route.method)
        && ((entry.name && route.name === entry.name) || (route.action === entry.action && route.uri === uri)));
    const source = candidates[0] ?? (entry.action !== 'Closure' ? routes.find(route => route.action === entry.action && entry.method.split('|').includes(route.method)) : undefined);
    const [controller, method = '__invoke'] = entry.action.split('@');
    const owner = controllers.get(controller);
    const body = methodBody(owner, method);
    const route = {
        ...source, method: entry.method, uri, name: entry.name, action: entry.action,
        source: source?.source ?? entry.path ?? owner?.path ?? 'RUNTIME_REGISTRATION',
        pages: source?.pages ?? [...body.matchAll(/Inertia::render\(\s*['"]([^'"]+)['"]/g)].map(render => render[1]),
        spaBoundary: source?.spaBoundary ?? 'NOT_MIGRATED',
        apiContract: source?.apiContract ?? 'NOT_CONTRACTED',
        authorization: entry.middleware,
        runtime: Object.fromEntries(Object.entries(runtime).map(([variant, registered]) => [variant,
            registered.filter(actual => actual.method === entry.method && actual.uri === entry.uri && actual.action === entry.action)])),
    };
    return [`${entry.method} ${uri} ${entry.action}`, route];
})).values()] : routes;
const testSources = await Promise.all((await walk('tests')).filter(path => /\.(php|mjs)$/.test(path))
    .map(async path => ({ path, source: await read(path) })));
for (const route of matrixRoutes) {
    route.migration = migratedRoutes.get(`${route.method.split('|')[0]} ${route.uri}`) ?? null;
    if (route.migration) {
        route.spaBoundary = route.migration.spaBoundary;
        route.apiContract = route.migration.apiContract;
    }
    const controller = route.action.split('\\').at(-1).split('@')[0].replace(/Controller$/, '');
    route.testLeads = testSources.filter(test => (controller !== 'Closure' && test.path.includes(controller))
        || (route.name && test.source.includes(route.name)) || test.source.includes(`'${route.uri}'`)
        || route.pages.some(page => test.source.includes(`'${page}'`))).map(test => test.path);
}
const report = {
    baseline: '505da99f1c551f2f6143980ad624d1b591a6824d',
    evidence: process.argv.includes('--runtime')
        ? 'SOURCE_AND_RUNTIME_ROUTE_REGISTRATION; both Goals opt-in states recorded. Runtime middleware names establish registration, not authorization behavior or passing coverage. Test references are discovery leads.'
        : 'STATIC_SOURCE_ONLY; runtime expansion pending. Includes conditional routes, not a claim that they are enabled. Test references are discovery leads, not passing evidence.',
    routes: matrixRoutes, sourceDeclarations: routes, runtime, imports: edges, support,
    summary: {
        registeredRoutes: matrixRoutes.length,
        legacyRenderRoutes: matrixRoutes.filter(route => route.pages.length).length,
        locallyAcceptedJourneys: migration.journeys.filter(journey => journey.status === 'LOCAL_JOURNEY_ACCEPTED').map(journey => journey.id),
    },
    unresolved: ['Runtime authorization behavior and route constraints', 'Delegated/dynamic render calls', 'Behavioral browser parity', 'Nonliteral route declarations'],
};
const json = JSON.stringify(report, null, 2) + '\n';
const escape = value => String(value).replaceAll('|', '\\|').replaceAll('\n', ' ');
const rows = matrixRoutes.map(route => [
    `${route.method} ${route.uri} (${route.source})`,
    route.migration?.journey || route.pages.join(', ') || route.name || route.action,
    route.runtime?.goalsEnabled.flatMap(entry => entry.middleware).join('; ') || route.authorization.join('; ') || 'web; inspect action',
    route.migration ? route.migration.businessOwners.join(', ') : route.action,
    route.spaBoundary,
    route.apiContract,
    route.migration ? route.migration.tests.join(', ') : route.testLeads.length ? 'UNVERIFIED: ' + route.testLeads.slice(0, 4).join(', ') + (route.testLeads.length > 4 ? '; more in JSON' : '') : 'PENDING: no direct test reference found',
    route.migration?.status ?? (route.spaBoundary === 'OPT_IN_EXISTING_GOALS' ? 'OPT_IN; legacy retained' : 'REMAINING'),
].map(escape).join(' | '));
const markdown = '# Inertia retirement migration matrix\n\n' + report.evidence + '\n\n'
    + 'Generated with `node scripts/inertia-inventory.mjs --runtime`. Full business-owner imports, component dependency graph and test discovery are in `inertia-inventory.json`. Local journey acceptance is explicitly recorded in `contracts/spa-migration.json`; discovery alone accepts nothing. Final Foundation/CI and release acceptance remain separate.\n\n'
    + '| Legacy Route | User Journey | Authorization (registered middleware) | Business Owner / HTTP owner | SPA Route / HTTP Boundary | API Contract | Tests | Status |\n'
    + '|---|---|---|---|---|---|---|---|\n'
    + rows.map(row => `| ${row} |`).join('\n') + '\n';
for (const [path, content] of [['docs/inertia-inventory.json', json], ['docs/INERTIA_RETIREMENT_MATRIX.md', markdown]]) {
    if (process.argv.includes('--check')) {
        if (await read(path) !== content) throw Error(`Inventory drift: ${path}`);
    } else await writeFile(resolve(root, path), content);
}
console.log(`Inventory: ${matrixRoutes.length} matrix routes, ${routes.length} source declarations, ${sources.size} frontend modules; runtime variants: ${Object.keys(runtime).join(', ') || 'not executed'}. Behavioral acceptance pending.`);
