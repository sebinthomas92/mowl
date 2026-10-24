<?php

namespace App\Http\Controllers;

use App\Models\StoreConnection;
use App\Models\Workspace;
use App\Services\Pages\StoreConnector;
use App\Services\Pages\StoreException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Where WordPress sends an owner back after they approve (or decline) MarketingOwl on their site. */
class StoreApprovalController extends Controller
{
    public function __invoke(Request $request, StoreConnector $connector): RedirectResponse
    {
        $user = $request->user();
        $pending = $connector->pendingApproval((string) $request->query('state'), $user);
        if (! $pending) {
            return $this->back('That WordPress approval link has expired. Click Connect with WordPress again.');
        }
        $workspace = Workspace::find($pending['workspace']);
        if (! $workspace?->users()->whereKey($user->id)->wherePivot('role', 'owner')->exists()) {
            abort(403);
        }
        if ($request->query('success') === 'false') {
            return $this->back('WordPress was not approved, so nothing was connected. Click Connect with WordPress to try again.');
        }
        $username = trim((string) $request->query('user_login'));
        $password = preg_replace('/\s+/', '', (string) $request->query('password'));
        if ($username === '' || $password === '') {
            return $this->back('WordPress did not send a password back. Click Connect with WordPress to try again.');
        }

        if (! $connector->claimApproval($pending)) {
            return $this->back('That WordPress approval was already used. If the store is not listed below, click Connect with WordPress again.');
        }

        $store = $pending['store']
            ? StoreConnection::query()->where('workspace_id', $workspace->id)->findOrFail($pending['store'])
            : new StoreConnection(['workspace_id' => $workspace->id, 'driver' => 'woocommerce', 'created_by_user_id' => $user->id]);
        $store->fill([
            'name' => $pending['name'], 'brand_id' => $pending['brand'], 'site_url' => $pending['site'], 'api_base' => $pending['api'],
            'username' => $username, 'secret' => $password,
        ]);

        try {
            $connector->connect($store, $user);
        } catch (StoreException $exception) {
            return $this->back($exception->getMessage());
        }

        $request->session()->put('current_workspace_id', $workspace->id);

        return redirect()->to(route('workspace.settings').'#connections')->with('stores.flash', "{$store->name} is connected. You can publish pages to it now.");
    }

    private function back(string $problem): RedirectResponse
    {
        return redirect()->to(route('workspace.settings').'#connections')->with('stores.problem', $problem);
    }
}
