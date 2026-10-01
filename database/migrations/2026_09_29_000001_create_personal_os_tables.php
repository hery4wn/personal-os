<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary(); $table->string('name'); $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable(); $table->string('password'); $table->rememberToken(); $table->timestamps();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary(); $table->string('token'); $table->timestamp('created_at')->nullable();
        });
        Schema::create('user_settings', function (Blueprint $table) {
            $table->ulid('id')->primary(); $table->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone')->default('Asia/Makassar'); $table->string('currency', 3)->default('IDR');
            $table->unsignedTinyInteger('week_starts_on')->default(1); $table->string('date_format')->default('d M Y');
            $table->ulid('default_finance_account_id')->nullable();
            foreach (['vision_text', 'current_season_text', 'current_intention_text', 'principles_text'] as $field) $table->text($field)->nullable();
            $table->timestamps();
        });
        $owned = function (Blueprint $table) {
            $table->ulid('id')->primary(); $table->foreignUlid('user_id')->constrained()->cascadeOnDelete(); $table->timestamps(); $table->softDeletes();
        };
        Schema::create('goals', function (Blueprint $table) use ($owned) {
            $owned($table); $table->string('title'); $table->text('description')->nullable(); $table->text('why_it_matters')->nullable();
            $table->string('horizon')->default('current'); $table->string('status')->default('active'); $table->date('target_date')->nullable();
            $table->integer('sort_order')->default(0); $table->index(['user_id', 'status']); $table->index(['user_id', 'horizon']);
        });
        Schema::create('projects', function (Blueprint $table) use ($owned) {
            $owned($table); $table->foreignUlid('goal_id')->nullable()->constrained(); $table->string('name');
            $table->text('description')->nullable(); $table->text('brief')->nullable(); $table->string('status')->default('active');
            $table->date('start_date')->nullable(); $table->date('target_date')->nullable(); $table->boolean('is_current_focus')->default(false);
            $table->timestampTz('completed_at')->nullable(); $table->index(['user_id', 'status']); $table->index(['user_id', 'is_current_focus']);
        });
        DB::statement('CREATE UNIQUE INDEX projects_one_focus_per_user ON projects(user_id) WHERE is_current_focus = true AND deleted_at IS NULL');
        Schema::create('tasks', function (Blueprint $table) use ($owned) {
            $owned($table); $table->foreignUlid('project_id')->nullable()->constrained(); $table->string('title'); $table->text('description')->nullable();
            $table->string('status')->default('todo'); $table->string('previous_status')->nullable(); $table->string('priority')->default('normal');
            $table->date('planned_date')->nullable(); $table->timestampTz('due_at')->nullable(); $table->boolean('due_has_time')->default(false);
            $table->unsignedSmallInteger('estimated_minutes')->nullable(); $table->integer('sort_order')->default(0); $table->timestampTz('completed_at')->nullable();
            $table->index(['user_id', 'status']); $table->index(['user_id', 'planned_date']); $table->index(['user_id', 'due_at']);
            $table->index(['project_id', 'status', 'sort_order']);
        });
        Schema::create('calendar_events', function (Blueprint $table) use ($owned) {
            $owned($table); $table->string('title'); $table->text('description')->nullable(); $table->timestampTz('starts_at'); $table->timestampTz('ends_at');
            $table->boolean('all_day')->default(false); $table->string('category')->nullable(); $table->index(['user_id', 'starts_at']);
        });
        Schema::create('ideas', function (Blueprint $table) use ($owned) {
            $owned($table); $table->string('title'); $table->text('body')->nullable(); $table->string('status')->default('inbox');
            $table->string('source_context')->nullable(); $table->string('converted_type')->nullable(); $table->ulid('converted_id')->nullable(); $table->index(['user_id', 'status']);
        });
        Schema::create('notes', function (Blueprint $table) use ($owned) {
            $owned($table); $table->string('title')->nullable(); $table->text('body')->default(''); $table->boolean('pinned')->default(false);
            $table->string('notable_type')->nullable(); $table->ulid('notable_id')->nullable(); $table->index(['user_id', 'pinned']); $table->index(['notable_type', 'notable_id']);
        });
        Schema::create('habits', function (Blueprint $table) use ($owned) {
            $owned($table); $table->foreignUlid('goal_id')->nullable()->constrained(); $table->string('name'); $table->text('description')->nullable();
            $table->string('frequency_type')->default('daily'); $table->json('active_weekdays')->nullable(); $table->date('start_date');
            $table->boolean('active')->default(true); $table->integer('sort_order')->default(0);
        });
        Schema::create('habit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary(); $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('habit_id')->constrained()->cascadeOnDelete(); $table->date('completion_date');
            $table->boolean('completed')->default(true); $table->text('note')->nullable(); $table->timestamps();
            $table->unique(['habit_id', 'completion_date']); $table->index(['user_id', 'completion_date']);
        });
        Schema::create('finance_accounts', function (Blueprint $table) use ($owned) {
            $owned($table); $table->string('name'); $table->string('type')->default('bank'); $table->string('currency', 3)->default('IDR');
            $table->bigInteger('opening_balance_minor')->default(0); $table->boolean('active')->default(true); $table->integer('sort_order')->default(0);
        });
        Schema::create('finance_categories', function (Blueprint $table) use ($owned) {
            $owned($table); $table->string('name'); $table->string('type')->default('expense'); $table->string('icon')->nullable(); $table->boolean('active')->default(true);
        });
        Schema::create('finance_transactions', function (Blueprint $table) use ($owned) {
            $owned($table); $table->foreignUlid('account_id')->constrained('finance_accounts'); $table->foreignUlid('category_id')->constrained('finance_categories');
            $table->string('type'); $table->bigInteger('amount_minor'); $table->date('transaction_date'); $table->text('description')->nullable();
            $table->index(['user_id', 'transaction_date']); $table->index(['user_id', 'type', 'transaction_date']);
        });
        Schema::create('savings_goals', function (Blueprint $table) use ($owned) {
            $owned($table); $table->string('name'); $table->bigInteger('target_amount_minor'); $table->bigInteger('current_amount_minor')->default(0);
            $table->date('target_date')->nullable(); $table->string('status')->default('active');
        });
        Schema::create('wishlist_items', function (Blueprint $table) use ($owned) {
            $owned($table); $table->string('name'); $table->bigInteger('estimated_price_minor')->nullable(); $table->string('priority')->default('medium');
            $table->date('desired_date')->nullable(); $table->string('status')->default('considering'); $table->text('notes')->nullable();
        });
        Schema::create('content_items', function (Blueprint $table) use ($owned) {
            $owned($table); $table->foreignUlid('project_id')->nullable()->constrained(); $table->string('title'); $table->text('description')->nullable();
            $table->string('platform')->nullable(); $table->string('status')->default('idea');
            foreach (['hook', 'script_notes', 'b_roll_notes'] as $field) $table->text($field)->nullable();
            $table->date('planned_publish_date')->nullable(); $table->string('published_url', 2048)->nullable(); $table->timestampTz('published_at')->nullable();
            $table->integer('sort_order')->default(0); $table->index(['user_id', 'status', 'sort_order']);
        });
        Schema::create('weekly_reviews', function (Blueprint $table) use ($owned) {
            $owned($table); $table->date('week_start'); $table->date('week_end');
            foreach (['wins', 'challenges', 'lessons', 'improvements', 'next_week_focus', 'notes'] as $field) $table->text($field)->nullable();
            $table->json('snapshot')->nullable(); $table->unique(['user_id', 'week_start']);
        });
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->ulid('id')->primary(); $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type')->default('user'); $table->string('actor_identifier')->nullable(); $table->string('event');
            $table->string('subject_type'); $table->ulid('subject_id'); $table->json('metadata')->nullable(); $table->timestampTz('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['activity_logs', 'weekly_reviews', 'content_items', 'wishlist_items', 'savings_goals', 'finance_transactions', 'finance_categories', 'finance_accounts', 'habit_logs', 'habits', 'notes', 'ideas', 'calendar_events', 'tasks', 'projects', 'goals', 'user_settings', 'password_reset_tokens', 'users'] as $table) Schema::dropIfExists($table);
    }
};
