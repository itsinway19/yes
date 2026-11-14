/** Routing references confer no authority; the server qualifies each operation. */
export type NativeSourceReference = { kind: 'business'; publicId: string };
export type NativeSourceProps = { businessId: string };
export function nativeSourceReference(props: { businessId?: string }): NativeSourceReference {
    if (!props.businessId || 'workspaceId' in props) throw Error('One exact Business source owner is required');
    return { kind: 'business', publicId: props.businessId };
}
export function ownerKey(owner: NativeSourceReference): string {
    if (!owner || typeof owner !== 'object' || owner.kind !== 'business' || typeof owner.publicId !== 'string') throw Error('Retired source owner');
    return `business:${owner.publicId}`;
}
export function commandOwner(owner: NativeSourceReference): { business: string } {
    ownerKey(owner); return { business: owner.publicId };
}
export function providerRoute(owner: NativeSourceReference) {
    ownerKey(owner); return { root: '/businesses/{business}' as const, path: { business: owner.publicId } };
}
