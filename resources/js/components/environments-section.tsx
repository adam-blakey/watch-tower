import { router } from '@inertiajs/react';
import { KeyRound, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import EnvironmentController from '@/actions/App/Http/Controllers/Settings/EnvironmentController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type Environment = {
    id: string;
    name: string;
    slug: string;
    is_default: boolean;
    api_key_prefix: string | null;
    api_key_last_used_at: string | null;
    previous_api_key_expires_at: string | null;
};

const GRACE_OPTIONS = [
    {
        hours: 0,
        label: 'None',
        hint: 'The old key stops working immediately.',
    },
    { hours: 1, label: '1 hour', hint: 'The old key works for another hour.' },
    {
        hours: 24,
        label: '24 hours',
        hint: 'The old key works for another day.',
    },
    { hours: 168, label: '7 days', hint: 'The old key works for a week.' },
];

function formatDateTime(iso: string) {
    return new Date(iso).toLocaleString();
}

export function EnvironmentsSection({
    projectSlug,
    environments,
}: {
    projectSlug: string;
    environments: Environment[];
}) {
    const [rotateTarget, setRotateTarget] = useState<Environment | null>(null);
    const [renaming, setRenaming] = useState<Environment | null>(null);
    const [renamed, setRenamed] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [name, setName] = useState('');
    const [slug, setSlug] = useState('');

    const create = () =>
        router.post(
            EnvironmentController.store.url({ project: projectSlug }),
            { name, slug },
            {
                preserveScroll: true,
                onError: setErrors,
                onSuccess: () => {
                    setName('');
                    setSlug('');
                    setErrors({});
                },
            },
        );

    const rename = (environment: Environment) =>
        router.patch(
            EnvironmentController.update.url({
                project: projectSlug,
                environment: environment.id,
            }),
            { name: renamed },
            {
                preserveScroll: true,
                onError: setErrors,
                onSuccess: () => setRenaming(null),
            },
        );

    const remove = (environment: Environment) => {
        if (
            window.confirm(
                `Delete ${environment.name} and all its data? This cannot be undone.`,
            )
        ) {
            router.delete(
                EnvironmentController.destroy.url({
                    project: projectSlug,
                    environment: environment.id,
                }),
                { preserveScroll: true, onError: setErrors },
            );
        }
    };

    return (
        <section className="mt-6 rounded-lg border border-border bg-card">
            <div className="border-b border-border px-5 py-3">
                <h2 className="text-base font-semibold tracking-tight">
                    Environments
                </h2>
                <p className="text-xs text-muted-foreground">
                    Each environment has its own API key. Data sent with a key
                    is tagged with its environment.
                </p>
            </div>
            <ul className="divide-y divide-border">
                {environments.map((environment) => (
                    <li
                        key={environment.id}
                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                    >
                        <div className="min-w-0">
                            {renaming?.id === environment.id ? (
                                <form
                                    className="flex items-center gap-2"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        rename(environment);
                                    }}
                                >
                                    <Input
                                        autoFocus
                                        value={renamed}
                                        onChange={(e) =>
                                            setRenamed(e.target.value)
                                        }
                                    />
                                    <Button type="submit" size="sm">
                                        Save
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setRenaming(null)}
                                    >
                                        Cancel
                                    </Button>
                                </form>
                            ) : (
                                <div className="flex items-center gap-2 text-sm font-medium">
                                    {environment.name}
                                    <span className="font-mono text-xs font-normal text-muted-foreground">
                                        {environment.slug}
                                    </span>
                                    {environment.is_default && (
                                        <span className="rounded-md border border-border px-1.5 py-0.5 text-xs font-normal text-muted-foreground">
                                            Default
                                        </span>
                                    )}
                                </div>
                            )}
                            <div className="mt-1 text-xs text-muted-foreground">
                                <span className="font-mono">
                                    {environment.api_key_prefix
                                        ? `${environment.api_key_prefix}…`
                                        : '—'}
                                </span>{' '}
                                ·{' '}
                                {environment.api_key_last_used_at
                                    ? `Last used ${formatDateTime(environment.api_key_last_used_at)}`
                                    : 'Never used'}
                            </div>
                            {environment.previous_api_key_expires_at && (
                                <div className="mt-0.5 text-xs text-amber-600 dark:text-amber-400">
                                    Previous key valid until{' '}
                                    {formatDateTime(
                                        environment.previous_api_key_expires_at,
                                    )}
                                </div>
                            )}
                        </div>
                        <div className="flex items-center gap-1">
                            <Button
                                variant="ghost"
                                size="sm"
                                title="Rename"
                                onClick={() => {
                                    setRenaming(environment);
                                    setRenamed(environment.name);
                                }}
                            >
                                <Pencil className="h-3.5 w-3.5" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                title="Rotate API key"
                                onClick={() => setRotateTarget(environment)}
                            >
                                <KeyRound className="h-3.5 w-3.5" />
                            </Button>
                            {!environment.is_default && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    title="Delete"
                                    onClick={() => remove(environment)}
                                    className="text-rose-600 hover:bg-rose-50 hover:text-rose-700 dark:hover:bg-rose-950/40"
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                </Button>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
            <form
                className="flex flex-wrap items-start gap-2 border-t border-border px-5 py-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    create();
                }}
            >
                <div>
                    <Input
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="Name (Staging)"
                        required
                    />
                    {errors.name && (
                        <p className="mt-1 text-xs text-rose-600">
                            {errors.name}
                        </p>
                    )}
                </div>
                <div>
                    <Input
                        value={slug}
                        onChange={(e) => setSlug(e.target.value)}
                        placeholder="slug (staging)"
                        required
                        className="font-mono"
                    />
                    {errors.slug && (
                        <p className="mt-1 text-xs text-rose-600">
                            {errors.slug}
                        </p>
                    )}
                </div>
                <Button type="submit" size="sm">
                    <Plus className="mr-1 h-3.5 w-3.5" />
                    Add environment
                </Button>
            </form>
            {errors.environment && (
                <p className="px-5 pb-3 text-xs text-rose-600">
                    {errors.environment}
                </p>
            )}

            {rotateTarget && (
                <RotateKeyDialog
                    projectSlug={projectSlug}
                    environment={rotateTarget}
                    onClose={() => setRotateTarget(null)}
                />
            )}
        </section>
    );
}

function RotateKeyDialog({
    projectSlug,
    environment,
    onClose,
}: {
    projectSlug: string;
    environment: Environment;
    onClose: () => void;
}) {
    const [graceHours, setGraceHours] = useState(24);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        setError(null);
        router.post(
            EnvironmentController.rotateKey.url({
                project: projectSlug,
                environment: environment.id,
            }),
            { grace_hours: graceHours },
            {
                onError: (errs) =>
                    setError(
                        (errs.grace_hours as string) ??
                            'Failed to rotate the API key.',
                    ),
                onFinish: () => setProcessing(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4">
            <div className="w-full max-w-md rounded-lg border border-border bg-card shadow-xl">
                <div className="flex items-start gap-3 border-b border-border px-5 py-4">
                    <span className="grid h-9 w-9 place-items-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400">
                        <KeyRound className="h-5 w-5" />
                    </span>
                    <div>
                        <h2 className="text-base font-semibold tracking-tight">
                            Rotate API key
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            This issues a new key for{' '}
                            <span className="font-medium text-foreground">
                                {environment.name}
                            </span>
                            . After the grace period, agents still using the old
                            key are rejected until their NIGHTWATCH_TOKEN is
                            updated.
                        </p>
                    </div>
                </div>

                <div className="px-5 py-4">
                    <fieldset className="space-y-2">
                        <legend className="mb-2 text-xs text-muted-foreground">
                            Keep the old key working for:
                        </legend>
                        {GRACE_OPTIONS.map((option) => (
                            <label
                                key={option.hours}
                                className="flex cursor-pointer items-start gap-3 rounded-md border border-border px-3 py-2 text-sm has-checked:border-primary has-checked:bg-muted/50"
                            >
                                <input
                                    type="radio"
                                    name="grace_hours"
                                    value={option.hours}
                                    checked={graceHours === option.hours}
                                    onChange={() => setGraceHours(option.hours)}
                                    className="mt-1"
                                />
                                <span>
                                    <span className="font-medium">
                                        {option.label}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {option.hint}
                                    </span>
                                </span>
                            </label>
                        ))}
                        {error && (
                            <p className="text-xs text-rose-600">{error}</p>
                        )}
                    </fieldset>
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-border px-5 py-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button size="sm" onClick={submit} disabled={processing}>
                        {processing ? 'Rotating…' : 'Rotate key'}
                    </Button>
                </div>
            </div>
        </div>
    );
}
