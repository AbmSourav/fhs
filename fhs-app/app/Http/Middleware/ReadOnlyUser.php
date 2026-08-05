<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a view-only user changing anything.
 *
 * Applied to a whole route group rather than route by route, so a write route
 * added later is covered without anyone remembering to annotate it. Forgetting
 * is the failure worth designing against: an unannotated route would silently
 * let an investor record a sale.
 *
 * The rule is read off the HTTP verb rather than the route name, because that
 * is what actually distinguishes reading from writing — a route called
 * "orders.store" is only a write because it is a POST.
 */
class ReadOnlyUser
{
    /**
     * Verbs that change something.
     *
     * GET and HEAD are absent by definition. OPTIONS is absent because a
     * preflight request changes nothing and blocking it would break the
     * request that follows.
     */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), self::WRITE_METHODS, strict: true)) {
            return $next($request);
        }

        // Consults the gate rather than reading the role here, so who may write
        // stays defined in exactly one place.
        if (Gate::denies('write')) {
            abort(403, 'Your account has view-only access.');
        }

        return $next($request);
    }
}
