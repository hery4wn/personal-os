<?php
namespace App\Http\Controllers;

use App\Support\EntityCatalog as Catalog;
use Illuminate\Http\Request;

class SearchController
{
    public function __invoke(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $term = trim($request->input('q', '')); $groups = []; $results = [];
        if (mb_strlen($term) < 2) return response()->json(['groups' => (object) [], 'results' => []]);
        // Bound LIKE search with escaped wildcards; no user text can become SQL syntax.
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
        foreach (['tasks', 'projects', 'goals', 'ideas', 'notes', 'content'] as $entity) {
            $title = $entity === 'projects' ? 'name' : 'title'; $body = in_array($entity, ['ideas', 'notes'], true) ? 'body' : 'description';
            $records = Catalog::query($entity, $request->user())->where(fn ($q) => $q->whereRaw("LOWER($title) LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("LOWER($body) LIKE ? ESCAPE '!'", [$pattern]))
                ->orderByDesc('updated_at')->limit(8)->get();
            $liveProjects = $entity === 'tasks' ? Catalog::query('projects', $request->user())->whereIn('id', $records->pluck('project_id')->filter())->pluck('id')->flip() : collect();
            $groups[$entity] = $records->map(function ($record) use ($entity, $title, $liveProjects) {
                $href = match ($entity) { 'tasks' => $record->project_id && $liveProjects->has($record->project_id) ? '/projects/'.$record->project_id.'?item='.$record->id : '/today?item='.$record->id,
                    'projects', 'goals' => '/'.$entity.'/'.$record->id, default => '/'.$entity.'?item='.$record->id };
                return ['id' => $record->id, 'title' => $record->{$title} ?: mb_substr($record->body ?? 'Untitled note', 0, 70), 'type' => $entity, 'href' => $href, 'url' => $href];
            })->all();
            array_push($results, ...$groups[$entity]);
        }
        return response()->json(compact('groups', 'results'));
    }
}
