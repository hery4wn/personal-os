<?php
namespace App\Support;
use App\Models\Record;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class EntityCatalog
{
    public const TABLES = [
        'tasks' => 'tasks', 'projects' => 'projects', 'goals' => 'goals', 'events' => 'calendar_events',
        'ideas' => 'ideas', 'notes' => 'notes', 'habits' => 'habits', 'accounts' => 'finance_accounts',
        'categories' => 'finance_categories', 'transactions' => 'finance_transactions', 'savings' => 'savings_goals',
        'wishlist' => 'wishlist_items', 'content' => 'content_items', 'reviews' => 'weekly_reviews',
    ];
    public const STATUSES = [
        'tasks' => ['backlog', 'todo', 'in_progress', 'done'], 'projects' => ['planned', 'active', 'paused', 'completed', 'archived'],
        'goals' => ['active', 'achieved', 'paused', 'archived'], 'ideas' => ['inbox', 'exploring', 'planned', 'converted', 'archived'],
        'savings' => ['active', 'achieved', 'paused', 'archived'], 'wishlist' => ['considering', 'planned', 'purchased', 'cancelled'],
        'content' => ['idea', 'planning', 'script', 'recording', 'editing', 'ready', 'published'],
    ];
    public const MONEY = ['accounts' => ['opening_balance'], 'transactions' => ['amount'], 'savings' => ['target_amount', 'current_amount'], 'wishlist' => ['estimated_price']];
    public static function model(string $entity): Record
    {
        abort_unless(isset(self::TABLES[$entity]), 404);
        return (new Record)->setTable(self::TABLES[$entity]);
    }
    public static function query(string $entity, User $user): Builder
    {
        return self::model($entity)->newQuery()->where(self::TABLES[$entity].'.user_id', $user->id);
    }
    public static function find(string $entity, User $user, string $id): Record { return self::query($entity, $user)->findOrFail($id); }
}
