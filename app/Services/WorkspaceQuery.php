<?php
namespace App\Services;

use App\Models\Record;
use App\Models\User;
use App\Support\EntityCatalog as Catalog;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkspaceQuery
{
    public function __construct(private UserClock $clock, private HabitMetrics $habits, private FinanceSummary $finance) {}

    public function todayTasks(User $user): Builder
    {
        $now = $this->clock->now($user); $today = $now->toDateString();
        $start = $now->startOfDay()->utc(); $end = $now->endOfDay()->utc();
        return Catalog::query('tasks', $user)->where(fn ($q) => $q->where('planned_date', $today)->orWhereBetween('due_at', [$start, $end])
            ->orWhereBetween('completed_at', [$start, $end])->orWhere(fn ($q) => $q->where('status', '!=', 'done')->where('due_at', '<', $start)));
    }

    public function payload(User $user, string $section, ?string $selectedId = null, array $options = []): array
    {
        if ($selectedId && in_array($section, ['projects', 'goals'], true)) Catalog::find($section, $user, $selectedId);
        $data = array_fill_keys(array_keys(Catalog::TABLES), []); $pagination = []; $today = $this->clock->today($user);
        $now = $this->clock->now($user);
        $financeMonth = ! empty($options['month']) ? $this->clock->date($user, $options['month'].'-01') : $now->startOfMonth();
        $entities = match ($section) {
            'home' => ['tasks', 'events', 'habits', 'projects'], 'today' => ['tasks', 'events'], 'calendar' => ['events'],
            'projects' => $selectedId ? ['projects', 'tasks', 'notes'] : ['projects'],
            'goals' => $selectedId ? ['goals', 'projects'] : ['goals'],
            'finance' => ['accounts', 'categories', 'transactions', 'savings', 'wishlist'],
            'ideas', 'notes', 'habits', 'content', 'reviews' => [$section], default => [],
        };
        $focus = $section === 'home' ? Catalog::query('projects', $user)->where('is_current_focus', true)->value('id') : null;
        foreach ($entities as $entity) {
            $filters = [];
            if ($section === 'today' || ($section === 'home' && $entity === 'tasks')) $filters['filter'] = 'today';
            if ($section === 'projects' && $selectedId && $entity === 'tasks') $filters['project_id'] = $selectedId;
            if ($section === 'calendar') $filters = ['from' => $now->startOfMonth()->subWeek()->toDateString(), 'to' => $now->endOfMonth()->addWeek()->toDateString()];
            if ($entity === 'events' && in_array($section, ['home', 'today'], true)) $filters = ['from' => $today, 'to' => $today];
            if ($section === 'finance' && $entity === 'transactions') $filters = ['from' => $financeMonth->startOfMonth()->toDateString(), 'to' => $financeMonth->endOfMonth()->toDateString()];
            $query = $this->listing($user, $entity, $filters);
            if ($section === 'home' && $entity === 'projects') $query->whereKey($focus ?? '');
            if ($section === 'home' && $entity === 'habits') $query->where('active', true);
            if ($section === 'projects' && $selectedId && $entity === 'projects') $query->whereKey($selectedId);
            if ($section === 'projects' && $selectedId && $entity === 'notes') $query->where('notable_type', 'project')->where('notable_id', $selectedId);
            if ($section === 'goals' && $selectedId && $entity === 'goals') $query->whereKey($selectedId);
            if ($section === 'goals' && $selectedId && $entity === 'projects') $query->where('goal_id', $selectedId);
            $limit = in_array($entity, ['tasks', 'events'], true) && in_array($section, ['home', 'calendar'], true) ? 100 : 50;
            $page = $query->paginate($limit, ['*'], 'page', 1);
            $data[$entity] = $this->decorateMany($user, $entity, $page->getCollection());
            $pagination[$entity] = ['total' => $page->total(), 'loaded' => $page->count(), 'current_page' => 1,
                'last_page' => $page->lastPage(), 'per_page' => $limit, 'has_more' => $page->hasMorePages(), 'filters' => $filters];
        }
        if ($focus) {
            $nextTasks = $this->listing($user, 'tasks', ['project_id' => $focus])->limit(4)->get();
            $data['tasks'] = collect($data['tasks'])->concat($this->decorateMany($user, 'tasks', $nextTasks))->unique('id')->values()->all();
        }
        $todayStats = $this->todayTasks($user)->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done")->first();
        $total = (int) $todayStats->total; $done = (int) $todayStats->done;
        $summary = ['today' => ['completed_count' => $done, 'total_count' => $total, 'percentage' => $total ? (int) round($done * 100 / $total) : 0]];
        if (in_array($section, ['home', 'finance'], true)) $summary['finance'] = [...$this->finance->between($user, $financeMonth->startOfMonth()->toDateString(), $financeMonth->endOfMonth()->toDateString()), 'month' => $financeMonth->format('Y-m')];
        if ($section === 'home') {
            $scheduledHabits = collect($data['habits'])->where('scheduled_today', true);
            $summary += [
                'active_projects' => Catalog::query('projects', $user)->where('status', 'active')->count(),
                'active_goals' => Catalog::query('goals', $user)->where('status', 'active')->count(),
                'inbox_ideas' => Catalog::query('ideas', $user)->where('status', 'inbox')->count(),
                'active_content' => Catalog::query('content', $user)->where('status', '!=', 'published')->count(),
                'habits_completed' => $scheduledHabits->where('completed_today', true)->count(), 'habits_total' => $scheduledHabits->count(), 'current_focus_id' => $focus,
            ];
        }
        // Selection controls need names and IDs, not every project's notes and derived task history.
        $lookups = [
            'projects' => Catalog::query('projects', $user)->select('id', 'name', 'status', 'goal_id', 'is_current_focus')->orderBy('name')->limit(500)->get()->toArray(),
            'goals' => Catalog::query('goals', $user)->select('id', 'title', 'status')->orderBy('title')->limit(500)->get()->toArray(),
            'accounts' => Catalog::query('accounts', $user)->select('id', 'name', 'currency', 'active')->orderBy('name')->limit(500)->get()->toArray(),
            'categories' => Catalog::query('categories', $user)->select('id', 'name', 'type', 'active')->orderBy('name')->limit(500)->get()->toArray(),
        ];
        return ['section' => $section, 'selectedId' => $selectedId, 'user' => $user->only('id', 'name', 'email'), 'settings' => $user->settings->toArray(),
            'today' => $today, 'data' => $data, 'summary' => $summary, 'pagination' => $pagination,
            'lookups' => $lookups];
    }

    public function listing(User $user, string $entity, array $filters = []): Builder
    {
        $query = Catalog::query($entity, $user); $now = $this->clock->now($user);
        if (! empty($filters['status']) && isset(Catalog::STATUSES[$entity])) $query->where('status', $filters['status']);
        if (! empty($filters['project_id']) && in_array($entity, ['tasks', 'content'], true)) $query->where('project_id', $filters['project_id']);
        if (! empty($filters['goal_id']) && in_array($entity, ['projects', 'habits'], true)) $query->where('goal_id', $filters['goal_id']);
        if (! empty($filters['priority']) && in_array($entity, ['tasks', 'wishlist'], true)) $query->where('priority', $filters['priority']);
        if ($entity === 'transactions') {
            foreach (['type', 'account_id', 'category_id'] as $field) if (! empty($filters[$field])) $query->where($field, $filters[$field]);
        }
        if ($entity === 'content' && ! empty($filters['platform'])) $query->where('platform', $filters['platform']);
        if (! empty($filters['q'])) {
            $field = in_array($entity, ['projects', 'habits', 'accounts', 'categories', 'savings', 'wishlist'], true) ? 'name' : 'title';
            if (in_array($entity, ['transactions', 'reviews'], true)) $field = $entity === 'transactions' ? 'description' : 'wins';
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'])).'%';
            $query->whereRaw("LOWER($field) LIKE ? ESCAPE '!'", [$pattern]);
        }
        if ($entity === 'tasks') {
            if (($filters['filter'] ?? '') === 'today') $query->whereIn('id', $this->todayTasks($user)->select('id'));
            if (in_array($filters['filter'] ?? '', ['open', 'all_open'], true)) $query->where('status', '!=', 'done');
            if (($filters['filter'] ?? '') === 'completed') $query->where('status', 'done');
            if (($filters['filter'] ?? '') === 'overdue') $query->where('status', '!=', 'done')->where(fn ($q) =>
                $q->where(fn ($q) => $q->where('due_has_time', true)->where('due_at', '<', $now->utc()))
                    ->orWhere(fn ($q) => $q->where('due_has_time', false)->where('due_at', '<', $now->startOfDay()->utc())));
            $query->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
                ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->orderBy('sort_order');
        } elseif ($entity === 'projects') {
            $sort = $filters['sort'] ?? 'focus';
            if ($sort === 'updated') $query->orderByDesc('updated_at');
            elseif ($sort === 'target_date') $query->orderByRaw('CASE WHEN target_date IS NULL THEN 1 ELSE 0 END')->orderBy('target_date');
            elseif ($sort !== 'name') $query->orderByDesc('is_current_focus')->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'planned' THEN 1 ELSE 2 END");
            $query->orderBy('name');
        } elseif ($entity === 'reviews') $query->orderByDesc('week_start');
        elseif ($entity === 'transactions') $query->orderByDesc('transaction_date');
        elseif ($entity === 'content') $query->orderBy('sort_order')->orderBy('id');
        elseif ($entity === 'notes') $query->orderByDesc('pinned');
        elseif ($entity === 'events') $query->orderBy('starts_at');
        elseif ($entity === 'habits') $query->orderBy('sort_order');
        if (! empty($filters['from']) && ! empty($filters['to'])) {
            $start = $this->clock->date($user, $filters['from'])->utc(); $end = $this->clock->date($user, $filters['to'])->endOfDay()->utc();
            if ($entity === 'events') $query->where('starts_at', '<=', $end)->where('ends_at', '>=', $start);
            if ($entity === 'tasks') $query->where(fn ($q) => $q->whereBetween('due_at', [$start, $end])->orWhereBetween('planned_date', [$filters['from'], $filters['to']]));
            if ($entity === 'transactions') $query->whereBetween('transaction_date', [$filters['from'], $filters['to']]);
            if ($entity === 'content') $query->whereBetween('planned_publish_date', [$filters['from'], $filters['to']]);
        }
        return $query->orderByDesc('created_at')->orderBy('id');
    }

    public function decorateMany(User $user, string $entity, Collection $records): array
    {
        if ($records->isEmpty()) return [];
        if ($entity === 'habits') return $this->habits->decorate($user, $records);
        $stats = collect();
        if ($entity === 'projects') $stats = Catalog::query('tasks', $user)->whereIn('project_id', $records->pluck('id'))
            ->selectRaw("project_id, COUNT(*) as task_count, SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed_count")
            ->groupBy('project_id')->get()->keyBy('project_id');
        if ($entity === 'accounts') $stats = Catalog::query('transactions', $user)->whereIn('account_id', $records->pluck('id'))
            ->selectRaw("account_id, SUM(CASE WHEN type = 'income' THEN amount_minor ELSE -amount_minor END) as movement")
            ->groupBy('account_id')->get()->keyBy('account_id');
        if ($entity === 'goals') {
            $tasks = DB::table('tasks')->where('user_id', $user->id)->whereNull('deleted_at')->selectRaw("project_id, COUNT(*) as total, SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done")->groupBy('project_id');
            $stats = Catalog::query('projects', $user)->leftJoinSub($tasks, 'task_stats', 'task_stats.project_id', '=', 'projects.id')
                ->whereIn('projects.goal_id', $records->pluck('id'))->selectRaw('projects.goal_id, COUNT(*) as project_count, AVG(CASE WHEN task_stats.total > 0 THEN 100.0 * task_stats.done / task_stats.total ELSE 0 END) as progress')
                ->groupBy('projects.goal_id')->get()->keyBy('goal_id');
        }
        return $records->map(function (Record $record) use ($user, $entity, $stats) {
            $data = $record->toArray();
            foreach (Catalog::MONEY[$entity] ?? [] as $field) {
                $data[$field] = $record->{$field.'_minor'} === null ? null : Money::decimal($record->{$field.'_minor'}); unset($data[$field.'_minor']);
            }
            if ($entity === 'projects') {
                $taskCount = (int) ($stats->get($record->id)?->task_count ?? 0); $done = (int) ($stats->get($record->id)?->completed_count ?? 0);
                $data += ['task_count' => $taskCount, 'completed_count' => $done, 'progress' => $taskCount ? (int) round($done * 100 / $taskCount) : 0];
            }
            if ($entity === 'accounts') $data['balance'] = Money::decimal($record->opening_balance_minor + (int) ($stats->get($record->id)?->movement ?? 0));
            if ($entity === 'goals') {
                $data['project_count'] = (int) ($stats->get($record->id)?->project_count ?? 0);
                $data['progress'] = $data['project_count'] ? (int) round($stats->get($record->id)->progress) : null;
            }
            if ($entity === 'tasks') {
                $today = $this->clock->today($user); $due = $record->due_at?->setTimezone($user->settings->timezone);
                $data['due_date'] = $due?->toDateString();
                $data['due_date_only'] = ! $record->due_has_time;
                $data['is_overdue'] = $record->status !== 'done' && $due && ($record->due_has_time ? $due->lt($this->clock->now($user)) : $due->toDateString() < $today);
                $data['is_today'] = $record->planned_date === $today || $due?->toDateString() === $today || $data['is_overdue'] || $record->completed_at?->setTimezone($user->settings->timezone)->toDateString() === $today;
            }
            return $data;
        })->all();
    }

    public function record(User $user, string $entity, Record $record): array { return $this->decorateMany($user, $entity, collect([$record]))[0]; }
}
