import {readFile,writeFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import assert from 'node:assert/strict';
const path='contracts/application.openapi.json';
const before=JSON.parse(execFileSync('git',['show',`HEAD:${path}`],{encoding:'utf8',maxBuffer:8*1024*1024}));
const after=JSON.parse(await readFile(path,'utf8'));
let paths=0,schemas=0;
for(const [key,value] of Object.entries(before.paths)){assert.deepStrictEqual(after.paths[key],value,`Existing path changed: ${key}`);paths++;}
for(const [key,value] of Object.entries(before.components.schemas)){assert.deepStrictEqual(after.components.schemas[key],value,`Existing schema changed: ${key}`);schemas++;}
const result={scope:'Existing generated OpenAPI path/schema preservation; does not prove endpoint behavior',existingPathsPreserved:paths,existingSchemasPreserved:schemas,newPaths:Object.keys(after.paths).filter(p=>!(p in before.paths)),newSchemas:Object.keys(after.components.schemas).filter(p=>!(p in before.components.schemas)),result:'PASS'};
await writeFile(new URL('./contract-preservation-probe.json',import.meta.url),JSON.stringify(result,null,2));
console.log(JSON.stringify(result,null,2));
