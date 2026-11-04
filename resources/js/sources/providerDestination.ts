// Presentation destinations only: these keys grant no provider or Business authority.
export const advertisingPlatforms = [
    { id: 'google', label: 'Google Ads' },
    { id: 'meta', label: 'Meta Ads' },
    { id: 'snapchat', label: 'Snapchat Ads' },
    { id: 'tiktok', label: 'TikTok Ads' },
    { id: 'x', label: 'X Ads' },
] as const;
export type AdvertisingPlatform = typeof advertisingPlatforms[number]['id'];
export function selectedAdvertisingPlatform(value: unknown): AdvertisingPlatform | undefined {
    return typeof value === 'string' && advertisingPlatforms.some(platform => platform.id === value)
        ? value as AdvertisingPlatform : undefined;
}
export function initialAdvertisingPlatform(value: unknown, hash: string): AdvertisingPlatform | undefined {
    // Original OAuth callback owns entry when both presentation hints exist.
    return selectedAdvertisingPlatform(/^#(meta|google|tiktok|snapchat|x)-(?:flow|result)=/.exec(hash)?.[1])
        ?? selectedAdvertisingPlatform(value);
}
