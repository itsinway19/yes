export const DEFAULT_COLORS = { primary: '#155f53', secondary: '#334b73' };
export const validColor = (value: string) => /^#[0-9a-f]{6}$/i.test(value);
const channels = (value: string) => [1, 3, 5].map(offset => parseInt(value.slice(offset, offset + 2), 16));
export function luminance(value: string): number {
    if (!validColor(value)) throw new Error('Invalid color');
    const rgb = channels(value).map(channel => { const c = channel / 255; return c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4; });
    return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
}
export function foreground(value: string): string { const light = luminance(value); return (light + .05) / .05 >= 1.05 / (light + .05) ? '#000000' : '#ffffff'; }
export function ink(value: string): string {
    let result = value.toLowerCase();
    for (let step = 0; step < 64; step++) {
        if (1.05 / (luminance(result) + .05) >= 7) return result;
        result = '#' + channels(result).map(c => Math.round(c * .9).toString(16).padStart(2, '0')).join('');
    }
    return '#000000';
}
export function colorTokens(primary: string, secondary: string): Record<string, string> {
    return { '--ba-brand-primary': primary.toLowerCase(), '--ba-brand-secondary': secondary.toLowerCase(), '--ba-primary-text': foreground(primary), '--ba-secondary-text': foreground(secondary), '--ba-primary-ink': ink(primary), '--ba-secondary-ink': ink(secondary) };
}
