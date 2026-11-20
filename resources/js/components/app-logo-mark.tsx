import { usePage } from '@inertiajs/react';

import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/inertia';

export default function AppLogoMark({ className }: { className?: string }) {
    const { name, icon } = usePage<SharedProps>().props;

    if (icon) {
        return (
            <img
                src={icon}
                alt=""
                className={cn('shrink-0 rounded-md object-contain', className)}
            />
        );
    }

    const initials = name
        .split(/\s+/)
        .map((word) => word.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase();

    return (
        <span
            className={cn(
                'grid shrink-0 place-items-center rounded-md bg-emerald-500 text-xs font-semibold text-white',
                className,
            )}
        >
            {initials}
        </span>
    );
}
