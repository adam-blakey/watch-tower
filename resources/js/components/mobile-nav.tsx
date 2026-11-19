import { router } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';

type MobileNavProps = {
    title: string;
    children: ReactNode;
};

/**
 * Top bar with a menu button plus a slide-in drawer, shown below the md breakpoint.
 * Closes on navigation, backdrop click and Escape.
 */
export function MobileNav({ title, children }: MobileNavProps) {
    const [open, setOpen] = useState(false);

    useEffect(() => router.on('navigate', () => setOpen(false)), []);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open]);

    return (
        <div className="md:hidden">
            <header className="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-sidebar-border bg-sidebar px-4 text-sidebar-foreground">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-9 w-9"
                    aria-label="Open menu"
                    aria-expanded={open}
                    aria-controls="mobile-nav-drawer"
                    onClick={() => setOpen(true)}
                >
                    <Menu className="h-5 w-5" />
                </Button>
                <span className="truncate text-sm font-semibold">{title}</span>
            </header>

            {open && (
                <div className="fixed inset-0 z-40">
                    <div
                        className="absolute inset-0 bg-black/50"
                        aria-hidden="true"
                        onClick={() => setOpen(false)}
                    />
                    <aside
                        id="mobile-nav-drawer"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Navigation"
                        className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground shadow-xl"
                    >
                        <div className="flex h-14 shrink-0 items-center justify-between border-b border-sidebar-border px-4">
                            <span className="truncate text-sm font-semibold">
                                {title}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8"
                                aria-label="Close menu"
                                autoFocus
                                onClick={() => setOpen(false)}
                            >
                                <X className="h-4 w-4" />
                            </Button>
                        </div>
                        <div className="flex min-h-0 flex-1 flex-col">
                            {children}
                        </div>
                    </aside>
                </div>
            )}
        </div>
    );
}
