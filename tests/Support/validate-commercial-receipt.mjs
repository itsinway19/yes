import { matchesOriginalReceipt } from '../../resources/js/commercial/receiptProvenance.ts';

let input = '';
for await (const chunk of process.stdin) input += chunk;
const { command, receipt, principal } = JSON.parse(input);
console.log(JSON.stringify({ matches: await matchesOriginalReceipt(command, receipt, principal) }));
