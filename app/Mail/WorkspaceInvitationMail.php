<?php

namespace App\Mail;

use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WorkspaceInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public WorkspaceInvitation $invitation, public string $inviteUrl) {}

    public function build(): self
    {
        return $this->subject('Join '.$this->invitation->workspace->name.' on Marketing Owl')
            ->view('mail.workspace-invitation');
    }
}
