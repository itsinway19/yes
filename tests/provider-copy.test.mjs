import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
const asModule = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const language = asModule(`import {ref} from ${JSON.stringify(import.meta.resolve('vue'))};export const locale=ref('en');`);
const source = await readFile('resources/js/sources/providerCopy.ts', 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText.replace("'../spa/locale'", JSON.stringify(language));
const { providerCopy } = await import(asModule(compiled));
const { locale } = await import(language);
const arabic = /[\u0600-\u06ff]/;
test('every finite Arabic provider interaction message has English copy and retained messages follow locale', async () => {
    const messages = new Set();
    for (const file of ['Meta', 'Google', 'TikTok', 'Snapchat', 'X'].map(name => `resources/js/sources/${name}ConnectionPanel.vue`).concat('resources/js/sources/ProviderReportRequest.vue', 'resources/js/sources/SourceRegistryPanel.vue', 'resources/js/sources/DeclareSourceScope.vue', 'resources/js/Components/Sources/SourceCapabilityStatus.vue')) {
        const content = await readFile(file, 'utf8');
        const script = content.split('</script>')[0].replace('<script setup lang="ts">', '');
        const scanner = ts.createScanner(ts.ScriptTarget.Latest, true, ts.LanguageVariant.Standard, script);
        for (let token = scanner.scan(); token !== ts.SyntaxKind.EndOfFileToken; token = scanner.scan()) {
            if (token === ts.SyntaxKind.StringLiteral && arabic.test(scanner.getTokenValue())) messages.add(scanner.getTokenValue());
        }
        for (const match of content.matchAll(/providerCopy\("([^"\r\n]*)"\)/g)) if (arabic.test(match[1])) messages.add(match[1]);
        for (const match of content.split('<template>')[1].matchAll(/['"]([^'"\r\n]*[\u0600-\u06ff][^'"\r\n]*)['"]/g)) messages.add(match[1]);
        if (!file.endsWith('SourceRegistryPanel.vue') && !file.endsWith('SourceCapabilityStatus.vue')) assert.doesNotMatch(content.split('<template>')[1], /dir="rtl"/, `${file} must follow the active locale`);
    }
    locale.value = 'en';
    assert.deepEqual([...messages].filter(message => arabic.test(providerCopy(message))), [], 'Every finite interaction message must have English copy');
    const retained = 'تغيّرت حالة الربط. حدّث الحالة قبل إعادة المحاولة.';
    assert.equal(providerCopy(retained), 'Connection status changed. Refresh before trying again.');
    locale.value = 'ar'; assert.equal(providerCopy(retained), retained);
    assert.equal(providerCopy('Native customer account 123'), 'Native customer account 123', 'Native account content is preserved');
    locale.value = 'en'; assert.equal(providerCopy('رسالة تحقق أصلية من الخادم'), 'رسالة تحقق أصلية من الخادم', 'Unmapped server text is preserved'); locale.value = 'ar';
});
