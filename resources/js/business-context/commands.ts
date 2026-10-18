import { nextTick, reactive } from 'vue';
import { owned, stamp, TransportError } from '../spa/transport';
import { locale } from '../spa/locale';
import { read } from './state';
import { updateContext, updateMarkets, updateIntent, requestAuthority, type ContextUpdate, type MarketsUpdate, type IntentCommand, type AuthorityRequest } from './api';

type Options = { onSuccess?: (reply: { redirect: string }) => void; onError?: (errors: Record<string, string>) => void; onFinish?: () => void; onHttpException?: (response: { status: number }) => void };
type ReadOptions = Omit<Options, 'onSuccess'> & { onSuccess?: () => void };
type Command = () => Promise<{ redirect: string }>;
export async function perform(workspace: string, command: Command, options: Options = {}) {
    const captured = stamp();
    try {
        const reply = await command();
        if (!owned(captured)) return;
        const latest = await read(workspace, true);
        if (!owned(captured) || !latest) return;
        await nextTick();
        if (!owned(captured)) return;
        options.onSuccess?.(reply);
    } catch (failure) {
        if (!owned(captured)) return;
        if (failure instanceof TransportError) {
            options.onHttpException?.({ status: failure.status });
            if (failure.status === 413 && options.onHttpException) return;
            const errors = Object.fromEntries(Object.entries(failure.body.errors ?? {}).map(([key, messages]) => [key, messages[0]]));
            options.onError?.(Object.keys(errors).length ? errors : { value: failure.status === 403
                ? (locale.value === 'ar' ? 'لم يعد لديك إذن لتنفيذ هذا الطلب. احتفظنا بالمسودة.' : 'You no longer have permission. The draft is retained.')
                : (locale.value === 'ar' ? 'تعذّر تأكيد الحفظ. احتفظ بالمسودة وراجع الحالة الحالية قبل إعادة المحاولة.' : 'Save could not be confirmed. Keep your draft and review current state before retrying.') });
        } else options.onError?.({ value: locale.value === 'ar' ? 'تعذّر تأكيد الحفظ. احتفظ بالمسودة وراجع الحالة الحالية قبل إعادة المحاولة.' : 'Save could not be confirmed. Keep your draft and review current state before retrying.' });
    } finally { options.onFinish?.(); }
}
export async function refresh(workspace: string, options: ReadOptions = {}) {
    const captured = stamp();
    const latest = await read(workspace, true);
    await nextTick();
    if (latest && owned(captured)) options.onSuccess?.();
    options.onFinish?.();
}
export const saveIntent = (workspace: string, body: IntentCommand, options: Options) => perform(workspace, () => updateIntent(workspace, body), options);
type FormState = { processing: boolean; recentlySuccessful: boolean; errors: Record<string, string>; submit: (workspace: string, options?: Options) => Promise<void> };
export function useBusinessForm<Payload extends ContextUpdate | MarketsUpdate | AuthorityRequest>(input: Payload, kind: 'context' | 'markets' | 'authority') {
    const keys = Object.keys(input) as (keyof Payload)[];
    const form = reactive({ ...input, processing: false, recentlySuccessful: false, errors: {} as Record<string, string> }) as unknown as Payload & FormState;
    form.submit = async (workspace, options = {}) => {
        if (form.processing) return;
        const body = JSON.parse(JSON.stringify(Object.fromEntries(keys.map(key => [key, form[key]])))) as Payload;
        form.processing = true; form.recentlySuccessful = false; form.errors = {};
        await perform(workspace, () => kind === 'context' ? updateContext(workspace, body as ContextUpdate)
            : kind === 'markets' ? updateMarkets(workspace, body as MarketsUpdate) : requestAuthority(workspace, body as AuthorityRequest), {
            ...options,
            onSuccess: reply => { form.recentlySuccessful = true; options.onSuccess?.(reply); },
            onError: errors => { form.errors = errors; options.onError?.(errors); },
            onFinish: () => { form.processing = false; options.onFinish?.(); },
        });
    };
    return form;
}
