import { Check, Copy, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import type { NewApiKey } from '@/types/inertia';

export function NewKeyPanel({
    apiKey,
    onDismiss,
}: {
    apiKey: NewApiKey;
    onDismiss: () => void;
}) {
    return (
        <div
            role="status"
            className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm dark:border-amber-900/50 dark:bg-amber-950/40"
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="font-semibold text-amber-900 dark:text-amber-200">
                        API key for {apiKey.project}
                    </h2>
                    <p className="text-xs text-amber-800 dark:text-amber-300">
                        Copy this key now. It won't be shown again, only a short
                        prefix is kept.
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={onDismiss}
                    title="Dismiss"
                    aria-label="Dismiss"
                >
                    <X className="h-3.5 w-3.5" />
                </Button>
            </div>
            <div className="mt-3 flex items-center gap-2">
                <code className="min-w-0 flex-1 rounded-md border border-border bg-background px-3 py-2 font-mono text-xs break-all text-foreground">
                    {apiKey.key}
                </code>
                <CopyButton value={apiKey.key} />
            </div>
        </div>
    );
}

function CopyButton({ value }: { value: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
        } catch {
            const ta = document.createElement('textarea');
            ta.value = value;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }

        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <Button variant="outline" size="sm" onClick={copy}>
            {copied ? (
                <Check className="h-3.5 w-3.5 text-emerald-600" />
            ) : (
                <Copy className="h-3.5 w-3.5" />
            )}
            {copied ? 'Copied' : 'Copy'}
        </Button>
    );
}
