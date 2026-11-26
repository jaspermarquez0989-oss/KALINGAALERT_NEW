import { useEffect, useState } from 'react';
import { api } from './api.js';

export const ADVISORY_REFRESH_INTERVAL_MS = 30_000;

export function useApiData(path, refreshInterval = 0) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        let isCurrent = true;
        let refreshTimeout;

        async function loadData(isInitialLoad = false) {
            if (isInitialLoad) {
                setLoading(true);
                setError('');
            }

            try {
                const response = await api(path);
                if (isCurrent) {
                    setData(response);
                    setError('');
                }
            } catch (requestError) {
                if (isCurrent) {
                    setError(requestError.message);
                }
            } finally {
                if (isCurrent) {
                    if (isInitialLoad) {
                        setLoading(false);
                    }
                    if (refreshInterval > 0) {
                        refreshTimeout = setTimeout(() => loadData(), refreshInterval);
                    }
                }
            }
        }

        loadData(true);

        return () => {
            isCurrent = false;
            clearTimeout(refreshTimeout);
        };
    }, [path, refreshInterval]);

    return { data, loading, error };
}

export function PageTitle({ eyebrow, title, description, action }) {
    return (
        <div className="page-title">
            <div>
                {eyebrow && <p className="eyebrow">{eyebrow}</p>}
                <h1>{title}</h1>
                {description && <p className="muted">{description}</p>}
            </div>
            {action}
        </div>
    );
}

export function Notice({ children, tone = 'error' }) {
    if (!children) {
        return null;
    }

    return <div className={`notice notice-${tone}`} role={tone === 'error' ? 'alert' : 'status'}>{children}</div>;
}

function SkeletonLines({ count = 3 }) {
    return (
        <div className="skeleton-lines">
            {Array.from({ length: count }, (_, index) => <span className="skeleton-block" key={index} />)}
        </div>
    );
}

export function LoadingState({ label = 'Loading information…', variant = 'page' }) {
    if (variant === 'list') {
        return (
            <div className="skeleton-loading skeleton-list" role="status" aria-label={label} aria-busy="true">
                {Array.from({ length: 5 }, (_, index) => (
                    <div className="skeleton-list-row" key={index} aria-hidden="true">
                        <div className="skeleton-lines">
                            <span className="skeleton-block skeleton-row-title" />
                            <span className="skeleton-block skeleton-row-description" />
                        </div>
                        <span className="skeleton-block skeleton-row-badge" />
                    </div>
                ))}
            </div>
        );
    }

    if (variant === 'screen') {
        return (
            <div className="skeleton-loading skeleton-screen" role="status" aria-label={label} aria-busy="true">
                <span className="skeleton-block skeleton-screen-mark" aria-hidden="true" />
                <span className="skeleton-block skeleton-screen-line" aria-hidden="true" />
            </div>
        );
    }

    return (
        <div className="skeleton-loading skeleton-page" role="status" aria-label={label} aria-busy="true">
            <div className="skeleton-page-heading" aria-hidden="true">
                <span className="skeleton-block skeleton-heading-eyebrow" />
                <span className="skeleton-block skeleton-heading-title" />
                <span className="skeleton-block skeleton-heading-description" />
            </div>
            <div className="skeleton-metrics" aria-hidden="true">
                {Array.from({ length: 3 }, (_, index) => <div className="skeleton-card skeleton-metric" key={index}><SkeletonLines count={2} /></div>)}
            </div>
            <div className="skeleton-panels" aria-hidden="true">
                <div className="skeleton-card skeleton-panel"><SkeletonLines count={5} /></div>
                <div className="skeleton-card skeleton-panel"><SkeletonLines count={4} /></div>
            </div>
            <div className="skeleton-card skeleton-map" aria-hidden="true"><SkeletonLines count={2} /></div>
        </div>
    );
}

export function EmptyState({ title, children }) {
    return (
        <div className="empty-state">
            <span className="empty-mark">—</span>
            <h3>{title}</h3>
            {children && <p className="muted">{children}</p>}
        </div>
    );
}

export function StatusBadge({ children }) {
    const value = String(children ?? 'unknown').toLowerCase().replaceAll('_', '-');

    return <span className={`status-badge status-${value}`}>{String(children ?? 'Unknown').replaceAll('_', ' ')}</span>;
}

export function Panel({ title, action, children, className = '' }) {
    return (
        <section className={`panel ${className}`}>
            {(title || action) && (
                <div className="panel-heading">
                    {title && <h2>{title}</h2>}
                    {action}
                </div>
            )}
            {children}
        </section>
    );
}

export function Field({ label, hint, children }) {
    return (
        <label className="field">
            <span className="field-label">{label}</span>
            {children}
            {hint && <span className="field-hint">{hint}</span>}
        </label>
    );
}

export function TextInput(props) {
    return <input className="input" {...props} />;
}

export function TextArea(props) {
    return <textarea className="input textarea" {...props} />;
}

export function SelectInput(props) {
    return <select className="input" {...props} />;
}

export function FormActions({ children }) {
    return <div className="form-actions">{children}</div>;
}

export function formatDate(value) {
    if (!value) {
        return 'Not set';
    }

    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Manila',
    }).format(new Date(value));
}

export function roleLabel(role) {
    return ({
        system_admin: 'System administrator',
        mdrrmo: 'MDRRMO personnel',
        bfp_officer: 'BFP / fire information officer',
        alert_operator: 'Authorized alert operator',
        resident: 'Community resident',
    })[role] ?? role;
}
