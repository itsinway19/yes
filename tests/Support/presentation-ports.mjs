// Feature tests inject document evidence and presentation independently. They do
// not start a real document, router, lease timer or browser restoration runtime.
const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
export function withPresentationPorts(ports) {
    const document = ports['../spa/transport'] ?? ports['./transport'] ?? ports['@/spa/transport'];
    if (!document) return ports;
    const previous = ports['../spa/presentation'] ?? ports['@/spa/presentation'];
    const presentation = moduleUrl(`import * as document from ${JSON.stringify(document)};
        ${previous ? `import * as feature from ${JSON.stringify(previous)};` : 'const feature = {};'}
        export const status = feature.status ?? document.status;
        export const confirmDeparture = feature.confirmDeparture ?? {value:()=>true};
        export function publish(admission, commit) { if (admission) admission.publish(commit); else commit(); }`);
    return { ...ports, '../spa/transport': document, '../spa/presentation': presentation, '@/spa/presentation': presentation };
}
