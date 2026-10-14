<?php

namespace App\Notifications;

use App\Models\ContentVersion;
use App\Notifications\Channels\WorkspaceDatabaseChannel;
use Illuminate\Notifications\Notification;

class ContentActivity extends Notification
{
    public const EVENTS = [
        'review_requested' => ['icon' => 'eye', 'verb' => 'submitted', 'suffix' => ' for review'],
        'approved' => ['icon' => 'circle-check', 'verb' => 'approved', 'suffix' => ''],
        'changes_requested' => ['icon' => 'arrow-back-up', 'verb' => 'requested changes on', 'suffix' => ''],
        'commented' => ['icon' => 'message', 'verb' => 'commented on', 'suffix' => ''],
    ];

    public ?int $workspaceId;

    public function __construct(
        public string $event,
        public ContentVersion $version,
        public string $actorName,
    ) {
        $this->workspaceId = $version->item->workspace_id;
    }

    public function via(object $notifiable): array
    {
        return [WorkspaceDatabaseChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        $item = $this->version->item;
        $event = self::EVENTS[$this->event];

        return [
            'event' => $this->event,
            'actor' => $this->actorName,
            'title' => "{$this->actorName} {$event['verb']} {$item->title} v{$this->version->version}{$event['suffix']}",
            'url' => $this->event === 'review_requested'
                ? route('review.index', ['item' => $item->id])
                : route('products.show', [$item->product_id, 'tab' => 'content', 'item' => $item->id]),
        ];
    }
}
