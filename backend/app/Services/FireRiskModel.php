<?php

namespace App\Services;

use JsonException;
use RuntimeException;

class FireRiskModel
{
    private const NUMERIC_FEATURES = [
        'month',
        'elevation_m',
        'temperature_c',
        'relative_humidity_pct',
        'wind_speed_kmh',
        'rainfall_mm',
        'days_since_last_rain',
        'soil_moisture_pct',
        'fuel_dryness_index',
        'dist_to_fire_station_km',
        'distance_to_water_km',
    ];

    private const CATEGORICAL_FEATURES = [
        'barangay',
        'land_use_zone',
        'season',
    ];

    private ?array $loadedModel = null;

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        $model = $this->loadModel();

        return [
            'model_version' => $model['model_version'],
            'model_type' => $model['model_type'],
            'model_status' => $model['model_status'],
            'forecast_horizon' => $model['forecast_horizon'],
            'prediction_radius_meters' => $model['prediction_radius_meters'],
            'outcome_scope' => $model['outcome_scope'],
            'training_rows' => $model['training_rows'],
            'training_date_range' => $model['training_date_range'],
            'dataset_verification' => $model['source_origin'],
            'training_config' => $model['training_config'],
            'tuning' => $model['tuning'],
            'validation' => $model['validation'],
            'baseline_comparison' => $model['baseline_comparison'],
            'threshold' => $model['threshold'],
            'numeric_ranges' => $model['numeric_ranges'],
            'categories' => $model['categories'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function predict(array $input): float
    {
        $model = $this->loadModel();
        $values = [];

        foreach (self::NUMERIC_FEATURES as $feature) {
            $values[] = ((float) $input[$feature] - $model['numeric_means'][$feature])
                / $model['numeric_scales'][$feature];
        }

        foreach (self::CATEGORICAL_FEATURES as $feature) {
            foreach ($model['categories'][$feature] as $category) {
                $values[] = $input[$feature] === $category ? 1.0 : 0.0;
            }
        }

        $score = $model['intercept'];
        foreach ($model['weights'] as $index => $weight) {
            $score += $weight * $values[$index];
        }

        if ($score >= 0) {
            $expScore = exp(-min($score, 700));

            return 1 / (1 + $expScore);
        }

        $expScore = exp(max($score, -700));

        return $expScore / (1 + $expScore);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadModel(): array
    {
        if ($this->loadedModel !== null) {
            return $this->loadedModel;
        }

        $path = resource_path('ml/fire_risk_model.json');
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new RuntimeException('The fire-risk model artifact is missing or unreadable.');
        }

        try {
            $model = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The fire-risk model artifact is not valid JSON.', previous: $exception);
        }

        if (! is_array($model)
            || ($model['model_type'] ?? null) !== 'standardized_logistic_regression'
            || ! is_string($model['model_version'] ?? null)
            || ($model['model_status'] ?? null) !== 'staff_decision_support'
            || ($model['forecast_horizon'] ?? null) !== 'next_24_hours'
            || ($model['prediction_radius_meters'] ?? null) !== 500
            || ! is_string($model['outcome_scope'] ?? null)
            || ! is_numeric($model['intercept'] ?? null)
            || ! is_numeric($model['threshold'] ?? null)
            || ! is_numeric($model['training_rows'] ?? null)
            || ! is_array($model['training_date_range'] ?? null)
            || ! is_array($model['training_config'] ?? null)
            || ! is_array($model['tuning'] ?? null)
            || ! is_array($model['validation'] ?? null)
            || ! is_array($model['baseline_comparison'] ?? null)
            || ! is_array($model['weights'] ?? null)
            || ! is_array($model['numeric_means'] ?? null)
            || ! is_array($model['numeric_scales'] ?? null)
            || ! is_array($model['numeric_ranges'] ?? null)
            || ! is_array($model['categories'] ?? null)
            || ! is_array($model['features'] ?? null)
            || ! array_is_list($model['weights'])
            || ! array_is_list($model['features'])) {
            throw new RuntimeException('The fire-risk model artifact has an invalid structure.');
        }

        if (! is_finite((float) $model['intercept'])
            || ! is_finite((float) $model['threshold'])
            || (float) $model['threshold'] < 0
            || (float) $model['threshold'] > 1) {
            throw new RuntimeException('The fire-risk model parameters are invalid.');
        }

        $expectedFeatures = self::NUMERIC_FEATURES;
        foreach (self::CATEGORICAL_FEATURES as $feature) {
            if (! is_array($model['categories'][$feature] ?? null)
                || ! array_is_list($model['categories'][$feature])
                || $model['categories'][$feature] === []) {
                throw new RuntimeException('The fire-risk model artifact is missing a category list.');
            }

            foreach ($model['categories'][$feature] as $category) {
                if (! is_string($category)) {
                    throw new RuntimeException('The fire-risk model artifact has an invalid category.');
                }
                $expectedFeatures[] = $feature.'='.$category;
            }
        }

        if ($model['features'] !== $expectedFeatures
            || count($model['weights']) !== count($expectedFeatures)) {
            throw new RuntimeException('The fire-risk model feature definitions do not match.');
        }

        foreach (self::NUMERIC_FEATURES as $feature) {
            if (! is_numeric($model['numeric_means'][$feature] ?? null)
                || ! is_numeric($model['numeric_scales'][$feature] ?? null)
                || ! is_array($model['numeric_ranges'][$feature] ?? null)
                || ! is_finite((float) $model['numeric_means'][$feature])
                || ! is_finite((float) $model['numeric_scales'][$feature])
                || ! is_numeric($model['numeric_ranges'][$feature]['minimum'] ?? null)
                || ! is_numeric($model['numeric_ranges'][$feature]['maximum'] ?? null)
                || ! is_finite((float) $model['numeric_ranges'][$feature]['minimum'])
                || ! is_finite((float) $model['numeric_ranges'][$feature]['maximum'])
                || (float) $model['numeric_ranges'][$feature]['minimum'] > (float) $model['numeric_ranges'][$feature]['maximum']
                || (float) $model['numeric_scales'][$feature] <= 0) {
                throw new RuntimeException('The fire-risk model scaling values are invalid.');
            }
        }

        foreach ($model['weights'] as $weight) {
            if (! is_numeric($weight) || ! is_finite((float) $weight)) {
                throw new RuntimeException('The fire-risk model weights are invalid.');
            }
        }

        $this->loadedModel = $model;

        return $this->loadedModel;
    }
}
