<?php
namespace App\Http\Controllers;

use App\Services\WorkspaceQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkspaceController
{
    public function __invoke(Request $request, WorkspaceQuery $query, ?string $id = null)
    {
        $section = $request->segment(1) ?: 'home';
        $options = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        return Inertia::render('Workspace', $query->payload($request->user(), $section, $id, $options));
    }
}
