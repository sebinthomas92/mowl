<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use App\Models\CampaignPack;
use App\Models\Product;
use App\Support\Membership;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies roles and brand scopes to every signed-in page, and to Livewire updates through persistent middleware.
 *
 * Clients only reach the client portal. A product, brand or launch pack outside a member's brand scope is a 404,
 * judged against the workspace that owns it, so a direct link from another workspace cannot slip past.
 */
class EnforceWorkspaceRole
{
    /** Routes a client may use. */
    private const CLIENT_ROUTES = ['portal.*', 'logout', 'workspaces.select'];

    /** Routes that only create, so reviewers and clients never land on them. */
    private const CREATE_ROUTES = ['products.create', 'brands.create', 'campaign-packs.create'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();
        if (! $user || ! $route) {
            return $next($request);
        }

        foreach (['product' => Product::class, 'pack' => CampaignPack::class, 'brand' => Brand::class] as $parameter => $class) {
            $value = $route->parameter($parameter);
            if ($value === null || $value === '') {
                continue;
            }
            $model = $value instanceof Model ? $value : (is_numeric($value) ? $class::query()->find($value) : null);
            [$workspaceId, $brandId] = $this->owner($model);
            if ($workspaceId === null) {
                continue;
            }
            $membership = Membership::of($user, $workspaceId);
            if ($membership && ($membership->isClient() || ! $membership->allowsBrand($brandId))) {
                abort(404);
            }
        }

        $membership = Membership::current();
        if (! $membership) {
            return $next($request);
        }

        if ($membership->isClient()) {
            if ($route->named(self::CLIENT_ROUTES)) {
                return $next($request);
            }

            return $request->isMethod('GET') && ! $request->hasHeader('X-Livewire')
                ? redirect()->route('portal.index')
                : abort(403);
        }

        if ($route->named(self::CREATE_ROUTES) && ! $membership->canCreate()) {
            abort(403, 'Your role can review work but not create it.');
        }
        if ($route->named('brands.create') && ! $membership->canCreateBrands()) {
            abort(403, 'Your access is limited to one brand.');
        }
        if ($route->named('usage.index', 'billing.index') && ! $membership->canSeeCosts()) {
            abort(403, 'Usage and costs are visible to the workspace owner.');
        }

        return $next($request);
    }

    /** @return array{0: ?int, 1: ?int} workspace id and brand id */
    private function owner(?Model $model): array
    {
        return match (true) {
            $model instanceof Brand => [$model->workspace_id, $model->id],
            $model instanceof Product => [$model->brand?->workspace_id, $model->brand_id],
            $model instanceof CampaignPack => [$model->product?->brand?->workspace_id, $model->product?->brand_id],
            default => [null, null],
        };
    }
}
