<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Tables are selected exclusively through EntityCatalog's server-side allowlist. */
class Record extends Model
{
    use HasUlids, SoftDeletes;
    protected $guarded = ['id', 'user_id'];
    protected $fillable = [
        'title', 'name', 'description', 'body', 'brief', 'status', 'previous_status', 'priority', 'project_id', 'goal_id',
        'horizon', 'why_it_matters', 'target_date', 'start_date', 'planned_date', 'due_at', 'due_has_time', 'estimated_minutes',
        'sort_order', 'completed_at', 'is_current_focus', 'starts_at', 'ends_at', 'all_day', 'category', 'source_context',
        'converted_type', 'converted_id', 'pinned', 'notable_type', 'notable_id', 'frequency_type', 'active_weekdays', 'active',
        'type', 'currency', 'opening_balance_minor', 'icon', 'account_id', 'category_id', 'amount_minor', 'transaction_date',
        'target_amount_minor', 'current_amount_minor', 'estimated_price_minor', 'desired_date', 'notes', 'platform', 'hook',
        'script_notes', 'b_roll_notes', 'planned_publish_date', 'published_url', 'published_at', 'week_start', 'week_end',
        'wins', 'challenges', 'lessons', 'improvements', 'next_week_focus', 'snapshot',
    ];
    protected $hidden = ['deleted_at', 'previous_status'];
    protected function casts(): array
    {
        return [
            'is_current_focus' => 'boolean', 'due_has_time' => 'boolean', 'all_day' => 'boolean', 'active' => 'boolean', 'pinned' => 'boolean',
            'active_weekdays' => 'array', 'snapshot' => 'array', 'sort_order' => 'integer', 'estimated_minutes' => 'integer',
            'due_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime',
            'opening_balance_minor' => 'integer', 'amount_minor' => 'integer', 'target_amount_minor' => 'integer',
            'current_amount_minor' => 'integer', 'estimated_price_minor' => 'integer',
        ];
    }
}
