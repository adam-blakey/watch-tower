import { createInertiaApp } from '@inertiajs/react';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Lets the progress bar's injected <style> pass the Content-Security-Policy.
    nonce: window.__webpack_nonce__,
    progress: {
        color: '#4B5563',
    },
});
