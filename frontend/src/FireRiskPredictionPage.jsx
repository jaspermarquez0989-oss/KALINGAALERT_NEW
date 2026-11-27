import { useEffect, useState } from 'react';
import { api } from './api.js';
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

export default function FireRiskPredictionPage() {
    const [modelInfo, setModelInfo] = useState(null);
    const [form, setForm] = useState(initialForm);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const validationFolds = modelInfo?.validation?.folds ?? [];
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
        setForm((current) => ({ ...current, [name]: value }));
        setResult(null);
    }

    async function submitPrediction(event) {
        event.preventDefault();
        setError('');
        setResult(null);
        setSaving(true);
        try {
            const body = Object.fromEntries(
                Object.entries(form).map(([name, value]) => [
                    name,
                    ['barangay', 'land_use_zone', 'season'].includes(name)
                        ? value
                        : Number(value),
                ]),
            );
            const response = await api('/fire-risk/predict', { method: 'POST', body });
            setResult(response.data);
        } catch (requestError) {
            setError(requestError.message);
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
            <Panel title="Model and historical validation">
                {loading ? <p className="muted">Loading model information…</p> : modelInfo && (
                    <>
                        <p className="muted">
                            Version {modelInfo.model_version} · {modelInfo.training_rows} training rows ·
                            {' '}Training period {modelInfo.training_date_range?.[0] ?? 'not available'} to {modelInfo.training_date_range?.[1] ?? 'not available'}.
                            {' '}Dataset timing, location, and labels were verified by MDRRMO staff, as reported by the user.
                        </p>
                        <p className="muted">{modelInfo.outcome_scope}</p>
                        <p className="muted">
                            Forward-year validation across {modelInfo.validation.summary.test_rows} rows: {(modelInfo.validation.summary.accuracy * 100).toFixed(1)}% accuracy, {(modelInfo.validation.summary.recall * 100).toFixed(1)}% fire recall, mean yearly ROC AUC {modelInfo.validation.summary.mean_yearly_roc_auc.toFixed(3)}. Majority-class baseline accuracy: {(modelInfo.baseline_comparison.majority_class_accuracy * 100).toFixed(1)}%.
                        </p>
                        <p className="muted">
                            Selected regularization: {modelInfo.training_config.l2_strength} (tuned on {modelInfo.tuning.years.join(', ')}); held-out probability quality Brier score {modelInfo.validation.summary.mean_yearly_brier_score.toFixed(3)}. This score is not calibrated for decision thresholds.
                        </p>
                        <p className="muted">
                            Historical forward-year validation at the 50% threshold:
                        </p>
                        <div className="table-scroll">
                            <table>
                                <thead><tr><th>Test year</th><th>Rows</th><th>Fire recall</th><th>Missed fires</th></tr></thead>
                                <tbody>
                                    {validationFolds.map((fold) => (
                                        <tr key={fold.year}>
                                            <td>{fold.year}<small>{fold.test_date_range?.[0]} – {fold.test_date_range?.[1]}</small></td>
                                            <td>{fold.test_rows}</td>
                                            <td>{(fold.metrics.recall * 100).toFixed(1)}%</td>
                                            <td>{fold.metrics.confusion_matrix.false_negative}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="muted">Threshold trade-offs across all held-out years:</p>
                        <div className="table-scroll">
                            <table>
                                <thead><tr><th>Score threshold</th><th>Fire recall</th><th>Precision</th><th>False positives</th><th>Missed fires</th></tr></thead>
                                <tbody>
                                    {(modelInfo.validation?.summary?.threshold_sweep ?? []).map((item) => (
                                        <tr key={item.threshold}>
                                            <td>{(item.threshold * 100).toFixed(0)}%</td>
                                            <td>{(item.recall * 100).toFixed(1)}%</td>
                                            <td>{(item.precision * 100).toFixed(1)}%</td>
                                            <td>{item.false_positives}</td>
                                            <td>{item.false_negatives}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="field-hint">
                            Lower thresholds catch more fires but also create more false positives. Thresholds shown are trade-offs, not approved alert levels. Validation covers Bongabong history only.
                        </p>
                    </>
                )}
            </Panel>
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
                                <span>Model score <small>Not a calibrated probability</small></span>
                                <strong>{score.toFixed(1)} / 100</strong>
                            </div>
                            <div
                                className="fire-risk-score-track"
                                role="meter"
                                aria-label="Fire-risk model score"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow={score}
                                aria-valuetext={`${score.toFixed(1)} out of 100 model-score points; reference threshold ${referenceThreshold.toFixed(0)}; not a calibrated probability`}
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
                </Panel>
            )}
        </>
    );
}
