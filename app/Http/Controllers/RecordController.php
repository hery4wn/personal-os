<?php
namespace App\Http\Controllers;

use App\Actions\RecordActions;
use App\Http\Requests\RecordRequest;
use App\Services\ReviewSummary;
use App\Services\UserClock;
use App\Services\WorkspaceQuery;
use App\Support\EntityCatalog as Catalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecordController
{
    public function __construct(private RecordActions $actions, private WorkspaceQuery $query) {}

    public function index(Request $request, string $entity)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:30'], 'project_id' => ['nullable', 'string', 'max:26'], 'goal_id' => ['nullable', 'string', 'max:26'],
            'priority' => ['nullable', 'string', 'max:20'], 'filter' => ['nullable', Rule::in(['all', 'open', 'all_open', 'today', 'overdue', 'completed'])],
            'q' => ['nullable', 'string', 'max:200'], 'sort' => ['nullable', Rule::in(['focus', 'updated', 'target_date', 'name'])],
            'type' => ['nullable', Rule::in(['income', 'expense'])], 'category_id' => ['nullable', 'string', 'max:26'], 'account_id' => ['nullable', 'string', 'max:26'],
            'platform' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        if ($request->boolean('archived')) $filters['status'] = 'archived';
        $query = $this->query->listing($request->user(), $entity, $filters);
        $page = $query->paginate((int) ($filters['per_page'] ?? 50))->withQueryString();
        $result = $page->toArray(); $result['data'] = $this->query->decorateMany($request->user(), $entity, $page->getCollection());
        return response()->json($result);
    }

    public function show(Request $request, string $entity, string $id)
    {
        return response()->json(['data' => $this->query->record($request->user(), $entity, Catalog::find($entity, $request->user(), $id))]);
    }

    public function store(RecordRequest $request, string $entity)
    {
        $record = $this->actions->save($request->user(), $entity, $request->validated());
        return response()->json(['data' => $this->query->record($request->user(), $entity, $record)], 201);
    }

    public function update(RecordRequest $request, string $entity, string $id)
    {
        $record = $this->actions->save($request->user(), $entity, $request->validated(), $id);
        return response()->json(['data' => $this->query->record($request->user(), $entity, $record)]);
    }

    public function destroy(Request $request, string $entity, string $id)
    {
        $this->actions->delete($request->user(), $entity, $id); return response()->json(['deleted' => true]);
    }

    public function complete(Request $request, string $id)
    {
        $validated = $request->validate(['completed' => ['sometimes', 'boolean'], 'done' => ['sometimes', 'boolean']]);
        $done = $validated['completed'] ?? $validated['done'] ?? null;
        $record = $this->actions->complete($request->user(), $id, $done);
        return response()->json(['data' => $this->query->record($request->user(), 'tasks', $record)]);
    }

    public function move(Request $request, string $entity, string $id)
    {
        abort_unless(in_array($entity, ['tasks', 'content'], true), 404);
        $data = $request->validate(['status' => ['required', Rule::in(Catalog::STATUSES[$entity])], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000']]);
        $record = $this->actions->move($request->user(), $entity, $id, $data['status'], $data['sort_order'] ?? 0);
        return response()->json(['data' => $this->query->record($request->user(), $entity, $record)]);
    }

    public function focus(Request $request, string $id)
    {
        $record = $this->actions->focus($request->user(), $id); return response()->json(['data' => $this->query->record($request->user(), 'projects', $record)]);
    }

    public function toggle(Request $request, string $id)
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $completed = $this->actions->toggleHabit($request->user(), $id, $request->input('date'));
        return response()->json(['completed' => $completed]);
    }

    public function convert(Request $request, string $id)
    {
        $data = $request->validate(['type' => ['required', Rule::in(['project', 'task', 'content', 'note'])], 'overrides' => ['sometimes', 'array']]);
        $target = $this->actions->convertIdea($request->user(), $id, $data['type'], $data['overrides'] ?? []);
        $entity = ['project' => 'projects', 'task' => 'tasks', 'content' => 'content', 'note' => 'notes'][$data['type']];
        return response()->json(['type' => $data['type'], 'data' => $this->query->record($request->user(), $entity, $target)], 201);
    }

    public function capture(Request $request, string $type)
    {
        $map = ['task' => 'tasks', 'idea' => 'ideas', 'note' => 'notes', 'expense' => 'transactions']; abort_unless(isset($map[$type]), 404);
        $input = $request->all(); if ($type === 'expense') $input['type'] = 'expense';
        if ($type === 'task' && $request->boolean('today')) $input['planned_date'] = app(UserClock::class)->today($request->user());
        $record = $this->actions->save($request->user(), $map[$type], $input);
        return response()->json(['data' => $this->query->record($request->user(), $map[$type], $record)], 201);
    }

    public function reviewSummary(Request $request, ReviewSummary $summary, UserClock $clock)
    {
        $request->validate(['week_start' => ['nullable', 'date_format:Y-m-d'], 'week_end' => ['nullable', 'date_format:Y-m-d']]);
        $start = $request->input('week_start') ?: $clock->weekStart($request->user())->toDateString();
        $lastDay = $clock->date($request->user(), $start)->addDays(6)->toDateString();
        $end = $request->input('week_end') ?: $lastDay;
        if ($end < $start || $end > $lastDay) {
            throw \Illuminate\Validation\ValidationException::withMessages(['week_end' => 'Choose a date within this review week.']);
        }
        return response()->json($summary->forWeek($request->user(), $start, $end));
    }
}
