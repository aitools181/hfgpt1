import { router } from '@inertiajs/react';
import { FormEvent, FormHTMLAttributes, ReactNode, useEffect, useRef } from 'react';

type Props = Omit<FormHTMLAttributes<HTMLFormElement>, 'action' | 'method' | 'onSubmit' | 'children'> & {
    action: string;
    children: ReactNode;
    delay?: number;
};

/**
 * GET filter form that refreshes Inertia results while the user works.
 * Text-like controls are debounced; selects, dates, checkboxes and radios apply immediately.
 * Server-side authorization/scope and pagination remain authoritative.
 */
export default function LiveFilterForm({ action, children, delay = 300, ...props }: Props) {
    const formRef = useRef<HTMLFormElement>(null);
    const timerRef = useRef<number | null>(null);

    useEffect(() => () => {
        if (timerRef.current !== null) window.clearTimeout(timerRef.current);
    }, []);

    const visit = (wait = 0) => {
        if (timerRef.current !== null) window.clearTimeout(timerRef.current);
        timerRef.current = window.setTimeout(() => {
            const form = formRef.current;
            if (!form) return;

            const params: Record<string, string> = {};
            const formData = new FormData(form);
            formData.forEach((value, key) => {
                if (typeof value !== 'string') return;
                const normalized = value.trim();
                if (normalized !== '') params[key] = normalized;
            });

            router.get(action, params, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, wait);
    };

    const handleChange = (event: FormEvent<HTMLFormElement>) => {
        const target = event.target as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
        if (!target?.name || target.hasAttribute('data-live-filter-ignore')) return;

        if (target instanceof HTMLSelectElement) {
            visit(0);
            return;
        }

        if (target instanceof HTMLTextAreaElement) {
            visit(delay);
            return;
        }

        const immediateTypes = new Set(['date', 'datetime-local', 'month', 'week', 'time', 'checkbox', 'radio', 'range']);
        visit(immediateTypes.has(target.type) ? 0 : delay);
    };

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        visit(0);
    };

    return <form ref={formRef} method="get" action={action} onChange={handleChange} onSubmit={handleSubmit} {...props}>{children}</form>;
}
