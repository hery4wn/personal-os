<?php
namespace App\Services;
use App\Models\User;
use Carbon\CarbonImmutable;

class UserClock
{
    public function now(User $user): CarbonImmutable { return CarbonImmutable::now($user->settings->timezone); }
    public function today(User $user): string { return $this->now($user)->toDateString(); }
    public function date(User $user, string $date): CarbonImmutable { return CarbonImmutable::parse($date, $user->settings->timezone)->startOfDay(); }
    public function utc(User $user, string $value): CarbonImmutable { return CarbonImmutable::parse($value, $user->settings->timezone)->utc(); }
    public function weekStart(User $user, ?string $date = null): CarbonImmutable
    {
        return ($date ? $this->date($user, $date) : $this->now($user))->startOfWeek((int) $user->settings->week_starts_on);
    }
}
