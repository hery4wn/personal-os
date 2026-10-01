<?php
namespace App\Services;

use App\Models\User;
use App\Support\EntityCatalog as Catalog;
use App\Support\Money;

class FinanceSummary
{
    public function between(User $user, string $from, string $to): array
    {
        // Different account currencies are never silently added together. The selected display currency determines the summary.
        $query = Catalog::query('transactions', $user)
            ->join('finance_accounts as accounts', 'accounts.id', '=', 'finance_transactions.account_id')
            ->where('accounts.user_id', $user->id)->where('accounts.currency', $user->settings->currency)
            ->whereBetween('transaction_date', [$from, $to]);
        $totals = (clone $query)->selectRaw('finance_transactions.type, SUM(amount_minor) as total')->groupBy('finance_transactions.type')->pluck('total', 'type');
        $income = (int) ($totals['income'] ?? 0); $expenses = (int) ($totals['expense'] ?? 0);
        $breakdown = (clone $query)->where('finance_transactions.type', 'expense')
            ->join('finance_categories as categories', 'categories.id', '=', 'finance_transactions.category_id')
            ->selectRaw('category_id, categories.name, SUM(amount_minor) as total')->groupBy('category_id', 'categories.name')
            ->orderByDesc('total')->get()->map(fn ($row) => ['category_id' => $row->category_id, 'name' => $row->name, 'amount' => Money::decimal($row->total)])->all();
        return ['income' => Money::decimal($income), 'expenses' => Money::decimal($expenses), 'net_savings' => Money::decimal($income - $expenses),
            'currency' => $user->settings->currency, 'breakdown' => $breakdown];
    }
}
