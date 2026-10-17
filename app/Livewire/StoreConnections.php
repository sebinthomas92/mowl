<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Models\Page;
use App\Models\PublishAttempt;
use App\Models\StoreConnection;
use App\Services\Pages\StoreException;
use App\Services\Pages\Stores\StoreClients;
use App\Services\Shopify\ShopifyAuthorization;
use App\Support\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Store connections on the Settings page. Owners add, check, reconnect and remove them. */
class StoreConnections extends Component
{
    use InteractsWithWorkspace;

    public bool $formOpen = false;

    public ?int $editingId = null;

    public string $driver = 'woocommerce';

    public string $name = '';

    public string $brandId = '';

    public string $siteUrl = '';

    public string $username = '';

    public string $secret = '';

    public ?string $flash = null;

    public function open(?int $id = null): void
    {
        $this->authorizeOwner();
        $this->resetValidation();
        $this->reset(['driver', 'name', 'brandId', 'siteUrl', 'username', 'secret', 'flash']);
        $this->editingId = null;
        if ($id) {
            $store = $this->store($id);
            $this->editingId = $store->id;
            $this->driver = $store->driver;
            $this->name = $store->name;
            $this->brandId = (string) ($store->brand_id ?? '');
            $this->siteUrl = $store->driver !== 'sandbox' ? $store->site_url : '';
            $this->username = (string) $store->username;
        }
        $this->formOpen = true;
    }

    public function close(): void
    {
        $this->formOpen = false;
        $this->editingId = null;
        $this->resetValidation();
    }

    public function save(StoreClients $clients): void
    {
        $this->authorizeOwner();
        $workspace = $this->currentWorkspace();
        if ($this->editingId) {
            $this->driver = $this->store($this->editingId)->driver;
        }
        if ($this->driver === 'shopify') {
            $this->connectShopify();

            return;
        }
        $woo = $this->driver === 'woocommerce';
        $data = $this->validate([
            'driver' => ['required', Rule::in(array_keys(StoreConnection::DRIVERS))],
            'name' => ['required', 'string', 'max:120'],
            'brandId' => ['nullable', Rule::exists('brands', 'id')->where('workspace_id', $workspace->id)],
            'siteUrl' => [$woo ? 'required' : 'nullable', 'url:http,https', 'max:500'],
            'username' => [$woo ? 'required' : 'nullable', 'string', 'max:120'],
            'secret' => [$woo && ! $this->editingId ? 'required' : 'nullable', 'string', 'max:200'],
        ], [], ['siteUrl' => 'store URL', 'secret' => 'application password', 'brandId' => 'brand']);

        $store = $this->editingId ? $this->store($this->editingId) : new StoreConnection([
            'workspace_id' => $workspace->id, 'created_by_user_id' => auth()->id(), 'driver' => $data['driver'],
        ]);
        $store->fill(['name' => trim($data['name']), 'brand_id' => $data['brandId'] ?: null]);
        if ($store->driver === 'sandbox') {
            $store->api_base ??= Str::random(40);
            $store->site_url = route('sandbox-store.home', $store->api_base);
        } else {
            $store->fill(['site_url' => rtrim(trim($data['siteUrl']), '/'), 'username' => trim($data['username'])]);
            if (filled($data['secret'])) {
                // Application passwords are shown with spaces; WordPress accepts them either way.
                $store->secret = preg_replace('/\s+/', '', $data['secret']);
            }
        }

        try {
            $result = $clients->for($store)->check($store);
        } catch (StoreException $exception) {
            $this->addError($store->driver === 'woocommerce' && $exception->auth ? 'secret' : 'siteUrl', $exception->getMessage());

            return;
        }
        $moved = $store->exists && $store->isDirty('site_url');
        if ($moved && $store->pages()->whereIn('publish_state', ['publishing', 'verifying'])->exists()) {
            $this->addError('siteUrl', 'A page is publishing to this store. Change the address once it finishes.');

            return;
        }
        DB::transaction(function () use ($store, $result, $moved): void {
            if ($moved) {
                // A new address is a different site: its pages and images have to be created there again.
                $store->media = null;
                $store->pages()->update([
                    'remote_id' => null, 'remote_url' => null, 'remote_store' => null, 'live_version' => null,
                    'publish_state' => 'idle', 'verified_at' => null, 'last_error' => null,
                ]);
            }
            $store->fill($result + ['status' => 'connected', 'last_error' => null, 'checked_at' => now()])->save();
        });
        $this->audit($this->editingId ? 'store_connection_updated' : 'store_connection_added', $store);

        $this->close();
        $this->secret = '';
        $this->flash = "{$store->name} is connected.";
    }

    private function connectShopify(): void
    {
        $workspace = $this->currentWorkspace();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'brandId' => ['nullable', Rule::exists('brands', 'id')->where('workspace_id', $workspace->id)],
            'siteUrl' => ['required', 'string', 'max:255'],
        ], [], ['siteUrl' => 'Shopify store address', 'brandId' => 'brand']);
        if ($this->editingId && $this->store($this->editingId)->pages()->whereIn('publish_state', ['publishing', 'verifying'])->exists()) {
            $this->addError('siteUrl', 'Wait for publishing to finish before reconnecting this store.');

            return;
        }
        try {
            $url = app(ShopifyAuthorization::class)->begin(request(), [
                'workspace_id' => $workspace->id, 'brand_id' => $data['brandId'] ?: null,
                'store_id' => $this->editingId, 'name' => trim($data['name']), 'site_url' => $data['siteUrl'],
            ]);
            $this->redirect($url);
        } catch (StoreException $exception) {
            $this->addError('siteUrl', $exception->getMessage());
        }
    }

    public function check(int $id, StoreClients $clients): void
    {
        $this->authorizeOwner();
        $store = $this->store($id);
        try {
            $result = $clients->for($store)->check($store);
            $store->update($result + ['status' => 'connected', 'last_error' => null, 'checked_at' => now()]);
            $this->flash = "{$store->name} is connected.";
        } catch (StoreException $exception) {
            $store->update(['status' => 'needs_attention', 'last_error' => mb_substr($exception->getMessage(), 0, 500), 'checked_at' => now()]);
            $this->flash = null;
        }
    }

    public function remove(int $id): void
    {
        $this->authorizeOwner();
        $store = $this->store($id);
        $this->audit('store_connection_removed', $store);
        DB::transaction(function () use ($store): void {
            $error = "{$store->name} was removed while this page was publishing. Choose a store, then publish again.";
            $pending = PublishAttempt::query()->where('store_connection_id', $store->id)->whereIn('status', ['publishing', 'verifying']);
            Page::query()->whereIn('id', (clone $pending)->select('page_id'))->whereIn('publish_state', ['publishing', 'verifying'])
                ->update(['publish_state' => 'failed', 'last_error' => $error]);
            $pending->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
            $store->delete();
        });
        $this->flash = "{$store->name} was removed. Pages already published there stay in the store.";
    }

    public function render()
    {
        $workspace = $this->currentWorkspace();
        // A brand-scoped member sees shared stores and their own brand's, never another brand's.
        $membership = Membership::of(auth()->user(), $workspace);
        $brandIds = $membership ? $membership->brandIds() : [];
        $stores = StoreConnection::query()->where('workspace_id', $workspace->id)
            ->when($brandIds !== null, fn ($query) => $query->where(fn ($query) => $query->whereNull('brand_id')->orWhereIn('brand_id', $brandIds)));

        return view('livewire.store-connections', [
            'stores' => $stores->with('brand')->withCount('pages')->orderBy('name')->get(),
            'brands' => $workspace->brands()->when($brandIds !== null, fn ($query) => $query->whereIn('id', $brandIds))->orderBy('name')->get(['id', 'name']),
            'isOwner' => $this->isOwner(),
        ]);
    }

    private function store(int $id): StoreConnection
    {
        $store = StoreConnection::query()->where('workspace_id', $this->currentWorkspace()->id)->find($id);
        abort_unless($store, 404);

        return $store;
    }

    private function isOwner(): bool
    {
        return auth()->user()->workspaces()->whereKey($this->currentWorkspace()->id)->wherePivot('role', 'owner')->exists();
    }

    private function authorizeOwner(): void
    {
        abort_unless($this->isOwner(), 403);
    }

    private function audit(string $event, StoreConnection $store): void
    {
        $this->currentWorkspace()->auditEvents()->create([
            'actor_user_id' => auth()->id(), 'event' => $event, 'subject_type' => StoreConnection::class, 'subject_id' => $store->id,
            'metadata' => ['name' => $store->name, 'driver' => $store->driver, 'site_url' => $store->site_url],
        ]);
    }
}
