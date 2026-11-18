<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <script nonce="{{ Vite::cspNonce() }}">
            // Radix's scroll lock (react-style-singleton) reads this to put the nonce on the <style> it injects.
            window.__webpack_nonce__ = @json(Vite::cspNonce());
            (function () {
                try {
                    var stored = window.localStorage.getItem('lw-theme');
                    var theme = stored === 'light' ? 'light' : 'dark';
                    if (theme === 'dark') {
                        document.documentElement.classList.add('dark');
                    } else {
                        document.documentElement.classList.remove('dark');
                    }
                } catch (e) {}
            })();
        </script>

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased bg-background text-foreground">
        <x-inertia::app />
    </body>
</html>
