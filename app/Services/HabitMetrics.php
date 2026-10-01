<?php
namespace App\Services;

use App\Models\HabitLog;
use App\Models\Record;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class HabitMetrics
{
    public function __construct(private UserClock $clock) {}

    public function scheduled(Record $habit, CarbonImmutable $date): bool
    {
        return $date->toDateString() >= $habit->start_date && ($habit->frequency_type === 'daily' || in_array($date->isoWeekday(), $habit->active_weekdays ?? [], true));
    }

    public function decorate(User $user, Collection $habits): array
    {
        // One query for every displayed habit, including streak history: no per-habit database queries.
        $logs = HabitLog::where('user_id', $user->id)->whereIn('habit_id', $habits->pluck('id'))->where('completed', true)->get()->groupBy('habit_id');
        $today = $this->clock->now($user)->startOfDay(); $weekStart = $this->clock->weekStart($user);
        return $habits->map(function (Record $habit) use ($logs, $today, $weekStart) {
            $completed = ($logs->get($habit->id) ?? collect())->pluck('completion_date')->flip();
            $week = []; $scheduledDays = 0; $completedDays = 0;
            for ($i = 0; $i < 7; $i++) {
                $date = $weekStart->addDays($i); $key = $date->toDateString();
                $week[] = ['date' => $key, 'scheduled' => $this->scheduled($habit, $date), 'completed' => $completed->has($key)];
            }
            for ($i = 0; $i < 30; $i++) {
                $date = $today->subDays($i);
                if ($this->scheduled($habit, $date)) { $scheduledDays++; if ($completed->has($date->toDateString())) $completedDays++; }
            }
            $streak = 0; $cursor = $today;
            // Today is still in progress; yesterday's streak remains until a scheduled day is missed.
            if (! $completed->has($today->toDateString())) $cursor = $cursor->subDay();
            while ($cursor->toDateString() >= $habit->start_date) {
                if ($this->scheduled($habit, $cursor)) {
                    if (! $completed->has($cursor->toDateString())) break;
                    $streak++;
                }
                $cursor = $cursor->subDay();
            }
            return [...$habit->toArray(), 'completed_today' => $completed->has($today->toDateString()),
                'scheduled_today' => (bool) $habit->active && $this->scheduled($habit, $today), 'week' => $week,
                'streak' => $streak, 'rate' => $scheduledDays ? (int) round($completedDays * 100 / $scheduledDays) : 0];
        })->all();
    }
}
