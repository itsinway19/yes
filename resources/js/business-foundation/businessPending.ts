import { stamp } from '../spa/transport';
import { hasPendingPurposeScope } from '../object-purpose/commands';
import { pendingCommands } from '../inquiry-evidence/editor';
import { commandsFor } from '../sources/commands';
import { businessDraftPrefix, hasPendingBusinessDraft } from './drafts';

/** Presentation safety for a new archive, across this Business's admitted paths. */
export function hasPendingBusinessOperation(business: string): boolean {
    return hasPendingBusinessDraft(business)
        || hasPendingPurposeScope(businessDraftPrefix(business))
        || [...pendingCommands.keys()].some(key => key.startsWith(`${stamp()}:${business}:`))
        || commandsFor({ kind: 'business', publicId: business }).length > 0;
}
