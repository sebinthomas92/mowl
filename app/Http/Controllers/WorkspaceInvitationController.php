<?php

namespace App\Http\Controllers;

use App\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceInvitationController extends Controller
{
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = WorkspaceInvitation::findOpenToken($token);
        abort_if($invitation === null, 404);

        if (! $request->user()) {
            return redirect()->route('register', ['invite' => $token]);
        }

        abort_unless(strcasecmp($request->user()->email, $invitation->email) === 0, 403);

        DB::transaction(function () use ($invitation, $request): void {
            $lockedInvitation = WorkspaceInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_unless($lockedInvitation->isOpen(), 410);

            $existing = $lockedInvitation->workspace->users()->whereKey($request->user()->id)->first();
            // An owner keeps ownership; an invitation never demotes them.
            abort_if($existing?->pivot->role === 'owner', 409);
            abort_if(! $existing && $lockedInvitation->role !== 'client' && $lockedInvitation->workspace->users()->wherePivot('role', '!=', 'client')->count() >= config('campaigns.seat_limit'), 409);

            $lockedInvitation->workspace->users()->syncWithoutDetaching([
                $request->user()->id => $lockedInvitation->pivotAttributes(),
            ]);
            $lockedInvitation->update(['accepted_at' => now()]);
        });

        $request->session()->put('current_workspace_id', $invitation->workspace_id);

        return $invitation->role === 'client'
            ? redirect()->route('portal.index')
            : redirect()->route('team.index')->with('status', 'You joined '.$invitation->workspace->name.'.');
    }
}
