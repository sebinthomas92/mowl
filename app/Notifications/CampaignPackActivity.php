<?php

namespace App\Notifications;

use App\Models\CampaignPackVersion;
use App\Models\User;
use App\Notifications\Channels\WorkspaceDatabaseChannel;
use Illuminate\Notifications\Notification;

class CampaignPackActivity extends Notification
{
    public const EVENTS = [
        'review_requested' => ['icon' => 'eye', 'verb' => 'asked for a review of'],
        'approved' => ['icon' => 'circle-check', 'verb' => 'approved'],
        'rejected' => ['icon' => 'arrow-back-up', 'verb' => 'sent back'],
        'commented' => ['icon' => 'message', 'verb' => 'commented on'],
    ];

    public ?int $workspaceId;

    public function __construct(
        public string $event,
        public CampaignPackVersion $version,
        public User $actor,
    ) {
        $this->workspaceId = $version->campaignPack->product->brand->workspace_id;
    }

    public function via(object $notifiable): array
    {
        return [WorkspaceDatabaseChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        $pack = $this->version->campaignPack;

        return [
            'event' => $this->event,
            'actor' => $this->actor->name,
            'title' => "{$this->actor->name} ".self::EVENTS[$this->event]['verb']." {$pack->product->name} v{$this->version->version}",
            'url' => route('campaign-packs.show', $pack),
        ];
    }
}
