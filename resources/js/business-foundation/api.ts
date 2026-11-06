import type { components, operations } from '../api/generated/application';
import { api } from '../spa/api';
export type Choices = components['schemas']['BusinessChoices'];
export type SourceChoices = components['schemas']['NativeSourceChoices'];
export type InternalTeamCommand = components['schemas']['InternalTeamCommand'];
export type InternalTeamSurface = components['schemas']['InternalTeamSurface'];
export type DirectTeamEnrollment = operations['enrollIndependentBusinessTeam']['requestBody']['content']['application/json'];
export type PurposeStatementEnrollment = operations['enrollOwnBusinessPurposeStatements']['requestBody']['content']['application/json'];
export async function enrollPurposeStatement(business: string, body: PurposeStatementEnrollment) {
    const result = await api.POST('/api/businesses/{business}/purpose-enrollment', { params: { path: { business } }, body });
    if (!result.data) throw Error('Purpose statement enrollment receipt unavailable');
    return result.data.data;
}
export async function enrollDirectTeam(business: string, body: DirectTeamEnrollment) {
    const result = await api.POST('/api/businesses/{business}/direct-team', { params: { path: { business } }, body });
    if (!result.data) throw Error('Independent team enrollment receipt unavailable');
    return result.data.data;
}
export async function internalTeamSurface(business: string, access: number): Promise<InternalTeamSurface> {
    const result = await api.GET('/api/businesses/{business}/internal-team', { params: { path: { business }, query: { access } } });
    if (!result.data) throw Error('Internal file team unavailable');
    return result.data.data;
}
export async function writeInternalTeam(business: string, body: InternalTeamCommand) {
    const result = await api.POST('/api/businesses/{business}/internal-team', { params: { path: { business } }, body });
    if (!result.data) throw Error('Internal file custody receipt unavailable');
    return result.data.data;
}
export type BusinessSources = components['schemas']['BusinessSources'];
export async function businessSources(business: string, access: number, after?: number): Promise<BusinessSources> {
    const result = await api.GET('/api/businesses/{business}/sources', {
        params: { path: { business }, query: { access, ...(after ? { after } : {}) } },
    });
    if (!result.data) throw Error('Qualified Business sources unavailable');
    return result.data.data;
}
export type SourceSurface = components['schemas']['NativeSourceSurface'];
export type DataUseChoices = components['schemas']['DataUseChoices'];
export type BindingCommand = operations['writeBusinessDataUseBinding']['requestBody']['content']['application/json'];
export async function dataUseChoices(after?: number): Promise<DataUseChoices> {
    const result = await api.GET('/api/data-rights/use-scopes', { params: { query: after ? { after } : {} } });
    if (!result.data) throw Error('Data-use scope choices unavailable');
    return result.data.data;
}
export async function writeBinding(business: string, body: BindingCommand) {
    const result = await api.POST('/api/businesses/{business}/data-use-bindings', { params: { path: { business } }, body });
    if (!result.data) throw Error('Data-use binding acknowledgement unavailable');
    return result.data.data;
}
export type BoundaryChoices = components['schemas']['ClientBoundaryChoices'];
export type BoundarySurface = components['schemas']['ClientBoundarySurface'];
export type ResourceCommand = operations['writeQualifiedDataResourceRight']['requestBody']['content']['application/json'];
export async function writeResourceRight(body: ResourceCommand) {
    const result = await api.POST('/api/data-rights/commands', { body });
    if (!result.data) throw Error('Resource right acknowledgement unavailable');
    return result.data.data;
}
export async function businessChoices(after?: number): Promise<Choices> {
    const result = await api.GET('/api/businesses', { params: { query: after ? { after } : {} } });
    if (!result.data) throw Error('Business choices unavailable');
    return result.data.data;
}
export async function businessSurface(business: string, access: number): Promise<Surface> {
    const result = await api.GET('/api/businesses/{business}/surface', { params: { path: { business }, query: { access } } });
    if (!result.data) throw Error('Business selection unavailable');
    return result.data.data;
}
export type BusinessPurposes = components['schemas']['BusinessPurposes'];
export async function businessPurposes(business: string, access: number): Promise<BusinessPurposes> {
    const result = await api.GET('/api/businesses/{business}/purposes', {params:{path:{business},query:{access}}});
    if (!result.data) throw Error('Business purposes unavailable'); return result.data.data;
}
export async function writeBusinessPurpose(business: string, body: operations['writeBusinessPurpose']['requestBody']['content']['application/json']) {
    const result = await api.POST('/api/businesses/{business}/purposes', {params:{path:{business}},body});
    if (!result.data) throw Error('Purpose acknowledgement unavailable'); return result.data.data;
}
export type Surface = components['schemas']['BusinessSurface'];
export type LifecycleCommand = operations['writeIndependentBusinessLifecycle']['requestBody']['content']['application/json'];
export async function writeLifecycle(business: string, body: LifecycleCommand) {
    const result = await api.POST('/api/businesses/{business}/lifecycle', { params: { path: { business } }, body });
    if (!result.data) throw Error('Business lifecycle acknowledgement unavailable');
    return result.data.data;
}
export type BusinessHistory = components['schemas']['BusinessHistory'];
export type LegacyHistory = components['schemas']['BusinessLegacyHistory'];
export async function legacyHistory(business: string, access: number, kind: LegacyHistory['kind'], after?: string): Promise<LegacyHistory> {
    const result = await api.GET('/api/businesses/{business}/legacy-history', { params: { path: { business }, query: { access, kind, ...(after ? { after } : {}) } } });
    if (!result.data) throw Error('Qualified original history unavailable');
    return result.data.data;
}
export async function businessHistory(business: string, access: number, kind: BusinessHistory['kind'], after?: string): Promise<BusinessHistory> {
    if (after && !Number.isSafeInteger(Number(after))) throw Error('History cursor is not safely representable');
    const result = await api.GET('/api/businesses/{business}/history', { params: { path: { business }, query: { access, kind, ...(after ? { after: Number(after) } : {}) } } });
    if (!result.data) throw Error('Business history unavailable');
    return result.data.data;
}
export type Catalog = components['schemas']['BusinessCatalog'];
export type DraftResult = components['schemas']['BusinessDraftResult']['data'];
export type WriteResult = components['schemas']['BusinessContextWriteResult']['data'];
export type Selection = components['schemas']['BusinessAuthoritySelection'];
export type CreateCommand = { command_id: string; name: string; business_type_id: number | null };
export type ContextCommand = CreateCommand & { expected_context: number; authority_selection: Selection };
export type LinkCommand = operations['writeIndependentBusinessRelationship']['requestBody']['content']['application/json'];
export type GrantCommand = operations['writeIndependentBusinessGrant']['requestBody']['content']['application/json'];
export async function writeGrant(business: string, body: GrantCommand) {
    const result = await api.POST('/api/businesses/{business}/grants', { params: { path: { business } }, body });
    if (!result.data) throw Error('Delegation acknowledgement unavailable');
    return result.data.data;
}
export async function writeLink(business: string, body: LinkCommand) {
    const result = await api.POST('/api/businesses/{business}/workspace-links', { params: { path: { business } }, body });
    if (!result.data) throw Error('Relationship acknowledgement unavailable');
    return result.data.data;
}
export type FactKind = 'markets' | 'intent' | 'preferences';
export type Intent = components['schemas']['CurrentIntentValue'];
export type Goals = components['schemas']['GoalValue'];
type FactBase = { command_id: string; authority_selection: Selection };
export type FactCommand = FactBase & ({ kind: 'markets'; codes: string[]; expected_context: number }
    | { kind: 'intent'; value: Intent | null; confirmations: Record<string, boolean>; expected_context: number; expected_intent: number }
    | { kind: 'preferences'; value: components['schemas']['WriteGoalValue']; expected_preference: number });
export async function writeFact(business: string, command: FactCommand) {
    const params = { path: { business } };
    if (command.kind === 'markets') {
        const { kind: _kind, ...body } = command;
        const result = await api.PUT('/api/businesses/{business}/markets', { params, body });
        if (!result.data) throw Error('Business acknowledgement unavailable');
        return result.data.data;
    }
    if (command.kind === 'intent') {
        const { kind: _kind, ...body } = command;
        const result = await api.PUT('/api/businesses/{business}/intent', { params, body });
        if (!result.data) throw Error('Business acknowledgement unavailable');
        return result.data.data;
    }
    const { kind: _kind, ...body } = command;
    const result = await api.PUT('/api/businesses/{business}/preferences', { params, body });
    if (!result.data) throw Error('Business acknowledgement unavailable');
    return result.data.data;
}
export async function create(body: CreateCommand): Promise<DraftResult> {
    const result = await api.POST('/api/businesses', { body });
    if (!result.data) throw Error('Business acknowledgement unavailable');
    return result.data.data;
}
export async function write(business: string, body: ContextCommand): Promise<WriteResult> {
    const result = await api.PUT('/api/businesses/{business}/context', { params: { path: { business } }, body });
    if (!result.data) throw Error('Business acknowledgement unavailable');
    return result.data.data;
}
