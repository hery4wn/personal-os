<?php
namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rule;

final class RecordRules
{
    public static function rules(string $entity, User $user, ?string $id = null): array
    {
        EntityCatalog::model($entity);
        $required = $id ? 'sometimes' : 'required';
        $title = [$required, 'string', 'max:255'];
        $text = ['nullable', 'string', 'max:50000'];
        $date = ['nullable', 'date_format:Y-m-d'];
        $link = fn (string $table) => ['nullable', 'string', Rule::exists($table, 'id')->where('user_id', $user->id)->whereNull('deleted_at')];
        $money = fn (bool $required = false) => [$required ? 'required' : 'nullable', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'];
        $status = fn () => ['sometimes', Rule::in(EntityCatalog::STATUSES[$entity])];
        $rules = match ($entity) {
            'tasks' => [
                'title' => $title, 'description' => $text, 'project_id' => $link('projects'), 'status' => $status(),
                'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])], 'planned_date' => $date,
                'due_at' => ['nullable', 'date'], 'due_has_time' => ['sometimes', 'boolean'],
                'estimated_minutes' => ['nullable', 'integer', 'between:1,1440'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            ],
            'projects' => [
                'name' => $title, 'description' => $text, 'brief' => $text, 'status' => $status(), 'goal_id' => $link('goals'),
                'start_date' => $date, 'target_date' => $date, 'is_current_focus' => ['sometimes', 'boolean'],
            ],
            'goals' => [
                'title' => $title, 'description' => $text, 'why_it_matters' => $text,
                'horizon' => ['sometimes', Rule::in(['current', 'year', 'three_to_five_year', 'someday'])],
                'status' => $status(), 'target_date' => $date, 'sort_order' => ['sometimes', 'integer', 'min:0'],
            ],
            'events' => [
                'title' => $title, 'description' => $text, 'starts_at' => [$required, 'date'], 'ends_at' => [$required, 'date'],
                'all_day' => ['sometimes', 'boolean'], 'category' => ['nullable', 'string', 'max:80'],
            ],
            'ideas' => ['title' => $title, 'body' => $text, 'status' => $status(), 'source_context' => ['nullable', 'string', 'max:255']],
            'notes' => [
                'title' => ['nullable', 'string', 'max:255'], 'body' => $text, 'pinned' => ['sometimes', 'boolean'],
                'notable_type' => ['nullable', Rule::in(['project', 'goal', 'content'])], 'notable_id' => ['nullable', 'string'],
            ],
            'habits' => [
                'name' => $title, 'description' => $text, 'frequency_type' => ['sometimes', Rule::in(['daily', 'weekdays'])],
                'active_weekdays' => ['nullable', 'array', 'max:7'], 'active_weekdays.*' => ['integer', 'between:1,7', 'distinct'],
                'start_date' => $date, 'active' => ['sometimes', 'boolean'], 'goal_id' => $link('goals'), 'sort_order' => ['sometimes', 'integer', 'min:0'],
            ],
            'accounts' => [
                'name' => $title, 'type' => ['sometimes', Rule::in(['cash', 'bank', 'ewallet', 'savings', 'other'])],
                'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
                'opening_balance' => ['nullable', 'regex:/^-?\d{1,13}(?:\.\d{1,2})?$/'], 'active' => ['sometimes', 'boolean'],
            ],
            'categories' => ['name' => $title, 'type' => ['sometimes', Rule::in(['income', 'expense'])], 'icon' => ['nullable', 'string', 'max:50'], 'active' => ['sometimes', 'boolean']],
            'transactions' => [
                'type' => [$required, Rule::in(['income', 'expense'])], 'amount' => $money(! $id),
                'transaction_date' => $date, 'account_id' => $link('finance_accounts'), 'category_id' => $link('finance_categories'), 'description' => $text,
            ],
            'savings' => ['name' => $title, 'target_amount' => $money(! $id), 'current_amount' => $money(), 'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'], 'target_date' => $date, 'status' => $status()],
            'wishlist' => [
                'name' => $title, 'estimated_price' => $money(), 'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'], 'priority' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
                'desired_date' => $date, 'status' => $status(), 'notes' => $text,
            ],
            'content' => [
                'title' => $title, 'description' => $text, 'platform' => ['nullable', 'string', 'max:80'], 'status' => $status(),
                'hook' => $text, 'script_notes' => $text, 'b_roll_notes' => $text, 'planned_publish_date' => $date,
                'published_url' => ['nullable', 'url:http,https', 'max:2048'], 'project_id' => $link('projects'), 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            ],
            'reviews' => [
                'week_start' => [$required, 'date_format:Y-m-d', Rule::unique('weekly_reviews')->where('user_id', $user->id)->ignore($id)],
                'week_end' => ['sometimes', 'date_format:Y-m-d'], 'wins' => $text, 'challenges' => $text, 'lessons' => $text,
                'improvements' => $text, 'next_week_focus' => $text, 'notes' => $text,
            ],
        };
        return $rules;
    }
}
