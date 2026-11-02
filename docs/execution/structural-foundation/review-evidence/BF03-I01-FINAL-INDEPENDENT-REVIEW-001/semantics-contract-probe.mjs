import fs from 'node:fs';
import Ajv from 'ajv';

// Read-only examination of the actual generated schema; no HTTP/SQL assertions.
const spec = JSON.parse(fs.readFileSync('contracts/application.openapi.json', 'utf8'));
const schema = spec.components.schemas.InquiryDeclarationValue;
const validate = new Ajv({ strict: false, allErrors: true }).compile(schema);
const value = {
    cohort: { channel_kind: 'form', definition_version: 1, country_code: 'SA', population: 'recorded_inquiries', counting_policy: 'distinct_inquiries' },
    observation: { period_start: '2020-01-01T00:00:00Z', period_end: '2020-01-02T00:00:00Z', as_of: '2020-01-03T00:00:00Z', timezone: 'Asia/Riyadh' },
    cost: null,
    inquiries: { count: 100, coverage: 'complete' },
    acceptance: { count: 10, pending_count: 0, criterion_code: 'operator_defined', criterion_version: 1, cutoff: '2020-01-03T00:00:00Z', maturity: 'mature', coverage: 'complete' },
};
const result = {
    scope: 'Generated InquiryDeclarationValue schema only; SQL constraints separately inspected, not executed.',
    baselineAccepted: validate(value),
    acceptanceProperties: Object.keys(schema.properties.acceptance.anyOf[0].properties),
    cohortProperties: Object.keys(schema.properties.cohort.properties),
    variants: [],
};
for (const [name, mutate] of [
    ['criterion_definition', v => { v.acceptance.criterion_definition = 'Inquiry is inside the service region and has a stated budget.'; }],
    ['criterion_ref', v => { v.acceptance.criterion_ref = 'own-private-criterion-1'; }],
    ['cohort_definition', v => { v.cohort.definition = 'Only paid-search form inquiries for service A.'; }],
]) {
    const candidate = structuredClone(value);
    mutate(candidate);
    result.variants.push({ name, accepted: validate(candidate), errors: structuredClone(validate.errors) });
}
console.log(JSON.stringify(result, null, 2));
