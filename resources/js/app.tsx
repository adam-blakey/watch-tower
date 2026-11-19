import { createInertiaApp } from '@inertiajs/react';

createInertiaApp({
    title: (title, page) => {
        const appName = String(page.props.name);

        return title ? `${title} - ${appName}` : appName;
    },
    nonce: window.__webpack_nonce__,
    progress: {
        color: '#4B5563',
    },
});
