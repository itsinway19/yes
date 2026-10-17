import Ajv from 'ajv/dist/2020.js';
import { spec } from '../../scripts/application-openapi.mjs';

let input = '';
for await (const chunk of process.stdin) input += chunk;
const validate = new Ajv({ strict: false, validateFormats: false }).compile({ ...spec, $ref: `#/components/schemas/${process.argv[2]}` });
if (!validate(JSON.parse(input))) {
    process.stderr.write(JSON.stringify(validate.errors));
    process.exitCode = 1;
}
