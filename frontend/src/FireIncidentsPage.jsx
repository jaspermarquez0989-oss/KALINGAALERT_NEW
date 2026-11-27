import { useEffect, useRef, useState } from 'react';
import { api } from './api.js';
import { formatCoordinatePair } from './coordinates.js';
import { bongabongBarangays } from './data/bongabong-barangays.js';
import { lookupBongabongBarangay } from './data/lookup-bongabong-barangay.js';
import {
    EmptyState,
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
} from './ui.jsx';

const incidentTypes = [
    ['structure', 'Structure fire'],
    ['vegetation', 'Vegetation fire'],
    ['grassland', 'Grassland fire'],
    ['vehicle', 'Vehicle fire'],
    ['other', 'Other'],
];
const incidentCauses = [
    ['electrical', 'Electrical'],
    ['cooking', 'Cooking'],
    ['open_burning', 'Open burning'],
    ['equipment', 'Equipment'],
    ['lightning', 'Lightning'],
    ['undetermined', 'Undetermined'],
    ['other', 'Other'],
];

function localDateTimeValue() {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
}

function emptyIncidentForm() {
    return {
        occurred_at: localDateTimeValue(),
        barangay: '',
        sitio: '',
        incident_type: 'structure',
        cause: 'undetermined',
        latitude: '',
        longitude: '',
        injuries: '0',
        fatalities: '0',
        property_damage_estimate: '',
        source_reference: '',
        source_url: '',
        details: '',
    };
}

function csvCell(value) {
    const text = String(value ?? '');
    const safeText = typeof value === 'string' && /^[\s]*[=+\-@]/.test(text) ? `'${text}` : text;
    return `"${safeText.replaceAll('"', '""')}"`;
}

function downloadCsv(records) {
    const columns = [
        'id',
        'occurred_at',
        'barangay',
        'sitio',
        'incident_type',
        'cause',
        'latitude',
        'longitude',
        'injuries',
        'fatalities',
        'property_damage_estimate',
        'source_reference',
        'fire_occurred',
    ];
    const rows = [columns, ...records.map((record) => columns.map((column) => record[column]))];
    const contents = `\uFEFF${rows.map((row) => row.map(csvCell).join(',')).join('\r\n')}`;
    const url = URL.createObjectURL(new Blob([contents], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'kalingaalert-verified-fire-incidents.csv';
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 0);
}

export default function FireIncidentsPage({ user }) {
    const canSubmit = ['system_admin', 'mdrrmo', 'bfp_officer'].includes(user.role);
    const canReview = user.role === 'bfp_officer';
    const [form, setForm] = useState(emptyIncidentForm);
    const [filter, setFilter] = useState('all');
    const [page, setPage] = useState(1);
    const [records, setRecords] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [busyId, setBusyId] = useState(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [refreshKey, setRefreshKey] = useState(0);
    const [locatingBarangay, setLocatingBarangay] = useState('');
    const [locationError, setLocationError] = useState('');
    const locationLookupId = useRef(0);

    useEffect(() => {
        let isCurrent = true;

        async function loadIncidents() {
            setLoading(true);
            setError('');
            const query = new URLSearchParams({ per_page: '50', page: String(page) });
            if (filter !== 'all') query.set('verification_status', filter);

            try {
                const response = await api(`/fire-incidents?${query}`);
                if (isCurrent) {
                    setRecords(response.data ?? []);
                    setMeta(response.meta ?? null);
                }
            } catch (requestError) {
                if (isCurrent) setError(requestError.message);
            } finally {
                if (isCurrent) setLoading(false);
            }
        }

        loadIncidents();
        return () => { isCurrent = false; };
    }, [filter, page, refreshKey]);

    useEffect(() => () => {
        locationLookupId.current += 1;
    }, []);

    async function selectBarangay(barangay) {
        const lookupId = ++locationLookupId.current;
        setForm((current) => ({ ...current, barangay, latitude: '', longitude: '' }));
        setLocationError('');
        setLocatingBarangay('');

        if (!barangay) return;

        setLocatingBarangay(barangay);
        try {
            const coordinates = await lookupBongabongBarangay(barangay);
            if (lookupId === locationLookupId.current) {
                setForm((current) => ({
                    ...current,
                    latitude: String(coordinates.latitude),
                    longitude: String(coordinates.longitude),
                }));
            }
        } catch (requestError) {
            if (lookupId === locationLookupId.current) {
                setLocationError(requestError.message);
            }
        } finally {
            if (lookupId === locationLookupId.current) {
                setLocatingBarangay('');
            }
        }
    }

    async function submitIncident(event) {
        event.preventDefault();
        setError('');
        setNotice('');
        setLocationError('');
        setSaving(true);

        try {
            await api('/fire-incidents', {
                method: 'POST',
                body: {
                    ...form,
                    occurred_at: new Date(form.occurred_at).toISOString(),
                    latitude: Number(form.latitude),
                    longitude: Number(form.longitude),
                    injuries: Number(form.injuries),
                    fatalities: Number(form.fatalities),
                    property_damage_estimate: form.property_damage_estimate === ''
                        ? null
                        : Number(form.property_damage_estimate),
                    source_url: form.source_url || null,
                    details: form.details || null,
                    sitio: form.sitio || null,
                },
            });
            setForm(emptyIncidentForm());
            setFilter('pending');
            setPage(1);
            setRefreshKey((key) => key + 1);
            setNotice('Incident submitted. It will be included in the verified dataset only after BFP review.');
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setSaving(false);
        }
    }

    async function reviewIncident(incident, decision) {
        let reviewNotes = '';

        if (decision === 'verified') {
            if (!window.confirm('Verify this incident against its official BFP source?')) return;
        } else {
            const response = window.prompt('Enter the reason this incident cannot be verified:');
            if (response === null) return;
            reviewNotes = response.trim();
            if (!reviewNotes) {
                setError('A reason is required when rejecting an incident.');
                return;
            }
        }

        setError('');
        setNotice('');
        setBusyId(incident.id);

        try {
            const response = await api(`/fire-incidents/${incident.id}/review`, {
                method: 'PATCH',
                body: { decision, review_notes: reviewNotes || null },
            });
            setNotice(response.message);
            setRefreshKey((key) => key + 1);
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusyId(null);
        }
    }

    async function exportVerifiedDataset() {
        setError('');
        setNotice('');
        setExporting(true);

        try {
            const response = await api('/fire-incidents/training-data');
            if (response.data?.length) {
                downloadCsv(response.data);
            }
            setNotice(`${response.meta?.verified_records ?? 0} verified incident record(s) exported.`);
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setExporting(false);
        }
    }

    function updateField(name, value) {
        setForm((current) => ({ ...current, [name]: value }));
        if (name === 'latitude' || name === 'longitude') setLocationError('');
    }

    return (
        <>
            <PageTitle
                eyebrow="BFP INCIDENT RECORDS"
                title="Historical fire incidents"
                description="Record incidents from official sources, then have a different BFP officer verify each entry before it enters the training dataset."
            />
            {error && <Notice>{error}</Notice>}
            {notice && <Notice tone="success">{notice}</Notice>}
            <Panel title="Verified training-data readiness">
                <p className="muted">
                    {meta?.verified_records ?? 0} verified incident record(s) are available for export. The CSV contains verified fire-positive examples only; a future prediction model will also need reliable non-fire observations and matching weather/environment data.
                </p>
                <button className="button button-muted button-small" disabled={exporting || !meta?.verified_records} onClick={exportVerifiedDataset} type="button">
                    {exporting ? 'Preparing export...' : 'Download verified training data'}
                </button>
            </Panel>
            {canSubmit && (
                <Panel title="Submit a historical incident" className="fire-incident-form-panel">
                    <p className="muted">Use the incident time and location from the official record. Do not enter victims’ names or other personal information.</p>
                    <form className="stack-form" onSubmit={submitIncident}>
                        <div className="form-grid">
                            <Field label="Incident date and time">
                                <TextInput type="datetime-local" required max={localDateTimeValue()} value={form.occurred_at} onChange={(event) => updateField('occurred_at', event.target.value)} />
                            </Field>
                            <Field label="Barangay">
                                <SelectInput required value={form.barangay} onChange={(event) => selectBarangay(event.target.value)}>
                                    <option value="">Select a barangay</option>
                                    {bongabongBarangays.map((barangay) => <option key={barangay} value={barangay}>{barangay}</option>)}
                                </SelectInput>
                            </Field>
                            <Field label="Sitio / purok (optional)">
                                <TextInput maxLength="120" value={form.sitio} onChange={(event) => updateField('sitio', event.target.value)} />
                            </Field>
                            <Field label="Incident type">
                                <SelectInput required value={form.incident_type} onChange={(event) => updateField('incident_type', event.target.value)}>
                                    {incidentTypes.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                </SelectInput>
                            </Field>
                        </div>
                        <div className="form-grid">
                            <Field label="Recorded cause">
                                <SelectInput required value={form.cause} onChange={(event) => updateField('cause', event.target.value)}>
                                    {incidentCauses.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                </SelectInput>
                            </Field>
                            <Field label="Injuries">
                                <TextInput type="number" min="0" step="1" required value={form.injuries} onChange={(event) => updateField('injuries', event.target.value)} />
                            </Field>
                            <Field label="Latitude" hint="Starts at the mapped barangay point; use official incident coordinates if available.">
                                <TextInput type="number" step="any" min="-90" max="90" required value={form.latitude} onChange={(event) => updateField('latitude', event.target.value)} />
                            </Field>
                            <Field label="Longitude">
                                <TextInput type="number" step="any" min="-180" max="180" required value={form.longitude} onChange={(event) => updateField('longitude', event.target.value)} />
                            </Field>
                            <Field label="Fatalities">
                                <TextInput type="number" min="0" step="1" required value={form.fatalities} onChange={(event) => updateField('fatalities', event.target.value)} />
                            </Field>
                            <Field label="Estimated property damage (PHP, optional)">
                                <TextInput type="number" min="0" step="0.01" value={form.property_damage_estimate} onChange={(event) => updateField('property_damage_estimate', event.target.value)} />
                            </Field>
                        </div>
                        {locatingBarangay && <Notice tone="info">Finding mapped coordinates for {locatingBarangay}…</Notice>}
                        {locationError && <Notice>{locationError}</Notice>}
                        <Field label="Official BFP source reference" hint="Required. For example, an incident log or report number.">
                            <TextInput required maxLength="255" value={form.source_reference} onChange={(event) => updateField('source_reference', event.target.value)} />
                        </Field>
                        <Field label="Official source URL (optional)">
                            <TextInput type="url" maxLength="2048" value={form.source_url} onChange={(event) => updateField('source_url', event.target.value)} />
                        </Field>
                        <Field label="Incident notes (optional)">
                            <TextArea maxLength="5000" value={form.details} onChange={(event) => updateField('details', event.target.value)} />
                        </Field>
                        <FormActions>
                            <button className="button" disabled={saving || Boolean(locatingBarangay)} type="submit">{saving ? 'Submitting...' : 'Submit for BFP verification'}</button>
                        </FormActions>
                    </form>
                </Panel>
            )}
            <Panel title="Incident records">
                <div className="fire-incident-list-controls">
                    <Field label="Verification status">
                        <SelectInput value={filter} onChange={(event) => {
                            setFilter(event.target.value);
                            setPage(1);
                        }}>
                            <option value="all">All records</option>
                            <option value="pending">Pending review</option>
                            <option value="verified">Verified</option>
                            <option value="rejected">Rejected</option>
                        </SelectInput>
                    </Field>
                    {canReview && <span className="field-hint">Only a different BFP officer can verify a submission.</span>}
                </div>
                {loading ? <LoadingState variant="list" label="Loading fire incident records..." /> : (
                    <>
                        {!records.length ? <EmptyState title="No incident records">Submit documented historical incidents to begin building the verified dataset.</EmptyState> : (
                            <div className="table-scroll"><table><thead><tr>
                                <th>Occurred</th><th>Location</th><th>Type / cause</th><th>Coordinates</th><th>Injuries / fatalities</th><th>Official source</th><th>Status</th><th>Review</th>
                            </tr></thead><tbody>
                                {records.map((incident) => (
                                    <tr key={incident.id}>
                                        <td>{formatDate(incident.occurred_at)}</td>
                                        <td><strong>{incident.barangay}</strong><small>{incident.sitio || 'Sitio not recorded'}</small></td>
                                        <td><strong>{incident.incident_type.replaceAll('_', ' ')}</strong><small>{incident.cause.replaceAll('_', ' ')}</small></td>
                                        <td>{formatCoordinatePair(incident.latitude, incident.longitude) ?? '—'}</td>
                                        <td>{incident.injuries} / {incident.fatalities}</td>
                                        <td>{incident.source_url
                                            ? <a className="text-link" href={incident.source_url} target="_blank" rel="noreferrer">{incident.source_reference}</a>
                                            : incident.source_reference}
                                        </td>
                                        <td><StatusBadge>{incident.verification_status}</StatusBadge></td>
                                        <td>
                                            {canReview && incident.verification_status === 'pending'
                                                ? incident.submitted_by?.id === user.id
                                                    ? <span className="field-hint">Awaiting another BFP officer</span>
                                                    : <div className="inline-actions">
                                                        <button className="text-button" disabled={busyId === incident.id} onClick={() => reviewIncident(incident, 'verified')} type="button">Verify</button>
                                                        <button className="text-button button-danger-text" disabled={busyId === incident.id} onClick={() => reviewIncident(incident, 'rejected')} type="button">Reject</button>
                                                    </div>
                                                : incident.reviewer?.name ?? '—'}
                                            {incident.review_notes && <small>{incident.review_notes}</small>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody></table></div>
                        )}
                        {meta?.last_page > 1 && (
                            <nav className="advisory-pagination" aria-label="Fire incident pages">
                                <button aria-label="Previous page" disabled={page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))} type="button">‹</button>
                                <span aria-live="polite">Page {meta.current_page} of {meta.last_page} · {meta.total} records</span>
                                <button aria-label="Next page" disabled={page >= meta.last_page} onClick={() => setPage((current) => Math.min(meta.last_page, current + 1))} type="button">›</button>
                            </nav>
                        )}
                    </>
                )}
            </Panel>
            <Notice tone="info">Verified historical incidents are evidence records, not predictions. The training export currently contains positive fire cases only and must be paired with labeled non-fire periods and environmental observations before machine learning is appropriate.</Notice>
        </>
    );
}
