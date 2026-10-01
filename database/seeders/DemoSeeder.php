<?php
namespace Database\Seeders;

use App\Actions\InitializeUser;
use App\Actions\RecordActions;
use App\Models\HabitLog;
use App\Models\User;
use App\Services\UserClock;
use App\Support\EntityCatalog as Catalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    public function run(): void { $this->seedDemo(); }

    public function seedDemo(): User
    {
        if (! app()->environment(['local', 'testing'])) throw new \RuntimeException('Demo access is disabled outside local development.');
        return DB::transaction(function () {
            $user = User::where('email', 'hery@example.test')->first();
            if ($user) return $user; // Never overwrite a user's existing work.
            $user = User::create(['name' => 'Hery', 'email' => 'hery@example.test', 'password' => env('DEMO_PASSWORD', 'PersonalOS2026!')]);
            app(InitializeUser::class)->handle($user); $user->refresh();
            $user->settings->update(['vision_text' => 'Build useful things, keep learning, and live with intention.',
                'current_season_text' => 'Building my foundations in Web + AI.', 'current_intention_text' => 'A little progress, every day.',
                'principles_text' => 'Stay curious. Start small. Make it meaningful.']);
            $actions = app(RecordActions::class); $clock = app(UserClock::class); $now = $clock->now($user); $today = $now->toDateString();
            $create = fn (string $entity, array $data) => $actions->save($user, $entity, $data);
            $web = $create('goals', ['title' => 'Build strong Web + AI foundations', 'description' => 'Turn curiosity into useful, reliable products.', 'horizon' => 'year', 'why_it_matters' => 'Create more opportunities by doing meaningful work.', 'target_date' => $now->endOfYear()->toDateString()]);
            $english = $create('goals', ['title' => 'Communicate with confidence', 'description' => 'Practice English consistently and share what I learn.', 'horizon' => 'current']);
            $career = $create('goals', ['title' => 'Get real industry experience', 'description' => 'Find an internship where I can learn and contribute.', 'horizon' => 'year']);
            $os = $create('projects', ['name' => 'Personal OS', 'description' => 'One calm place for everything that matters.', 'brief' => 'Build my own command center for goals, projects, habits and everyday life.', 'status' => 'active', 'goal_id' => $web->id, 'is_current_focus' => true, 'start_date' => $now->subDays(12)->toDateString(), 'target_date' => $now->addDays(21)->toDateString()]);
            $eng = $create('projects', ['name' => 'English Improvement', 'description' => 'A little practice, a lot more confidence.', 'status' => 'active', 'goal_id' => $english->id, 'target_date' => $now->addMonths(2)->toDateString()]);
            $intern = $create('projects', ['name' => 'Internship Preparation', 'description' => 'Prepare a thoughtful portfolio and application.', 'status' => 'active', 'goal_id' => $career->id]);
            foreach ([
                ['Map the Personal OS modules', 'done', 'high', '09:00'],
                ['Sketch the dashboard layout', 'done', 'normal', '10:00'],
                ['Build the project workspace', 'in_progress', 'high', '14:00'],
                ['Connect goals to daily tasks', 'todo', 'normal', '16:00'],
                ['Write a short build reflection', 'todo', 'low', null],
            ] as $i => [$title, $status, $priority, $time]) {
                $create('tasks', ['title' => $title, 'description' => 'Move the Personal OS project one meaningful step forward.', 'project_id' => $os->id,
                    'status' => $status, 'priority' => $priority, 'planned_date' => $today, 'due_at' => $time ? "$today $time" : null,
                    'due_has_time' => (bool) $time, 'estimated_minutes' => 30, 'sort_order' => $i]);
            }
            $create('tasks', ['title' => 'Practice a 2-minute English introduction', 'project_id' => $eng->id, 'planned_date' => $now->addDay()->toDateString(), 'priority' => 'normal']);
            $create('tasks', ['title' => 'Review internship opportunities', 'project_id' => $intern->id, 'status' => 'backlog', 'priority' => 'high']);
            $create('notes', ['title' => 'A place to think clearly', 'body' => "The goal is not to track everything. It is to make space for what matters.\n\nCapture → Organize → Plan → Act → Review.", 'pinned' => true, 'notable_type' => 'project', 'notable_id' => $os->id]);
            foreach ([['Deep work · Personal OS', '09:00', '11:00', 'Project'], ['English practice', '13:00', '13:30', 'Learning'], ['Evening reset', '19:00', '19:30', 'Personal']] as [$title, $start, $end, $category]) {
                $create('events', ['title' => $title, 'starts_at' => "$today $start", 'ends_at' => "$today $end", 'category' => $category]);
            }
            foreach (['Wake up early', 'Move for 20 minutes', 'Read 10 pages', 'Practice English', 'Learn something new'] as $i => $name) {
                $habit = $create('habits', ['name' => $name, 'description' => 'Small actions compound over time.', 'frequency_type' => $i === 4 ? 'weekdays' : 'daily',
                    'active_weekdays' => $i === 4 ? [1, 2, 3, 4, 5] : null, 'start_date' => $now->subDays(14)->toDateString(), 'sort_order' => $i]);
                for ($day = 0; $day < 10; $day++) {
                    $date = $now->subDays($day); if ($i === 4 && $date->isoWeekday() > 5) continue;
                    if (($day === 0 && $i > 1) || ($day === 6 && $i % 2 === 0)) continue;
                    HabitLog::create(['user_id' => $user->id, 'habit_id' => $habit->id, 'completion_date' => $date->toDateString(), 'completed' => true]);
                }
            }
            $account = Catalog::query('accounts', $user)->first(); $actions->save($user, 'accounts', ['name' => 'Everyday wallet', 'opening_balance' => '750000'], $account->id);
            $bank = $create('accounts', ['name' => 'Savings account', 'type' => 'savings', 'currency' => 'IDR', 'opening_balance' => '2000000']);
            $categories = Catalog::query('categories', $user)->pluck('id', 'name');
            foreach ([['income', 'Salary', '3500000', 'Part-time work'], ['expense', 'Food', '425000', 'Meals & groceries'], ['expense', 'Transport', '180000', 'Getting around'], ['expense', 'Education', '249000', 'A new course'], ['expense', 'Coffee', '95000', 'Coffee breaks'], ['expense', 'Bills', '350000', 'Monthly essentials']] as $i => [$type, $category, $amount, $description]) {
                $create('transactions', ['type' => $type, 'amount' => $amount, 'account_id' => $account->id, 'category_id' => $categories[$category],
                    'transaction_date' => $now->startOfMonth()->addDays(min($i + 1, $now->day - 1))->toDateString(), 'description' => $description]);
            }
            $create('savings', ['name' => 'A little more freedom', 'target_amount' => '10000000', 'current_amount' => '3250000', 'target_date' => $now->addMonths(6)->toDateString()]);
            $create('wishlist', ['name' => 'A better desk setup', 'estimated_price' => '1500000', 'priority' => 'medium', 'status' => 'considering', 'notes' => 'Make my workspace more comfortable.']);
            foreach (['A tiny app for daily English practice', 'What if my notes could become projects?', 'A weekly build-in-public journal'] as $title) $create('ideas', ['title' => $title, 'body' => 'A thought worth coming back to.', 'source_context' => 'A moment of curiosity']);
            $create('ideas', ['title' => 'Learn Docker through a small project', 'status' => 'exploring', 'body' => 'Start with one application and keep a learning log.']);
            $create('content', ['title' => 'Why I’m building my Personal OS', 'description' => 'The story behind creating a calmer digital space.', 'platform' => 'YouTube', 'status' => 'script', 'hook' => 'I had plans everywhere, but no single place to act on them.', 'project_id' => $os->id, 'planned_publish_date' => $now->addDays(3)->toDateString()]);
            $create('content', ['title' => 'Small steps, real progress', 'description' => 'A short reflection on learning in public.', 'platform' => 'LinkedIn', 'status' => 'idea']);
            $create('content', ['title' => 'Personal OS · the first look', 'platform' => 'Instagram', 'status' => 'editing', 'project_id' => $os->id, 'planned_publish_date' => $now->addDays(5)->toDateString()]);
            $previous = $clock->weekStart($user)->subWeek();
            $create('reviews', ['week_start' => $previous->toDateString(), 'week_end' => $previous->addDays(6)->toDateString(), 'wins' => 'Made space for a consistent learning routine.',
                'challenges' => 'Some days had too many priorities.', 'lessons' => 'One clear next step is more useful than a perfect plan.', 'next_week_focus' => 'Bring the Personal OS workspace to life.']);
            return $user;
        });
    }
}
