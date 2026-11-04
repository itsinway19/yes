export type paths = Record<string, never>;
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
            /** @enum {string} */
            code?: "fresh_authentication_required";
            /** @enum {string} */
            reauthenticate?: "/internal/identity/switch" | "/confirm-password";
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
export type operations = Record<string, never>;
