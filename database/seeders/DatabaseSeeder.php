<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) throw new \RuntimeException('Demo data is restricted to local development and tests.');
        $this->call(DemoSeeder::class);
    }
}
