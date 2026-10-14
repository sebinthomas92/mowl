<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Services\WorkflowCatalog;
use Livewire\Attributes\Url;
use Livewire\Component;

class WorkflowIndex extends Component
{
    use InteractsWithWorkspace;

    #[Url(as: 'category', except: 'all')]
    public string $category = 'all';

    #[Url(as: 'workflow', except: '')]
    public string $selected = '';

    public function setCategory(string $category): void
    {
        $this->category = $category === 'all' || array_key_exists($category, app(WorkflowCatalog::class)->categories()) ? $category : 'all';
    }

    public function select(string $slug): void
    {
        $this->selected = app(WorkflowCatalog::class)->find($slug) ? $slug : '';
    }

    public function render(WorkflowCatalog $catalog)
    {
        $all = $catalog->all();
        $list = $this->category === 'all' ? $all : $all->where('category', $this->category)->values();
        $current = $catalog->find($this->selected) ?? $list->first() ?? $all->first();
        $current = $current ? $catalog->withBodies($current) : null;

        return view('livewire.workflow-index', [
            'workspace' => $this->currentWorkspace(),
            'categories' => $catalog->categories(),
            'counts' => $all->countBy('category'),
            'workflows' => $list,
            'current' => $current,
            'promptCount' => $all->sum(fn (array $workflow) => count($workflow['prompts'])),
        ])->layout('components.layouts.app');
    }
}
