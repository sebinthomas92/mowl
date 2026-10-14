<?php

namespace App\Notifications;

use App\Models\Page;
use App\Notifications\Channels\WorkspaceDatabaseChannel;
use Illuminate\Notifications\Notification;

class PageActivity extends Notification
{
    public const EVENTS = [
        'published' => ['icon' => 'world-check'],
        'rolled_back' => ['icon' => 'history'],
        'publish_failed' => ['icon' => 'world-x'],
    ];

    public ?int $workspaceId;

    public function __construct(public string $event, public Page $page, public int $version)
    {
        $this->workspaceId = $page->workspace_id;
    }

    public function via(object $notifiable): array
    {
        return [WorkspaceDatabaseChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        $name = "{$this->page->name} v{$this->version}";

        return [
            'event' => $this->event,
            'title' => match ($this->event) {
                'published' => "{$name} is live and verified",
                'rolled_back' => "{$this->page->name} was rolled back to v{$this->version}",
                default => "Publishing {$name} failed",
            },
            'url' => route('products.show', [$this->page->product_id, 'tab' => 'pages', 'page' => $this->page->id]),
        ];
    }
}
