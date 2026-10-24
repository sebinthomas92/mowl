<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Services\WorkflowCatalog;
use Livewire\Component;

class HelpIndex extends Component
{
    use InteractsWithWorkspace;

    public function render(WorkflowCatalog $catalog)
    {
        $library = $catalog->all()->where('availability', 'download');

        return view('livewire.help-index', [
            'workspace' => $this->currentWorkspace(),
            'guides' => [
                ['title' => 'Add your first product', 'text' => 'Import from a URL, confirm facts, save without generating.', 'route' => 'products.create'],
                ['title' => 'Resolve source conflicts', 'text' => 'How "source says" and the confirmed value work in Knowledge.', 'route' => 'products.index'],
                ['title' => 'Run a launch pack', 'text' => 'Brief, credit estimate, partial failure and retry.', 'route' => 'campaign-packs.create'],
                ['title' => 'Review an exact version', 'text' => 'Approve, request changes, revise.', 'route' => 'review.index'],
                ['title' => 'Publish and verify a page', 'text' => 'Why a publish is not live until it is verified.', 'route' => 'products.index'],
                ['title' => 'Use a prompt pack', 'text' => 'Copy a website or motion prompt into your own agent.', 'route' => 'workflows.index'],
            ],
            'packCount' => $library->count(),
            'promptCount' => $library->sum(fn (array $workflow) => count($workflow['prompts'])),
            'libraryCategories' => $library->pluck('category_label')->unique()->values(),
        ])->layout('components.layouts.app')->title('Help');
    }
}
