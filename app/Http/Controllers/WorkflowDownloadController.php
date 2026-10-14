<?php

namespace App\Http\Controllers;

use App\Services\WorkflowCatalog;

class WorkflowDownloadController extends Controller
{
    public function __invoke(string $workflow, WorkflowCatalog $catalog)
    {
        $entry = $catalog->find($workflow);
        $markdown = $entry ? $catalog->markdown($entry) : null;
        abort_unless($markdown !== null, 404);

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$entry['slug'].'.md"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
