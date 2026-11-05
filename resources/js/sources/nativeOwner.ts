/** Resource routing only; the server qualifies every operation independently. */
export type NativeSourceReference = string | { kind: 'business'; publicId: string };
export type NativeSourceProps = { workspaceId: string; businessId?: never } | { businessId: string; workspaceId?: never };

export function nativeSourceReference(props: { workspaceId?: string; businessId?: string }): NativeSourceReference {
    if (!!props.workspaceId === !!props.businessId) throw Error('One exact source owner is required');
    return props.businessId ? { kind: 'business', publicId: props.businessId } : props.workspaceId!;
}
export function ownerKey(owner: NativeSourceReference): string {
    return typeof owner === 'string' ? owner : `business:${owner.publicId}`;
}
export function commandOwner(owner: NativeSourceReference): { workspace: string; business?: never } | { business: string; workspace?: never } {
    return typeof owner === 'string' ? { workspace: owner } : { business: owner.publicId };
}
export function providerRoute(owner: NativeSourceReference) {
    const id = typeof owner === 'string' ? owner : owner.publicId;
    return {
        root: typeof owner === 'string' ? '/workspaces/{workspace}' as const : '/businesses/{business}' as const,
        // Only the parameter named in the selected template is serialized.
        path: { workspace: id, business: id },
    };
}
