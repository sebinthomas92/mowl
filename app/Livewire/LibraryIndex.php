<?php

namespace App\Livewire;

use App\Concerns\InteractsWithWorkspace;
use App\Services\ContentLibrary;
use Livewire\Component;

class LibraryIndex extends Component
{
    use InteractsWithWorkspace;

    public function render()
    {
        return view('livewire.library-index', [
            'workspace' => $this->currentWorkspace(),
            'available' => ContentLibrary::available(),
        ])->layout('components.layouts.app');
    }
}
