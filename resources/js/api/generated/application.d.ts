export interface paths {
    "/workspaces/{workspace}/goals": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description Accept application/json. Without it, the existing route renders Inertia HTML. */
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
    "/spa/api/workspaces/{workspace}/goals": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** @description Opt-in SPA contextual read: reuses canonical Goals and BusinessContext projections. Witness returns only visibility. Passive/witness checks never extend canonical idle authority. */
        get: operations["readGoalsContext"];
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
    readGoalsContext: {
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
}
