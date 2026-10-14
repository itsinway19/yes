import type { Page } from '@inertiajs/core';

export interface NavigationApi {
    configureNavigation(hooks: {
        validate: (page: Page) => boolean;
        failure: (error: unknown, restored: boolean) => void;
    }): void;
    navigationPhase(page: Page): 'preparing' | 'cancelled' | 'committed' | 'settled' | 'failed' | 'compensating' | undefined;
}
