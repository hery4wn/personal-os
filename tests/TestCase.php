<?php
namespace Tests;

use App\Actions\InitializeUser;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite();
    }
    protected function person(string $email = 'test@example.test'): User
    {
        $user = User::create(['name' => 'Test User', 'email' => $email, 'password' => 'Password123!']);
        app(InitializeUser::class)->handle($user); return $user->fresh();
    }
}
