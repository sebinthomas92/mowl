<?php

namespace App\Http\Controllers;

use App\Services\WorkflowCatalog;
use Illuminate\Support\Facades\File;

class WorkflowDownloadController extends Controller
{
    public function __invoke(string $workflow, WorkflowCatalog $catalog)
    {
        $entry = $catalog->find($workflow);
        $path = $entry ? $catalog->path($entry) : null;
        abort_unless($path && File::exists($path), 404);

        return response(File::get($path), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$entry['slug'].'.md"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
