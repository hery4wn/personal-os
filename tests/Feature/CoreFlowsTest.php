<?php
namespace Tests\Feature;

use App\Actions\RecordActions;
use App\Models\HabitLog;
use App\Models\User;
use App\Services\FinanceSummary;
use App\Services\HabitMetrics;
use App\Services\WorkspaceQuery;
use App\Support\EntityCatalog as Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CoreFlowsTest extends TestCase
{
    use RefreshDatabase;
    private function create(string $entity, array $data): string { return $this->postJson('/data/'.$entity, $data)->assertCreated()->json('data.id'); }

    public function test_registration_login_logout_and_private_routes(): void
    {
        $this->get('/home')->assertRedirect('/login');
        $this->post('/register', ['name' => 'Hery', 'email' => 'hery@example.test', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'])->assertRedirect('/home');
        $user = User::first(); $this->assertAuthenticatedAs($user); $this->assertNotSame('Password123!', $user->password);
        $this->assertSame('Asia/Makassar', $user->settings->timezone);
        $this->post('/logout')->assertRedirect('/login'); $this->assertGuest();
        $this->post('/login', ['email' => 'hery@example.test', 'password' => 'Password123!'])->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function test_complete_connected_smoke_journey_and_real_dashboard(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $goal = $this->create('goals', ['title' => 'Learn to build']);
        $project = $this->create('projects', ['name' => 'Personal OS', 'goal_id' => $goal, 'is_current_focus' => true]);
        $today = CarbonImmutable::now('Asia/Makassar')->toDateString(); $tasks = [];
        foreach (['Design', 'Build', 'Verify'] as $title) $tasks[] = $this->create('tasks', ['title' => $title, 'project_id' => $project, 'planned_date' => $today]);
        $this->postJson('/data/tasks/'.$tasks[0].'/move', ['status' => 'in_progress', 'sort_order' => 0])->assertOk();
        $this->postJson('/data/tasks/'.$tasks[0].'/complete')->assertOk()->assertJsonPath('data.status', 'done');
        $habit = $this->create('habits', ['name' => 'Read']); $this->postJson('/data/habits/'.$habit.'/toggle')->assertOk()->assertJsonPath('completed', true);
        $category = Catalog::query('categories', $user)->where('type', 'expense')->first();
        $this->postJson('/quick-capture/expense', ['amount' => '35000', 'category_id' => $category->id])->assertCreated();
        $this->postJson('/quick-capture/idea', ['title' => 'A new thought'])->assertCreated();
        $payload = app(WorkspaceQuery::class)->payload($user, 'home');
        $this->assertSame(33, $payload['summary']['today']['percentage']);
        $this->assertSame('35000', $payload['summary']['finance']['expenses']);
        $this->assertSame(1, $payload['summary']['habits_completed']); $this->assertSame(1, $payload['summary']['inbox_ideas']);
        $start = CarbonImmutable::now('Asia/Makassar')->startOfWeek()->toDateString();
        $review = $this->create('reviews', ['week_start' => $start, 'wins' => 'Built something useful']);
        $this->assertSame(1, Catalog::find('reviews', $user, $review)->snapshot['tasks_completed']);
        $this->get('/home')->assertOk(); $this->get('/projects/'.$project)->assertOk();
    }

    public function test_resources_search_and_foreign_relations_are_user_isolated(): void
    {
        $owner = $this->person('owner@example.test'); $stranger = $this->person('stranger@example.test');
        $actions = app(RecordActions::class); $goal = $actions->save($owner, 'goals', ['title' => 'Secret goal']);
        $project = $actions->save($owner, 'projects', ['name' => 'Secret project']); $task = $actions->save($owner, 'tasks', ['title' => 'Secret task', 'project_id' => $project->id]);
        $transaction = $actions->save($owner, 'transactions', ['type' => 'expense', 'amount' => '50000', 'category_id' => Catalog::query('categories', $owner)->where('type', 'expense')->first()->id]);
        $this->actingAs($stranger);
        foreach (['tasks' => $task, 'projects' => $project, 'transactions' => $transaction] as $entity => $record) {
            $this->patchJson('/data/'.$entity.'/'.$record->id, [($entity === 'projects' ? 'name' : 'title') => 'Changed'])->assertNotFound();
            $this->deleteJson('/data/'.$entity.'/'.$record->id)->assertNotFound();
        }
        $this->postJson('/data/tasks/'.$task->id.'/complete')->assertNotFound();
        $this->get('/projects/'.$project->id)->assertNotFound();
        $this->postJson('/data/tasks', ['title' => 'Bad link', 'project_id' => $project->id])->assertUnprocessable();
        $this->postJson('/data/projects', ['name' => 'Bad link', 'goal_id' => $goal->id])->assertUnprocessable();
        $this->postJson('/data/notes', ['title' => 'Bad link', 'notable_type' => 'project', 'notable_id' => $project->id])->assertUnprocessable();
        $this->getJson('/search?q=Secret')->assertOk()->assertJsonPath('results', []);
        $this->getJson('/data/transactions')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_task_status_timestamp_restore_soft_delete_and_focus_uniqueness(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $a = $this->create('projects', ['name' => 'A', 'is_current_focus' => true]); $b = $this->create('projects', ['name' => 'B']);
        $this->postJson('/data/projects/'.$b.'/focus')->assertOk();
        $this->assertFalse(Catalog::find('projects', $user, $a)->is_current_focus); $this->assertTrue(Catalog::find('projects', $user, $b)->is_current_focus);
        $task = $this->create('tasks', ['title' => 'Implement', 'status' => 'in_progress', 'project_id' => $b]);
        $this->postJson('/data/tasks/'.$task.'/complete')->assertOk(); $this->assertNotNull(Catalog::find('tasks', $user, $task)->completed_at);
        $this->postJson('/data/tasks/'.$task.'/complete')->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertNull(Catalog::find('tasks', $user, $task)->completed_at);
        $this->deleteJson('/data/projects/'.$b)->assertOk(); $this->assertDatabaseHas('tasks', ['id' => $task, 'project_id' => $b]);
        $this->deleteJson('/data/tasks/'.$task)->assertOk(); $this->assertSoftDeleted('tasks', ['id' => $task]);
    }

    public function test_habits_respect_timezone_unique_date_and_scheduled_streaks(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 17:00:00', 'UTC')); // Tuesday in Makassar.
        try {
            $user = $this->person(); $this->actingAs($user);
            $habit = $this->create('habits', ['name' => 'Weekday practice', 'frequency_type' => 'weekdays', 'active_weekdays' => [1, 2, 3, 4, 5], 'start_date' => '2026-09-25']);
            foreach (['2026-09-25', '2026-09-28'] as $date) $this->postJson('/data/habits/'.$habit.'/toggle', compact('date'))->assertOk();
            $this->postJson('/data/habits/'.$habit.'/toggle')->assertOk();
            $this->assertDatabaseHas('habit_logs', ['habit_id' => $habit, 'completion_date' => '2026-09-29']);
            $stats = app(HabitMetrics::class)->decorate($user, collect([Catalog::find('habits', $user, $habit)]))[0];
            $this->assertSame(3, $stats['streak']);
            $this->postJson('/data/habits/'.$habit.'/toggle', ['date' => '2026-09-27'])->assertUnprocessable();
            $this->postJson('/data/habits/'.$habit.'/toggle')->assertOk(); $this->postJson('/data/habits/'.$habit.'/toggle')->assertOk();
            $this->assertSame(1, HabitLog::where('habit_id', $habit)->where('completion_date', '2026-09-29')->count());
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_finance_validation_exact_totals_and_history_protection(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $expense = Catalog::query('categories', $user)->where('type', 'expense')->first(); $income = Catalog::query('categories', $user)->where('type', 'income')->first();
        $account = $user->settings->default_finance_account_id;
        foreach (['0', '-5', '10.001', '1.50'] as $amount) $this->postJson('/data/transactions', ['type' => 'expense', 'amount' => $amount, 'category_id' => $expense->id])->assertUnprocessable();
        $this->postJson('/data/transactions', ['type' => 'income', 'amount' => '1000', 'category_id' => $expense->id])->assertUnprocessable();
        $this->create('transactions', ['type' => 'income', 'amount' => '1000000', 'category_id' => $income->id, 'transaction_date' => '2026-09-01']);
        $this->create('transactions', ['type' => 'expense', 'amount' => '35000', 'category_id' => $expense->id, 'transaction_date' => '2026-09-29']);
        $this->create('transactions', ['type' => 'expense', 'amount' => '10000', 'category_id' => $expense->id, 'transaction_date' => '2026-08-31']);
        $summary = app(FinanceSummary::class)->between($user, '2026-09-01', '2026-09-30');
        $this->assertSame('965000', $summary['net_savings']);
        $this->deleteJson('/data/accounts/'.$account)->assertUnprocessable(); $this->deleteJson('/data/categories/'.$expense->id)->assertUnprocessable();
        $usd = $this->create('accounts', ['name' => 'USD', 'currency' => 'USD']);
        $this->create('transactions', ['type' => 'expense', 'amount' => '0.10', 'category_id' => $expense->id, 'account_id' => $usd, 'transaction_date' => '2026-09-01']);
        $this->create('transactions', ['type' => 'expense', 'amount' => '0.20', 'category_id' => $expense->id, 'account_id' => $usd, 'transaction_date' => '2026-09-01']);
        $user->settings->update(['currency' => 'USD']);
        $this->assertSame('0.30', app(FinanceSummary::class)->between($user, '2026-09-01', '2026-09-30')['expenses']);
    }

    public function test_idea_conversion_is_atomic_and_cannot_repeat(): void
    {
        $user = $this->person(); $this->actingAs($user); $idea = $this->create('ideas', ['title' => 'Build an app', 'body' => 'Keep it small.']);
        $this->postJson('/data/ideas/'.$idea.'/convert', ['type' => 'project', 'overrides' => ['name' => '']])->assertUnprocessable();
        $this->assertSame(0, Catalog::query('projects', $user)->count()); $this->assertSame('inbox', Catalog::find('ideas', $user, $idea)->status);
        $target = $this->postJson('/data/ideas/'.$idea.'/convert', ['type' => 'project'])->assertCreated()->json('data.id');
        $this->assertSame($target, Catalog::find('ideas', $user, $idea)->converted_id);
        $this->postJson('/data/ideas/'.$idea.'/convert', ['type' => 'task'])->assertUnprocessable();
    }

    public function test_review_uniqueness_snapshot_and_quick_capture_minimums(): void
    {
        $user = $this->person(); $this->actingAs($user);
        $this->postJson('/quick-capture/task', ['title' => 'Captured', 'today' => true])->assertCreated();
        $this->postJson('/quick-capture/note', ['title' => 'A title only'])->assertCreated();
        $this->postJson('/quick-capture/note', ['body' => 'A body only'])->assertCreated();
        $this->postJson('/quick-capture/note', [])->assertUnprocessable();
        $id = $this->create('reviews', ['week_start' => '2026-09-28']);
        $snapshot = Catalog::find('reviews', $user, $id)->snapshot;
        $this->postJson('/data/reviews', ['week_start' => '2026-09-28'])->assertUnprocessable();
        $this->patchJson('/data/reviews/'.$id, ['wins' => 'A good week'])->assertOk();
        $this->assertSame($snapshot, Catalog::find('reviews', $user, $id)->snapshot);
    }
}
