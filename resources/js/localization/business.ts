import { usePage } from '@inertiajs/vue3';
import { useBusinessCopy as useCopy } from './businessCopy';

export function useBusinessCopy() {
    const page = usePage();
    return useCopy(() => page.props.locale);
}
