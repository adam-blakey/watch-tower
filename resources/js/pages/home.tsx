import { Head, Link, usePage } from '@inertiajs/react';
import { FolderKanban, Plus } from 'lucide-react';

import ProjectAdminController from '@/actions/App/Http/Controllers/Settings/ProjectAdminController';
import { Button } from '@/components/ui/button';
import { SettingsShell } from '@/layouts/settings-shell';
import type { SharedProps } from '@/types/inertia';

type Props = {
    reason: 'no_projects' | 'not_assigned';
};

export default function Home({ reason }: Props) {
    const { props } = usePage<SharedProps>();
    const isSuperAdmin = Boolean(props.auth?.user?.is_super_admin);

    const message =
        reason === 'not_assigned'
            ? "You don't have access to any projects yet. Ask a super admin to give you access in Settings → Admins."
            : isSuperAdmin
              ? 'No projects have been created yet. Create one to start receiving telemetry.'
              : 'No projects have been created yet. Ask a super admin to create one and give you access.';

    return (
        <SettingsShell>
            <Head title="No projects" />
            <div className="mx-auto flex w-full max-w-md flex-col items-center px-8 py-24 text-center">
                <FolderKanban className="mb-4 h-10 w-10 text-muted-foreground" />
                <h1 className="text-xl font-semibold tracking-tight">
                    {reason === 'not_assigned'
                        ? 'No project access'
                        : 'No projects yet'}
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">{message}</p>
                {isSuperAdmin && reason === 'no_projects' && (
                    <Button asChild size="sm" className="mt-6">
                        <Link href={ProjectAdminController.create.url()}>
                            <Plus className="mr-1 h-3.5 w-3.5" />
                            New project
                        </Link>
                    </Button>
                )}
            </div>
        </SettingsShell>
    );
}
