<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\AlertRecipient;
use App\Models\RiskArea;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $isResident = $user->role === UserRole::CommunityResident->value;

        $activeAlerts = Alert::query()
            ->with(['source', 'riskAreas'])
            ->currentlyActive();

        if ($isResident) {
            $activeAlerts->whereHas('recipients', fn (Builder $query) => $query->where('user_id', $user->id));
        } elseif (! in_array($user->role, [
            UserRole::SystemAdministrator->value,
            UserRole::MdrrmoPersonnel->value,
            UserRole::AlertOperator->value,
        ], true)) {
            $activeAlerts->whereRaw('1 = 0');
        }

        $alerts = $activeAlerts->orderByDesc('published_at')->limit(6)->get();
        $allActiveAlertIds = (clone $activeAlerts)->pluck('id');
        $areas = RiskArea::query()->where('is_active', true)->orderBy('barangay')->orderBy('name')->get();
        $coverage = $alerts
            ->flatMap(fn (Alert $alert): Collection => $alert->riskAreas->map(
                fn (RiskArea $area): array => [
                    'area_id' => $area->id,
                    'title' => $alert->title,
                    'severity' => $alert->severity,
                    'message' => $alert->message,
                    'safety_instructions' => $alert->safety_instructions,
                ],
            ))
            ->groupBy('area_id');

        return view('dashboard', [
            'alerts' => $alerts,
            'areas' => $areas,
            'mapAreas' => $areas->map(fn (RiskArea $area): array => [
                'name' => $area->name,
                'barangay' => $area->barangay,
                'sitio' => $area->sitio,
                'classification' => $area->classification,
                'boundary' => $area->boundary,
                'alerts' => $coverage->get($area->id, collect())->values()->all(),
            ]),
            'stats' => [
                'risk_areas' => $areas->count(),
                'active_alerts' => $allActiveAlertIds->count(),
                'recipients' => AlertRecipient::query()
                    ->whereIn('alert_id', $allActiveAlertIds)
                    ->distinct()
                    ->count('user_id'),
                'unread_alerts' => $user->unreadAlertCount(),
            ],
        ]);
    }
}
