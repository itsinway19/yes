export interface paths {
    "/workspaces/{workspace}/goals": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description Accept application/json for canonical preferences. Native HTML requests receive the unified SPA shell, without a business projection. */
        get: operations["readGoals"];
        put?: never;
        /** @description Canonical existing writer; exact two-field JSON object, maximum 8192 bytes. No automatic retry after uncertain delivery. Same-origin Laravel CSRF checks remain authoritative. */
        post: operations["saveGoals"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/goals/context": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description Unified SPA contextual read through the existing WorkspaceContext, Goals and BusinessContext readers. Passive/witness requests preserve canonical idle authority. */
        get: operations["readCanonicalGoalsContext"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["listWorkspaces"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/business/start": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readBusinessCreation"];
        put?: never;
        /** @description Existing atomic WorkspaceAuthority/BusinessContext writer and session creation key. No automatic retry after uncertain delivery. An explicit same-command retry preserves the original key and body. */
        post: operations["createBusiness"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/reassess-owner-grant": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** @description Existing fresh-authenticated GrantAuthority owner reassessment; no revoked or expired grant is restored. */
        post: operations["reassessOwnerGrant"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/business": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readBusinessContext"];
        put?: never;
        post: operations["updateBusinessContext"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/business/authority": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["requestBusinessContextAuthority"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/business/target-markets": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["replaceBusinessTargetMarkets"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/business/current-intent": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["replaceOrClearCurrentIntent"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/object-purposes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readObjectPurposeProjection"];
        put?: never;
        /** @description Original fresh-authenticated Meta-only HTTP admission edge and canonical SQL writer. Command identity is idempotent; uncertain delivery is never automatically replayed or replaced. */
        post: operations["declareObjectPurpose"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/object-purposes/{purpose}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readSelectedObjectPurposeProjection"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/object-purposes/context": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readObjectPurposeContext"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/object-purposes/{purpose}/context": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readSelectedObjectPurposeContext"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/object-purposes/{purpose}/revisions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** @description Original fresh-authenticated Meta-only HTTP admission edge and canonical SQL writer. Command identity is idempotent; uncertain delivery is never automatically replayed or replaced. */
        post: operations["reviseObjectPurpose"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/locale": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["setApplicationLocale"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readWorkspaceSources"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/source-grants": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["requestSourceAuthority"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/sources": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["applySourceCommand"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/source-audit/{obligation}/recover": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["recoverSourceAudit"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/services": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["authorizeServiceScope"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/services/{service}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete: operations["revokeServiceScope"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/meta": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["metaConnectionSnapshot"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/meta/connect": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["metaBeginConnection"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/meta/discovery/{flow}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["metaDiscoverAccounts"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/meta/select": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["metaSelectAccount"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/meta/{source}/disconnect": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["metaDisconnectLocalAccess"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/meta/{source}/report": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description Existing MetaReportPeriod; no absent observations are converted to zero or COMPLETE. */
        get: operations["metaReportStatus"];
        put?: never;
        /** @description Existing bounded background queue request, not a provider acknowledgement, report completion or business outcome. Preserve the original command, version, generation and deadline after unknown delivery. */
        post: operations["metaQueueReport"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/google": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["googleConnectionSnapshot"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/google/connect": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["googleBeginConnection"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/google/discovery/{flow}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["googleDiscoverAccounts"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/google/select": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["googleSelectAccount"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/google/{source}/disconnect": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["googleDisconnectLocalAccess"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/google/{source}/report": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description Existing ProviderReportPeriod; no absent observations are converted to zero or COMPLETE. */
        get: operations["googleReportStatus"];
        put?: never;
        /** @description Existing bounded background queue request, not a provider acknowledgement, report completion or business outcome. Preserve the original command, version, generation and deadline after unknown delivery. */
        post: operations["googleQueueReport"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/google/hierarchy/{flow}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["googleBrowseHierarchy"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/google/{source}/renew": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["googleRenewAccess"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/tiktok": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["tiktokConnectionSnapshot"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/tiktok/connect": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["tiktokBeginConnection"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/tiktok/discovery/{flow}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["tiktokDiscoverAccounts"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/tiktok/select": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["tiktokSelectAccount"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/tiktok/{source}/disconnect": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["tiktokDisconnectLocalAccess"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/tiktok/{source}/report": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description Existing ProviderReportPeriod; no absent observations are converted to zero or COMPLETE. */
        get: operations["tiktokReportStatus"];
        put?: never;
        /** @description Existing bounded background queue request, not a provider acknowledgement, report completion or business outcome. Preserve the original command, version, generation and deadline after unknown delivery. */
        post: operations["tiktokQueueReport"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/snapchat": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["snapchatConnectionSnapshot"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/snapchat/connect": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["snapchatBeginConnection"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/snapchat/discovery/{flow}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["snapchatDiscoverAccounts"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/snapchat/select": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["snapchatSelectAccount"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/snapchat/{source}/disconnect": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["snapchatDisconnectLocalAccess"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/snapchat/{source}/report": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description Existing ProviderReportPeriod; no absent observations are converted to zero or COMPLETE. */
        get: operations["snapchatReportStatus"];
        put?: never;
        /** @description Existing bounded background queue request, not a provider acknowledgement, report completion or business outcome. Preserve the original command, version, generation and deadline after unknown delivery. */
        post: operations["snapchatQueueReport"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/snapchat/{source}/renew": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["snapchatRenewAccess"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/x": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["xConnectionSnapshot"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/x/connect": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["xBeginConnection"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/x/discovery/{flow}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["xDiscoverAccounts"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/x/select": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["xSelectAccount"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/x/{source}/disconnect": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["xDisconnectLocalAccess"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/x/{source}/report": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description Existing XReportPeriod; no absent observations are converted to zero or COMPLETE. */
        get: operations["xReportStatus"];
        put?: never;
        /** @description Existing bounded background queue request, not a provider acknowledgement, report completion or business outcome. Preserve the original command, version, generation and deadline after unknown delivery. */
        post: operations["xQueueReport"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workspaces/{workspace}/providers/x/{source}/campaigns": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["xCampaignChoices"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/profile": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readAccountProfile"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch: operations["updateAccountProfile"];
        trace?: never;
    };
    "/password": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put: operations["updateAccountPassword"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/security/devices": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readAccountDevices"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/security/sessions/{session}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete: operations["revokeAccountSession"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/security/sessions/revoke-others": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["revokeOtherAccountSessions"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/security/passkeys/options": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["accountPasskeyOptions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/security/passkeys": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["storeAccountPasskey"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/security/passkeys/{passkey}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete: operations["deleteAccountPasskey"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/security/devices": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readInternalDevices"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/security/sessions/{session}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete: operations["revokeInternalSession"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/security/sessions/revoke-others": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["revokeOtherInternalSessions"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/security/passkeys/options": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["internalPasskeyOptions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/security/passkeys": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["storeInternalPasskey"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/security/passkeys/{passkey}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete: operations["deleteInternalPasskey"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/staff/onboarding": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readStaffEnrollment"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/staff/onboarding/passkeys/options": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["staffEnrollmentOptions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/staff/onboarding/passkeys": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["storeStaffEnrollmentPasskey"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/login": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readLoginEntry"];
        put?: never;
        /** @description Existing JSON identity transition contract. The shared transport verifies its acknowledgement and departs to a new native document; the browser never adopts the returned identity. */
        post: operations["authenticateAdvertiser"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/register": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readRegistrationEntry"];
        put?: never;
        /** @description Existing JSON identity transition contract. The shared transport verifies its acknowledgement and departs to a new native document; the browser never adopts the returned identity. */
        post: operations["registerAdvertiser"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/identity/switch": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readIdentitySwitchEntry"];
        put?: never;
        /** @description Existing JSON identity transition contract. The shared transport verifies its acknowledgement and departs to a new native document; the browser never adopts the returned identity. */
        post: operations["switchAdvertiser"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/forgot-password": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readRecoveryEntry"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/reset-password/{token}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readResetEntry"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/verify-email": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readVerificationEntry"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/confirm-password": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readConfirmationEntry"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/auth/passkey/options": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["advertiserLoginOptions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/identity/passkey/options": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["advertiserSwitchOptions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/auth/passkey": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** @description Existing JSON identity transition contract. The shared transport verifies its acknowledgement and departs to a new native document; the browser never adopts the returned identity. */
        post: operations["authenticateAdvertiserPasskey"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/identity/passkey": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** @description Existing JSON identity transition contract. The shared transport verifies its acknowledgement and departs to a new native document; the browser never adopts the returned identity. */
        post: operations["switchAdvertiserPasskey"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/login": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readInternalLoginEntry"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/identity/switch": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readInternalSwitchEntry"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/auth/passkey/options": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["internalLoginOptions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/identity/passkey/options": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["internalSwitchOptions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/auth/passkey": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** @description Existing JSON identity transition contract. The shared transport verifies its acknowledgement and departs to a new native document; the browser never adopts the returned identity. */
        post: operations["authenticateInternalPasskey"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/identity/passkey": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** @description Existing JSON identity transition contract. The shared transport verifies its acknowledgement and departs to a new native document; the browser never adopts the returned identity. */
        post: operations["switchInternalPasskey"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/locale": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["setInternalLocale"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/services": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readInternalServices"];
        put?: never;
        post: operations["createInternalService"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/services/{service}/rotate": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["rotateInternalService"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/services/{service}/revoke": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["revokeInternalService"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/service-grants": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["issueInternalServiceGrant"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/service-grants/{grant}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete: operations["revokeInternalServiceGrant"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/business-types": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readInternalBusinessTypes"];
        put?: never;
        post: operations["createInternalBusinessType"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/business-types/{type}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["updateInternalBusinessType"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/business-types/{type}/guidance": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["saveInternalBusinessGuidance"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/business-types/{type}/prior": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["saveInternalBusinessPrior"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/administration/customers": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readInternalCustomers"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/administration/customers/status": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["changeInternalCustomerStatus"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/administration": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readInternalAdministration"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/staff": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["inviteInternalStaff"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/staff/{staff}/resend-invitation": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["internalStaffresend_invitation"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/staff/{staff}/attest": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["internalStaffattest"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/staff/{staff}/activate": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["internalStaffactivate"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/staff/{staff}/revoke": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["internalStaffrevoke"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/staff/{staff}/attest-factors": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["internalStaffattest_factors"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/grants": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["issueInternalStaffGrant"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/grants/{grant}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete: operations["revokeInternalStaffGrant"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readCommercialOperations"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial/markets": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["recordCommercialMarket"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial/catalog-references": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["recordCommercialCatalog"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial/configurations": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["recordCommercialConfiguration"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial/assignability": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["setCommercialAssignability"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial/registrations": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["registerCommercialScope"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial/receipts/{command}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readCommercialCommandReceipt"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial/workspace-reference": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readCommercialWorkspaceReference"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial/contexts/{scope}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readInternalCommercialContext"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial/audit/{obligation}/recover": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["recoverCommercialAudit"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial/grants": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["issueCommercialGrant"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial/grants/{grant}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete: operations["revokeCommercialGrant"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/commercial/owner-mandate": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["executeCommercialOwnerMandate"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/administration/commercial-markets": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get: operations["readCommercialPreparation"];
        put?: never;
        post: operations["prepareCommercialMarket"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/administration/commercial-markets/new-intent": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["startNextCommercialIntent"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/administration/commercial-markets/delegate": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["delegateCommercialMarket"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/internal/administration/commercial-markets/{approval}/operator": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post: operations["authorizePreparedCommercialOperator"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
}
export type webhooks = Record<string, never>;
export interface components {
    schemas: {
        GoalValue: {
            goals: string[];
            priority: string | null;
            results: {
                [key: string]: string[];
            };
        };
        Preferences: {
            value: components["schemas"]["GoalValue"];
            version: number;
        };
        SaveGoals: {
            value: components["schemas"]["WriteGoalValue"];
            version: number;
        };
        /** @description Canonical SQL additionally requires exact catalog IDs, selected priority, results only for selected goals and results from the matching catalog. It normalizes ordering and empty results. Initial read may be empty/null; that state is not a valid write. */
        WriteGoalValue: {
            goals: string[];
            priority: string;
            results: {
                [key: string]: string[];
            };
        };
        Error: {
            message?: string;
            identityConflict?: boolean;
        } & {
            [key: string]: unknown;
        };
        ValidationError: {
            message: string;
            errors: {
                [key: string]: string[];
            };
        };
        Visibility: {
            protected: boolean;
            path: string;
            context: string;
        };
        GoalsWitness: {
            visibility: components["schemas"]["Visibility"];
        };
        GoalsContext: {
            workspace: {
                /** Format: uuid */
                public_id: string;
                name: string;
            };
            preferences: components["schemas"]["Preferences"];
            business: {
                type: ({
                    name_ar: string;
                    name_en: string;
                } & {
                    [key: string]: unknown;
                }) | null;
                canManage: boolean;
            } & {
                [key: string]: unknown;
            };
            catalog: {
                id: string;
                name_ar: string;
                name_en: string;
                description_ar: string;
                description_en: string;
                results: {
                    id: string;
                    name_ar: string;
                    name_en: string;
                }[];
            }[];
            visibility: components["schemas"]["Visibility"];
        };
        WorkspaceReference: {
            /** Format: uuid */
            public_id: string;
            name: string;
            slug: string;
        };
        WorkspaceIndexData: {
            workspaces: components["schemas"]["WorkspaceReference"][];
            reassessableWorkspaces: components["schemas"]["WorkspaceReference"][];
        };
        WorkspaceIndexResource: {
            data: components["schemas"]["WorkspaceIndexData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        BusinessType: {
            id: number;
            key: string;
            name_ar: string;
            name_en: string;
            active: boolean;
            version: number;
        };
        BusinessCreationData: {
            types: components["schemas"]["BusinessType"][];
            /** @constant */
            canManage: true;
            /** @constant */
            canRequestAuthority: false;
            /** Format: uuid */
            creationKey: string;
            /** @constant */
            version: 0;
        };
        BusinessCreationResource: {
            data: components["schemas"]["BusinessCreationData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        CreateBusiness: {
            name: string;
            business_type_id: number;
            /** Format: uuid */
            creation_key: string;
        };
        ApplicationDestination: {
            redirect: string;
        };
        CountryReference: {
            code: string;
            name_ar: string;
            name_en: string;
        };
        CurrentIntentValue: {
            /** @enum {unknown} */
            direction: "increase_value" | "preserve_value" | "improve_efficiency_preserving_value" | "validate_business_direction";
            /** @constant */
            scope: "business_wide";
            description: string | null;
            outcome: {
                meaning: string;
                clarification: string | null;
                count_unit: string | null;
            };
            quantity: string | null;
            horizon: {
                deadline_date: string;
                timezone: string;
            } | null;
        };
        CurrentIntentProjection: {
            /** @enum {unknown} */
            state: "NOT_DECLARED" | "PRESENT";
            value: components["schemas"]["CurrentIntentValue"] | null;
            version: number;
        };
        BusinessContextProjection: {
            type: {
                id: number;
                key: string;
                name_ar: string;
                name_en: string;
                active: boolean;
            } | null;
            version: number;
            currentIntent: components["schemas"]["CurrentIntentProjection"];
            canManage: boolean;
            targetMarketsDeclared: boolean;
            targetMarkets: components["schemas"]["CountryReference"][];
        };
        BusinessContextData: {
            workspace: {
                /** Format: uuid */
                public_id: string;
                name: string;
                business_type_id: number | null;
                business_context_version: number;
            };
            types: components["schemas"]["BusinessType"][];
            canManage: boolean;
            canRequestAuthority: boolean;
            version: number;
            business: components["schemas"]["BusinessContextProjection"];
            countryOptions: components["schemas"]["CountryReference"][];
            timezoneOptions: string[];
        };
        BusinessContextResource: {
            data: components["schemas"]["BusinessContextData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        UpdateBusinessContext: {
            name: string;
            business_type_id: number;
            version: number;
        };
        RequestBusinessContextAuthority: {
            /** @constant */
            decision: "authorize";
            /** @constant */
            reason: "complete_business_context";
        };
        ReplaceTargetMarkets: {
            country_codes: string[];
            version: number;
        };
        CurrentIntentCommand: {
            /** @enum {unknown} */
            command: "replace" | "clear";
            value: components["schemas"]["CurrentIntentValue"] | null;
            confirmations: {
                declaration?: boolean;
                business_wide?: boolean;
                outcome?: boolean;
                acceptance_clear?: boolean;
                clarification_required?: boolean;
                quantity?: boolean;
                horizon?: boolean;
            };
            version: number;
        };
        PurposeObject: {
            provider: string;
            account_namespace: string;
            account_id: string;
            object_kind: string;
            object_id: string;
            incarnation: string | null;
        };
        MetaPurposeObject: components["schemas"]["PurposeObject"] & ({
            /** @constant */
            provider: "meta";
            /** @constant */
            account_namespace: "meta_ads_account";
            account_id: string;
            /** @enum {unknown} */
            object_kind: "campaign" | "ad_set";
            object_id: string;
        } & {
            [key: string]: unknown;
        });
        PurposeSnapshot: {
            meaning: string;
            qualifications: string | null;
            phase: string | null;
            included_objects: components["schemas"]["PurposeObject"][];
            excluded_objects: components["schemas"]["PurposeObject"][];
            provenance: {
                speaker: string | null;
                statement_at: string | null;
                source: string;
                identity_basis: string;
                authority_basis: string;
                historical_support: string | null;
                effective_basis: string;
            };
            dtm: {
                mode: string;
                version: number;
                countries: string[];
                subset: string[];
                statement: string;
            } | null;
            intent: {
                version: number;
                value: components["schemas"]["CurrentIntentValue"];
                statement: string;
            } | null;
            end_condition: string | null;
            limitations: string | null;
            conflicts: string[];
        };
        PurposeRevision: {
            /** Format: uuid */
            purpose_id: string;
            /** Format: uuid */
            revision_id: string;
            version: number;
            operation: string;
            effective_from: string;
            effective_until: string | null;
            accepted_at: string;
            actor_user_id: number;
            actor_generation: number;
            ownership_version: number;
            snapshot: components["schemas"]["PurposeSnapshot"];
            reason: string | null;
            supersedes_revision_id: string | null;
            affected_revision_ids: string[];
        } & {
            [key: string]: unknown;
        };
        PurposeItem: {
            /** Format: uuid */
            purpose_id: string;
            head_version: number;
            state: string;
            applicable: components["schemas"]["PurposeRevision"] | null;
            history: components["schemas"]["PurposeRevision"][];
            relationship_revalidation_required: boolean;
            continuity: string;
            dtm_standing: string;
            scheduled_revision_ids: string[];
        };
        PurposeProjection: {
            state: string;
            items: components["schemas"]["PurposeItem"][];
            current_context_version: number;
            latest_dtm_event_context_version: number;
            effective_at: string;
            known_at: string;
        };
        ObjectPurposeData: {
            workspace: {
                /** Format: uuid */
                public_id: string;
                name: string;
            };
            projection: components["schemas"]["PurposeProjection"];
            selectedPurpose: string | null;
            business: components["schemas"]["BusinessContextProjection"];
        };
        ObjectPurposeResource: {
            data: components["schemas"]["ObjectPurposeData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        PurposeCommand: {
            /** Format: uuid */
            command_id: string;
            purpose_id: string | null;
            expected_version: number;
            /** @enum {unknown} */
            operation: "declare" | "replace" | "correct" | "withdraw";
            declaration: components["schemas"]["MetaPurposeSnapshot"] | null;
            effective_from: string | null;
            effective_until: string | null;
            supersedes_revision_id: string | null;
            affected_revision_ids: string[];
            correction_reason: string | null;
            withdrawal_reason: string | null;
            confirmations: {
                meaning: boolean;
                scope: boolean;
                authority: boolean;
                identity: boolean;
                standing: boolean;
                dtm: boolean;
                intent: boolean;
                historical: boolean;
                transition: boolean;
            };
        };
        PurposeAcknowledgement: {
            /** Format: uuid */
            purpose_id: string;
            /** Format: uuid */
            revision_id: string;
            version: number;
        };
        MetaPurposeSnapshot: components["schemas"]["PurposeSnapshot"] & ({
            included_objects: components["schemas"]["MetaPurposeObject"][];
            excluded_objects: components["schemas"]["MetaPurposeObject"][];
        } & {
            [key: string]: unknown;
        });
        SourceScope: {
            resource: string;
            operation: string;
            purpose: string;
        };
        SourceAuthorityCommand: {
            /** Format: uuid */
            source_id: string;
            resource: string;
            operation: string;
            purpose: string;
            reason: string;
            /** @enum {unknown} */
            action: "source.register" | "source.manage" | "source.read" | "source.audit.recover" | "provider.connect" | "provider.read" | "provider.revoke";
            /** @enum {unknown} */
            decision: "authorize" | "revoke";
            expires_at?: string | null;
        };
        SourceCommand: {
            /** Format: uuid */
            source_id: string;
            resource: string;
            operation: string;
            purpose: string;
            reason: string;
            /** Format: uuid */
            command_id: string;
            expected_version: number;
            /** @constant */
            effect: "register";
            family: string;
            namespace: string;
            external_id: string;
            label: string;
        } | {
            /** Format: uuid */
            source_id: string;
            resource: string;
            operation: string;
            purpose: string;
            reason: string;
            /** Format: uuid */
            command_id: string;
            expected_version: number;
            /** @constant */
            effect: "rename";
            label: string;
        } | {
            /** Format: uuid */
            source_id: string;
            resource: string;
            operation: string;
            purpose: string;
            reason: string;
            /** Format: uuid */
            command_id: string;
            expected_version: number;
            /** @constant */
            effect: "declare_scope";
        } | {
            /** Format: uuid */
            source_id: string;
            resource: string;
            operation: string;
            purpose: string;
            reason: string;
            /** Format: uuid */
            command_id: string;
            expected_version: number;
            /** @constant */
            effect: "revoke";
        };
        SourceReceipt: {
            /** Format: uuid */
            command_id: string;
            /** Format: uuid */
            source_id: string;
            version: number;
            registration_state: string;
            /** Format: uuid */
            obligation_id: string;
            /** @constant */
            local_audit: "DURABLE";
            reason: string;
        };
        SourceItem: {
            resource: string;
            operation: string;
            purpose: string;
            /** Format: uuid */
            id: string;
            family: string;
            namespace: string;
            declared_external_id: string;
            label: string;
            version: number;
            status: string;
            /** @constant */
            identity_verification: "UNVERIFIED";
            axes: {
                [key: string]: string;
            };
            capability: {
                state: string;
                reason: string;
                last_verified_at: string | null;
            };
            can_manage: boolean;
            scope_choices: components["schemas"]["SourceScope"][];
            next_repair: string;
            audit_delivery: {
                resource: string;
                operation: string;
                purpose: string;
                /** Format: uuid */
                id: string;
                /** Format: uuid */
                command_id: string;
                receipt_version: number;
                state: string;
                attempts: number;
                can_recover: boolean;
                recovery_reason: string | null;
            }[];
        } & {
            [key: string]: unknown;
        };
        SourceRegistryProjection: {
            state: string;
            items: components["schemas"]["SourceItem"][];
            registration_authorities: {
                /** Format: uuid */
                source_target_id: string;
                source_resource: string;
                source_operation: string;
                source_purpose: string;
            }[];
        };
        WorkspaceSourcesData: {
            workspace: components["schemas"]["WorkspaceReference"];
            business: components["schemas"]["BusinessContextProjection"];
            guidance: {
                sources: {
                    provider: string;
                    label: string;
                    priority: number;
                    rationale: string | null;
                }[];
                priors: {
                    id: number;
                    provider: string;
                    product: string;
                    label: string;
                    rationale: string;
                    applicability: string;
                    limits: string;
                    basis: string;
                }[];
            };
            canRequestSourceAuthority: boolean;
            canManageServices: boolean;
            /** Format: uuid */
            sourceCandidateId: string;
            sources: components["schemas"]["SourceRegistryProjection"];
            services: {
                /** Format: uuid */
                public_id: string;
                name: string;
                purpose: string;
            }[];
            commercial: {
                state: string;
                context: {
                    scope_id: string;
                    /** Format: uuid */
                    market_id: string;
                    market_label: string;
                    configuration_id: string;
                    catalog_identity: string;
                    catalog_version: string;
                    denomination_code: string;
                    effective_at: string;
                } | null;
                agreement_state: string;
                amount_state: string;
            };
        };
        WorkspaceSourcesResource: {
            data: components["schemas"]["WorkspaceSourcesData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        GoogleConnectCommand: {
            intent: components["schemas"]["ProviderFlowHandle"];
            recover_offline_grant?: boolean;
        } | {
            /** Format: uuid */
            source_id: string;
            expected_version: number;
            recover_offline_grant?: boolean;
        };
        SnapchatRenewalSuccess: {
            /** Format: uuid */
            source_id: string;
            receipt: unknown;
            access: components["schemas"]["ProviderAccess"];
            status?: never;
        } & {
            [key: string]: unknown;
        };
        SnapchatRenewalActionRequired: {
            /** @constant */
            status: "PROVIDER_ACTION_REQUIRED";
            reason: string;
            /** Format: uuid */
            source_id: string;
            access: components["schemas"]["ProviderAccess"];
            receipt?: never;
        };
        SnapchatRenewalOutcome: components["schemas"]["SnapchatRenewalSuccess"] | components["schemas"]["SnapchatRenewalActionRequired"];
        ProviderFlowHandle: string;
        ProviderActionError: {
            status: string;
            reason: string;
        } & {
            [key: string]: unknown;
        };
        ProviderAccess: {
            version: number;
            connection: string;
            native_observation: {
                state: string;
                observed_at: string;
            } | null;
            axes: {
                [key: string]: string;
            };
            effective: {
                state: string;
                reason: string;
                last_verified_at: string | null;
            };
            reporting: {
                can_report: boolean;
                why_not: string | null;
            };
        };
        ProviderSnapshot: {
            connections: {
                /** Format: uuid */
                source_id: string;
                label: string;
                declared_account_id: string;
                registration: string;
                access: components["schemas"]["ProviderAccess"];
            }[];
            availability: string;
            connect_intent: components["schemas"]["ProviderFlowHandle"] | null;
            notice: ({
                privacy_url: string;
                revision: string;
                deletion_instructions_url?: string;
                purpose?: string;
            } & {
                [key: string]: unknown;
            }) | null;
        };
        ProviderAccountChoice: {
            account_id: string;
            label: string;
            existing_source_label: string | null;
            selection_handle: components["schemas"]["ProviderFlowHandle"];
        };
        GoogleAccountChoice: {
            account_id: string;
            label: string;
            existing_source_label: string | null;
            selection_handle: components["schemas"]["ProviderFlowHandle"] | null;
            hierarchy_handle: components["schemas"]["ProviderFlowHandle"] | null;
            manager: boolean | null;
        };
        ProviderVersionCommand: {
            /** Format: uuid */
            command_id: string;
            expected_version: number;
        };
        ProviderReportPeriod: {
            /** Format: date */
            start_date: string;
            /** Format: date */
            end_date: string;
        };
        MetaReportPeriod: {
            /** Format: date */
            start_date: string;
            /** Format: date */
            end_date: string;
            /** @enum {unknown} */
            mode?: "SYNC" | "ASYNC";
        };
        XReportPeriod: {
            /** Format: date */
            start_date: string;
            /** Format: date */
            end_date: string;
            campaign_ids: string[];
            /** @enum {unknown} */
            placement: "ALL_ON_TWITTER" | "SPOTLIGHT" | "TREND";
            /** @enum {unknown} */
            mode?: "SYNC" | "ASYNC";
        };
        ProviderReportCommand: {
            /** Format: date */
            start_date: string;
            /** Format: date */
            end_date: string;
            /** Format: uuid */
            command_id: string;
            expected_version: number;
            expected_generation: number;
            /** Format: date-time */
            deadline: string;
        };
        MetaReportCommand: {
            /** Format: date */
            start_date: string;
            /** Format: date */
            end_date: string;
            /** @enum {unknown} */
            mode?: "SYNC" | "ASYNC";
            /** Format: uuid */
            command_id: string;
            expected_version: number;
            expected_generation: number;
            /** Format: date-time */
            deadline: string;
        };
        XReportCommand: {
            /** Format: date */
            start_date: string;
            /** Format: date */
            end_date: string;
            campaign_ids: string[];
            /** @enum {unknown} */
            placement: "ALL_ON_TWITTER" | "SPOTLIGHT" | "TREND";
            /** @enum {unknown} */
            mode?: "SYNC" | "ASYNC";
            /** Format: uuid */
            command_id: string;
            expected_version: number;
            expected_generation: number;
            /** Format: date-time */
            deadline: string;
        };
        ProviderReportStatus: {
            /** Format: uuid */
            source_id: string;
            generation: number;
            timezone: string;
            deadline: string;
            download?: {
                generation: number;
                url: string;
            } | null;
            last_run: {
                coverage: string;
                failure: string | null;
                recorded_at: string;
            } | null;
        };
        InternalDevicesData: {
            /** @constant */
            internalContext: true;
            sessions: components["schemas"]["AccountSession"][];
            passkeys: components["schemas"]["AccountPasskey"][];
        };
        InternalDevicesResource: {
            data: components["schemas"]["InternalDevicesData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        StaffEnrollmentData: {
            verified: boolean;
            /** @enum {unknown} */
            kind: "founder" | "employee";
            passkeys: {
                id: number;
                name: string;
                created_at: string;
            }[];
            attested: boolean;
        };
        StaffEnrollmentResource: {
            data: components["schemas"]["StaffEnrollmentData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        AccountAcknowledgement: {
            /** @constant */
            saved: true;
        };
        AccountProfileCommand: {
            name: string;
            /** Format: email */
            email: string;
        };
        AccountPasswordCommand: {
            current_password: string;
            password: string;
            password_confirmation: string;
        };
        AccountProfileData: {
            user: {
                name: string;
                email: string;
                email_verified_at: string | null;
            };
            mustVerifyEmail: boolean;
            canChangeEmail: boolean;
            status: string | null;
            errors: {
                [key: string]: string[];
            } | unknown[];
            old: {
                name: string | null;
                email: string | null;
            };
        };
        AccountProfileResource: {
            data: components["schemas"]["AccountProfileData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        AccountSession: {
            id: number;
            current: boolean;
            description: string;
            last_seen_at: string;
            authenticated_at: string;
        };
        AccountPasskey: {
            id: number;
            name: string;
            provider: string | null;
            created_at: string;
            last_used_at: string | null;
        };
        AccountDevicesData: {
            /** @constant */
            internalContext: false;
            sessions: components["schemas"]["AccountSession"][];
            passkeys: components["schemas"]["AccountPasskey"][];
        };
        AccountDevicesResource: {
            data: components["schemas"]["AccountDevicesData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        AccountPasskeyOptions: {
            options: {
                [key: string]: unknown;
            };
        };
        AccountPasskeyCommand: {
            name: string;
            credential: {
                id: string;
                rawId: string;
                /** @constant */
                type: "public-key";
                response: {
                    [key: string]: unknown;
                };
            } & {
                [key: string]: unknown;
            };
        };
        AccountPasskeyAcknowledgement: {
            id: number;
            name: string;
        };
        InternalAuthTransitionResponse: {
            transition: {
                /** Format: uuid */
                lineage: string;
                /** @constant */
                boundary: "internal";
                epoch: number;
                nonce: string;
                /** @constant */
                kind: "login";
                /** @constant */
                status: "active";
            };
            identity: components["schemas"]["AuthIdentityEvidence"];
            redirect: string;
        };
        AuthEntryVisibility: {
            /** @constant */
            protected: false;
            path: string;
            context: null;
        } | {
            /** @constant */
            protected: true;
            path: string;
            context: string;
        };
        AuthEntryWitness: {
            visibility: components["schemas"]["AuthEntryVisibility"];
        };
        AuthEntryResource: {
            data: components["schemas"]["AuthEntryData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["AuthEntryVisibility"];
        };
        AuthPasswordCommand: {
            /** Format: email */
            email: string;
            password: string;
            remember?: boolean;
        };
        AuthRegistrationCommand: {
            name: string;
            /** Format: email */
            email: string;
            password: string;
            password_confirmation: string;
        };
        AuthPasskeyCommand: {
            credential: {
                id: string;
                rawId: string;
                /** @constant */
                type: "public-key";
                response: {
                    [key: string]: unknown;
                };
            } & {
                [key: string]: unknown;
            };
        };
        AuthIdentityBinding: {
            /** Format: uuid */
            lineage: string;
            epoch: number;
            /** @enum {unknown} */
            boundary: "advertiser" | "internal";
            credential: string;
            principal: number;
            lifecycle: number;
            context: string;
            generation: string;
        };
        AuthIdentityEvidence: {
            receipt: string;
            binding: components["schemas"]["AuthIdentityBinding"];
            csrf: string;
            transition: string;
            leaseMilliseconds: number;
            /** @enum {unknown} */
            renewalProtocol: 0 | 1;
        };
        AuthTransitionResponse: {
            transition: {
                /** Format: uuid */
                lineage: string;
                /** @constant */
                boundary: "advertiser";
                epoch: number;
                nonce: string;
                /** @constant */
                kind: "login";
                /** @constant */
                status: "active";
            };
            identity: components["schemas"]["AuthIdentityEvidence"];
            redirect: string;
        };
        AuthEntryData: {
            /** @enum {unknown} */
            kind: "login" | "register" | "forgot-password" | "reset-password" | "verify-email" | "confirm-password";
            canResetPassword: boolean;
            passwordPolicy: {
                min: number;
                max: number | null;
                browserRules: string;
            } | null;
            status: string | null;
            errors: {
                [key: string]: string[];
            } | unknown[];
            old: {
                name: string | null;
                email: string | null;
            };
        };
        InternalServiceCreate: {
            name: string;
        };
        InternalServiceCredential: {
            /** Format: uuid */
            public_id: string;
            credential: string;
        };
        InternalServiceGrantCommand: {
            /** Format: uuid */
            service: string;
            /** Format: uuid */
            workspace: string;
            reason: string;
        };
        InternalServicesData: {
            services: {
                /** Format: uuid */
                public_id: string;
                name: string;
                /** @enum {unknown} */
                status: "enabled" | "revoked";
                version: number;
            }[];
            scopes: {
                /** Format: uuid */
                service_public_id: string;
                service_name: string;
                purpose: string;
                /** Format: uuid */
                workspace_public_id: string;
            }[];
            grants: {
                id: number;
                service_name: string;
                reason: string;
                /** Format: uuid */
                workspace_public_id: string;
            }[];
        };
        InternalServicesResource: {
            data: components["schemas"]["InternalServicesData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        InternalBusinessTypeCreate: {
            key: string;
            name_ar: string;
            name_en: string;
            active: boolean;
        };
        InternalBusinessTypeUpdate: {
            key: string;
            name_ar: string;
            name_en: string;
            active: boolean;
            version: number;
        };
        InternalBusinessGuidanceCommand: {
            /** @enum {unknown} */
            provider: "meta" | "google" | "snapchat" | "tiktok" | "x";
            priority: number;
            rationale_ar: string;
            rationale_en: string;
            active: boolean;
        };
        InternalBusinessPriorCommand: {
            /** @enum {unknown} */
            provider: "meta" | "google" | "snapchat" | "tiktok" | "x";
            /** @enum {unknown} */
            product: "google.search";
            /** @enum {unknown} */
            basis: "ADMIN_APPROVED_HYPOTHESIS" | "EVIDENCE_SUPPORTED_PRIOR";
            documented_basis: string;
            applicability: string;
            limits: string;
            rationale_ar: string;
            rationale_en: string;
            internal_rationale: string;
            approved: boolean;
            active: boolean;
        } | {
            /** @enum {unknown} */
            provider: "meta" | "google" | "snapchat" | "tiktok" | "x";
            /** @enum {unknown} */
            product: "google.search";
            /** @enum {unknown} */
            basis: "ADMIN_APPROVED_HYPOTHESIS" | "EVIDENCE_SUPPORTED_PRIOR";
            documented_basis: string;
            applicability: string;
            limits: string;
            rationale_ar: string;
            rationale_en: string;
            internal_rationale: string;
            approved: boolean;
            active: boolean;
            id: number;
            version: number;
        };
        InternalBusinessTypesData: {
            canManage: boolean;
            types: {
                key: string;
                name_ar: string;
                name_en: string;
                active: boolean;
                id: number;
                version: number;
                source_guidance: {
                    /** @enum {unknown} */
                    provider: "meta" | "google" | "snapchat" | "tiktok" | "x";
                    priority: number;
                    rationale_ar: string;
                    rationale_en: string;
                    active: boolean;
                    business_type_id: number;
                }[];
                promotion_priors: {
                    /** @enum {unknown} */
                    provider: "meta" | "google" | "snapchat" | "tiktok" | "x";
                    /** @enum {unknown} */
                    product: "google.search";
                    /** @enum {unknown} */
                    basis: "ADMIN_APPROVED_HYPOTHESIS" | "EVIDENCE_SUPPORTED_PRIOR";
                    documented_basis: string;
                    applicability: string;
                    limits: string;
                    rationale_ar: string;
                    rationale_en: string;
                    internal_rationale: string;
                    approved: boolean;
                    active: boolean;
                    id: number;
                    business_type_id: number;
                    version: number;
                    approved_by: number | null;
                    approved_at: string | null;
                }[];
            }[];
            providers: {
                /** @enum {unknown} */
                key: "meta" | "google" | "snapchat" | "tiktok" | "x";
                label: string;
            }[];
            products: {
                /** @enum {unknown} */
                key: "google.search";
                /** @enum {unknown} */
                provider: "meta" | "google" | "snapchat" | "tiktok" | "x";
                label_ar: string;
                label_en: string;
            }[];
        };
        InternalBusinessTypesResource: {
            data: components["schemas"]["InternalBusinessTypesData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        InternalCustomerStatusCommand: {
            /** Format: uuid */
            command_id: string;
            /** @enum {unknown} */
            kind: "users" | "workspaces";
            /** Format: uuid */
            target: string;
            /** @enum {unknown} */
            decision: "suspend" | "reactivate";
            reason: string;
            /** @enum {unknown} */
            expected_status: "pending" | "current" | "disabled" | "active" | "suspended";
            expected_revision: number;
        };
        InternalCustomerStatusAcknowledgement: {
            /** @enum {unknown} */
            status: "pending" | "current" | "disabled" | "active" | "suspended";
            revision: number;
            replayed: boolean;
        };
        InternalCustomersData: {
            filters: {
                /** @enum {unknown} */
                kind: "users" | "workspaces";
                search: string;
                status: string;
                page: number;
            };
            freshAuthentication: boolean;
            total: number;
            rows: ({
                /** Format: uuid */
                public_id: string;
                name: string;
                revision: number;
                /** @enum {unknown} */
                status: "pending" | "current" | "disabled";
                email: string;
                email_verified_at: string | null;
                workspace_count: number;
                can_reactivate: boolean;
            } | {
                /** Format: uuid */
                public_id: string;
                name: string;
                revision: number;
                /** @enum {unknown} */
                status: "active" | "suspended" | "archived";
                business_type_ar: string | null;
                business_type_en: string | null;
                owner_name: string | null;
                owner_email: string | null;
                market_label: string | null;
                member_count: number;
            })[];
            details: ({
                /** Format: uuid */
                public_id: string;
                name: string;
                history: {
                    /** @enum {unknown} */
                    decision: "suspend" | "reactivate";
                    reason: string;
                    occurred_at: string;
                    actor_name: string;
                }[];
                email: string;
                workspaces: {
                    /** Format: uuid */
                    public_id: string;
                    name: string;
                    /** @enum {unknown} */
                    status: "active" | "suspended" | "archived";
                    role: string;
                }[];
            } | {
                /** Format: uuid */
                public_id: string;
                name: string;
                history: {
                    /** @enum {unknown} */
                    decision: "suspend" | "reactivate";
                    reason: string;
                    occurred_at: string;
                    actor_name: string;
                }[];
                members: {
                    name: string;
                    email: string;
                    role: string;
                    /** @enum {unknown} */
                    status: "pending" | "current" | "disabled";
                }[];
            }) | null;
        };
        InternalCustomersResource: {
            data: components["schemas"]["InternalCustomersData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        InternalOperationAcknowledgement: {
            /** @constant */
            saved: true;
        };
        InternalStaffInvitation: {
            name: string;
            /** Format: email */
            email: string;
        };
        InternalStaffGrantCommand: {
            /** Format: uuid */
            staff: string;
            action: string;
            reason: string;
        };
        InternalAdministrationData: {
            people: {
                /** Format: uuid */
                public_id: string;
                name: string;
                email: string;
                email_verified_at: string | null;
                /** @enum {unknown} */
                kind: "founder" | "employee";
                /** @enum {unknown} */
                status: "pending" | "active" | "revoked";
                attested_at: string | null;
                factor_independence_attested_at: string | null;
                passkey_count: number;
            }[];
            staffGrants: {
                id: number;
                action: string;
                reason: string;
                /** Format: uuid */
                public_id: string;
                name: string;
            }[];
            grantableActions: string[];
            canAttestFactors: boolean;
            canManageCommercialPreparation: boolean;
            permissions: {
                "staff.invite": boolean;
                "staff.attest": boolean;
                "staff.activate": boolean;
                "staff.revoke": boolean;
                "grant.manage": boolean;
                "service.manage": boolean;
            };
            metaOperatorStatus: {
                application_configured: boolean;
                notice_links_configured: boolean;
            } | null;
            googleOperatorStatus: {
                application_configured: boolean;
                notice_links_configured: boolean;
            } | null;
            tiktokOperatorStatus: {
                application_configured: boolean;
                notice_links_configured: boolean;
            } | null;
            snapchatOperatorStatus: {
                application_configured: boolean;
                notice_links_configured: boolean;
            } | null;
            xOperatorStatus: {
                application_configured: boolean;
                notice_links_configured: boolean;
            } | null;
        };
        InternalAdministrationResource: {
            data: components["schemas"]["InternalAdministrationData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        CommercialOperationsData: {
            markets: {
                /** Format: uuid */
                id: string;
                label: string;
                country_code: string | null;
                configuration_id: string | null;
                revision: number;
            }[];
            configurations: {
                /** Format: uuid */
                id: string;
                /** Format: uuid */
                market_id: string;
                version: number;
                catalog_reference_id: string | null;
                denomination_code: string | null;
                recorded_at: string;
            }[];
            catalogReferences: {
                /** Format: uuid */
                id: string;
                /** Format: uuid */
                market_id: string;
                /** Format: uuid */
                catalog_identity: string;
                catalog_version: string;
                approval_reference: string;
                source_sha256: string;
                recorded_at: string;
            }[];
            countryOptions: {
                [key: string]: {
                    ar: string;
                    en: string;
                    currency: string;
                };
            };
            currencyOptions: string[];
            currencyNames: {
                [key: string]: {
                    ar: string;
                    en: string;
                };
            };
            targets: {
                action: string;
                /** Format: uuid */
                target_id: string;
                version_id: string | null;
                workspace_public_id: string | null;
                label?: string | null;
            }[];
            newMarketTargets: {
                action: string;
                /** Format: uuid */
                target_id: string;
                version_id: string | null;
                workspace_public_id: string | null;
                label?: string | null;
            }[];
            obligations: {
                /** Format: uuid */
                id: string;
                /** Format: uuid */
                command_id: string;
                /** Format: uuid */
                target_id: string;
                state: string;
                attempts: number;
                acknowledgment_id: string | null;
                workspace_public_id: string | null;
            }[];
            founderAccess: boolean;
            /** Format: uuid */
            newCatalogReferenceId: string;
            /** Format: uuid */
            newConfigurationId: string;
            result: {
                [key: string]: unknown;
            } | null;
            freshAuthentication: boolean;
        };
        CommercialOperationsResource: {
            data: components["schemas"]["CommercialOperationsData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        CommercialMarketCommand: {
            /** Format: uuid */
            command_id: string;
            reason_code: string;
            /** Format: uuid */
            market_id: string;
            label: string;
        };
        CommercialCatalogCommand: {
            /** Format: uuid */
            command_id: string;
            reason_code: string;
            /** Format: uuid */
            market_id: string;
            /** Format: uuid */
            catalog_reference_id: string;
            /** Format: uuid */
            catalog_identity: string;
            catalog_version: string;
            approval_reference: string;
            source_sha256: string;
        };
        CommercialConfigurationCommand: {
            /** Format: uuid */
            command_id: string;
            reason_code: string;
            /** Format: uuid */
            market_id: string;
            /** Format: uuid */
            configuration_id: string;
            catalog_reference_id?: string | null;
            denomination_code?: string | null;
        };
        CommercialAssignabilityCommand: {
            /** Format: uuid */
            command_id: string;
            reason_code: string;
            /** Format: uuid */
            market_id: string;
            configuration_id?: string | null;
            expected_revision: number;
        };
        CommercialRegistrationCommand: {
            /** Format: uuid */
            command_id: string;
            reason_code: string;
            /** Format: uuid */
            scope_id: string;
            /** Format: uuid */
            workspace_public_id: string;
            /** Format: uuid */
            market_id: string;
            /** Format: uuid */
            configuration_id: string;
            /** Format: uuid */
            catalog_reference_id: string;
            denomination_code: string;
        };
        CommercialEffectResult: {
            /** Format: uuid */
            market_id: string;
            label: string;
        } | {
            /** Format: uuid */
            market_id: string;
            /** Format: uuid */
            configuration_id: string;
            version: number;
            catalog_reference_id: string | null;
            denomination_code: string | null;
        } | {
            /** Format: uuid */
            catalog_reference_id: string;
            /** Format: uuid */
            market_id: string;
            /** Format: uuid */
            catalog_identity: string;
            catalog_version: string;
        } | {
            /** Format: uuid */
            market_id: string;
            configuration_id: string | null;
            revision: number;
        } | {
            /** Format: uuid */
            scope_id: string;
            /** Format: uuid */
            workspace_public_id: string;
            /** Format: uuid */
            market_id: string;
            /** Format: uuid */
            configuration_id: string;
            /** Format: uuid */
            catalog_reference_id: string;
            /** Format: uuid */
            catalog_identity: string;
            catalog_version: string;
            configuration_version: number;
            denomination_code: string;
            /** @constant */
            formation_state: "CONTEXT_RECORDED";
            /** @constant */
            agreement_state: "UNKNOWN";
            /** @constant */
            amount_state: "UNKNOWN";
        };
        CommercialCommandReceipt: {
            /** Format: uuid */
            command_id: string;
            effect: string;
            /** Format: uuid */
            target_id: string;
            workspace_id: number | null;
            request_fingerprint: string;
            result: string;
            actor_user_id: number;
            actor_generation: number;
            reason_code: string;
            policy_version: string;
            decided_at: string;
            recorded_at: string;
        };
        CommercialGrantCommand: {
            /** Format: uuid */
            grantee: string;
            effect: string;
            /** Format: uuid */
            target_id: string;
            workspace_id?: number | null;
            version_id?: string | null;
            reason_code: string;
            expires_at?: string | null;
        };
        CommercialContextResult: {
            /** @constant */
            state: "CONTEXT_RECORDED";
            context: {
                /** Format: uuid */
                scope_id: string;
                /** Format: uuid */
                market_id: string;
                market_label: string;
                /** Format: uuid */
                configuration_id: string;
                /** Format: uuid */
                catalog_identity: string;
                catalog_version: string;
                denomination_code: string;
                decided_at: string;
                effective_at: string;
                recorded_at: string;
                /** Format: uuid */
                workspace_public_id: string;
            };
            /** @constant */
            agreement_state: "UNKNOWN";
            /** @constant */
            amount_state: "UNKNOWN";
        };
        CommercialPreparationData: {
            canPrepare: boolean;
            intentId: string | null;
            intentBinding: string | null;
            people: {
                /** Format: uuid */
                public_id: string;
                name: string;
                kind: string;
            }[];
            approvals: {
                /** Format: uuid */
                id: string;
                /** Format: uuid */
                target_id: string;
                label: string;
                reason: string;
                expires_at: string;
                approved_at: string;
                administrator_name: string;
                /** Format: uuid */
                administrator_public_id: string;
                founder_id: number;
                administrator_id: number;
                status: string;
                revoked_at: string | null;
                consumed: boolean;
                active: boolean;
                canAuthorize: boolean;
            }[];
            countryOptions: {
                [key: string]: {
                    ar: string;
                    en: string;
                    currency: string;
                };
            };
            currencyOptions: string[];
            currencyNames: {
                [key: string]: {
                    ar: string;
                    en: string;
                };
            };
            freshAuthentication: boolean;
            directRecorded: boolean;
            recordedMarkets: {
                /** Format: uuid */
                id: string;
                label: string;
                country_code: string | null;
                configuration_id: string | null;
                revision: number;
            }[];
        };
        CommercialPreparationResource: {
            data: components["schemas"]["CommercialPreparationData"];
            /** @enum {unknown} */
            locale: "ar" | "en";
            visibility: components["schemas"]["Visibility"];
        };
        CommercialPreparationCommand: {
            /** @constant */
            mode: "direct";
            /** Format: uuid */
            intent_id: string;
            intent_binding: string;
            country_code: string;
            denomination_code: string;
            reason: string;
        } | {
            /** @constant */
            mode: "direct";
            /** Format: uuid */
            intent_id: string;
            intent_binding: string;
            label: string;
            reason: string;
        } | {
            /** Format: uuid */
            intent_id: string;
            intent_binding: string;
            /** Format: uuid */
            administrator: string;
            label: string;
            reason: string;
            expires_at: string;
        };
        CommercialPreparationAcknowledgement: {
            /** Format: uuid */
            market_id: string;
            label: string;
            country_code: string;
            /** Format: uuid */
            configuration_id: string;
            denomination_code: string;
        } | {
            /** Format: uuid */
            market_id: string;
            label: string;
        } | {
            /** @constant */
            state: "MARKET_AUTHORITY_APPROVED";
            /** Format: uuid */
            target_id: string;
            /** Format: uuid */
            approval_id: string;
            label: string;
        };
        CommercialOperatorCommand: {
            /** Format: uuid */
            operator: string;
            reason: string;
        };
        CommercialDelegationCommand: {
            /** Format: uuid */
            market_id: string;
            /** Format: uuid */
            operator: string;
            /** @enum {unknown} */
            permission: "read" | "manage";
            reason: string;
            expires_at: string;
        };
    };
    responses: never;
    parameters: never;
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    readGoals: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized canonical projection. Never shared-cache private data. */
            200: {
                headers: {
                    /** @description Server document evidence, verified by the web transport. Not independent authority. */
                    "X-Builderad-Identity"?: string;
                    "Cache-Control"?: string;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Preferences"];
                };
            };
            /** @description No current authenticated session. A stale document may instead be rejected with identityConflict 409 before auth. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Unverified principal, absent Workspace scope or withdrawn grant. No write effect. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description CAS mismatch: preserve draft and explicitly review current version. identityConflict=true instead closes the document; never adopt it as a CAS conflict. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description CSRF rejection, including a transport session that no longer contains the CSRF token. No write effect. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Invalid exact JSON value/version shape, size or domain selection; errors.value contains validation messages. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
        };
    };
    saveGoals: {
        parameters: {
            query?: never;
            header?: {
                /** @description Browser CSRF token. Laravel 13 may accept trusted same-origin Fetch Metadata before token validation; absent that evidence a valid token is required. */
                "X-CSRF-TOKEN"?: string;
            };
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["SaveGoals"];
            };
        };
        responses: {
            /** @description Authorized canonical projection. Never shared-cache private data. */
            200: {
                headers: {
                    /** @description Server document evidence, verified by the web transport. Not independent authority. */
                    "X-Builderad-Identity"?: string;
                    "Cache-Control"?: string;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Preferences"];
                };
            };
            /** @description No current authenticated session. A stale document may instead be rejected with identityConflict 409 before auth. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Unverified principal, absent Workspace scope or withdrawn grant. No write effect. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description CAS mismatch: preserve draft and explicitly review current version. identityConflict=true instead closes the document; never adopt it as a CAS conflict. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description CSRF rejection, including a transport session that no longer contains the CSRF token. No write effect. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Invalid exact JSON value/version shape, size or domain selection; errors.value contains validation messages. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
        };
    };
    readCanonicalGoalsContext: {
        parameters: {
            query?: never;
            header?: {
                "X-Goals-Passive"?: "1";
                "X-Goals-Witness"?: "1";
            };
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized canonical projection. Never shared-cache private data. */
            200: {
                headers: {
                    /** @description Server document evidence, verified by the web transport. Not independent authority. */
                    "X-Builderad-Identity"?: string;
                    "Cache-Control"?: string;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["GoalsContext"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description No current authenticated session. A stale document may instead be rejected with identityConflict 409 before auth. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Unverified principal, absent Workspace scope or withdrawn grant. No write effect. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description CAS mismatch: preserve draft and explicitly review current version. identityConflict=true instead closes the document; never adopt it as a CAS conflict. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description CSRF rejection, including a transport session that no longer contains the CSRF token. No write effect. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Invalid exact JSON value/version shape, size or domain selection; errors.value contains validation messages. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
        };
    };
    listWorkspaces: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["WorkspaceIndexResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readBusinessCreation: {
        parameters: {
            query?: {
                new?: "1";
            };
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["BusinessCreationResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Existing businesses redirect to their list unless new=1. Use a new native document for this boundary. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    createBusiness: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CreateBusiness"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ApplicationDestination"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    reassessOwnerGrant: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ApplicationDestination"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readBusinessContext: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["BusinessContextResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    updateBusinessContext: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["UpdateBusinessContext"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ApplicationDestination"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original technical input-size boundary; preserve the full draft. */
            413: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    requestBusinessContextAuthority: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["RequestBusinessContextAuthority"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ApplicationDestination"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original technical input-size boundary; preserve the full draft. */
            413: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    replaceBusinessTargetMarkets: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ReplaceTargetMarkets"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ApplicationDestination"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original technical input-size boundary; preserve the full draft. */
            413: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    replaceOrClearCurrentIntent: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CurrentIntentCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ApplicationDestination"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original technical input-size boundary; preserve the full draft. */
            413: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readObjectPurposeProjection: {
        parameters: {
            query?: {
                /** @description Existing timezone-qualified temporal filter. Future knowledge and malformed instants remain invalid. */
                effective_at?: string;
                /** @description Existing timezone-qualified temporal filter. Future knowledge and malformed instants remain invalid. */
                known_at?: string;
            };
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["PurposeProjection"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Unknown exact purpose; never an empty successful read. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    declareObjectPurpose: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["PurposeCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["PurposeAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readSelectedObjectPurposeProjection: {
        parameters: {
            query?: {
                /** @description Existing timezone-qualified temporal filter. Future knowledge and malformed instants remain invalid. */
                effective_at?: string;
                /** @description Existing timezone-qualified temporal filter. Future knowledge and malformed instants remain invalid. */
                known_at?: string;
            };
            header?: never;
            path: {
                workspace: string;
                purpose: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["PurposeProjection"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Unknown exact purpose; never an empty successful read. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readObjectPurposeContext: {
        parameters: {
            query?: {
                /** @description Existing timezone-qualified temporal filter. Future knowledge and malformed instants remain invalid. */
                effective_at?: string;
                /** @description Existing timezone-qualified temporal filter. Future knowledge and malformed instants remain invalid. */
                known_at?: string;
            };
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ObjectPurposeResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Unknown exact purpose; never an empty successful read. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readSelectedObjectPurposeContext: {
        parameters: {
            query?: {
                /** @description Existing timezone-qualified temporal filter. Future knowledge and malformed instants remain invalid. */
                effective_at?: string;
                /** @description Existing timezone-qualified temporal filter. Future knowledge and malformed instants remain invalid. */
                known_at?: string;
            };
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path: {
                workspace: string;
                purpose: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ObjectPurposeResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Unknown exact purpose; never an empty successful read. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    reviseObjectPurpose: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                purpose: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["PurposeCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["PurposeAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    setApplicationLocale: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @enum {unknown} */
                    locale: "ar" | "en";
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @enum {unknown} */
                        locale: "ar" | "en";
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readWorkspaceSources: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["WorkspaceSourcesResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Existing missing Business prerequisite redirects to the canonical Business context. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    requestSourceAuthority: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["SourceAuthorityCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @enum {unknown} */
                        decision: "authorize" | "revoke";
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    applySourceCommand: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["SourceCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["SourceReceipt"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    recoverSourceAudit: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                obligation: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        obligation_id: string;
                        delivery_state: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    authorizeServiceScope: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: uuid */
                    service: string;
                    purpose: string;
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ApplicationDestination"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    revokeServiceScope: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                service: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ApplicationDestination"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    metaConnectionSnapshot: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderSnapshot"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    metaBeginConnection: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    intent: components["schemas"]["ProviderFlowHandle"];
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        authorization_url: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    metaDiscoverAccounts: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                flow: components["schemas"]["ProviderFlowHandle"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        accounts: components["schemas"]["ProviderAccountChoice"][];
                        continuation: components["schemas"]["ProviderFlowHandle"] | null;
                        coverage: string;
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    metaSelectAccount: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    selection: components["schemas"]["ProviderFlowHandle"];
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        source_id: string;
                        receipt: unknown;
                        access: components["schemas"]["ProviderAccess"];
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    metaDisconnectLocalAccess: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ProviderVersionCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        source_id: string;
                        receipt: unknown;
                        access: components["schemas"]["ProviderAccess"];
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    metaReportStatus: {
        parameters: {
            query: {
                start_date: string;
                end_date: string;
                mode?: "SYNC" | "ASYNC";
            };
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderReportStatus"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    metaQueueReport: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["MetaReportCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @constant */
                        queued: true;
                        /** Format: uuid */
                        command_id: string;
                        /** Format: uuid */
                        source_id: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    googleConnectionSnapshot: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderSnapshot"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    googleBeginConnection: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["GoogleConnectCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        authorization_url: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    googleDiscoverAccounts: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                flow: components["schemas"]["ProviderFlowHandle"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        accounts: components["schemas"]["GoogleAccountChoice"][];
                        continuation: components["schemas"]["ProviderFlowHandle"] | null;
                        coverage: string;
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    googleSelectAccount: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    selection: components["schemas"]["ProviderFlowHandle"];
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        source_id: string;
                        receipt: unknown;
                        status: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    googleDisconnectLocalAccess: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ProviderVersionCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        source_id: string;
                        receipt: unknown;
                        access: components["schemas"]["ProviderAccess"];
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    googleReportStatus: {
        parameters: {
            query: {
                start_date: string;
                end_date: string;
            };
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderReportStatus"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    googleQueueReport: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ProviderReportCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @constant */
                        queued: true;
                        /** Format: uuid */
                        command_id: string;
                        /** Format: uuid */
                        source_id: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    googleBrowseHierarchy: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                flow: components["schemas"]["ProviderFlowHandle"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        accounts: components["schemas"]["GoogleAccountChoice"][];
                        continuation: components["schemas"]["ProviderFlowHandle"] | null;
                        coverage: string;
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    googleRenewAccess: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ProviderVersionCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        source_id: string;
                        receipt: unknown;
                        access: components["schemas"]["ProviderAccess"];
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    tiktokConnectionSnapshot: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderSnapshot"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    tiktokBeginConnection: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    intent: components["schemas"]["ProviderFlowHandle"];
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        authorization_url: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    tiktokDiscoverAccounts: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                flow: components["schemas"]["ProviderFlowHandle"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        accounts: components["schemas"]["ProviderAccountChoice"][];
                        continuation: components["schemas"]["ProviderFlowHandle"] | null;
                        coverage: string;
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    tiktokSelectAccount: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    selection: components["schemas"]["ProviderFlowHandle"];
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        source_id: string;
                        receipt: unknown;
                        status: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    tiktokDisconnectLocalAccess: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ProviderVersionCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        source_id: string;
                        receipt: unknown;
                        access: components["schemas"]["ProviderAccess"];
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    tiktokReportStatus: {
        parameters: {
            query: {
                start_date: string;
                end_date: string;
            };
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderReportStatus"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    tiktokQueueReport: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ProviderReportCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @constant */
                        queued: true;
                        /** Format: uuid */
                        command_id: string;
                        /** Format: uuid */
                        source_id: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    snapchatConnectionSnapshot: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderSnapshot"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    snapchatBeginConnection: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    intent: components["schemas"]["ProviderFlowHandle"];
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        authorization_url: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    snapchatDiscoverAccounts: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                flow: components["schemas"]["ProviderFlowHandle"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        accounts: components["schemas"]["ProviderAccountChoice"][];
                        continuation: components["schemas"]["ProviderFlowHandle"] | null;
                        coverage: string;
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    snapchatSelectAccount: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    selection: components["schemas"]["ProviderFlowHandle"];
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        source_id: string;
                        receipt: unknown;
                        access: components["schemas"]["ProviderAccess"];
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    snapchatDisconnectLocalAccess: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ProviderVersionCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        source_id: string;
                        receipt: unknown;
                        access: components["schemas"]["ProviderAccess"];
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    snapchatReportStatus: {
        parameters: {
            query: {
                start_date: string;
                end_date: string;
            };
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderReportStatus"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    snapchatQueueReport: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ProviderReportCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @constant */
                        queued: true;
                        /** Format: uuid */
                        command_id: string;
                        /** Format: uuid */
                        source_id: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    snapchatRenewAccess: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ProviderVersionCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["SnapchatRenewalSuccess"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["SnapchatRenewalActionRequired"] | {
                        /** @constant */
                        status: "PROVIDER_ACTION_REQUIRED";
                        reason: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    xConnectionSnapshot: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderSnapshot"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    xBeginConnection: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    intent: components["schemas"]["ProviderFlowHandle"];
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        authorization_url: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    xDiscoverAccounts: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                flow: components["schemas"]["ProviderFlowHandle"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        accounts: components["schemas"]["ProviderAccountChoice"][];
                        continuation: components["schemas"]["ProviderFlowHandle"] | null;
                        coverage: string;
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    xSelectAccount: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    selection: components["schemas"]["ProviderFlowHandle"];
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        source_id: string;
                        receipt: unknown;
                        access: components["schemas"]["ProviderAccess"];
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    xDisconnectLocalAccess: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["ProviderVersionCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        source_id: string;
                        receipt: unknown;
                        access: components["schemas"]["ProviderAccess"];
                    } & {
                        [key: string]: unknown;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    xReportStatus: {
        parameters: {
            query: {
                start_date: string;
                end_date: string;
                "campaign_ids[]": string[];
                placement: "ALL_ON_TWITTER" | "SPOTLIGHT" | "TREND";
                mode?: "SYNC" | "ASYNC";
            };
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderReportStatus"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    xQueueReport: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["XReportCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            202: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @constant */
                        queued: true;
                        /** Format: uuid */
                        command_id: string;
                        /** Format: uuid */
                        source_id: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    xCampaignChoices: {
        parameters: {
            query?: {
                continuation?: components["schemas"]["ProviderFlowHandle"];
            };
            header?: never;
            path: {
                workspace: string;
                source: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        campaigns: {
                            id: string;
                            label: string;
                        }[];
                        continuation: components["schemas"]["ProviderFlowHandle"] | null;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ProviderActionError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readAccountProfile: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountProfileResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    updateAccountProfile: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["AccountProfileCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountAcknowledgement"];
                };
            };
            /** @description Email change retains the native Laravel terminal redirect to login. Submit a native document form; never treat it as an ordinary JSON save. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    updateAccountPassword: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["AccountPasswordCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readAccountDevices: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountDevicesResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    revokeAccountSession: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                session: number;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    revokeOtherAccountSessions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    accountPasskeyOptions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountPasskeyOptions"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    storeAccountPasskey: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["AccountPasskeyCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountPasskeyAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    deleteAccountPasskey: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                passkey: number;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readInternalDevices: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalDevicesResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    revokeInternalSession: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                session: number;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    revokeOtherInternalSessions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    internalPasskeyOptions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountPasskeyOptions"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    storeInternalPasskey: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["AccountPasskeyCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountPasskeyAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    deleteInternalPasskey: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                passkey: number;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readStaffEnrollment: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["StaffEnrollmentResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    staffEnrollmentOptions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountPasskeyOptions"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    storeStaffEnrollmentPasskey: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["AccountPasskeyCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountPasskeyAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readLoginEntry: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthEntryResource"] | components["schemas"]["AuthEntryWitness"];
                };
            };
            /** @description Existing Laravel access/intended destination redirect. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    authenticateAdvertiser: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["AuthPasswordCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthTransitionResponse"];
                };
            };
            /** @description Existing Laravel access middleware redirect, not an authentication success acknowledgement. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readRegistrationEntry: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthEntryResource"] | components["schemas"]["AuthEntryWitness"];
                };
            };
            /** @description Existing Laravel access/intended destination redirect. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    registerAdvertiser: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["AuthRegistrationCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthTransitionResponse"];
                };
            };
            /** @description Existing Laravel access middleware redirect, not an authentication success acknowledgement. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readIdentitySwitchEntry: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthEntryResource"] | components["schemas"]["AuthEntryWitness"];
                };
            };
            /** @description Existing Laravel access/intended destination redirect. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    switchAdvertiser: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["AuthPasswordCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthTransitionResponse"];
                };
            };
            /** @description Existing Laravel access middleware redirect, not an authentication success acknowledgement. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readRecoveryEntry: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthEntryResource"] | components["schemas"]["AuthEntryWitness"];
                };
            };
            /** @description Existing Laravel access/intended destination redirect. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readResetEntry: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path: {
                token: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthEntryResource"] | components["schemas"]["AuthEntryWitness"];
                };
            };
            /** @description Existing Laravel access/intended destination redirect. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readVerificationEntry: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthEntryResource"] | components["schemas"]["AuthEntryWitness"];
                };
            };
            /** @description Existing Laravel access/intended destination redirect. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readConfirmationEntry: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthEntryResource"] | components["schemas"]["AuthEntryWitness"];
                };
            };
            /** @description Existing Laravel access/intended destination redirect. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    advertiserLoginOptions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountPasskeyOptions"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    advertiserSwitchOptions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountPasskeyOptions"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    authenticateAdvertiserPasskey: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["AuthPasskeyCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthTransitionResponse"];
                };
            };
            /** @description Existing Laravel access middleware redirect, not an authentication success acknowledgement. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    switchAdvertiserPasskey: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["AuthPasskeyCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthTransitionResponse"];
                };
            };
            /** @description Existing Laravel access middleware redirect, not an authentication success acknowledgement. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readInternalLoginEntry: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthEntryResource"] | components["schemas"]["AuthEntryWitness"];
                };
            };
            /** @description Existing Laravel access/intended destination redirect. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readInternalSwitchEntry: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AuthEntryResource"] | components["schemas"]["AuthEntryWitness"];
                };
            };
            /** @description Existing Laravel access/intended destination redirect. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    internalLoginOptions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountPasskeyOptions"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    internalSwitchOptions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["AccountPasskeyOptions"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    authenticateInternalPasskey: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["AuthPasskeyCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalAuthTransitionResponse"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    switchInternalPasskey: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["AuthPasskeyCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalAuthTransitionResponse"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            429: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    setInternalLocale: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @enum {unknown} */
                    locale: "ar" | "en";
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @enum {unknown} */
                        locale: "ar" | "en";
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readInternalServices: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalServicesResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    createInternalService: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["InternalServiceCreate"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalServiceCredential"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    rotateInternalService: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                service: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalServiceCredential"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    revokeInternalService: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                service: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    issueInternalServiceGrant: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["InternalServiceGrantCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    revokeInternalServiceGrant: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                grant: number;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readInternalBusinessTypes: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalBusinessTypesResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    createInternalBusinessType: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["InternalBusinessTypeCreate"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    updateInternalBusinessType: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                type: number;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["InternalBusinessTypeUpdate"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    saveInternalBusinessGuidance: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                type: number;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["InternalBusinessGuidanceCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    saveInternalBusinessPrior: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                type: number;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["InternalBusinessPriorCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readInternalCustomers: {
        parameters: {
            query?: {
                kind?: "users" | "workspaces";
                search?: string;
                status?: "" | "pending" | "current" | "disabled" | "active" | "suspended" | "archived";
                page?: number;
                selected?: string;
            };
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalCustomersResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    changeInternalCustomerStatus: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["InternalCustomerStatusCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalCustomerStatusAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readInternalAdministration: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalAdministrationResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    inviteInternalStaff: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["InternalStaffInvitation"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    internalStaffresend_invitation: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                staff: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    internalStaffattest: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                staff: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    internalStaffactivate: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                staff: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    internalStaffrevoke: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                staff: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    internalStaffattest_factors: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                staff: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    issueInternalStaffGrant: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["InternalStaffGrantCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    revokeInternalStaffGrant: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                grant: number;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["InternalOperationAcknowledgement"];
                };
            };
            /** @description Existing non-JSON command redirect/flash; same authority writer and middleware. */
            302: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Authorized existing Laravel owner response. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readCommercialOperations: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CommercialOperationsResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    recordCommercialMarket: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CommercialMarketCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        market_id: string;
                        label: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    recordCommercialCatalog: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CommercialCatalogCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        catalog_reference_id: string;
                        /** Format: uuid */
                        market_id: string;
                        /** Format: uuid */
                        catalog_identity: string;
                        catalog_version: string;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    recordCommercialConfiguration: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CommercialConfigurationCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        market_id: string;
                        /** Format: uuid */
                        configuration_id: string;
                        version: number;
                        catalog_reference_id: string | null;
                        denomination_code: string | null;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    setCommercialAssignability: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CommercialAssignabilityCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        market_id: string;
                        configuration_id: string | null;
                        revision: number;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    registerCommercialScope: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CommercialRegistrationCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        scope_id: string;
                        /** Format: uuid */
                        workspace_public_id: string;
                        /** Format: uuid */
                        market_id: string;
                        /** Format: uuid */
                        configuration_id: string;
                        /** Format: uuid */
                        catalog_reference_id: string;
                        /** Format: uuid */
                        catalog_identity: string;
                        catalog_version: string;
                        configuration_version: number;
                        denomination_code: string;
                        /** @constant */
                        formation_state: "CONTEXT_RECORDED";
                        /** @constant */
                        agreement_state: "UNKNOWN";
                        /** @constant */
                        amount_state: "UNKNOWN";
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readCommercialCommandReceipt: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                command: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CommercialCommandReceipt"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readCommercialWorkspaceReference: {
        parameters: {
            query: {
                scope_id: string;
                workspace_public_id: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        public_id: string;
                        name: string;
                        slug: string;
                    } | null;
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readInternalCommercialContext: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                scope: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CommercialContextResult"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    recoverCommercialAudit: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                obligation: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** Format: uuid */
                        obligation_id: string;
                        /** @enum {unknown} */
                        state: "acknowledged" | "failed";
                        acknowledgment_id: string | null;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    issueCommercialGrant: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CommercialGrantCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        grant_id: number;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    revokeCommercialGrant: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                grant: number;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Existing grant revoked. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    executeCommercialOwnerMandate: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: uuid */
                    mandate_id: string;
                };
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        grant_id: number;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    readCommercialPreparation: {
        parameters: {
            query?: never;
            header?: {
                "X-Builderad-Passive"?: "1";
                "X-Builderad-Witness"?: "1";
            };
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CommercialPreparationResource"] | components["schemas"]["GoalsWitness"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    prepareCommercialMarket: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CommercialPreparationCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CommercialPreparationAcknowledgement"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    startNextCommercialIntent: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @constant */
                        saved: true;
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    delegateCommercialMarket: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CommercialDelegationCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @constant */
                        state: "MARKET_EMPLOYEE_AUTHORIZED";
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
    authorizePreparedCommercialOperator: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                approval: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CommercialOperatorCommand"];
            };
        };
        responses: {
            /** @description Authorized existing Laravel owner response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @constant */
                        state: "MARKET_OPERATOR_AUTHORIZED";
                    };
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Identity context conflict; close the document when identityConflict is true. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            419: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ValidationError"];
                };
            };
            /** @description Original Laravel authorization, CSRF, validation or service failure. */
            503: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Error"];
                };
            };
        };
    };
}
