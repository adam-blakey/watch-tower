import type { Auth } from '@/types/auth';
import type { NewApiKey } from '@/types/inertia';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
        flashDataType: {
            new_api_key?: NewApiKey;
        };
    }
}

declare global {
    /** CSP nonce set by the inline script in app.blade.php. */
    var __webpack_nonce__: string | undefined;
}
