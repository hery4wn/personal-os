<?php
namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\HabitLog;
use App\Models\Record;
use App\Models\User;
use App\Services\ReviewSummary;
use App\Services\UserClock;
use App\Support\EntityCatalog as Catalog;
use App\Support\Money;
use App\Support\RecordRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Reusable, authenticated mutation boundary for web controllers and future API adapters. */
class RecordActions
{
    public function __construct(private UserClock $clock) {}

    public function save(User $user, string $entity, array $input, ?string $id = null): Record
    {
        return DB::transaction(function () use ($user, $entity, $input, $id) {
            // A per-user row lock serializes focus, sorting and conversion operations without cross-user contention.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $record = $id ? Catalog::find($entity, $user, $id) : Catalog::model($entity);
            $data = Validator::make($input, RecordRules::rules($entity, $user, $id))->validate();
            if (! $id) $record->user_id = $user->id;
            $data = $this->normalize($user, $entity, $record, $data);
            $oldStatus = $record->status;
            $record->fill($data);

            if ($entity === 'projects') {
                if ($record->status !== 'active') $record->is_current_focus = false;
                if ($record->is_current_focus) Catalog::query('projects', $user)->where('id', '!=', $record->id ?? '')->update(['is_current_focus' => false]);
                $record->completed_at = $record->status === 'completed' ? ($record->completed_at ?? now()) : null;
            }
            if ($entity === 'tasks') {
                if ($record->status === 'done') {
                    $record->completed_at ??= now();
                    if ($oldStatus !== 'done') $record->previous_status = $oldStatus ?: 'todo';
                } else $record->completed_at = null;
            }
            if ($entity === 'content') $record->published_at = $record->status === 'published' ? ($record->published_at ?? now()) : null;
            if ($entity === 'reviews' && (! $id || $record->isDirty(['week_start', 'week_end']))) {
                $record->snapshot = app(ReviewSummary::class)->forWeek($user, $record->week_start, $record->week_end);
            }
            $record->save();
            $this->log($user, $entity, $record, $id ? 'updated' : 'created', ['previous_status' => $oldStatus, 'status' => $record->status]);
            return $record->refresh();
        }, 3);
    }

    private function normalize(User $user, string $entity, Record $record, array $data): array
    {
        $new = ! $record->exists; $today = $this->clock->today($user);
        $defaults = match ($entity) {
            'tasks' => ['status' => 'todo', 'priority' => 'normal', 'sort_order' => 0],
            'projects' => ['status' => 'active', 'is_current_focus' => false],
            'goals' => ['status' => 'active', 'horizon' => 'current'], 'ideas' => ['status' => 'inbox'],
            'notes' => ['body' => '', 'pinned' => false], 'habits' => ['frequency_type' => 'daily', 'active' => true, 'start_date' => $today],
            'accounts' => ['type' => 'bank', 'currency' => $user->settings->currency, 'opening_balance' => '0', 'active' => true],
            'categories' => ['type' => 'expense', 'active' => true],
            'transactions' => ['transaction_date' => $today, 'account_id' => $user->settings->default_finance_account_id],
            'savings' => ['status' => 'active', 'current_amount' => '0', 'currency' => $user->settings->currency],
            'wishlist' => ['status' => 'considering', 'priority' => 'medium', 'currency' => $user->settings->currency],
            'content' => ['status' => 'idea', 'sort_order' => 0], default => [],
        };
        if ($new) $data = [...$defaults, ...$data];
        if (isset($data['active_weekdays'])) $data['active_weekdays'] = array_values(array_map('intval', $data['active_weekdays']));
        $effective = [...$record->attributesToArray(), ...$data];

        foreach (['due_at', 'starts_at', 'ends_at'] as $field) if (array_key_exists($field, $data) && $data[$field]) {
            if ($field === 'due_at' && ! array_key_exists('due_has_time', $data)) $data['due_has_time'] = strlen($data[$field]) > 10;
            $data[$field] = $this->clock->utc($user, $data[$field]);
        }
        if ($entity === 'events') {
            $start = $data['starts_at'] ?? $record->starts_at; $end = $data['ends_at'] ?? $record->ends_at;
            if ($end->lt($start)) $this->invalid('ends_at', 'The end must be on or after the start.');
        }
        if ($entity === 'projects' && ! empty($effective['is_current_focus']) && $effective['status'] !== 'active') {
            if (! $record->is_current_focus || array_key_exists('is_current_focus', $data)) $this->invalid('is_current_focus', 'Only an active project can be your current focus.');
        }
        if ($entity === 'ideas' && ($effective['status'] ?? null) === 'converted' && ! $record->converted_id) $this->invalid('status', 'Use Convert to turn an idea into another item.');
        if ($entity === 'notes') {
            if (! trim($effective['title'] ?? '') && ! trim($effective['body'] ?? '')) $this->invalid('body', 'Add a title or a note.');
            if (! empty($effective['notable_type']) || ! empty($effective['notable_id'])) {
                $map = ['project' => 'projects', 'goal' => 'goals', 'content' => 'content'];
                if (empty($effective['notable_type']) || empty($effective['notable_id']) || ! Catalog::query($map[$effective['notable_type']], $user)->whereKey($effective['notable_id'])->exists()) {
                    $this->invalid('notable_id', 'Choose one of your own projects, goals or content items.');
                }
            }
            if (array_key_exists('body', $data)) $data['body'] ??= '';
        }
        if ($entity === 'habits') {
            if (($effective['frequency_type'] ?? 'daily') === 'weekdays' && empty($effective['active_weekdays'])) $this->invalid('active_weekdays', 'Choose at least one weekday.');
            if (array_key_exists('start_date', $data) && ! $data['start_date']) $data['start_date'] = $today;
        }
        if ($entity === 'accounts' && $record->exists && isset($data['currency']) && $data['currency'] !== $record->currency && Catalog::query('transactions', $user)->withTrashed()->where('account_id', $record->id)->exists()) {
            $this->invalid('currency', 'An account with transactions must keep its original currency. Create another account for a different currency.');
        }
        if ($entity === 'categories' && $record->exists && isset($data['type']) && $data['type'] !== $record->type && Catalog::query('transactions', $user)->withTrashed()->where('category_id', $record->id)->exists()) {
            $this->invalid('type', 'A category used by transactions must keep its original type.');
        }
        if (in_array($entity, ['savings', 'wishlist'], true) && $record->exists && isset($data['currency']) && $data['currency'] !== $record->currency) {
            $this->invalid('currency', 'This item must keep its original currency. Create another item for a different currency.');
        }
        $currency = in_array($entity, ['accounts', 'savings', 'wishlist'], true) ? $effective['currency'] : $user->settings->currency;
        if ($entity === 'transactions') {
            if (empty($effective['account_id'])) $this->invalid('account_id', 'Choose a finance account.');
            if (empty($effective['category_id'])) $this->invalid('category_id', 'Choose a category.');
            $account = Catalog::find('accounts', $user, $effective['account_id']);
            $category = Catalog::find('categories', $user, $effective['category_id']);
            if (! $account->active && ($new || $record->account_id !== $account->id)) $this->invalid('account_id', 'Choose an active account.');
            if (! $category->active && ($new || $record->category_id !== $category->id)) $this->invalid('category_id', 'Choose an active category.');
            if ($category->type !== $effective['type']) $this->invalid('category_id', 'The category must match the transaction type.');
            $currency = $account->currency;
            if (! array_key_exists('amount', $data) && in_array($currency, ['IDR', 'JPY', 'KRW'], true) && $record->amount_minor % 100 !== 0) {
                $this->invalid('amount', 'Use a whole amount for this account currency.');
            }
            if (array_key_exists('transaction_date', $data) && ! $data['transaction_date']) $data['transaction_date'] = $today;
        }
        foreach (Catalog::MONEY[$entity] ?? [] as $field) if (array_key_exists($field, $data)) {
            if ($data[$field] === null && $field === 'estimated_price') $data[$field.'_minor'] = null;
            else {
                $minor = Money::minor($data[$field] ?? '0', $field, $field === 'opening_balance');
                if (in_array($field, ['amount', 'target_amount'], true) && $minor <= 0) $this->invalid($field, 'Amount must be greater than zero.');
                if (in_array($currency, ['IDR', 'JPY', 'KRW'], true) && $minor % 100 !== 0) $this->invalid($field, 'Use a whole amount for this currency.');
                $data[$field.'_minor'] = $minor;
            }
            unset($data[$field]);
        }
        if ($entity === 'reviews') {
            $start = $this->clock->date($user, $effective['week_start']);
            if (($new || (isset($data['week_start']) && $data['week_start'] !== $record->week_start)) && ! $start->equalTo($this->clock->weekStart($user, $effective['week_start']))) $this->invalid('week_start', 'Choose the first day of a week using your Settings preference.');
            $end = $effective['week_end'] ?? $start->addDays(6)->toDateString();
            if ($end < $effective['week_start'] || $end > $start->addDays(6)->toDateString()) $this->invalid('week_end', 'Choose a date in this review week on or after the start.');
            if ($new || array_key_exists('week_end', $data)) $data['week_end'] = $end;
        }
        return $data;
    }

    public function delete(User $user, string $entity, string $id): void
    {
        DB::transaction(function () use ($user, $entity, $id) {
            $record = Catalog::find($entity, $user, $id);
            if (in_array($entity, ['accounts', 'categories'], true)) {
                $field = $entity === 'accounts' ? 'account_id' : 'category_id';
                if (Catalog::query('transactions', $user)->withTrashed()->where($field, $id)->exists()) $this->invalid('delete', 'This item has transaction history. Mark it inactive instead.');
            }
            if ($entity === 'accounts' && $user->settings->default_finance_account_id === $id) $user->settings->update(['default_finance_account_id' => null]);
            if ($entity === 'projects') $record->is_current_focus = false;
            $record->save(); $record->delete(); $this->log($user, $entity, $record, 'deleted');
        });
    }

    public function complete(User $user, string $id, ?bool $done = null): Record
    {
        $record = Catalog::find('tasks', $user, $id);
        $done ??= $record->status !== 'done';
        return $this->save($user, 'tasks', ['status' => $done ? 'done' : ($record->previous_status ?: 'todo')], $id);
    }

    public function focus(User $user, string $id): Record
    {
        $record = Catalog::find('projects', $user, $id);
        if ($record->status !== 'active') $this->invalid('status', 'Activate this project before making it your focus.');
        return $this->save($user, 'projects', ['is_current_focus' => true], $id);
    }

    public function move(User $user, string $entity, string $id, string $status, int $position = 0): Record
    {
        abort_unless(in_array($entity, ['tasks', 'content'], true), 404);
        return DB::transaction(function () use ($user, $entity, $id, $status, $position) {
            $record = $this->save($user, $entity, ['status' => $status, 'sort_order' => max(0, $position)], $id);
            $siblings = Catalog::query($entity, $user)->where('status', $status)->where('id', '!=', $id);
            if ($entity === 'tasks') $siblings->where('project_id', $record->project_id);
            $ids = $siblings->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
            array_splice($ids, min(max(0, $position), count($ids)), 0, [$id]);
            foreach ($ids as $order => $siblingId) Catalog::query($entity, $user)->whereKey($siblingId)->update(['sort_order' => $order]);
            return $record->refresh();
        });
    }

    public function toggleHabit(User $user, string $id, ?string $date = null): bool
    {
        return DB::transaction(function () use ($user, $id, $date) {
            $habit = Catalog::query('habits', $user)->whereKey($id)->lockForUpdate()->firstOrFail();
            $date ??= $this->clock->today($user);
            Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
            if ($date > $this->clock->today($user) || $date < $habit->start_date) $this->invalid('date', 'Choose a date between the habit start and today.');
            if (! $habit->active) $this->invalid('active', 'Activate this habit before completing it.');
            if ($habit->frequency_type === 'weekdays' && ! in_array($this->clock->date($user, $date)->isoWeekday(), $habit->active_weekdays ?? [], true)) $this->invalid('date', 'This habit is not scheduled on that day.');
            $query = HabitLog::where('user_id', $user->id)->where('habit_id', $id)->where('completion_date', $date);
            $existing = $query->first();
            if ($existing) $existing->delete();
            else HabitLog::create(['user_id' => $user->id, 'habit_id' => $id, 'completion_date' => $date, 'completed' => true]);
            $this->log($user, 'habits', $habit, $existing ? 'uncompleted' : 'completed', ['date' => $date]);
            return ! $existing;
        }, 3);
    }

    public function convertIdea(User $user, string $id, string $type, array $overrides = []): Record
    {
        return DB::transaction(function () use ($user, $id, $type, $overrides) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $idea = Catalog::find('ideas', $user, $id);
            $targets = ['project' => 'projects', 'task' => 'tasks', 'content' => 'content', 'note' => 'notes'];
            if (! isset($targets[$type])) $this->invalid('type', 'Choose Project, Task, Content or Note.');
            if ($idea->converted_id) $this->invalid('type', 'This idea has already been converted.');
            $payload = $type === 'project' ? ['name' => $idea->title, 'description' => $idea->body] : ['title' => $idea->title, $type === 'note' ? 'body' : 'description' => $idea->body];
            $target = $this->save($user, $targets[$type], [...$payload, ...$overrides]);
            $idea->fill(['status' => 'converted', 'converted_type' => $type, 'converted_id' => $target->id]); $idea->save();
            $this->log($user, 'ideas', $idea, 'converted', ['type' => $type, 'target_id' => $target->id]);
            return $target;
        }, 3);
    }

    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
    private function log(User $user, string $entity, Record $record, string $event, array $metadata = []): void
    {
        ActivityLog::create(['user_id' => $user->id, 'actor_type' => 'user', 'actor_identifier' => $user->id,
            'event' => $event, 'subject_type' => $entity, 'subject_id' => $record->id, 'metadata' => $metadata, 'created_at' => now()]);
    }
}
