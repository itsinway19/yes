import { onClose, stamp } from '../spa/transport';
export type WorkspaceSection = 'overview' | 'inquiries' | 'direction' | 'settings' | 'access';
// Presentation only: no Business facts, source assertions or authority are stored here.
export const workspacePresentation = new Map<string, { section: WorkspaceSection; positions: Partial<Record<WorkspaceSection, number>> }>();
export const workspacePresentationKey = (resource: string) => `${stamp()}:${resource}`;
onClose(() => workspacePresentation.clear());
