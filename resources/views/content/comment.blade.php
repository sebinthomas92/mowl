<div class="msg">
    <span class="av" aria-hidden="true">{{ mb_strtoupper(mb_substr($comment->author_name, 0, 1)) }}</span>
    <div class="grow">
        <strong class="medium">{{ $comment->author_name }}</strong>
        <span class="muted xs">{{ ['client' => 'Client', 'owner' => 'Owner', 'member' => 'Contributor', 'reviewer' => 'Reviewer'][$comment->author_role] ?? 'Team' }} · on v{{ $version->version }} · {{ $comment->created_at->format('j M, H:i') }}</span>
        <p class="msg-body">{{ $comment->body }}</p>
    </div>
</div>
