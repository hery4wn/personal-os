<?php
namespace App\Actions;

use App\Models\User;
use App\Models\UserSettings;
use App\Support\EntityCatalog;
use Illuminate\Support\Facades\DB;

class InitializeUser
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user) {
            if ($user->settings()->exists()) return;
            $settings = new UserSettings; $settings->user_id = $user->id; $settings->save();
            $account = EntityCatalog::model('accounts'); $account->user_id = $user->id;
            $account->fill(['name' => 'Cash', 'type' => 'cash', 'currency' => 'IDR', 'opening_balance_minor' => 0]); $account->save();
            $settings->update(['default_finance_account_id' => $account->id]);
            $categories = [
                'expense' => ['Food', 'Transport', 'Coffee', 'Education', 'Technology', 'Entertainment', 'Family', 'Bills', 'Health/Personal', 'Other'],
                'income' => ['Salary', 'Freelance', 'Gift', 'Sale', 'Other Income'],
            ];
            foreach ($categories as $type => $names) foreach ($names as $name) {
                $category = EntityCatalog::model('categories'); $category->user_id = $user->id; $category->fill(compact('name', 'type')); $category->save();
            }
            $user->unsetRelation('settings');
        });
    }
}
