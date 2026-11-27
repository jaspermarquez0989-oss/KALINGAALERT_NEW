<?php

namespace App\Http\Middleware;

use App\Services\AuditRecorder;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordApiActivity
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $route = $request->route();
        $routeName = $route?->getName();
        $response = $next($request);

        if (
            $actor !== null
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && $routeName !== 'api.v1.staff.audit'
        ) {
            $subject = collect($route?->parameters() ?? [])
                ->first(fn (mixed $parameter): bool => $parameter instanceof Model);

            $this->audit->record($actor, 'system.viewed', $subject ?? $routeName ?? $request->path(), [
                'route' => $routeName ?? $request->path(),
                'method' => $request->method(),
                'status' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }
}
