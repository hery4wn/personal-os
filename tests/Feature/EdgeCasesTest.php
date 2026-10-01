<?php
namespace Tests\Feature;

use App\Actions\RecordActions;
use App\Models\User;
use App\Services\HabitMetrics;
use App\Services\WorkspaceQuery;
use App\Support\EntityCatalog as Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function create(string $entity, array $data): string
    {
        return $this->postJson('/data/'.$entity, $data)->assertCreated()->json('data.id');
    }

    public function test_daily_checkins_are_idempotent_private_and_reversible(): void
    {
        $this->getJson('/daily-checkins?from=2026-09-01&to=2026-09-30')->assertUnauthorized();
        $first = $this->person('checkin-one@example.test');
        $second = $this->person('checkin-two@example.test');
        $this->actingAs($first);
        foreach ([1, 2] as $attempt) $this->putJson('/daily-checkins', ['date' => '2026-09-10', 'checked' => true])->assertOk();
        $this->assertDatabaseCount('daily_checkins', 1);
        $this->getJson('/daily-checkins?from=2026-09-01&to=2026-09-30')->assertJsonPath('dates', ['2026-09-10']);
        $this->actingAs($second)->getJson('/daily-checkins?from=2026-09-01&to=2026-09-30')->assertJsonPath('dates', []);
        $this->putJson('/daily-checkins', ['date' => '2026-09-10', 'checked' => false])->assertOk();
        $this->assertDatabaseCount('daily_checkins', 1);
        $this->actingAs($first)->putJson('/daily-checkins', ['date' => '2026-09-10', 'checked' => false])->assertOk();
        $this->assertDatabaseCount('daily_checkins', 0);
        $this->putJson('/daily-checkins', ['date' => 'invalid', 'checked' => true])->assertUnprocessable();
        $this->getJson('/daily-checkins?from=2026-09-30&to=2026-09-01')->assertUnprocessable();
    }

    public function test_email_uniqueness_is_case_insensitive_and_sessions_regenerate(): void
    {
        $existing = $this->person('same@example.test');
        $this->postJson('/register', ['name' => 'Duplicate', 'email' => 'SAME@EXAMPLE.TEST', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(1, User::count());
        $this->withSession(['before_login' => true]); $beforeLogin = session()->getId();
        $this->post('/login', ['email' => 'SAME@EXAMPLE.TEST', 'password' => 'Password123!'])->assertRedirect('/home');
        $this->assertAuthenticatedAs($existing); $this->assertNotSame($beforeLogin, session()->getId());
        $other = $this->person('other@example.test'); $this->actingAs($other);
        $this->patchJson('/settings', ['email' => 'SAME@EXAMPLE.TEST'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson('/settings', ['email' => 'NEW@EXAMPLE.TEST'])->assertOk();
        $this->assertSame('new@example.test', $other->fresh()->email);
    }

    public function test_foreign_finance_and_polymorphic_links_are_rejected(): void
    {
        $owner = $this->person('owner@example.test'); $user = $this->person(); $this->actingAs($user);
        $foreignAccount = $owner->settings->default_finance_account_id;
        $foreignCategory = Catalog::query('categories', $owner)->where('type', 'expense')->first()->id;
        $ownCategory = Catalog::query('categories', $user)->where('type', 'expense')->first()->id;
        $foreignGoal = app(RecordActions::class)->save($owner, 'goals', ['title' => 'Private goal']);
        $foreignContent = app(RecordActions::class)->save($owner, 'content', ['title' => 'Private content']);
        $this->postJson('/data/transactions', ['type' => 'expense', 'amount' => '1', 'account_id' => $foreignAccount, 'category_id' => $ownCategory])->assertUnprocessable();
        $this->postJson('/data/transactions', ['type' => 'expense', 'amount' => '1', 'category_id' => $foreignCategory])->assertUnprocessable();
        $this->postJson('/data/habits', ['name' => 'Mine', 'goal_id' => $foreignGoal->id])->assertUnprocessable();
        $this->postJson('/data/notes', ['body' => 'Mine', 'notable_type' => 'content', 'notable_id' => $foreignContent->id])->assertUnprocessable();
        $this->patchJson('/settings', ['default_finance_account_id' => $foreignAccount])->assertUnprocessable();
        $this->getJson('/data/content/'.$foreignContent->id)->assertNotFound();
        $this->getJson('/data/users')->assertNotFound();
    }

    public function test_currency_invariant_applies_when_only_the_account_changes(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $usd = $this->create('accounts', ['name' => 'USD account', 'currency' => 'USD']);
        $category = Catalog::query('categories', $user)->where('type', 'expense')->first()->id;
        $transaction = $this->create('transactions', ['type' => 'expense', 'amount' => '0.50', 'account_id' => $usd, 'category_id' => $category]);
        $this->patchJson('/data/transactions/'.$transaction, ['account_id' => $user->settings->default_finance_account_id])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame($usd, Catalog::find('transactions', $user, $transaction)->account_id);
        $this->patchJson('/data/transactions/'.$transaction, ['account_id' => $user->settings->default_finance_account_id, 'amount' => '5000'])->assertOk()->assertJsonPath('data.amount', '5000');
    }

    public function test_weekday_strings_are_normalized_and_historical_reviews_stay_editable(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-29 09:00:00', 'Asia/Makassar'));
        try {
            $user = $this->person(); $this->actingAs($user);
            $habit = $this->create('habits', ['name' => 'Tuesday', 'frequency_type' => 'weekdays', 'active_weekdays' => ['2']]);
            $this->assertSame([2], Catalog::find('habits', $user, $habit)->active_weekdays);
            $this->postJson('/data/habits/'.$habit.'/toggle')->assertOk();
            $daily = $this->create('habits', ['name' => 'Daily']);
            $this->patchJson('/data/habits/'.$daily, ['frequency_type' => 'weekdays', 'active_weekdays' => []])->assertUnprocessable();
            $review = $this->create('reviews', ['week_start' => '2026-09-28']);
            $this->patchJson('/settings', ['week_starts_on' => 0])->assertOk();
            $this->patchJson('/data/reviews/'.$review, ['wins' => 'Still editable'])->assertOk();
            $this->postJson('/data/reviews', ['week_start' => '2026-10-05'])->assertUnprocessable();
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_datetime_validation_calendar_overlap_and_local_today_boundaries(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 17:00:00', 'UTC'));
        try {
            $user = $this->person(); $this->actingAs($user);
            $this->postJson('/data/events', ['title' => 'Bad', 'starts_at' => '2026-09-29T10:00', 'ends_at' => '2026-09-29T09:00'])->assertUnprocessable();
            $event = $this->create('events', ['title' => 'Overnight', 'starts_at' => '2026-09-28T23:00', 'ends_at' => '2026-09-29T02:00']);
            $this->getJson('/data/events/'.$event)->assertOk()->assertJsonPath('data.starts_at', '2026-09-28T15:00:00.000000Z');
            $this->patchJson('/data/events/'.$event, ['ends_at' => '2026-09-28T20:00'])->assertUnprocessable();
            $this->getJson('/data/events?from=2026-09-29&to=2026-09-29')->assertOk()->assertJsonPath('total', 1);
            $todayTask = $this->create('tasks', ['title' => 'Local today', 'due_at' => '2026-09-29T00:30']);
            $allDay = $this->create('tasks', ['title' => 'Date only', 'due_at' => '2026-09-29', 'due_has_time' => false]);
            $future = $this->create('tasks', ['title' => 'Tomorrow', 'due_at' => '2026-09-30T09:00']);
            $response = $this->getJson('/data/tasks?filter=today')->assertOk()->assertJsonPath('total', 2);
            $this->assertContains($todayTask, array_column($response->json('data'), 'id'));
            $this->getJson('/data/tasks?filter=overdue')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $todayTask);
            $this->getJson('/data/tasks/'.$allDay)->assertOk()->assertJsonPath('data.due_date_only', true)->assertJsonPath('data.is_overdue', false);
            $this->getJson('/data/events?from=2026-09-30&to=2026-09-29')->assertUnprocessable();
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_task_filters_and_pagination_do_not_lose_records_outside_today(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $project = $this->create('projects', ['name' => 'A larger project']);
        $actions = app(RecordActions::class);
        for ($i = 0; $i < 54; $i++) $actions->save($user, 'tasks', ['title' => 'Future task '.$i, 'project_id' => $project, 'priority' => 'high', 'due_at' => '2099-01-01']);
        $done = $this->create('tasks', ['title' => 'Finished', 'status' => 'done']);
        $this->getJson('/data/tasks?filter=open&per_page=50')->assertOk()->assertJsonPath('total', 54)->assertJsonCount(50, 'data');
        $this->getJson('/data/tasks?filter=open&per_page=50&page=2')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/data/tasks?filter=completed')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $done);
        $this->getJson('/data/tasks?project_id='.$project.'&priority=urgent')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/data/tasks?q=Future%20task%2053')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_payloads_are_route_specific_and_goal_progress_is_derived(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $goal = $this->create('goals', ['title' => 'Progress']);
        $project = $this->create('projects', ['name' => 'Half done', 'goal_id' => $goal]);
        $empty = $this->create('projects', ['name' => 'No tasks', 'goal_id' => $goal]);
        $this->create('tasks', ['title' => 'Done', 'status' => 'done', 'project_id' => $project]);
        $this->create('tasks', ['title' => 'To do', 'project_id' => $project]);
        $this->create('ideas', ['title' => 'Not sent on settings']);
        $payload = app(WorkspaceQuery::class)->payload($user, 'goals');
        $this->assertSame(25, $payload['data']['goals'][0]['progress']);
        $this->assertSame(2, $payload['data']['goals'][0]['project_count']);
        $this->assertSame([], $payload['data']['tasks']); $this->assertSame([], $payload['data']['ideas']);
        $this->assertArrayNotHasKey('finance', $payload['summary']);
        DB::enableQueryLog(); DB::flushQueryLog();
        $settingsPayload = app(WorkspaceQuery::class)->payload($user, 'settings');
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame([], array_filter($settingsPayload['data']));
        $this->assertLessThanOrEqual(6, count($queries));
        $this->assertStringNotContainsString('brief', json_encode($settingsPayload['lookups']));
    }

    public function test_deleted_project_tasks_remain_readable_and_searchable_without_broken_links(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $project = $this->create('projects', ['name' => 'Archive me']);
        $task = $this->create('tasks', ['title' => 'Preserved history', 'project_id' => $project]);
        $this->deleteJson('/data/projects/'.$project)->assertOk();
        $this->getJson('/search?q=Preserved')->assertOk()->assertJsonPath('results.0.href', '/today?item='.$task);
        $this->getJson('/data/tasks/'.$task)->assertOk()->assertJsonPath('data.title', 'Preserved history');
    }

    public function test_selected_finance_month_limits_both_summary_and_transactions(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $category = Catalog::query('categories', $user)->where('type', 'expense')->first()->id;
        $this->create('transactions', ['type' => 'expense', 'amount' => '1000', 'category_id' => $category, 'transaction_date' => '2026-08-31']);
        $this->create('transactions', ['type' => 'expense', 'amount' => '2000', 'category_id' => $category, 'transaction_date' => '2026-09-01']);
        $payload = app(WorkspaceQuery::class)->payload($user, 'finance', null, ['month' => '2026-08']);
        $this->assertSame('2026-08', $payload['summary']['finance']['month']);
        $this->assertSame('1000', $payload['summary']['finance']['expenses']);
        $this->assertCount(1, $payload['data']['transactions']);
        $this->get('/finance?month=not-a-month')->assertRedirect();
    }

    public function test_savings_and_wishlist_preserve_currency_after_settings_change(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $saving = $this->create('savings', ['name' => 'Emergency fund', 'target_amount' => '1000000', 'current_amount' => '50000']);
        $wish = $this->create('wishlist', ['name' => 'A desk', 'estimated_price' => '200000']);
        $this->patchJson('/settings', ['currency' => 'USD'])->assertOk();
        $this->getJson('/data/savings/'.$saving)->assertOk()->assertJsonPath('data.currency', 'IDR')->assertJsonPath('data.target_amount', '1000000');
        $this->getJson('/data/wishlist/'.$wish)->assertOk()->assertJsonPath('data.currency', 'IDR')->assertJsonPath('data.estimated_price', '200000');
        $this->patchJson('/data/savings/'.$saving, ['current_amount' => '100000'])->assertOk()->assertJsonPath('data.currency', 'IDR');
        $this->patchJson('/data/savings/'.$saving, ['current_amount' => '0.50'])->assertUnprocessable()->assertJsonValidationErrors('current_amount');
        $this->patchJson('/data/wishlist/'.$wish, ['currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $usdSaving = $this->create('savings', ['name' => 'Trip', 'target_amount' => '50.25']);
        $usdWish = $this->create('wishlist', ['name' => 'Book', 'estimated_price' => '12.50']);
        $this->patchJson('/settings', ['currency' => 'IDR'])->assertOk();
        $this->patchJson('/data/savings/'.$usdSaving, ['current_amount' => '1.25'])->assertOk()->assertJsonPath('data.currency', 'USD');
        $this->patchJson('/data/wishlist/'.$usdWish, ['estimated_price' => '10.25'])->assertOk()->assertJsonPath('data.currency', 'USD');
        $this->postJson('/data/wishlist', ['name' => 'Invalid', 'currency' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('currency');
    }

    public function test_currency_migration_backfills_existing_and_deleted_items(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $this->patchJson('/settings', ['currency' => 'USD'])->assertOk();
        $saving = $this->create('savings', ['name' => 'Trip', 'target_amount' => '100.50']);
        $wish = $this->create('wishlist', ['name' => 'Book', 'estimated_price' => '9.25']);
        $this->deleteJson('/data/wishlist/'.$wish)->assertOk();
        $migration = require database_path('migrations/2026_09_29_000002_preserve_goal_and_wishlist_currencies.php');
        $migration->down(); $migration->up();
        $this->assertDatabaseHas('savings_goals', ['id' => $saving, 'currency' => 'USD', 'target_amount_minor' => 10050]);
        $this->assertDatabaseHas('wishlist_items', ['id' => $wish, 'currency' => 'USD', 'estimated_price_minor' => 925]);
    }

    public function test_finance_and_content_filters_are_applied_before_pagination(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $expense = Catalog::query('categories', $user)->where('type', 'expense')->first()->id;
        $income = Catalog::query('categories', $user)->where('type', 'income')->first()->id;
        $secondAccount = $this->create('accounts', ['name' => 'Cash', 'type' => 'cash']);
        $secondCategory = $this->create('categories', ['name' => 'Other expense', 'type' => 'expense']);
        $expected = $this->create('transactions', ['type' => 'expense', 'amount' => '1000', 'category_id' => $expense, 'transaction_date' => '2026-09-01']);
        $this->create('transactions', ['type' => 'income', 'amount' => '1000', 'category_id' => $income, 'transaction_date' => '2026-09-01']);
        $this->create('transactions', ['type' => 'expense', 'amount' => '1000', 'category_id' => $expense, 'account_id' => $secondAccount, 'transaction_date' => '2026-09-01']);
        $this->create('transactions', ['type' => 'expense', 'amount' => '1000', 'category_id' => $secondCategory, 'transaction_date' => '2026-09-01']);
        $filters = http_build_query(['type' => 'expense', 'account_id' => $user->settings->default_finance_account_id, 'category_id' => $expense, 'from' => '2026-09-01', 'to' => '2026-09-30', 'per_page' => 1]);
        $this->getJson('/data/transactions?'.$filters)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $expected);
        $this->getJson('/data/transactions?type=transfer')->assertUnprocessable();
        $this->create('content', ['title' => 'Short video', 'platform' => 'Instagram', 'status' => 'planning']);
        $this->create('content', ['title' => 'Long video', 'platform' => 'YouTube', 'status' => 'planning']);
        $this->getJson('/data/content?platform=YouTube&status=planning&per_page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.title', 'Long video');
    }

    public function test_content_order_matches_move_order_even_before_positions_are_assigned(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $ids = [$this->create('content', ['title' => 'First', 'status' => 'planning']), $this->create('content', ['title' => 'Second', 'status' => 'planning'])];
        sort($ids);
        DB::table('content_items')->where('id', $ids[0])->update(['created_at' => '2026-01-01 00:00:00']);
        DB::table('content_items')->where('id', $ids[1])->update(['created_at' => '2026-01-02 00:00:00']);
        $this->getJson('/data/content?status=planning')->assertOk()->assertJsonPath('data.0.id', $ids[0]);
        $this->postJson('/data/content/'.$ids[1].'/move', ['status' => 'planning', 'sort_order' => 0])->assertOk();
        $this->getJson('/data/content?status=planning')->assertOk()->assertJsonPath('data.0.id', $ids[1])->assertJsonPath('data.1.id', $ids[0]);
    }

    public function test_review_preview_respects_shortened_window_and_rejects_invalid_windows(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $category = Catalog::query('categories', $user)->where('type', 'expense')->first()->id;
        foreach (['2026-09-01', '2026-09-05'] as $date) {
            $task = $this->create('tasks', ['title' => 'Review window '.$date]);
            DB::table('tasks')->where('id', $task)->update(['status' => 'done', 'completed_at' => $date.' 04:00:00']);
            $this->create('transactions', ['type' => 'expense', 'amount' => '1000', 'category_id' => $category, 'transaction_date' => $date]);
        }
        $this->getJson('/review-summary?week_start=2026-09-01&week_end=2026-09-03')
            ->assertOk()->assertJsonPath('tasks_completed', 1)->assertJsonPath('expenses', '1000');
        $this->getJson('/review-summary?week_start=2026-09-01')
            ->assertOk()->assertJsonPath('tasks_completed', 2)->assertJsonPath('expenses', '2000');
        foreach (['2026-08-31', '2026-09-08'] as $end) {
            $this->getJson('/review-summary?week_start=2026-09-01&week_end='.$end)
                ->assertUnprocessable()->assertJsonValidationErrors('week_end');
        }
    }

    public function test_deleted_transactions_still_protect_account_and_category_history(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $category = Catalog::query('categories', $user)->where('type', 'expense')->first()->id;
        $transaction = $this->create('transactions', ['type' => 'expense', 'amount' => '1000', 'category_id' => $category]);
        $this->deleteJson('/data/transactions/'.$transaction)->assertOk();
        $this->patchJson('/data/accounts/'.$user->settings->default_finance_account_id, ['currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->patchJson('/data/categories/'.$category, ['type' => 'income'])->assertUnprocessable()->assertJsonValidationErrors('type');
    }
}
