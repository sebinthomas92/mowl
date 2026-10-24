<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to team</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page team-roles">
            <div class="pagehead">
                <div>
                    <div class="eyebrow">Workspace</div>
                    <h1 class="display">Team</h1>
                    <p class="muted">Roles decide who can create, review, publish and see costs. Clients only see their brand's client view.</p>
                </div>
                <span class="tag num">{{ $seatsTaken }} of {{ $seatLimit }} seats · clients are free</span>
            </div>

            @if(session('status'))<div class="banner ok small" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ session('status') }}</span></div>@endif

            <div class="tw">
                <table class="tbl">
                    <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Scope</th><th scope="col">Can publish</th>@if($isOwner)<th scope="col"><span class="sr">Actions</span></th>@endif</tr></thead>
                    <tbody>
                        @foreach($members as $member)
                            @php($memberRole = $member->pivot->role)
                            <tr wire:key="member-{{ $member->id }}">
                                <td><strong class="medium">{{ $member->name }}</strong>@if($member->id === auth()->id()) <span class="muted xs">(you)</span>@endif<br><span class="muted xs">{{ $member->email }}</span></td>
                                @if($isOwner && $memberRole !== 'owner')
                                    <td>
                                        <select class="input input-sm" aria-label="Role for {{ $member->name }}" wire:model="access.{{ $member->id }}.role">
                                            @foreach($memberRoles as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                        </select>
                                        @error('access.'.$member->id.'.role')<span class="err xs" role="alert">{{ $message }}</span>@enderror
                                    </td>
                                    <td>
                                        @if($scopesAvailable)
                                            <select class="input input-sm" aria-label="Scope for {{ $member->name }}" wire:model="access.{{ $member->id }}.scope">
                                                <option value="">All brands</option>
                                                @foreach($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach
                                            </select>
                                            @error('access.'.$member->id.'.scope')<span class="err xs" role="alert">{{ $message }}</span>@enderror
                                        @else
                                            All brands
                                        @endif
                                    </td>
                                @else
                                    <td>{{ \App\Support\Membership::label($memberRole) }}</td>
                                    <td>{{ $memberRole === 'owner' || empty($member->pivot->brand_id) ? 'All brands' : ($brandNames[$member->pivot->brand_id] ?? 'One brand') }}@if($memberRole === 'client')<br><span class="muted xs">Client view only</span>@endif</td>
                                @endif
                                <td>@if($memberRole === 'owner')<span class="tag ok"><span class="dot"></span>Yes</span>@else<span class="tag"><span class="dot"></span>No</span>@endif</td>
                                @if($isOwner)
                                    <td class="team-actions">
                                        @if($memberRole !== 'owner')
                                            <button type="button" class="pill ghost sm" wire:click="updateAccess({{ $member->id }})">Save</button>
                                            <button type="button" class="linkbtn" wire:click="removeMember({{ $member->id }})" wire:confirm="Remove {{ $member->name }} from the workspace?">Remove</button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                        @foreach($invitations as $invitation)
                            <tr wire:key="invitation-{{ $invitation->id }}">
                                <td><strong class="medium">{{ $invitation->email }}</strong><br><span class="muted xs">Invitation pending · expires {{ $invitation->expires_at->diffForHumans() }}</span></td>
                                <td>{{ \App\Support\Membership::label($invitation->role) }}</td>
                                <td>{{ $invitation->brand_id ? ($brandNames[$invitation->brand_id] ?? 'One brand') : 'All brands' }}</td>
                                <td><span class="tag rev"><span class="dot"></span>Pending</span></td>
                                @if($isOwner)<td class="team-actions"><button type="button" class="linkbtn" wire:click="revoke({{ $invitation->id }})">Revoke</button></td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <section class="card col" aria-labelledby="invite-heading">
                <span class="eyebrow" id="invite-heading">Invite someone</span>
                @if($isOwner)
                    <form wire:submit="invite" class="col">
                        <div class="grid team-invite">
                            <div class="field c5"><label for="inv-email">Email</label><input id="inv-email" class="input" type="email" wire:model="email" placeholder="name@company.example" autocomplete="email">@error('email')<span class="err" role="alert">{{ $message }}</span>@enderror</div>
                            <div class="field c3"><label for="inv-role">Role</label><select id="inv-role" class="input" wire:model.live="role">@foreach($roles as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('role')<span class="err" role="alert">{{ $message }}</span>@enderror</div>
                            <div class="field c4"><label for="inv-scope">Scope</label>
                                @if($scopesAvailable)
                                    <select id="inv-scope" class="input" wire:model="scope">@if($role !== 'client')<option value="">All brands</option>@else<option value="">Choose a brand</option>@endif @foreach($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach</select>
                                @else
                                    <select id="inv-scope" class="input" disabled><option>All brands</option></select>
                                @endif
                                @error('scope')<span class="err" role="alert">{{ $message }}</span>@enderror
                            </div>
                        </div>
                        <div class="row"><button class="pill dark sm" type="submit">Create invitation link</button><span class="muted xs">The link expires in 7 days. No email is sent; copy the link and share it.</span></div>
                    </form>
                    @if($inviteUrl)
                        <div class="invite-link" x-data="{ copied: false }"><span>{{ $inviteUrl }}</span><button type="button" @click="navigator.clipboard.writeText(@js($inviteUrl)); copied = true"><b x-text="copied ? 'Copied' : 'Copy link'"></b></button></div>
                    @endif
                @else
                    <p class="small muted">Only the workspace owner can invite people or change roles.</p>
                @endif
                <div class="team-roles-guide">
                    <div><strong class="medium">Contributor</strong><span class="muted small">Adds products, runs launch packs and submits work for review.</span></div>
                    <div><strong class="medium">Reviewer</strong><span class="muted small">Approves or requests changes. Creates nothing and sees no costs.</span></div>
                    <div><strong class="medium">Client</strong><span class="muted small">Sees one brand's work in review, approved deliverables and live pages. Never drafts, costs or knowledge.</span></div>
                    <div><strong class="medium">Owner</strong><span class="muted small">Everything above, plus publishing, team and settings.</span></div>
                </div>
            </section>
        </main>
    </div>
</div>
