<?php
namespace App\Http\Controllers;

use App\Actions\InitializeUser;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController
{
    public function page(Request $request)
    {
        return Inertia::render('Auth', ['mode' => $request->is('register') ? 'register' : 'login', 'demoAvailable' => app()->environment('local')]);
    }

    public function register(Request $request, InitializeUser $initialize)
    {
        if (is_string($request->input('email'))) $request->merge(['email' => strtolower(trim($request->input('email')))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()]]);
        $data['email'] = strtolower($data['email']);
        $user = DB::transaction(function () use ($data, $initialize) { $user = User::create($data); $initialize->handle($user); return $user; });
        Auth::login($user); $request->session()->regenerate();
        return redirect('/home');
    }

    public function login(Request $request)
    {
        if (is_string($request->input('email'))) $request->merge(['email' => strtolower(trim($request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $data['email'] = strtolower($data['email']);
        if (! Auth::attempt($data, $request->boolean('remember'))) throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        $request->session()->regenerate(); return redirect()->intended('/home');
    }

    public function logout(Request $request)
    {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken(); return redirect('/login');
    }

    public function demo(Request $request, DemoSeeder $seeder)
    {
        abort_unless(app()->environment('local'), 404);
        $user = $seeder->seedDemo(); Auth::login($user); $request->session()->regenerate(); return redirect('/home');
    }
}
