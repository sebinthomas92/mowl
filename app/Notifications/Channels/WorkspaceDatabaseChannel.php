<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Database channel that stores the workspace a notification belongs to,
 * so the inbox only shows what happened in the workspace being viewed.
 */
class WorkspaceDatabaseChannel extends DatabaseChannel
{
    protected function buildPayload($notifiable, Notification $notification)
    {
        return parent::buildPayload($notifiable, $notification) + [
            'workspace_id' => $notification->workspaceId ?? null,
        ];
    }
}
