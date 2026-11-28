<?php

namespace App\Http\Controllers;

use App\Models\FireRiskPrediction;
use App\Services\AuditRecorder;
use App\Services\FireRiskModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

class FireRiskPredictionController extends Controller
{
    public function index(): JsonResponse
    {
        $predictions = FireRiskPrediction::query()
            ->where('created_at', '>=', now()->subHours(24))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $predictions->map(fn (FireRiskPrediction $prediction): array => [
                'id' => $prediction->id,
                'barangay' => $prediction->barangay,
                'latitude' => $prediction->latitude,
                'longitude' => $prediction->longitude,
                'score_percent' => $prediction->score_percent,
                'threshold' => $prediction->threshold,
                'threshold_met' => $prediction->threshold_met,
                'model_version' => $prediction->model_version,
                'prediction_radius_meters' => $prediction->prediction_radius_meters,
                'created_at' => $prediction->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'window_hours' => 24,
                'limit' => 100,
            ],
        ]);
    }

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
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ]);

        try {
            $score = $model->predict($validated);
        } catch (RuntimeException $exception) {
            return $this->unavailable($exception);
        }

        $threshold = $metadata['threshold'];
        $thresholdMet = $score >= $threshold;
        $prediction = FireRiskPrediction::create([
            'barangay' => $validated['barangay'],
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'score_percent' => round($score * 100, 1),
            'threshold' => $threshold,
            'threshold_met' => $thresholdMet,
            'model_version' => $metadata['model_version'],
            'prediction_radius_meters' => $metadata['prediction_radius_meters'],
            'created_by' => $request->user()->id,
        ]);
        $audit->record($request->user(), 'fire_risk.predicted', FireRiskModel::class, [
            'prediction_id' => $prediction->id,
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
                'prediction_id' => $prediction->id,
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
