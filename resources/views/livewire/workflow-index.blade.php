@php($availability = config('workflows.availability'))
@php($gradients = ['linear-gradient(140deg,#FFD9CC,#FFC2D9 55%,#E3D2FF)', 'linear-gradient(140deg,#FF8A6B,#EA38D0)', 'linear-gradient(140deg,#E3D2FF,#FFE3DA)', 'linear-gradient(140deg,#B98CFF,#FF8A6B)', 'linear-gradient(140deg,#FFE3DA,#D5E4FF)', 'linear-gradient(140deg,#EA38D0,#B98CFF)'])
@php($availTag = fn (array $workflow) => '<span class="tag '.$availability[$workflow['availability']]['tone'].'"><span class="dot"></span>'.e($availability[$workflow['availability']]['label']).'</span>')
<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to workflows</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page wf-page">
            <header class="pagehead">
                <div>
                    <div class="eyebrow">{{ $workspace->name }}</div>
                    <h1 class="display">Workflows</h1>
                    <p class="muted">Start from an outcome. Run a workflow here, or copy a prompt pack into your own agent.@if($promptCount) <span class="num">{{ $promptCount }}</span> {{ \Illuminate\Support\Str::plural('prompt', $promptCount) }} in the library.@endif</p>
                </div>
                <a class="pill ghost sm" href="{{ config('workflows.source_url') }}" target="_blank" rel="noopener">Open the prompt showcase <i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>
            </header>

            <div class="chips" role="group" aria-label="Category">
                <button type="button" class="chip" aria-pressed="{{ $category === 'all' ? 'true' : 'false' }}" wire:click="setCategory('all')">All</button>
                @foreach($categories as $key => $label)
                    @if($counts->get($key))
                        <button type="button" class="chip" aria-pressed="{{ $category === $key ? 'true' : 'false' }}" wire:click="setCategory('{{ $key }}')">{{ $label }} <span class="num muted">{{ $counts->get($key) }}</span></button>
                    @endif
                @endforeach
            </div>

            @if($workflows->isEmpty())
                <div class="empty">
                    <strong class="medium">No workflows in this category yet</strong>
                    <button type="button" class="pill ghost sm" wire:click="setCategory('all')">Show all workflows</button>
                </div>
            @else
                <div class="wf-layout">
                    <div class="cgrid wf-grid">
                        @foreach($workflows as $workflow)
                            <button type="button" class="ccard" wire:key="wf-{{ $workflow['slug'] }}" aria-pressed="{{ $current && $current['slug'] === $workflow['slug'] ? 'true' : 'false' }}" wire:click="select('{{ $workflow['slug'] }}')">
                                <span class="thumb wf-thumb" style="background:{{ $gradients[$workflow['gradient'] % 6] }}" aria-hidden="true">
                                    @if($workflow['category'] === 'motion')<i class="ti ti-player-play"></i>@elseif($workflow['category'] === 'web')<i class="ti ti-browser"></i>@endif
                                    @if($workflow['length'])<span class="wf-len">{{ $workflow['length'] }}</span>@endif
                                </span>
                                <span class="ccard-title">{{ $workflow['name'] }}</span>
                                <span class="muted xs">{{ $workflow['category_label'] }} · @if($workflow['prompts']){{ count($workflow['prompts']) }} {{ \Illuminate\Support\Str::plural('prompt', count($workflow['prompts'])) }}@else{{ $workflow['scope'] }} scope @endif</span>
                                {!! $availTag($workflow) !!}
                            </button>
                        @endforeach
                    </div>

                    @if($current)
                        <aside class="card col wf-detail" aria-label="{{ $current['name'] }}" wire:key="detail-{{ $current['slug'] }}" x-data="{ copied: null }">
                            <span class="muted xs">{{ $current['category_label'] }} / {{ $current['kind'] }}</span>
                            <h2 class="display">{{ $current['name'] }}</h2>
                            <div class="row wf-tags">{!! $availTag($current) !!}<span class="tag">{{ $current['scope'] }} scope</span></div>
                            @if($current['description'])<p class="small">{{ $current['description'] }}</p>@endif
                            <div>
                                @if($current['needs'])<div class="kv"><span class="muted">Needs</span><span>{{ $current['needs'] }}</span></div>@endif
                                @if($current['gives'])<div class="kv"><span class="muted">Gives you</span><span>{{ $current['gives'] }}</span></div>@endif
                                <div class="kv"><span class="muted">Credits</span><span>{{ $current['availability'] === 'run' ? 'Shown before anything runs' : 'None in MarketingOwl' }}</span></div>
                                <div class="kv"><span class="muted">Agent download</span><span>{{ $current['prompts'] ? 'Prompt file for Claude or your own agent' : 'Not offered' }}</span></div>
                            </div>

                            @if($current['availability'] === 'run' && $current['action'])
                                <a class="pill pri block" href="{{ route($current['action']['route']) }}" wire:navigate>{{ $current['action']['label'] }}</a>
                            @endif
                            @if($current['prompts'])
                                <a class="pill ghost block" href="{{ route('workflows.download', $current['slug']) }}" download>Download for your agent</a>
                            @endif
                            @if($current['availability'] === 'coming')
                                <div class="banner neu xs">This workflow is planned and not released. It is listed so you can see where it will live.</div>
                            @endif

                            @if($current['prompts'])
                                <span class="eyebrow">Prompts</span>
                                <div class="wf-prompts">
                                    @foreach($current['prompts'] as $index => $prompt)
                                        <details class="wf-prompt" @if($loop->first) open @endif>
                                            <summary><span class="grow medium">{{ $prompt['title'] }}</span>@if(($prompt['tier'] ?? null) === 'premium')<span class="tag rev">Premium</span>@endif<i class="ti ti-chevron-down" aria-hidden="true"></i></summary>
                                            <pre class="wf-body">{{ $prompt['body'] }}</pre>
                                            <button type="button" class="pill ghost sm" @click="navigator.clipboard.writeText(@js($prompt['body'])); copied = {{ $index }}; setTimeout(() => copied = null, 1600)">
                                                <i class="ti ti-copy" aria-hidden="true"></i><span x-text="copied === {{ $index }} ? 'Copied' : 'Copy prompt'">Copy prompt</span>
                                            </button>
                                        </details>
                                    @endforeach
                                </div>
                                @if($current['credit'])<span class="muted xs">{{ $current['credit'] }}</span>@endif
                                @if($current['source_url'])<a class="linkbtn" href="{{ $current['source_url'] }}" target="_blank" rel="noopener">See it on the prompt showcase</a>@endif
                            @endif
                            <span class="muted xs">Running opens the brief and credit review first. A download or copy does not use credits.</span>
                        </aside>
                    @endif
                </div>
            @endif
        </main>
    </div>
</div>
