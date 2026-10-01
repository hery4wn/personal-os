<?php
namespace App\Services;

use App\Models\HabitLog;
use App\Models\User;
use App\Support\EntityCatalog as Catalog;

class ReviewSummary
{
    public function __construct(private UserClock $clock, private FinanceSummary $finance, private HabitMetrics $habits) {}

    public function forWeek(User $user, string $start, ?string $end = null): array
    {
        $localStart = $this->clock->date($user, $start); $end ??= $localStart->addDays(6)->toDateString();
        $localEnd = $this->clock->date($user, $end); $utcStart = $localStart->utc(); $utcEnd = $localEnd->endOfDay()->utc();
        $tasks = Catalog::query('tasks', $user);
        $completed = (clone $tasks)->whereBetween('completed_at', [$utcStart, $utcEnd])->count();
        $planned = (clone $tasks)->where(fn ($q) => $q->whereBetween('planned_date', [$start, $end])->orWhereBetween('due_at', [$utcStart, $utcEnd])->orWhereBetween('completed_at', [$utcStart, $utcEnd]))->count();
        $habits = Catalog::query('habits', $user)->where('active', true)->get();
        $logs = HabitLog::where('user_id', $user->id)->whereBetween('completion_date', [$start, $end])->where('completed', true)->get()->groupBy('habit_id');
        $scheduled = 0; $done = 0; $today = $this->clock->today($user);
        foreach ($habits as $habit) {
            $dates = ($logs->get($habit->id) ?? collect())->pluck('completion_date')->flip();
            for ($cursor = $localStart; $cursor->lte($localEnd) && $cursor->toDateString() <= $today; $cursor = $cursor->addDay()) {
                if ($this->habits->scheduled($habit, $cursor)) { $scheduled++; if ($dates->has($cursor->toDateString())) $done++; }
            }
        }
        $finance = $this->finance->between($user, $start, $end);
        return ['tasks_completed' => $completed, 'tasks_total' => $planned, 'habit_rate' => $scheduled ? (int) round($done * 100 / $scheduled) : 0,
            'projects_updated' => Catalog::query('projects', $user)->whereBetween('updated_at', [$utcStart, $utcEnd])->count(),
            'projects_completed' => Catalog::query('projects', $user)->whereBetween('completed_at', [$utcStart, $utcEnd])->count(),
            'income' => $finance['income'], 'expenses' => $finance['expenses'], 'net_savings' => $finance['net_savings'], 'currency' => $finance['currency'],
            'content_published' => Catalog::query('content', $user)->whereBetween('published_at', [$utcStart, $utcEnd])->count(),
            'ideas_captured' => Catalog::query('ideas', $user)->whereBetween('created_at', [$utcStart, $utcEnd])->count()];
    }
}
