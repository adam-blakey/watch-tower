<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;

trait FlashesNewApiKey
{
    /**
     * @return array{project: string, key: string}
     */
    private function newApiKeyFlash(string $label, string $apiKey): array
    {
        return [
            'project' => $label,
            'key' => Crypt::encryptString($apiKey),
        ];
    }

    private function flashNewApiKeyToPage(Request $request): void
    {
        $newApiKey = $this->pullNewApiKey($request);

        if ($newApiKey !== null) {
            Inertia::flash('new_api_key', $newApiKey);
        }
    }

    /**
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
