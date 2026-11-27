import { useEffect, useRef, useState } from 'react';
import { api, saveToken } from './api.js';
import { formatCoordinatePair, formatLatitude, formatLongitude } from './coordinates.js';
import bongabongBoundary from './data/bongabong-boundary.json';
import { bongabongBarangays } from './data/bongabong-barangays.js';
import { intensityColorForLevel } from './intensity.js';
import MapView from './MapView.jsx';
import {
    EmptyState,
    ADVISORY_REFRESH_INTERVAL_MS,
    Field,
    FormActions,
    LoadingState,
    Notice,
    PageTitle,
    Panel,
    SelectInput,
    StatusBadge,
    TextArea,
    TextInput,
    formatDate,
    roleLabel,
    useApiData,
} from './ui.jsx';

const alertSeverities = ['advisory', 'watch', 'warning', 'emergency'];
const bongabongBarangayAliases = {
    'Bagumbayan I': ['Bagong Bayan I'],
    'Bagumbayan II': ['Bagong Bayan II'],
    Polusahi: ['Pulosahi'],
};
const advisoryIntensities = [
    { value: 1, label: '1 - Very low' },
    { value: 2, label: '2 - Low' },
    { value: 3, label: '3 - Moderate' },
    { value: 4, label: '4 - High' },
    { value: 5, label: '5 - Very high' },
];

function isWithinBongabong(longitude, latitude) {
    const ring = bongabongBoundary.geometry.coordinates[0];
    let inside = false;

    for (let current = 0, previous = ring.length - 1; current < ring.length; previous = current++) {
        const [currentLongitude, currentLatitude] = ring[current];
        const [previousLongitude, previousLatitude] = ring[previous];
        const crossesLatitude = (currentLatitude > latitude) !== (previousLatitude > latitude);
        const intersection = ((previousLongitude - currentLongitude) * (latitude - currentLatitude)
            / ((previousLatitude - currentLatitude) || Number.EPSILON))
            + currentLongitude;

        if (crossesLatitude && longitude < intersection) {
            inside = !inside;
        }
    }

    return inside;
}
const staffRoles = ['system_admin', 'mdrrmo', 'bfp_officer', 'alert_operator'];

function IntensityIndicator({ value, compact = false }) {
    const level = Number(value) || 3;

    return (
        <span className="intensity-indicator">
            <i aria-hidden="true" style={{ backgroundColor: intensityColorForLevel(level) }} />
            {compact ? `${level}/5` : `Level ${level}/5`}
        </span>
    );
}

function FormError({ message }) {
    return <Notice>{message}</Notice>;
}

function PageState({ loading, error, children }) {
    if (loading) {
        return <LoadingState variant="list" />;
    }

    if (error) {
        return <Notice>{error}</Notice>;
    }

    return children;
}

function SubmitButton({ children = 'Save changes', saving = false, disabled = false }) {
    return <button className="button" type="submit" disabled={saving || disabled}>{saving ? 'Saving…' : children}</button>;
}

function LinkButton({ href, children, secondary = false }) {
    return <a className={`button ${secondary ? 'button-muted' : ''}`} href={href}>{children}</a>;
}

function SeverityCard({ label, value, tone = '' }) {
    return (
        <div className={`metric-card ${tone}`}>
            <span>{label}</span>
            <strong>{value}</strong>
        </div>
    );
}

function AlertCard({ alert, href, onClick, isRead }) {
    return (
        <a className="alert-card" href={href} onClick={onClick}>
            <div className="alert-card-top"><StatusBadge>{alert.severity}</StatusBadge><IntensityIndicator value={alert.intensity} /><StatusBadge>{alert.status}</StatusBadge>{isRead !== undefined && <span className={`alert-read-indicator ${isRead ? 'is-read' : 'is-unread'}`}><span aria-hidden="true" />{isRead ? 'Read' : 'Unread'}</span>}</div>
            <h3>{alert.title}</h3>
            <p>{alert.message}</p>
            <div className="alert-card-foot"><span>{alert.risk_areas?.length ?? 0} covered area(s)</span><span>{formatDate(alert.published_at ?? alert.created_at)}</span></div>
        </a>
    );
}

export function LandingPage() {
    return (
        <div className="landing">
            <section className="landing-hero">
                <div className="landing-copy">
                    <p className="eyebrow"><span className="live-dot" /> COMMUNITY PREPAREDNESS · BONGABONG</p>
                    <h1>Warnings for the places that need them.</h1>
                    <p className="landing-lede">KalingaAlert connects verified PAGASA weather bulletins and BFP fire information with MDRRMO-managed risk areas, then shares published advisories with all residents.</p>
                    <div className="inline-actions">
                        <LinkButton href="/register">Register as a resident</LinkButton>
                        <LinkButton href="/login" secondary>Sign in</LinkButton>
                    </div>
                    <div className="safety-note"><strong>Important</strong><span>This service does not predict hazards or dispatch rescue. Follow official public warnings and emergency instructions.</span></div>
                </div>
                <div className="hero-visual">
                    <div className="visual-kicker"><span className="live-dot" /> LOCATION-BASED ADVISORIES</div>
                    <div className="visual-map"><span className="contour contour-a" /><span className="contour contour-b" /><span className="contour contour-c" /><i className="map-dot dot-a" /><i className="map-dot dot-b" /><i className="map-dot dot-c" />
                        <div className="visual-caption"><span className="caption-symbol">+</span><span><b>Mapped coverage</b><small>Managed by local authorities</small></span></div>
                    </div>
                    <div className="source-strip"><span className="source-check">✓</span><span><b>Official sources</b><small>PAGASA · Bureau of Fire Protection</small></span></div>
                </div>
            </section>
            <section className="landing-process">
                <div className="section-heading"><div><p className="eyebrow">HOW IT WORKS</p><h2>Local information. Clear guidance.</h2></div><p>Every advisory carries its source, affected coverage, issue time, and practical safety instructions.</p></div>
                <div className="process-grid">
                    <article><span>01</span><div className="process-icon">⌖</div><h3>Map vulnerable areas</h3><p>Authorized personnel maintain mapped coverage used to target advisories.</p></article>
                    <article><span>02</span><div className="process-icon">✓</div><h3>Verify the source</h3><p>Regional warnings and BFP-confirmed fire information are reviewed by staff.</p></article>
                    <article><span>03</span><div className="process-icon">↗</div><h3>Notify all residents</h3><p>Approved advisories are shared with every active resident account.</p></article>
                </div>
            </section>
        </div>
    );
}

function AuthLayout({ title, description, children }) {
    return <section className="auth-card"><p className="eyebrow">KALINGAALERT ACCESS</p><h1>{title}</h1><p className="muted">{description}</p>{children}</section>;
}

export function LoginPage({ onAuthenticated, notice }) {
    const [error, setError] = useState(notice ?? '');
    const [saving, setSaving] = useState(false);

    async function handleSubmit(event) {
        event.preventDefault();
        setError('');
        setSaving(true);

        const formData = new FormData(event.currentTarget);

        try {
            const payload = await api('/auth/login', {
                method: 'POST',
                body: { email: formData.get('email'), password: formData.get('password') },
            });
            saveToken(payload.access_token);
            onAuthenticated(payload);
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <AuthLayout title="Welcome back" description="Sign in to view community advisories or manage official information.">
            <FormError message={error} />
            <form className="stack-form" onSubmit={handleSubmit}>
                <Field label="Email address"><TextInput name="email" type="email" autoComplete="email" required /></Field>
                <Field label="Password"><TextInput name="password" type="password" autoComplete="current-password" required /></Field>
                <SubmitButton saving={saving}>Sign in</SubmitButton>
            </form>
            <p className="auth-switch">Resident new to KalingaAlert? <a href="/register">Create an account</a></p>
        </AuthLayout>
    );
}

export function RegisterPage({ onAuthenticated }) {
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);

    async function handleSubmit(event) {
        event.preventDefault();
        setError('');
        setSaving(true);
        const formData = new FormData(event.currentTarget);

        try {
            const payload = await api('/auth/register', {
                method: 'POST',
                body: {
                    name: formData.get('name'),
                    email: formData.get('email'),
                    barangay: formData.get('barangay'),
                    sitio: formData.get('sitio') || null,
                    password: formData.get('password'),
                    password_confirmation: formData.get('password_confirmation'),
                },
            });
            saveToken(payload.access_token);
            onAuthenticated(payload);
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <AuthLayout title="Join your community" description="Create a resident account to receive community advisories.">
            <FormError message={error} />
            <form className="stack-form" onSubmit={handleSubmit}>
                <Field label="Full name"><TextInput name="name" autoComplete="name" maxLength="120" required /></Field>
                <Field label="Email address"><TextInput name="email" type="email" autoComplete="email" maxLength="255" required /></Field>
                <div className="form-grid">
                    <Field label="Barangay">
                        <SelectInput name="barangay" required defaultValue="">
                            <option value="">Select a barangay</option>
                            {bongabongBarangays.map((barangay) => <option key={barangay} value={barangay}>{barangay}</option>)}
                        </SelectInput>
                    </Field>
                    <Field label="Sitio / purok (optional)"><TextInput name="sitio" maxLength="120" /></Field>
                </div>
                <Field label="Password" hint="Use at least 10 characters, including letters and numbers.">
                    <TextInput name="password" type="password" autoComplete="new-password" minLength="10" required />
                </Field>
                <Field label="Confirm password"><TextInput name="password_confirmation" type="password" autoComplete="new-password" minLength="10" required /></Field>
                <SubmitButton saving={saving}>Create resident account</SubmitButton>
            </form>
            <p className="auth-switch">Already registered? <a href="/login">Sign in</a></p>
        </AuthLayout>
    );
}

export function DashboardPage({ user }) {
    const selectedAlertStorageKey = `kalinga_dashboard_selected_advisory_${user.id}`;
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [publishedPage, setPublishedPage] = useState(1);
    const [selectedDashboardAlertId, setSelectedDashboardAlertId] = useState(() => sessionStorage.getItem(selectedAlertStorageKey));

    useEffect(() => {
        let isCurrent = true;
        let refreshTimeout;

        async function loadDashboard(isInitialLoad = false) {
            try {
                if (user.role === 'resident') {
                    const [alertsResponse, status, coverageResponse] = await Promise.all([
                        api('/my/alerts?per_page=100'),
                        api('/my/alerts/status'),
                        api('/my/coverage'),
                    ]);
                    if (isCurrent) {
                        const alerts = alertsResponse.data.map((item) => ({
                            ...item.alert,
                            read_at: item.read_at,
                        }));
                        const areas = (coverageResponse.data ?? []).map((area) => ({
                            ...area,
                            alerts: alerts.filter((alert) => alert.risk_areas?.some((covered) => covered.id === area.id))
                                .map(({ id, title, severity, intensity, message, safety_instructions }) => ({
                                    id,
                                    title,
                                    severity,
                                    intensity,
                                    message,
                                    safety_instructions,
                                })),
                        }));
                        setData({
                            alerts,
                            status,
                            areas,
                        });
                    }
                } else {
                    const requests = [
                        api('/risk-areas?per_page=100'),
                        api('/hazard-sources?per_page=100'),
                    ];
                    const canViewAlerts = ['system_admin', 'mdrrmo', 'alert_operator'].includes(user.role);
                    if (canViewAlerts) requests.push(api('/alerts?per_page=100'));
                    const [areaResponse, sourceResponse, alertResponse] = await Promise.all(requests);
                    const alerts = alertResponse?.data ?? [];
                    const areas = (areaResponse.data ?? []).map((area) => ({
                        ...area,
                        alerts: alerts.filter((alert) => alert.status === 'published'
                            && alert.risk_areas?.some((covered) => covered.id === area.id))
                            .map((alert) => ({
                                id: alert.id,
                                title: alert.title,
                                severity: alert.severity,
                                intensity: alert.intensity,
                                message: alert.message,
                                safety_instructions: alert.safety_instructions,
                            })),
                    }));
                    if (isCurrent) setData({ areas, alerts, sources: sourceResponse.data ?? [] });
                }
                if (isCurrent) setError('');
            } catch (requestError) {
                if (isCurrent) setError(requestError.message);
            } finally {
                if (isCurrent) {
                    if (isInitialLoad) setLoading(false);
                    refreshTimeout = setTimeout(() => loadDashboard(), ADVISORY_REFRESH_INTERVAL_MS);
                }
            }
        }

        loadDashboard(true);

        return () => {
            isCurrent = false;
            clearTimeout(refreshTimeout);
        };
    }, [user.role]);

    useEffect(() => {
        if (!data || !selectedDashboardAlertId) {
            return;
        }

        const alerts = user.role === 'resident'
            ? data.alerts
            : (data.alerts ?? []).filter((alert) => alert.status === 'published');
        const selectedAlertExists = alerts.some((alert) => String(alert.id) === selectedDashboardAlertId);
        if (!selectedAlertExists) {
            sessionStorage.removeItem(selectedAlertStorageKey);
            setSelectedDashboardAlertId(null);
        }
    }, [data, selectedAlertStorageKey, selectedDashboardAlertId, user.role]);

    if (loading) return <LoadingState />;
    if (error) return <Notice>{error}</Notice>;

    const isResident = user.role === 'resident';
    const activeAlerts = isResident
        ? data.alerts
        : (data.alerts ?? []).filter((alert) => alert.status === 'published');
    const selectedAlert = activeAlerts.find((alert) => String(alert.id) === selectedDashboardAlertId);
    const mapAreas = selectedAlert
        ? data.areas
            .filter((area) => area.alerts.some((alert) => String(alert.id) === selectedDashboardAlertId))
            .map((area) => ({
                ...area,
                alerts: area.alerts.filter((alert) => String(alert.id) === selectedDashboardAlertId),
            }))
        : data.areas;
    const pageSize = 4;
    const pageCount = Math.max(1, Math.ceil(activeAlerts.length / pageSize));
    const visibleAlerts = selectedAlert
        ? [selectedAlert]
        : isResident
            ? activeAlerts.slice(0, pageSize)
            : activeAlerts.slice((publishedPage - 1) * pageSize, publishedPage * pageSize);

    return (
        <>
            <PageTitle eyebrow="COMMUNITY SITUATION" title={`Good day, ${user.name.split(' ')[0]}.`} description={isResident ? 'Here are the current community advisories available to all residents.' : 'A clear view of current advisories, mapped coverage, and source review.'} />
            <div className="metric-grid">
                <SeverityCard label="Active advisories" value={isResident ? data.status.active_alert_count : activeAlerts.length} tone="metric-accent" />
                <SeverityCard label="Unread alerts" value={isResident ? data.status.unread_count : '—'} />
                {!isResident && <SeverityCard label="Mapped risk areas" value={data.areas.filter((area) => area.is_active).length} />}
                {!isResident && <SeverityCard label="Sources awaiting review" value={data.sources.filter((source) => source.verification_status === 'pending').length} />}
            </div>
            <div className={`content-grid dashboard-grid ${isResident ? 'resident-dashboard-grid' : ''}`}>
                <Panel
                    title={selectedAlert ? 'Selected advisory' : (isResident ? 'Your latest advisories' : 'Published advisories')}
                    action={(
                        <div className="dashboard-panel-actions">
                            {selectedAlert && <button className="text-button" onClick={() => {
                                sessionStorage.removeItem(selectedAlertStorageKey);
                                setSelectedDashboardAlertId(null);
                                if (!isResident) setPublishedPage(1);
                            }} type="button">Show latest</button>}
                            <a className="text-link" href={isResident ? '/my-alerts' : '/alerts'}>View all</a>
                        </div>
                    )}
                >
                    {activeAlerts.length === 0 ? <EmptyState title="No active advisories">We will show matched alerts here when available.</EmptyState> : (
                        <>
                            <div className="alert-list">{visibleAlerts.map((alert) => (
                                <AlertCard
                                    key={alert.id}
                                    alert={alert}
                                    isRead={isResident ? Boolean(alert.read_at) : undefined}
                                    href={isResident ? `/my-alerts/${alert.id}` : `/alerts/${alert.id}`}
                                    onClick={(event) => {
                                        event.preventDefault();
                                        sessionStorage.setItem(selectedAlertStorageKey, String(alert.id));
                                        setSelectedDashboardAlertId(String(alert.id));
                                    }}
                                />
                            ))}</div>
                            {!isResident && pageCount > 1 && (
                                <nav className="advisory-pagination" aria-label="Published advisory pages">
                                    <button
                                        aria-label="Previous page"
                                        disabled={publishedPage === 1}
                                        onClick={() => setPublishedPage((page) => Math.max(1, page - 1))}
                                        type="button"
                                    >‹</button>
                                    <span aria-live="polite">Page {publishedPage} of {pageCount}</span>
                                    <button
                                        aria-label="Next page"
                                        disabled={publishedPage === pageCount}
                                        onClick={() => setPublishedPage((page) => Math.min(pageCount, page + 1))}
                                        type="button"
                                    >›</button>
                                </nav>
                            )}
                        </>
                    )}
                </Panel>
                {!isResident && (
                    <Panel title="Official information" className="dashboard-side-panel">
                        <p className="muted">Sources are reviewed before they can be used to prepare a public advisory.</p>
                        <div className="source-count"><strong>{data.sources?.filter((source) => source.verification_status === 'verified').length ?? 0}</strong><span>verified source records</span></div>
                        <a className="text-link" href="/hazard-sources">Review official sources</a>
                    </Panel>
                )}
            </div>
            <Panel title={isResident ? 'Community risk areas' : 'Mapped coverage'} className="map-panel">
                {data.areas.length || isResident
                    ? <MapView areas={mapAreas.filter((area) => area.is_active)} />
                    : <EmptyState title="No active mapped areas" />}
                {isResident && <p className="muted">This map shows all active public risk areas and current advisory level. Your exact home location stays private.</p>}
                {isResident && data.areas.length === 0 && <Notice tone="info">No active risk areas are currently mapped.</Notice>}
            </Panel>
            <p className="disclaimer"><strong>Safety notice</strong> Historical fire records are not live incident reports or fire predictions. KalingaAlert does not dispatch rescue. Follow official warnings and local emergency instructions.</p>
        </>
    );
}

export function AlertsPage() {
    const { data, loading, error } = useApiData('/alerts?per_page=100', ADVISORY_REFRESH_INTERVAL_MS);
    const [revisionError, setRevisionError] = useState('');
    const [revisingAlertId, setRevisingAlertId] = useState(null);

    async function editPublishedAlert(alert) {
        const confirmed = window.confirm('Create an editable replacement draft? The published advisory stays active until its replacement is published.');
        if (!confirmed) return;

        setRevisionError('');
        setRevisingAlertId(alert.id);
        try {
            const response = await api(`/alerts/${alert.id}/revise`, { method: 'POST' });
            window.location.assign(`/alerts/${response.data.id}/edit`);
        } catch (requestError) {
            setRevisionError(requestError.message);
            setRevisingAlertId(null);
        }
    }

    return (
        <>
            <PageTitle eyebrow="ADVISORY OPERATIONS" title="Advisories" description="Prepare, review, and publish verified hazard advisories." action={<LinkButton href="/alerts/create">Prepare advisory</LinkButton>} />
            <Notice>{revisionError}</Notice>
            <Panel>
                <PageState loading={loading} error={error}>
                    {!data?.data?.length ? <EmptyState title="No advisories have been created yet">Verified source records are required to prepare an advisory.</EmptyState> : (
                        <div className="table-scroll"><table><thead><tr><th>Advisory</th><th>Severity</th><th>Level</th><th>Status</th><th>Coverage</th><th>Updated</th><th /></tr></thead><tbody>
                            {data.data.map((alert) => <tr key={alert.id}>
                                <td><strong>{alert.title}</strong><small>{alert.hazard_source?.organization ?? 'Official source'}</small></td>
                                <td><StatusBadge>{alert.severity}</StatusBadge></td>
                                <td><IntensityIndicator compact value={alert.intensity} /></td>
                                <td><StatusBadge>{alert.status}</StatusBadge></td>
                                <td>{alert.risk_areas?.length ?? 0} area(s)</td>
                                <td>{formatDate(alert.updated_at)}</td>
                                <td>
                                    <div className="inline-actions">
                                        <a className="text-link" href={`/alerts/${alert.id}`}>Open</a>
                                        {['draft', 'approved'].includes(alert.status) && <a className="text-link" href={`/alerts/${alert.id}/edit`}>Edit</a>}
                                        {alert.status === 'published' && (
                                            <button
                                                className="text-button"
                                                disabled={revisingAlertId === alert.id}
                                                onClick={() => editPublishedAlert(alert)}
                                                title="Create an editable replacement draft"
                                                type="button"
                                            >
                                                {revisingAlertId === alert.id ? 'Preparing…' : 'Edit'}
                                            </button>
                                        )}
                                    </div>
                                </td>
                            </tr>)}
                        </tbody></table></div>
                    )}
                </PageState>
            </Panel>
        </>
    );
}

function toManilaDateTimeInput(value) {
    if (!value) return '';
    return new Date(new Date(value).getTime() + 8 * 60 * 60 * 1000).toISOString().slice(0, 16);
}

export function AlertFormPage({ id }) {
    const editing = Boolean(id);
    const [sources, setSources] = useState([]);
    const [areas, setAreas] = useState([]);
    const [form, setForm] = useState({
        hazard_source_id: '',
        title: '',
        message: '',
        safety_instructions: '',
        severity: 'advisory',
        intensity: 3,
        starts_at: toManilaDateTimeInput(new Date().toISOString()),
        ends_at: '',
        risk_area_ids: [],
    });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        let isCurrent = true;

        async function loadOptions() {
            try {
                const requests = [
                    api('/hazard-sources?verification_status=verified&per_page=100'),
                    api('/risk-areas?is_active=true&per_page=100'),
                ];
                if (editing) requests.push(api(`/alerts/${id}`));
                const [sourceResponse, areaResponse, alertResponse] = await Promise.all(requests);
                if (!isCurrent) return;

                setSources(sourceResponse.data ?? []);
                setAreas(areaResponse.data ?? []);

                if (alertResponse?.data) {
                    const alert = alertResponse.data;
                    setForm({
                        hazard_source_id: String(alert.hazard_source?.id ?? ''),
                        title: alert.title,
                        message: alert.message,
                        safety_instructions: alert.safety_instructions,
                        severity: alert.severity,
                        intensity: alert.intensity ?? 3,
                        starts_at: toManilaDateTimeInput(alert.starts_at),
                        ends_at: toManilaDateTimeInput(alert.ends_at),
                        risk_area_ids: alert.risk_areas?.map((area) => String(area.id)) ?? [],
                    });
                }
            } catch (requestError) {
                if (isCurrent) setError(requestError.message);
            } finally {
                if (isCurrent) setLoading(false);
            }
        }

        loadOptions();
        return () => { isCurrent = false; };
    }, [editing, id]);

    function update(key, value) {
        setForm((current) => ({ ...current, [key]: value }));
    }

    function toggleArea(areaId) {
        setForm((current) => ({
            ...current,
            risk_area_ids: current.risk_area_ids.includes(String(areaId))
                ? current.risk_area_ids.filter((idValue) => idValue !== String(areaId))
                : [...current.risk_area_ids, String(areaId)],
        }));
    }

    async function handleSubmit(event) {
        event.preventDefault();
        setError('');
        setSaving(true);

        try {
            const payload = await api(editing ? `/alerts/${id}` : '/alerts', {
                method: editing ? 'PUT' : 'POST',
                body: {
                    ...form,
                    hazard_source_id: Number(form.hazard_source_id),
                    risk_area_ids: form.risk_area_ids.map(Number),
                    ends_at: form.ends_at || null,
                },
            });
            window.location.assign(`/alerts/${payload.data.id}`);
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setSaving(false);
        }
    }

    if (loading) return <LoadingState />;

    return (
        <>
            <PageTitle eyebrow="ADVISORY OPERATIONS" title={editing ? 'Revise draft advisory' : 'Prepare an advisory'} description="Drafts remain unpublished until approved, or published immediately by a system administrator or MDRRMO." />
            <Panel className="form-panel advisory-form-panel">
                <FormError message={error} />
                {sources.length === 0 && <Notice tone="info">At least one verified official source is required before an advisory can be drafted.</Notice>}
                <form className="stack-form advisory-form" onSubmit={handleSubmit}>
                    <div className="form-grid">
                        <Field label="Verified source">
                            <SelectInput value={form.hazard_source_id} required onChange={(event) => update('hazard_source_id', event.target.value)}>
                                <option value="">Choose a source record</option>
                                {sources.map((source) => <option value={source.id} key={source.id}>{source.organization} · {source.title}</option>)}
                            </SelectInput>
                        </Field>
                        <Field label="Advisory title"><TextInput value={form.title} maxLength="180" required onChange={(event) => update('title', event.target.value)} /></Field>
                    </div>
                    <div className="form-grid advisory-message-fields">
                        <Field label="Message"><TextArea value={form.message} maxLength="5000" rows="5" required onChange={(event) => update('message', event.target.value)} /></Field>
                        <Field label="Safety instructions"><TextArea value={form.safety_instructions} maxLength="5000" rows="4" required onChange={(event) => update('safety_instructions', event.target.value)} /></Field>
                    </div>
                    <div className="form-grid">
                        <Field label="Severity"><SelectInput value={form.severity} onChange={(event) => update('severity', event.target.value)}>{alertSeverities.map((severity) => <option key={severity} value={severity}>{severity}</option>)}</SelectInput></Field>
                        <Field
                            label="Advisory level (1-5)"
                            hint={`Selected: ${advisoryIntensities.find(({ value }) => value === form.intensity)?.label ?? '3 - Moderate'}`}
                        >
                            <SelectInput value={form.intensity} onChange={(event) => update('intensity', Number(event.target.value))}>
                                {advisoryIntensities.map(({ value, label }) => <option key={value} value={value}>{label}</option>)}
                            </SelectInput>
                            <div className="intensity-color-key" role="img" aria-label="Color scale: green for very low, yellow-green for low, yellow for moderate, orange for high, red for very high">
                                {advisoryIntensities.map(({ value, label }) => (
                                    <span className={form.intensity === value ? 'is-selected' : ''} key={value} title={label}>
                                        <i aria-hidden="true" style={{ backgroundColor: intensityColorForLevel(value) }} />
                                        <b>{value}</b>
                                    </span>
                                ))}
                            </div>
                        </Field>
                        <Field label="Starts at (Philippine time)"><TextInput type="datetime-local" value={form.starts_at} required onChange={(event) => update('starts_at', event.target.value)} /></Field>
                        <Field label="Ends at (optional)"><TextInput type="datetime-local" value={form.ends_at} onChange={(event) => update('ends_at', event.target.value)} /></Field>
                    </div>
                    <fieldset className="choice-fieldset"><legend>Areas covered</legend>
                        {areas.length === 0 ? <p className="muted">No active risk areas are available.</p> : areas.map((area) => (
                            <label className="choice-row" key={area.id}>
                                <input type="checkbox" checked={form.risk_area_ids.includes(String(area.id))} onChange={() => toggleArea(area.id)} />
                                <span><strong>{area.name}</strong><small>{area.barangay}{area.sitio ? ` · ${area.sitio}` : ''}</small></span>
                            </label>
                        ))}
                    </fieldset>
                    <FormActions><SubmitButton saving={saving} disabled={sources.length === 0}>{editing ? 'Save revision' : 'Save draft'} </SubmitButton><LinkButton href="/alerts" secondary>Cancel</LinkButton></FormActions>
                </form>
            </Panel>
        </>
    );
}

export function AlertDetailPage({ id, user }) {
    const { data, loading, error } = useApiData(`/alerts/${id}`);
    const [actionError, setActionError] = useState('');
    const [busy, setBusy] = useState(false);
    const [closeReason, setCloseReason] = useState('');
    const alert = data?.data;
    const canPublishImmediately = ['system_admin', 'mdrrmo'].includes(user.role);

    async function performAction(path, method = 'PATCH', body) {
        setBusy(true);
        setActionError('');
        try {
            await api(path, { method, body });
            window.location.reload();
        } catch (requestError) {
            setActionError(requestError.message);
        } finally {
            setBusy(false);
        }
    }

    function closeAdvisory(event) {
        event.preventDefault();
        const reason = closeReason.trim();
        if (!reason) {
            setActionError('Enter a reason for closing this advisory.');
            return;
        }

        performAction(`/alerts/${id}/close`, 'PATCH', { action: 'expired', reason });
    }

    async function startRevision() {
        if (!window.confirm('Create a replacement draft for this advisory? The current advisory stays active until its replacement is published.')) {
            return;
        }

        setBusy(true);
        setActionError('');
        try {
            const response = await api(`/alerts/${id}/revise`, { method: 'POST' });
            window.location.assign(`/alerts/${response.data.id}/edit`);
        } catch (requestError) {
            setActionError(requestError.message);
        } finally {
            setBusy(false);
        }
    }

    if (loading) return <LoadingState />;
    if (error) return <Notice>{error}</Notice>;
    const coverageAreas = (alert.risk_areas ?? []).map((area) => ({
        ...area,
        alerts: [{
            title: alert.title,
            severity: alert.severity,
            intensity: alert.intensity,
            message: alert.message,
            safety_instructions: alert.safety_instructions,
        }],
    }));

    return (
        <>
            <PageTitle eyebrow="ADVISORY DETAILS" title={alert.title} description={`Created ${formatDate(alert.created_at)} · ${alert.hazard_source?.organization ?? 'Official source'}`} action={<LinkButton href="/alerts" secondary>Back to advisories</LinkButton>} />
            <Notice message={actionError}>{actionError}</Notice>
            <div className="detail-grid">
                <Panel title="Advisory"><div className="detail-badges"><StatusBadge>{alert.severity}</StatusBadge><IntensityIndicator value={alert.intensity} /><StatusBadge>{alert.status}</StatusBadge></div><p className="detail-message">{alert.message}</p><h3>Safety instructions</h3><p className="detail-safety-instructions">{alert.safety_instructions}</p><dl className="detail-list"><div><dt>Starts</dt><dd>{formatDate(alert.starts_at)}</dd></div><div><dt>Ends</dt><dd>{formatDate(alert.ends_at)}</dd></div><div><dt>Recipients</dt><dd>{alert.recipient_count ?? 'Not published'}</dd></div><div><dt>Drafted by</dt><dd>{alert.creator?.name ?? '—'}</dd></div><div><dt>Approved by</dt><dd>{alert.approver?.name ?? 'Not approved'}</dd></div></dl></Panel>
                <Panel title="Source and coverage"><div className="source-detail"><strong>{alert.hazard_source?.title}</strong><span>{alert.hazard_source?.organization}</span>{alert.hazard_source?.source_url && <a className="text-link" href={alert.hazard_source.source_url} target="_blank" rel="noreferrer">Open source</a>}</div><div className="coverage-list">{alert.risk_areas?.map((area) => <div key={area.id}><strong>{area.name}</strong><small>{area.barangay} · {area.classification}</small></div>)}</div></Panel>
            </div>
            <Panel title="Coverage heatmap"><MapView areas={coverageAreas} /></Panel>
            <Panel title="Workflow actions">
                <div className="workflow-actions">
                    {['draft', 'approved'].includes(alert.status) && <LinkButton href={`/alerts/${alert.id}/edit`} secondary>Edit draft</LinkButton>}
                    {alert.status === 'draft' && canPublishImmediately && <button className="button" disabled={busy} onClick={() => performAction(`/alerts/${id}/publish`)}>Publish draft now</button>}
                    {alert.status === 'draft' && alert.creator?.id !== user.id && <button className="button" disabled={busy} onClick={() => performAction(`/alerts/${id}/approve`)}>Approve</button>}
                    {alert.status === 'approved' && (alert.approver?.id !== user.id || canPublishImmediately) && <button className="button" disabled={busy} onClick={() => performAction(`/alerts/${id}/publish`)}>Publish to all residents</button>}
                    {alert.status === 'published' && <button className="button button-muted" disabled={busy} onClick={startRevision}>Create revision</button>}
                </div>
                {!['expired', 'cancelled'].includes(alert.status) && (
                    <form className="close-advisory-form" onSubmit={closeAdvisory}>
                        <Field label="Reason for closing this advisory">
                            <TextArea
                                value={closeReason}
                                maxLength="500"
                                rows="3"
                                required
                                onChange={(event) => setCloseReason(event.target.value)}
                            />
                        </Field>
                        <button className="button button-danger" disabled={busy || !closeReason.trim()} type="submit">Close advisory</button>
                    </form>
                )}
                <p className="field-hint">
                    {canPublishImmediately
                        ? 'System administrators and MDRRMO personnel may publish a draft immediately or publish an approved advisory. Publishing sends it to all active residents.'
                        : 'Approval and publication must be completed by different authorized staff accounts.'}
                </p>
            </Panel>
        </>
    );
}

export function ResidentAlertsPage() {
    const { data, loading, error } = useApiData('/my/alerts?per_page=100', ADVISORY_REFRESH_INTERVAL_MS);
    const items = data?.data ?? [];

    return (
        <>
            <PageTitle eyebrow="RESIDENT ALERTS" title="Community advisories" description="Current public alerts available to all residents." />
            <Panel><PageState loading={loading} error={error}>
                {items.length === 0 ? <EmptyState title="No active advisories">There are no current community advisories.</EmptyState> : (
                    <div className="alert-list">{items.map((item) => <AlertCard key={item.alert.id} alert={item.alert} isRead={Boolean(item.read_at)} href={`/my-alerts/${item.alert.id}`} />)}</div>
                )}
            </PageState></Panel>
        </>
    );
}

function SafetyInstructionContent({ value }) {
    const text = value?.trim() ?? '';
    const steps = text.split(/(?=\s+\d+\.\s+)/).map((step) => step.trim()).filter(Boolean);

    if (!/^\d+\.\s/.test(text) || steps.length < 2) {
        return <p className="detail-safety-instructions">{value}</p>;
    }

    return (
        <ol className="detail-safety-list">
            {steps.map((step, index) => <li key={index}>{step.replace(/^\d+\.\s*/, '')}</li>)}
        </ol>
    );
}

export function ResidentAlertPage({ id }) {
    const { data, loading, error } = useApiData(`/my/alerts/${id}`);
    const alert = data?.data;

    if (loading) return <LoadingState />;
    if (error) return <Notice>{error}</Notice>;
    const coverageAreas = (alert.risk_areas ?? []).map((area) => ({
        ...area,
        alerts: [{
            title: alert.title,
            severity: alert.severity,
            intensity: alert.intensity,
            message: alert.message,
            safety_instructions: alert.safety_instructions,
        }],
    }));

    return (
        <>
            <PageTitle eyebrow="RESIDENT ADVISORY" title={alert.title} action={<LinkButton href="/my-alerts" secondary>Back to my alerts</LinkButton>} />
            <div className="detail-grid">
                <Panel title="What you need to know" className="resident-advisory-panel">
                    <div className="resident-advisory-scroll" tabIndex={0} aria-label="Advisory message, safety instructions, and validity">
                        <div className="detail-badges"><StatusBadge>{alert.severity}</StatusBadge><IntensityIndicator value={alert.intensity} /></div>
                        <section className="resident-advisory-section">
                            <h3>Advisory message</h3>
                            <p className="detail-message">{alert.message}</p>
                        </section>
                        <section className="resident-advisory-section">
                            <h3>Safety instructions</h3>
                            <SafetyInstructionContent value={alert.safety_instructions} />
                        </section>
                        <dl className="detail-list">
                            <div><dt>Issued</dt><dd>{formatDate(alert.published_at)}</dd></div>
                            <div><dt>Valid until</dt><dd>{formatDate(alert.ends_at)}</dd></div>
                        </dl>
                    </div>
                </Panel>
                <Panel title="Official source"><strong>{alert.hazard_source?.title}</strong><p className="muted">{alert.hazard_source?.organization} · {formatDate(alert.hazard_source?.issued_at)}</p>{alert.hazard_source?.source_url && <a className="text-link" href={alert.hazard_source.source_url} target="_blank" rel="noreferrer">View official source</a>}<div className="coverage-list">{alert.risk_areas?.map((area) => <div key={area.id}><strong>{area.name}</strong><small>{area.barangay}{area.sitio ? ` · ${area.sitio}` : ''}</small></div>)}</div></Panel>
            </div>
            <Panel title="Affected-area heatmap"><MapView areas={coverageAreas} /></Panel>
            <p className="disclaimer"><strong>Safety notice</strong> For emergencies, follow local authorities and official emergency service channels.</p>
        </>
    );
}

export function HazardSourcesPage({ id, user }) {
    const creating = window.location.pathname.endsWith('/create');
    const sourcePath = id ? `/hazard-sources/${id}` : '/hazard-sources?per_page=100';
    const { data, loading, error } = useApiData(sourcePath);
    const [actionError, setActionError] = useState('');
    const [busyId, setBusyId] = useState(null);

    if (creating) return <HazardSourceForm user={user} />;
    if (loading) return <LoadingState />;
    if (error) return <Notice>{error}</Notice>;

    const sources = id ? [data.data] : (data.data ?? []);

    async function review(source, decision) {
        setBusyId(source.id);
        setActionError('');
        try {
            await api(`/hazard-sources/${source.id}/review`, { method: 'PATCH', body: { decision } });
            window.location.reload();
        } catch (requestError) {
            setActionError(requestError.message);
        } finally {
            setBusyId(null);
        }
    }

    return (
        <>
            <PageTitle eyebrow="SOURCE VERIFICATION" title={id ? 'Review source record' : 'Official sources'} description="Review source records before they are used to prepare an advisory." action={['system_admin', 'mdrrmo', 'bfp_officer'].includes(user.role) && <LinkButton href="/hazard-sources/create">Add source record</LinkButton>} />
            <FormError message={actionError} />
            <Panel><PageState loading={false} error="">
                {sources.length === 0 ? <EmptyState title="No source records">Imported and staff-submitted records will appear here.</EmptyState> : (
                    <div className="table-scroll"><table><thead><tr><th>Source record</th><th>Type</th><th>Issued</th><th>Verification</th><th>Submitted by</th><th>Review</th></tr></thead><tbody>
                        {sources.map((source) => <tr key={source.id}>
                            <td><strong>{source.title}</strong><small>{source.organization}{source.reference ? ` · ${source.reference}` : ''}</small>{source.source_url && <a className="text-link" href={source.source_url} target="_blank" rel="noreferrer">Source link</a>}</td>
                            <td>{source.source_type}</td><td>{formatDate(source.issued_at)}</td><td><StatusBadge>{source.verification_status}</StatusBadge></td><td>{source.creator?.name ?? 'System import'}</td>
                            <td>{source.verification_status === 'pending' && source.creator?.id !== user.id ? <div className="inline-actions"><button className="button button-small" disabled={busyId === source.id} onClick={() => review(source, 'verified')}>Verify</button><button className="button button-danger button-small" disabled={busyId === source.id} onClick={() => review(source, 'rejected')}>Reject</button></div> : source.verification_status === 'pending' ? 'Awaiting independent review' : '—'}</td>
                        </tr>)}
                    </tbody></table></div>
                )}
            </PageState></Panel>
        </>
    );
}

function HazardSourceForm({ user }) {
    const [form, setForm] = useState({ source_type: user.role === 'bfp_officer' ? 'fire' : 'weather', reference: '', title: '', details: '', source_url: '', issued_at: '' });
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);

    async function handleSubmit(event) {
        event.preventDefault();
        setError('');
        setSaving(true);

        try {
            await api('/hazard-sources', { method: 'POST', body: { ...form, source_url: form.source_url || null } });
            window.location.assign('/hazard-sources');
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <>
            <PageTitle eyebrow="SOURCE VERIFICATION" title="Add a source record" description="Records are submitted as pending and must be independently reviewed." />
            <Panel className="form-panel source-form-panel"><FormError message={error} />
                <form className="stack-form source-form" onSubmit={handleSubmit}>
                    <div className="form-grid">
                        <Field label="Source type"><SelectInput value={form.source_type} onChange={(event) => setForm({ ...form, source_type: event.target.value })}>{user.role !== 'bfp_officer' && <option value="weather">PAGASA weather</option>}<option value="fire">BFP fire information</option></SelectInput></Field>
                        <Field label="Record title"><TextInput required maxLength="180" value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} /></Field>
                    </div>
                    <div className="form-grid">
                        <Field label="Reference number (optional)"><TextInput maxLength="150" value={form.reference} onChange={(event) => setForm({ ...form, reference: event.target.value })} /></Field>
                        <Field label="Official HTTPS source URL (optional)"><TextInput type="url" value={form.source_url} onChange={(event) => setForm({ ...form, source_url: event.target.value })} /></Field>
                    </div>
                    <Field label="Details"><TextArea required maxLength="10000" rows="7" value={form.details} onChange={(event) => setForm({ ...form, details: event.target.value })} /></Field>
                    <Field label="Issued at (Philippine time)"><TextInput type="datetime-local" required value={form.issued_at} onChange={(event) => setForm({ ...form, issued_at: event.target.value })} /></Field>
                    <FormActions><SubmitButton saving={saving}>Submit for review</SubmitButton><LinkButton href="/hazard-sources" secondary>Cancel</LinkButton></FormActions>
                </form>
            </Panel>
        </>
    );
}

export function RiskAreasPage({ id, user }) {
    const creating = window.location.pathname.endsWith('/create');
    if (creating || (id && window.location.pathname.endsWith('/edit'))) {
        return <RiskAreaForm id={creating ? null : id} />;
    }

    return <RiskAreaList canManage={['system_admin', 'mdrrmo'].includes(user.role)} />;
}

function RiskAreaList({ canManage }) {
    const { data, loading, error } = useApiData('/risk-areas?per_page=100');
    const [actionError, setActionError] = useState('');
    const [busyId, setBusyId] = useState(null);
    const [publishedAlerts, setPublishedAlerts] = useState([]);
    const [alertsError, setAlertsError] = useState('');

    useEffect(() => {
        if (!canManage) return undefined;
        let isCurrent = true;
        api('/alerts?status=published&per_page=100')
            .then((response) => {
                if (isCurrent) setPublishedAlerts(response.data ?? []);
            })
            .catch((requestError) => {
                if (isCurrent) setAlertsError(requestError.message);
            });
        return () => { isCurrent = false; };
    }, [canManage]);

    async function toggle(area) {
        setBusyId(area.id);
        setActionError('');
        try {
            await api(`/risk-areas/${area.id}/toggle`, { method: 'PATCH' });
            window.location.reload();
        } catch (requestError) {
            setActionError(requestError.message);
        } finally {
            setBusyId(null);
        }
    }

    const areas = (data?.data ?? []).map((area) => ({
        ...area,
        alerts: publishedAlerts.filter((alert) => alert.risk_areas?.some((covered) => covered.id === area.id))
            .map(({ title, severity, intensity, message, safety_instructions }) => ({
                title,
                severity,
                intensity,
                message,
                safety_instructions,
            })),
    }));

    return (
        <>
            <PageTitle eyebrow="MAPPED COVERAGE" title="Risk areas" description="Maintain mapped coverage and view active advisory level." action={canManage && <LinkButton href="/risk-areas/create">Add risk area</LinkButton>} />
            <FormError message={actionError} />
            <Panel><PageState loading={loading} error={error || alertsError}>
                {!areas.length ? <EmptyState title="No risk areas configured">Add a mapped area before preparing advisories.</EmptyState> : (
                    <div className="table-scroll"><table><thead><tr><th>Area</th><th>Barangay</th><th>Classification</th><th>Latitude</th><th>Longitude</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                        {areas.map((area) => <tr key={area.id}>
                            <td><strong>{area.name}</strong><small>{area.sitio ?? 'All sitios'}</small></td><td>{area.barangay}</td><td>{area.classification}</td><td>{formatLatitude(area.latitude) ?? '—'}</td><td>{formatLongitude(area.longitude) ?? '—'}</td><td><StatusBadge>{area.is_active ? 'active' : 'inactive'}</StatusBadge></td>
                            <td><div className="inline-actions">{canManage && <><a className="text-link" href={`/risk-areas/${area.id}/edit`}>Edit</a><button className="text-button" disabled={busyId === area.id} onClick={() => toggle(area)}>{area.is_active ? 'Deactivate' : 'Activate'}</button></>}</div></td>
                        </tr>)}
                    </tbody></table></div>
                )}
            </PageState></Panel>
            {areas.length > 0 && <Panel title="Risk-area heatmap"><MapView areas={areas.filter((area) => area.is_active)} /></Panel>}
        </>
    );
}

function centerOfBoundary(boundary) {
    const ring = boundary?.coordinates?.[0] ?? [];
    if (!ring.length) return { latitude: '', longitude: '' };
    const latitudes = ring.map(([, latitude]) => Number(latitude));
    const longitudes = ring.map(([longitude]) => Number(longitude));
    return {
        latitude: String((Math.min(...latitudes) + Math.max(...latitudes)) / 2),
        longitude: String((Math.min(...longitudes) + Math.max(...longitudes)) / 2),
    };
}

function RiskAreaForm({ id }) {
    const editing = Boolean(id);
    const [form, setForm] = useState({ name: '', barangay: '', sitio: '', classification: '', latitude: '', longitude: '', is_active: true });
    const [boundary, setBoundary] = useState(null);
    const [mapFocus, setMapFocus] = useState(null);
    const [locatingBarangay, setLocatingBarangay] = useState('');
    const [locationLookupError, setLocationLookupError] = useState('');
    const [loading, setLoading] = useState(editing);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const locationLookupId = useRef(0);

    useEffect(() => {
        if (!editing) return;
        let isCurrent = true;
        api(`/risk-areas/${id}`)
            .then((response) => {
                if (isCurrent) {
                    const fallbackCenter = centerOfBoundary(response.data.boundary);
                    setForm({
                        name: response.data.name,
                        barangay: response.data.barangay,
                        sitio: response.data.sitio ?? '',
                        classification: response.data.classification,
                        latitude: response.data.latitude ?? fallbackCenter.latitude,
                        longitude: response.data.longitude ?? fallbackCenter.longitude,
                        is_active: response.data.is_active,
                    });
                    setBoundary(response.data.boundary);
                }
            })
            .catch((requestError) => { if (isCurrent) setError(requestError.message); })
            .finally(() => { if (isCurrent) setLoading(false); });
        return () => { isCurrent = false; };
    }, [editing, id]);

    async function focusMapOnBarangay(barangay) {
        const lookupId = ++locationLookupId.current;
        const aliases = bongabongBarangayAliases[barangay] ?? [];
        const names = [barangay, ...aliases].map((name) => name.toLowerCase());

        setMapFocus(null);
        setLocationLookupError('');
        setLocatingBarangay(barangay);

        try {
            const query = new URLSearchParams({
                q: `${barangay}, Oriental Mindoro, Philippines`,
                lat: '12.7074',
                lon: '121.4056',
                zoom: '12',
                limit: '20',
            });
            const response = await fetch(`https://photon.komoot.io/api/?${query}`);

            if (!response.ok) {
                throw new Error('The map search service is unavailable. You can still locate the barangay manually on the map.');
            }

            const result = await response.json();
            const features = Array.isArray(result.features) ? result.features : [];
            const isLocalResult = (feature) => {
                const properties = feature.properties ?? {};
                const coordinates = feature.geometry?.coordinates;
                const longitude = Number(coordinates?.[0]);
                const latitude = Number(coordinates?.[1]);
                const matchedName = [properties.name, properties.locality, properties.city]
                    .some((name) => typeof name === 'string' && names.includes(name.toLowerCase()));
                const inOrientalMindoro = !properties.state
                    || (typeof properties.state === 'string' && properties.state.toLowerCase() === 'oriental mindoro');

                return matchedName
                    && properties.countrycode === 'PH'
                    && inOrientalMindoro
                    && Number.isFinite(latitude)
                    && Number.isFinite(longitude)
                    && isWithinBongabong(longitude, latitude);
            };
            const feature = features.find((candidate) => {
                const properties = candidate.properties ?? {};
                return properties.osm_key === 'place'
                    && names.includes(String(properties.name ?? '').toLowerCase())
                    && isLocalResult(candidate);
            }) ?? features.find(isLocalResult);

            if (!feature) {
                throw new Error(`Could not find ${barangay} on the map. You can still locate it manually.`);
            }

            const [longitude, latitude] = feature.geometry.coordinates.map(Number);

            if (lookupId === locationLookupId.current) {
                setMapFocus({ latitude, longitude, zoom: 14, barangay });
            }
        } catch (requestError) {
            if (lookupId === locationLookupId.current) {
                setLocationLookupError(requestError.message);
            }
        } finally {
            if (lookupId === locationLookupId.current) {
                setLocatingBarangay('');
            }
        }
    }

    async function handleSubmit(event) {
        event.preventDefault();
        setError('');
        setSaving(true);
        try {
            await api(editing ? `/risk-areas/${id}` : '/risk-areas', {
                method: editing ? 'PUT' : 'POST',
                body: {
                    ...form,
                    latitude: Number(form.latitude),
                    longitude: Number(form.longitude),
                    boundary,
                    is_active: Boolean(form.is_active),
                },
            });
            window.location.assign('/risk-areas');
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setSaving(false);
        }
    }

    if (loading) return <LoadingState />;

    return (
        <>
            <PageTitle eyebrow="MAPPED COVERAGE" title={editing ? 'Edit risk area' : 'Add risk area'} description="Choose a barangay to center the map near it, then set the exact area center and boundary." />
            <Panel className="form-panel risk-form-panel"><FormError message={error} />
                <form className="stack-form" onSubmit={handleSubmit}>
                    <div className="form-grid">
                        <Field label="Area name"><TextInput required maxLength="150" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></Field>
                        <Field label="Barangay" hint="Selecting a barangay moves the map near its mapped location.">
                            <SelectInput required value={form.barangay} onChange={(event) => {
                                const barangay = event.target.value;
                                setForm((current) => ({ ...current, barangay }));
                                if (barangay) {
                                    focusMapOnBarangay(barangay);
                                } else {
                                    locationLookupId.current += 1;
                                    setMapFocus(null);
                                    setLocationLookupError('');
                                    setLocatingBarangay('');
                                }
                            }}>
                                <option value="">Select a barangay</option>
                                {form.barangay && !bongabongBarangays.includes(form.barangay) && <option value={form.barangay}>{form.barangay} (existing value)</option>}
                                {bongabongBarangays.map((barangay) => <option key={barangay} value={barangay}>{barangay}</option>)}
                            </SelectInput>
                        </Field>
                        <Field label="Sitio / purok (optional)">
                            <TextInput maxLength="120" value={form.sitio} onChange={(event) => setForm({ ...form, sitio: event.target.value })} />
                        </Field>
                        <Field label="Hazard classification"><TextInput required maxLength="80" placeholder="Flood-prone, landslide-prone…" value={form.classification} onChange={(event) => setForm({ ...form, classification: event.target.value })} /></Field>
                        <Field label="Latitude (decimal degrees)" hint="Positive values are N; negative values are S."><TextInput type="number" step="any" min="-90" max="90" required value={form.latitude} onChange={(event) => setForm({ ...form, latitude: event.target.value })} /></Field>
                        <Field label="Longitude (decimal degrees)" hint="Positive values are E; negative values are W."><TextInput type="number" step="any" min="-180" max="180" required value={form.longitude} onChange={(event) => setForm({ ...form, longitude: event.target.value })} /></Field>
                    </div>
                    <div className="coordinate-preview" role="status">
                        <strong>Formatted coordinates</strong>
                        <span>{formatCoordinatePair(form.latitude, form.longitude) ?? 'Choose a center point on the map or enter both coordinates.'}</span>
                    </div>
                    {locatingBarangay && <Notice tone="info">Finding {locatingBarangay} on the map…</Notice>}
                    {locationLookupError && <Notice tone="info">{locationLookupError}</Notice>}
                    <label className="choice-row"><input type="checkbox" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} /><span><strong>Active coverage</strong><small>Only active areas can receive new advisories.</small></span></label>
                    <MapView editable boundary={boundary} focusLocation={mapFocus} latitude={form.latitude} longitude={form.longitude} onBoundaryChange={setBoundary} onLocationChange={({ latitude, longitude }) => setForm((current) => ({ ...current, latitude: String(latitude), longitude: String(longitude) }))} />
                    {!boundary && <Notice>Finish drawing a boundary before saving.</Notice>}
                    <FormActions><SubmitButton saving={saving} disabled={!boundary || form.latitude === '' || form.longitude === ''}>{editing ? 'Save area' : 'Create area'}</SubmitButton><LinkButton href="/risk-areas" secondary>Cancel</LinkButton></FormActions>
                </form>
            </Panel>
        </>
    );
}

export function ProfilePage({ user }) {
    const { data, loading, error } = useApiData('/profile');
    const [form, setForm] = useState(null);
    const [requestError, setRequestError] = useState('');
    const [notice, setNotice] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (data?.data && !form) {
            setForm({
                name: data.data.name,
                email: data.data.email,
                barangay: data.data.barangay ?? '',
                sitio: data.data.sitio ?? '',
                location_latitude: data.data.location_latitude ?? '',
                location_longitude: data.data.location_longitude ?? '',
                current_password: '',
                password: '',
                password_confirmation: '',
            });
        }
    }, [data, form]);

    async function handleSubmit(event) {
        event.preventDefault();
        setRequestError('');
        setNotice('');
        setSaving(true);

        const payload = { ...form };
        if (!payload.password) {
            delete payload.current_password;
            delete payload.password;
            delete payload.password_confirmation;
        }
        payload.location_latitude = payload.location_latitude === '' ? null : Number(payload.location_latitude);
        payload.location_longitude = payload.location_longitude === '' ? null : Number(payload.location_longitude);

        try {
            const response = await api('/profile', { method: 'PATCH', body: payload });
            setForm((current) => ({ ...current, ...response.data, current_password: '', password: '', password_confirmation: '' }));
            setNotice(response.message);
        } catch (submitError) {
            setRequestError(submitError.message);
        } finally {
            setSaving(false);
        }
    }

    if (loading) return <LoadingState />;
    if (error) return <Notice>{error}</Notice>;
    if (!form) return null;

    return (
        <>
            <PageTitle eyebrow="ACCOUNT SETTINGS" title="My profile" description={`Signed in as ${roleLabel(user.role)}.`} />
            <Panel className="form-panel profile-form-panel"><FormError message={requestError} />{notice && <Notice tone="success">{notice}</Notice>}
                <form className="stack-form profile-form" onSubmit={handleSubmit}>
                    <div className="form-grid">
                        <Field label="Full name"><TextInput required maxLength="120" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></Field>
                        <Field label="Email address"><TextInput type="email" required maxLength="255" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} /></Field>
                    </div>
                    {user.role === 'resident' && <div className="form-grid">
                        <Field label="Barangay">
                            <SelectInput required value={form.barangay} onChange={(event) => setForm({ ...form, barangay: event.target.value })}>
                                <option value="">Select a barangay</option>
                                {form.barangay && !bongabongBarangays.includes(form.barangay) && <option value={form.barangay}>{form.barangay} (existing value)</option>}
                                {bongabongBarangays.map((barangay) => <option key={barangay} value={barangay}>{barangay}</option>)}
                            </SelectInput>
                        </Field>
                        <Field label="Sitio / purok"><TextInput maxLength="120" value={form.sitio} onChange={(event) => setForm({ ...form, sitio: event.target.value })} /></Field>
                    </div>}
                    <div className="profile-location">
                        <h3>Private home location</h3><p className="field-hint">Coordinates remain private and help show mapped coverage relevant to your location. Community alerts are shared with all residents.</p>
                        <div className="form-grid">
                            <Field label="Latitude"><TextInput type="number" step="any" min="-90" max="90" value={form.location_latitude} onChange={(event) => setForm({ ...form, location_latitude: event.target.value })} /></Field>
                            <Field label="Longitude"><TextInput type="number" step="any" min="-180" max="180" value={form.location_longitude} onChange={(event) => setForm({ ...form, location_longitude: event.target.value })} /></Field>
                        </div>
                    </div>
                    <div className="profile-location"><h3>Change password</h3>
                        <div className="stack-form">
                            <Field label="Current password"><TextInput type="password" autoComplete="current-password" value={form.current_password} onChange={(event) => setForm({ ...form, current_password: event.target.value })} /></Field>
                            <div className="form-grid">
                                <Field label="New password" hint="At least 10 characters, including letters and numbers."><TextInput type="password" autoComplete="new-password" minLength="10" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} /></Field>
                                <Field label="Confirm new password"><TextInput type="password" autoComplete="new-password" value={form.password_confirmation} onChange={(event) => setForm({ ...form, password_confirmation: event.target.value })} /></Field>
                            </div>
                        </div>
                    </div>
                    <FormActions><SubmitButton saving={saving}>Save profile</SubmitButton></FormActions>
                </form>
            </Panel>
        </>
    );
}

export function StaffPage({ user }) {
    const { data, loading, error } = useApiData('/staff?per_page=100');
    const [notice, setNotice] = useState('');
    const [actionError, setActionError] = useState('');

    async function updateStaff(id, action, body) {
        setActionError('');
        setNotice('');
        try {
            const response = await api(`/staff/${id}/${action}`, { method: 'PATCH', body });
            setNotice(response.message);
            window.location.reload();
        } catch (requestError) {
            setActionError(requestError.message);
        }
    }

    return (
        <>
            <PageTitle eyebrow="ACCESS MANAGEMENT" title="Staff accounts" description="Create authorized accounts and review staff access." action={<LinkButton href="/staff/create">Create staff account</LinkButton>} />
            <FormError message={actionError} />{notice && <Notice tone="success">{notice}</Notice>}
            <Panel><PageState loading={loading} error={error}>
                {!data?.data?.length ? <EmptyState title="No staff accounts found" /> : (
                    <div className="table-scroll"><table><thead><tr><th>Staff member</th><th>Role</th><th>Status</th><th>Access</th></tr></thead><tbody>
                        {data.data.map((member) => <tr key={member.id}>
                            <td><strong>{member.name}</strong><small>{member.email}</small></td>
                            <td><SelectInput value={member.role} onChange={(event) => updateStaff(member.id, 'role', { role: event.target.value })}>{staffRoles.map((role) => <option key={role} value={role}>{roleLabel(role)}</option>)}</SelectInput></td>
                            <td><StatusBadge>{member.is_active ? 'active' : 'inactive'}</StatusBadge></td>
                            <td>{member.id === user.id ? 'Current account' : <button className="text-button" onClick={() => updateStaff(member.id, 'active')}>{member.is_active ? 'Deactivate' : 'Activate'}</button>}</td>
                        </tr>)}
                    </tbody></table></div>
                )}
            </PageState></Panel>
        </>
    );
}

export function StaffFormPage() {
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);

    async function handleSubmit(event) {
        event.preventDefault();
        setError('');
        setSaving(true);
        const formData = new FormData(event.currentTarget);

        try {
            await api('/staff', {
                method: 'POST',
                body: {
                    name: formData.get('name'),
                    email: formData.get('email'),
                    role: formData.get('role'),
                    password: formData.get('password'),
                    password_confirmation: formData.get('password_confirmation'),
                },
            });
            window.location.assign('/staff');
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <>
            <PageTitle eyebrow="ACCESS MANAGEMENT" title="Create staff account" description="Share the temporary password through a secure channel." />
            <Panel className="form-panel"><FormError message={error} />
                <form className="stack-form" onSubmit={handleSubmit}>
                    <Field label="Full name"><TextInput name="name" required maxLength="120" /></Field>
                    <Field label="Email address"><TextInput name="email" type="email" required maxLength="255" /></Field>
                    <Field label="Staff role"><SelectInput name="role">{staffRoles.map((role) => <option key={role} value={role}>{roleLabel(role)}</option>)}</SelectInput></Field>
                    <Field label="Temporary password" hint="At least 12 characters, including letters and numbers."><TextInput name="password" type="password" minLength="12" required /></Field>
                    <Field label="Confirm password"><TextInput name="password_confirmation" type="password" required /></Field>
                    <FormActions><SubmitButton saving={saving}>Create account</SubmitButton><LinkButton href="/staff" secondary>Cancel</LinkButton></FormActions>
                </form>
            </Panel>
        </>
    );
}

export function AuditPage() {
    const [page, setPage] = useState(1);
    const { data, loading, error } = useApiData(`/staff/audit?page=${page}`);

    return (
        <>
            <PageTitle eyebrow="ACCESS MANAGEMENT" title="Audit history" description="Important actions including sign-ins, account changes, advisories, and risk areas." />
            <Panel><PageState loading={loading} error={error}>
                {!data?.data?.length ? <EmptyState title="No audit entries" /> : (
                    <>
                        <div className="table-scroll"><table><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Record / page</th><th>Details</th></tr></thead><tbody>
                            {data.data.map((entry) => {
                                const subject = entry.subject_id
                                    ? `${entry.subject_type?.split('\\').pop() ?? 'Record'} #${entry.subject_id}`
                                    : entry.subject_type?.split('\\').pop() ?? '—';
                                const details = Object.entries(entry.metadata ?? {})
                                    .map(([key, value]) => `${key.replaceAll('_', ' ')}: ${value !== null && typeof value === 'object' ? JSON.stringify(value) : value}`)
                                    .join(' · ');

                                return (
                                    <tr key={entry.id}>
                                        <td>{formatDate(entry.created_at)}</td>
                                        <td>{entry.actor?.name ?? 'System'}</td>
                                        <td><code>{entry.action}</code></td>
                                        <td>{subject}</td>
                                        <td>{details || '—'}</td>
                                    </tr>
                                );
                            })}
                        </tbody></table></div>
                        {data.meta?.last_page > 1 && (
                            <nav className="advisory-pagination" aria-label="Audit history pages">
                                <button
                                    aria-label="Previous page"
                                    disabled={page <= 1}
                                    onClick={() => setPage((current) => Math.max(1, current - 1))}
                                    type="button"
                                >‹</button>
                                <span aria-live="polite">Page {data.meta.current_page} of {data.meta.last_page} · {data.meta.total} actions</span>
                                <button
                                    aria-label="Next page"
                                    disabled={page >= data.meta.last_page}
                                    onClick={() => setPage((current) => Math.min(data.meta.last_page, current + 1))}
                                    type="button"
                                >›</button>
                            </nav>
                        )}
                    </>
                )}
            </PageState></Panel>
        </>
    );
}

export function NotFoundPage() {
    return <Panel><PageTitle eyebrow="NOT FOUND" title="This page is unavailable" description="The link may have changed, or your account may not have access." /><a className="text-link" href="/dashboard">Return to overview</a></Panel>;
}
