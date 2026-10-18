import { bootstrap } from '../spa/transport';

export function goalsPath(workspace: string, review = false): string {
    return `${bootstrap.application ? '' : '/spa'}/workspaces/${workspace}/goals${review ? '/review' : ''}`;
}
