import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Layers } from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { SharedProps } from '@/types/inertia';

type Crumb = {
    label: string;
    href?: string;
};

type PageHeaderProps = {
    title: ReactNode;
    breadcrumbs?: Crumb[];
    selectedRange?: string;
    onRangeChange?: (range: string) => void;
    actions?: ReactNode;
};

const RANGES = ['15m', '1h', '24h', '7d', '14d', '30d'] as const;

export function PageHeader({
    title,
    breadcrumbs,
    selectedRange,
    onRangeChange,
    actions,
}: PageHeaderProps) {
    return (
        <header className="border-b border-border bg-background px-6 py-4">
            {breadcrumbs ? (
                <nav className="mb-1 flex items-center gap-1 text-xs text-muted-foreground">
                    {breadcrumbs.map((crumb, i) => (
                        <span key={i} className="flex items-center gap-1">
                            {crumb.href ? (
                                <Link
                                    href={crumb.href}
                                    className="hover:text-foreground"
                                >
                                    {crumb.label}
                                </Link>
                            ) : (
                                <span>{crumb.label}</span>
                            )}
                            {i < breadcrumbs.length - 1 ? (
                                <ChevronRight className="h-3 w-3" />
                            ) : null}
                        </span>
                    ))}
                </nav>
            ) : null}

            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">
                    {title}
                </h1>

                <div className="flex items-center gap-3">
                    <EnvironmentPicker />
                    {selectedRange ? (
                        <RangeTabs
                            value={selectedRange}
                            onChange={onRangeChange}
                        />
                    ) : null}
                    {actions ? (
                        <>
                            {selectedRange ? (
                                <Separator
                                    orientation="vertical"
                                    className="h-6"
                                />
                            ) : null}
                            {actions}
                        </>
                    ) : null}
                </div>
            </div>
        </header>
    );
}

function RangeTabs({
    value,
    onChange,
}: {
    value: string;
    onChange?: (range: string) => void;
}) {
    return (
        <Tabs
            value={value}
            onValueChange={(range) => {
                if (onChange) {
                    onChange(range);

                    return;
                }

                const url = new URL(window.location.href);
                url.searchParams.set('range', range);
                router.visit(url.pathname + url.search, {
                    preserveScroll: true,
                });
            }}
        >
            <TabsList className="h-8">
                {RANGES.map((range) => (
                    <TabsTrigger key={range} value={range} className="px-2.5">
                        {range}
                    </TabsTrigger>
                ))}
            </TabsList>
        </Tabs>
    );
}

function EnvironmentPicker() {
    const project = usePage<SharedProps>().props.currentProject;

    if (!project || project.environments.length < 2) {
        return null;
    }

    const selected = project.selected_environments;
    const label =
        selected.length === 0
            ? 'All environments'
            : project.environments
                  .filter((environment) => selected.includes(environment.slug))
                  .map((environment) => environment.name)
                  .join(', ');

    const allSlugs = project.environments.map(
        (environment) => environment.slug,
    );
    const isAll = selected.length === 0;
    const isChecked = (slug: string) => isAll || selected.includes(slug);

    const visit = (next: string[]) => {
        // An empty selection means "all", so selecting every environment (or none) collapses to it.
        const nextSelected = next.length < allSlugs.length ? next : [];

        const url = new URL(window.location.href);
        // Inertia rewrites env[] as env[0], env[1], ..., so drop every variant before re-adding.
        [...url.searchParams.keys()]
            .filter((key) => key === 'env' || key.startsWith('env['))
            .forEach((key) => url.searchParams.delete(key));
        url.searchParams.delete('page');
        nextSelected.forEach((current) =>
            url.searchParams.append('env[]', current),
        );

        router.visit(url.pathname + url.search, {
            preserveScroll: true,
            // Keep the menu mounted so several environments can be toggled in one go.
            preserveState: true,
            // Sync visit (cancels the previous one); the optimistic props make rapid toggles build on each other.
            async: false,
            optimistic: () => ({
                currentProject: {
                    ...project,
                    selected_environments: nextSelected,
                },
            }),
        });
    };

    const toggle = (slug: string, checked: boolean) => {
        const current = isAll ? allSlugs : selected;
        visit(
            checked
                ? [...current, slug]
                : current.filter((existing) => existing !== slug),
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-8 max-w-56 gap-1.5 text-xs"
                >
                    <Layers className="h-3.5 w-3.5 shrink-0" />
                    <span className="truncate">{label}</span>
                    <ChevronDown className="h-3.5 w-3.5 shrink-0" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuCheckboxItem
                    checked={isAll}
                    onSelect={(event) => event.preventDefault()}
                    onCheckedChange={() => visit([])}
                >
                    All environments
                </DropdownMenuCheckboxItem>
                <DropdownMenuSeparator />
                {project.environments.map((environment) => (
                    <DropdownMenuCheckboxItem
                        key={environment.id}
                        checked={isChecked(environment.slug)}
                        onSelect={(event) => event.preventDefault()}
                        onCheckedChange={(checked) =>
                            toggle(environment.slug, checked)
                        }
                    >
                        {environment.name}
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
