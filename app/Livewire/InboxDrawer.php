<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class InboxDrawer extends Component
{
    use InteractsWithWorkspace;

    public function open(string $id): void
    {
        $notification = $this->notifications()->find($id);
        abort_unless($notification, 404);
        $notification->markAsRead();

        $this->redirect($notification->data['url'] ?? route('campaign-packs.index'), navigate: true);
    }

    public function markAllRead(): void
    {
        $this->notifications()->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function render()
    {
        // Production migrations run as a separate step; keep pages up until the table exists.
        if (! Schema::hasTable('notifications')) {
            return view('livewire.inbox-drawer', ['items' => collect(), 'unread' => 0]);
        }

        return view('livewire.inbox-drawer', [
            'items' => $this->notifications()->latest()->limit(30)->get(),
            'unread' => $this->notifications()->whereNull('read_at')->count(),
        ]);
    }

    private function notifications()
    {
        return auth()->user()->notifications()->where('workspace_id', $this->currentWorkspace()->id);
    }
}
