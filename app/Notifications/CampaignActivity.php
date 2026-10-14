<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Notifications\Channels\WorkspaceDatabaseChannel;
use Illuminate\Notifications\Notification;

class CampaignActivity extends Notification
{
    public const EVENTS = [
        'launched' => ['icon' => 'speakerphone'],
        'results_imported' => ['icon' => 'chart-bar'],
    ];

    public ?int $workspaceId;

    public function __construct(public string $event, public Campaign $campaign)
    {
        $this->workspaceId = $campaign->workspace_id;
    }

    public function via(object $notifiable): array
    {
        return [WorkspaceDatabaseChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'title' => match ($this->event) {
                'launched' => "{$this->campaign->name} launched. Versions are pinned to it.",
                default => "Results imported for {$this->campaign->name}",
            },
            'url' => route('products.show', [$this->campaign->product_id, 'tab' => 'campaigns', 'campaign' => $this->campaign->id]),
        ];
    }
}
