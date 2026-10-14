<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use Livewire\Component;

class InboxDrawer extends Component
{
    use InteractsWithWorkspace;

    public function open(string $id): void
    {
        $notification = $this->notifications()->findOrFail($id);
        $notification->markAsRead();

        $this->redirect($notification->data['url'] ?? route('campaign-packs.index'), navigate: true);
    }

    public function markAllRead(): void
    {
        $this->notifications()->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function render()
    {
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
