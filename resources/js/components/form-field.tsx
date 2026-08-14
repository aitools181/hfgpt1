import type { ReactNode } from 'react';

export default function FormField({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return <label className="block"><span className="hf-label">{label}</span>{children}{error && <div className="hf-error">{error}</div>}</label>;
}
