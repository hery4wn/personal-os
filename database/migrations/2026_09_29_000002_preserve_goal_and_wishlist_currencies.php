<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['savings_goals', 'wishlist_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('currency', 3)->default('IDR'));
            // Legacy values were entered in the owner's currency. Preserve that denomination,
            // including deleted records, before future settings changes affect new items.
            DB::table('user_settings')->select('id', 'user_id', 'currency')->orderBy('id')->chunkById(200, function ($settings) use ($name) {
                foreach ($settings as $setting) DB::table($name)->where('user_id', $setting->user_id)->update(['currency' => $setting->currency]);
            });
        }
    }

    public function down(): void
    {
        foreach (['savings_goals', 'wishlist_items'] as $name) Schema::table($name, fn (Blueprint $table) => $table->dropColumn('currency'));
    }
};
