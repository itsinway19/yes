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
}
