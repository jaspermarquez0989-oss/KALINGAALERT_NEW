import { useEffect, useRef, useState } from 'react';
import { api } from './api.js';
import { lookupBongabongBarangay } from './data/lookup-bongabong-barangay.js';
import MapView from './MapView.jsx';
import {
    Field,
    FormActions,
    Notice,
    PageTitle,
    Panel,
    SelectInput,
    TextInput,
} from './ui.jsx';

const numericInputs = [
    { name: 'elevation_m', label: 'Elevation', hint: 'Meters above sea level', min: -500, max: 9000, step: 'any' },
    { name: 'temperature_c', label: 'Temperature', hint: 'Degrees Celsius', min: -50, max: 60, step: 'any' },
    { name: 'relative_humidity_pct', label: 'Relative humidity', hint: 'Percent', min: 0, max: 100, step: 'any' },
    { name: 'wind_speed_kmh', label: 'Wind speed', hint: 'Kilometers per hour', min: 0, max: 500, step: 'any' },
    { name: 'rainfall_mm', label: 'Rainfall', hint: 'Millimeters', min: 0, max: 2000, step: 'any' },
    { name: 'days_since_last_rain', label: 'Days since last rain', min: 0, max: 3650, step: '1' },
    { name: 'soil_moisture_pct', label: 'Soil moisture', hint: 'Percent', min: 0, max: 100, step: 'any' },
    { name: 'fuel_dryness_index', label: 'Fuel dryness index', hint: 'Index from 0 to 100', min: 0, max: 100, step: 'any' },
    { name: 'dist_to_fire_station_km', label: 'Distance to fire station', hint: 'Kilometers', min: 0, max: 1000, step: 'any' },
    { name: 'distance_to_water_km', label: 'Distance to water source', hint: 'Kilometers', min: 0, max: 1000, step: 'any' },
];

function initialForm() {
    return {
        month: String(new Date().getMonth() + 1),
        barangay: '',
        land_use_zone: '',
        season: '',
        ...Object.fromEntries(numericInputs.map(({ name }) => [name, ''])),
    };
}

function monthLabel(month) {
    return new Date(2020, month - 1, 1).toLocaleString('en', { month: 'long' });
}

export default function FireRiskPredictionPage({ user }) {
    const [modelInfo, setModelInfo] = useState(null);
    const [form, setForm] = useState(initialForm);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [mapError, setMapError] = useState('');
    const [predictionLocation, setPredictionLocation] = useState(null);
    const predictionRequestId = useRef(0);
    const score = result ? Math.min(100, Math.max(0, result.score_percent)) : 0;
    const referenceThreshold = result ? Math.min(100, Math.max(0, result.threshold * 100)) : 50;

    useEffect(() => {
        let isCurrent = true;
        api('/fire-risk/model-info')
            .then((response) => {
                if (isCurrent) setModelInfo(response.data);
            })
            .catch((requestError) => {
                if (isCurrent) setError(requestError.message);
            })
            .finally(() => {
                if (isCurrent) setLoading(false);
            });
        return () => { isCurrent = false; };
    }, []);

    function updateField(name, value) {
        predictionRequestId.current += 1;
        setForm((current) => ({ ...current, [name]: value }));
        setResult(null);
        setPredictionLocation(null);
        setMapError('');
    }

    async function submitPrediction(event) {
        event.preventDefault();
        const requestId = ++predictionRequestId.current;
        setError('');
        setMapError('');
        setResult(null);
        setPredictionLocation(null);
        setSaving(true);
        try {
            let coordinates = null;
            try {
                coordinates = await lookupBongabongBarangay(form.barangay);
            } catch (locationError) {
                if (requestId === predictionRequestId.current) setMapError(locationError.message);
            }
            if (requestId !== predictionRequestId.current) return;

            const body = Object.fromEntries(
                Object.entries(form).map(([name, value]) => [
                    name,
                    ['barangay', 'land_use_zone', 'season'].includes(name)
                        ? value
                        : Number(value),
                ]),
            );
            if (coordinates) {
                body.latitude = coordinates.latitude;
                body.longitude = coordinates.longitude;
            }
            const response = await api('/fire-risk/predict', { method: 'POST', body });
            if (requestId !== predictionRequestId.current) return;
            setResult(response.data);
            if (coordinates) {
                setPredictionLocation({
                    id: response.data.prediction_id,
                    ...coordinates,
                    barangay: form.barangay,
                    scorePercent: response.data.score_percent,
                    thresholdPercent: response.data.threshold * 100,
                    thresholdMet: response.data.threshold_met,
                });
            }
        } catch (requestError) {
            if (requestId === predictionRequestId.current) setError(requestError.message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <>
            <PageTitle
                eyebrow="STAFF DECISION SUPPORT"
                title="24-hour fire occurrence by barangay"
                description="Estimate fire occurrence using pre-forecast conditions for the selected barangay."
            />
            <Notice tone="info">
                Staff decision support only. Enter verified readings before the forecast period; no live weather feed or sensor is connected. This score does not create an alert. A below-threshold result is not an all-clear—continue to follow official monitoring and response procedures.
            </Notice>
            {error && <Notice>{error}</Notice>}
            <Panel title="Enter conditions before the next 24 hours" className="fire-risk-form-panel">
                <p className="muted">
                    Select a barangay and provide its pre-forecast observations. The target is verified fire occurrence within 500 meters of a recorded observation point in that barangay during the next 24 hours. The model does not distinguish locations within a barangay and requires every field; missing data is not automatically filled in.
                </p>
                <form className="stack-form" onSubmit={submitPrediction}>
                    <div className="form-grid">
                        <Field label="Month">
                            <SelectInput required value={form.month} onChange={(event) => updateField('month', event.target.value)}>
                                <option value="">Select a month</option>
                                {Array.from({ length: 12 }, (_, index) => {
                                    const month = String(index + 1);
                                    return <option key={month} value={month}>{monthLabel(index + 1)}</option>;
                                })}
                            </SelectInput>
                        </Field>
                        <Field label="Barangay">
                            <SelectInput required value={form.barangay} onChange={(event) => updateField('barangay', event.target.value)}>
                                <option value="">Select a barangay</option>
                                {(modelInfo?.categories?.barangay ?? []).map((value) => <option key={value} value={value}>{value}</option>)}
                            </SelectInput>
                        </Field>
                        <Field label="Land-use zone">
                            <SelectInput required value={form.land_use_zone} onChange={(event) => updateField('land_use_zone', event.target.value)}>
                                <option value="">Select a zone</option>
                                {(modelInfo?.categories?.land_use_zone ?? []).map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}
                            </SelectInput>
                        </Field>
                        <Field label="Season">
                            <SelectInput required value={form.season} onChange={(event) => updateField('season', event.target.value)}>
                                <option value="">Select a season</option>
                                {(modelInfo?.categories?.season ?? []).map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}
                            </SelectInput>
                        </Field>
                    </div>
                    <div className="form-grid">
                        {numericInputs.map(({ name, label, hint, min, max, step }) => (
                            <Field
                                key={name}
                                label={label}
                                hint={`Observed dataset range: ${modelInfo?.numeric_ranges?.[name]?.minimum ?? min} to ${modelInfo?.numeric_ranges?.[name]?.maximum ?? max}${hint ? ` · ${hint}` : ''}`}
                            >
                                <TextInput
                                    type="number"
                                    name={name}
                                    min={modelInfo?.numeric_ranges?.[name]?.minimum ?? min}
                                    max={modelInfo?.numeric_ranges?.[name]?.maximum ?? max}
                                    step={step}
                                    required
                                    value={form[name]}
                                    onChange={(event) => updateField(name, event.target.value)}
                                />
                            </Field>
                        ))}
                    </div>
                    <FormActions>
                        <button className="button" disabled={loading || saving || !modelInfo} type="submit">
                            {saving ? 'Calculating estimate…' : 'Calculate 24-hour estimate'}
                        </button>
                    </FormActions>
                </form>
            </Panel>
            {result && (
                <Panel title="24-hour fire occurrence estimate">
                    <div className="fire-risk-result">
                        <div className={`fire-risk-outcome ${result.threshold_met ? 'fire-risk-outcome-raised' : 'fire-risk-outcome-below'}`} role="status" aria-live="polite">
                            <span className="field-hint">For {form.barangay} · next 24 hours</span>
                            <strong>{result.threshold_met ? 'Fire may occur' : 'Fire may still occur'}</strong>
                            <p>
                                {result.threshold_met
                                    ? `The model score meets or exceeds the ${referenceThreshold.toFixed(0)} / 100 reference threshold.`
                                    : `The model score is below the ${referenceThreshold.toFixed(0)} / 100 reference threshold. This is not an all-clear.`}
                            </p>
                        </div>
                        <div className="fire-risk-score">
                            <div className="fire-risk-score-heading">
                                <span>Possibility of fire <small>Model estimate</small></span>
                                <strong>{score.toFixed(1)} / 100</strong>
                            </div>
                            <div
                                className="fire-risk-score-track"
                                role="meter"
                                aria-label="Fire possibility estimate"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow={score}
                                aria-valuetext={`${score.toFixed(1)} out of 100 fire possibility estimate points; reference threshold ${referenceThreshold.toFixed(0)}`}
                                style={{
                                    background: `linear-gradient(90deg, #f3e8cc 0%, #f3e8cc ${referenceThreshold}%, #f4d9d3 ${referenceThreshold}%, #f4d9d3 100%)`,
                                }}
                            >
                                <span
                                    className={`fire-risk-score-fill ${result.threshold_met ? 'fire-risk-score-fill-raised' : ''}`}
                                    style={{ width: `${score}%` }}
                                />
                                <span className="fire-risk-score-threshold" aria-hidden="true" style={{ left: `${referenceThreshold}%` }} />
                            </div>
                            <div className="fire-risk-score-scale" aria-hidden="true">
                                <span>0</span>
                                <span className="fire-risk-score-threshold-label" style={{ left: `${referenceThreshold}%` }}>
                                    {referenceThreshold.toFixed(0)} reference
                                </span>
                                <span>100</span>
                            </div>
                        </div>
                    </div>
                    <Notice tone="info">{result.disclaimer}</Notice>
                    <p className="muted">
                        The map pin marks the selected barangay’s mapped reference point only; it does not identify an exact fire location. The score is a model-based indication of possible fire conditions, not a confirmed fire.
                    </p>
                    {mapError && <Notice>{mapError}</Notice>}
                    {predictionLocation && (
                        <MapView
                            prediction={predictionLocation}
                            canCreateAdvisories={['system_admin', 'mdrrmo', 'alert_operator'].includes(user?.role)}
                        />
                    )}
                </Panel>
            )}
        </>
    );
}
