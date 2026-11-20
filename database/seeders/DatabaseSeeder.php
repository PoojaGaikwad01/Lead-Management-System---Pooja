<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Idempotent: re-running never overwrites existing rows or changed passwords. Default password for all seeded users: "password". */
    public function run(): void
    {
        User::firstOrCreate(['email' => 'admin@funkymonkey.in'], [
            'name' => 'Admin',
            'phone' => '+919800000000',
            'password' => 'password',
            'role' => Role::Admin,
            'branch_id' => null,
            'is_active' => true,
        ]);

        $branches = [
            ['name' => 'Andheri', 'city' => 'Mumbai', 'manager' => 'Rahul Mehta', 'slug' => 'andheri'],
            ['name' => 'Koregaon Park', 'city' => 'Pune', 'manager' => 'Sneha Patil', 'slug' => 'koregaonpark'],
            ['name' => 'Indiranagar', 'city' => 'Bengaluru', 'manager' => 'Arjun Rao', 'slug' => 'indiranagar'],
        ];

        foreach ($branches as $i => $b) {
            $branch = Branch::firstOrCreate(['name' => $b['name']], ['city' => $b['city'], 'is_active' => true]);

            User::firstOrCreate(['email' => "manager.{$b['slug']}@funkymonkey.in"], [
                'name' => $b['manager'],
                'phone' => '+91980000000'.($i + 1),
                'password' => 'password',
                'role' => Role::BranchManager,
                'branch_id' => $branch->id,
                'is_active' => true,
            ]);
        }

        if (config('leads.mode') === 'mock') {
            $this->call(LeadSeeder::class);
        }
    }
}
