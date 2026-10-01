<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class DailyCheckinController {
    public function index(Request $request) {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        return response()->json(['dates' => DB::table('daily_checkins')->where('user_id', $request->user()->id)->whereBetween('date', [$data['from'], $data['to']])->pluck('date')]);
    }
    public function save(Request $request) {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'checked' => ['required', 'boolean']]);
        $key = ['user_id' => $request->user()->id, 'date' => $data['date']];
        if ($data['checked']) DB::table('daily_checkins')->insertOrIgnore($key);
        else DB::table('daily_checkins')->where($key)->delete();
        return response()->json(['checked' => $data['checked']]);
    }
}
