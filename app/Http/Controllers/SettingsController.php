<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SettingsController
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        if (is_string($request->input('email'))) $request->merge(['email' => strtolower(trim($request->input('email')))]);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'], 'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'timezone' => ['sometimes', 'timezone'], 'currency' => ['sometimes', 'regex:/^[A-Z]{3}$/'],
            'week_starts_on' => ['sometimes', 'integer', Rule::in([0, 1])], 'date_format' => ['sometimes', Rule::in(['d M Y', 'd/m/Y', 'm/d/Y', 'Y-m-d'])],
            'default_finance_account_id' => ['nullable', Rule::exists('finance_accounts', 'id')->where('user_id', $user->id)->where('active', true)->whereNull('deleted_at')],
            'vision_text' => ['nullable', 'string', 'max:10000'], 'current_season_text' => ['nullable', 'string', 'max:10000'],
            'current_intention_text' => ['nullable', 'string', 'max:1000'], 'principles_text' => ['nullable', 'string', 'max:10000'],
        ]);
        DB::transaction(function () use ($user, $data) {
            $profile = array_intersect_key($data, array_flip(['name', 'email']));
            if (isset($profile['email'])) $profile['email'] = strtolower($profile['email']);
            if ($profile) $user->update($profile);
            $user->settings->update(array_diff_key($data, array_flip(['name', 'email'])));
        });
        return response()->json(['settings' => $user->settings->fresh(), 'user' => $user->only('id', 'name', 'email')]);
    }
}
