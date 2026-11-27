<?php

namespace App\Http\Controllers;

use App\Services\AuditRecorder;
use App\Services\FireRiskModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

class FireRiskPredictionController extends Controller
{
    public function info(FireRiskModel $model): JsonResponse
    {
        try {
            return response()->json(['data' => $model->metadata()]);
        } catch (RuntimeException $exception) {
            return $this->unavailable($exception);
        }
    }

    public function predict(Request $request, FireRiskModel $model, AuditRecorder $audit): JsonResponse
    {
        try {
            $metadata = $model->metadata();
        } catch (RuntimeException $exception) {
            return $this->unavailable($exception);
        }

        $numericRules = [];
        foreach ($metadata['numeric_ranges'] as $feature => $range) {
            $numericRules[$feature] = [
                'required',
                'numeric',
                'between:'.$range['minimum'].','.$range['maximum'],
            ];
        }

        $validated = $request->validate([
            ...$numericRules,
            'month' => [
                'required',
                'integer',
                'between:'.$metadata['numeric_ranges']['month']['minimum'].','.$metadata['numeric_ranges']['month']['maximum'],
            ],
            'days_since_last_rain' => ['required', 'integer', ...array_slice($numericRules['days_since_last_rain'], 2)],
            'barangay' => ['required', 'string', Rule::in($metadata['categories']['barangay'])],
            'land_use_zone' => ['required', 'string', Rule::in($metadata['categories']['land_use_zone'])],
            'season' => ['required', 'string', Rule::in($metadata['categories']['season'])],
        ]);

        try {
            $score = $model->predict($validated);
        } catch (RuntimeException $exception) {
            return $this->unavailable($exception);
        }

        $threshold = $metadata['threshold'];
        $thresholdMet = $score >= $threshold;
        $audit->record($request->user(), 'fire_risk.predicted', FireRiskModel::class, [
            'model_version' => $metadata['model_version'],
            'forecast_horizon' => $metadata['forecast_horizon'],
            'prediction_radius_meters' => $metadata['prediction_radius_meters'],
            'outcome_scope' => $metadata['outcome_scope'],
            'barangay' => $validated['barangay'],
            'score_percent' => round($score * 100, 1),
            'threshold' => $threshold,
            'threshold_met' => $thresholdMet,
            'inputs' => $validated,
        ]);

        return response()->json([
            'data' => [
                'prediction' => $thresholdMet ? 'above_threshold' : 'below_threshold',
                'threshold_met' => $thresholdMet,
                'score_percent' => round($score * 100, 1),
                'threshold' => $threshold,
                'model_version' => $metadata['model_version'],
                'prediction_radius_meters' => $metadata['prediction_radius_meters'],
                'disclaimer' => 'Staff decision support only. A below-threshold result does not rule out fire. Follow official procedures and never create or suppress a public alert solely from this score.',
            ],
        ]);
    }

    private function unavailable(RuntimeException $exception): JsonResponse
    {
        Log::error('Fire-risk model is unavailable.', ['exception' => $exception]);

        return response()->json([
            'message' => 'The fire-risk model is unavailable. Please contact the system administrator.',
        ], 503);
    }
}
