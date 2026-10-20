export function goalsPath(workspace: string, review = false): string {
    return `/workspaces/${workspace}/goals${review ? '/review' : ''}`;
}
