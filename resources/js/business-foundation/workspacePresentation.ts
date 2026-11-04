import { onClose, stamp } from '../spa/transport';
export type WorkspaceSection = 'overview' | 'inquiries' | 'direction' | 'sources' | 'settings' | 'access';
export type WorkspaceSettingsView = 'details' | 'data' | 'purposes' | 'access';
export function workspaceLocation(section: WorkspaceSection, settingsView: WorkspaceSettingsView = 'details') {
    if (section === 'inquiries') return { section: 'settings' as const, settingsView: 'data' as const };
    if (section === 'access') return { section: 'settings' as const, settingsView: 'access' as const };
    return { section, settingsView };
}
// Presentation only: no Business facts, source assertions or authority are stored here.
export const workspacePresentation = new Map<string, { section: WorkspaceSection; settingsView?: WorkspaceSettingsView; positions: Partial<Record<WorkspaceSection, number>> }>();
export const workspacePresentationKey = (resource: string) => `${stamp()}:${resource}`;
onClose(() => workspacePresentation.clear());
