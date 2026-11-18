<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;

trait FlashesNewApiKey
{
    /**
     * The session flash that lets the next page show a new key once.
     *
     * The key is encrypted so that the plain text never sits in the session
     * store; `pullNewApiKey()` decrypts it for that one response.
     *
     * @return array{project: string, key: string}
     */
    private function newApiKeyFlash(string $label, string $apiKey): array
    {
        return [
            'project' => $label,
            'key' => Crypt::encryptString($apiKey),
        ];
    }

    /**
     * Hand a flashed key to the page as Inertia flash data, which, unlike a prop, is kept out of the browser's
     * history state, so Back or a session restore cannot show the key again.
     */
    private function flashNewApiKeyToPage(Request $request): void
    {
        $newApiKey = $this->pullNewApiKey($request);

        if ($newApiKey !== null) {
            Inertia::flash('new_api_key', $newApiKey);
        }
    }

    /**
     * Take the flashed key out of the session and decrypt it.
     *
     * Pulling it removes it from the session, so the plain text only exists
     * for the rest of this request.
     *
     * @return array{project: string, key: string}|null
     */
    private function pullNewApiKey(Request $request): ?array
    {
        $flashed = $request->session()->pull('new_api_key');

        if (! is_array($flashed) || ! isset($flashed['project'], $flashed['key'])) {
            return null;
        }

        try {
            return [
                'project' => $flashed['project'],
                'key' => Crypt::decryptString($flashed['key']),
            ];
        } catch (DecryptException) {
            return null;
        }
    }
}
