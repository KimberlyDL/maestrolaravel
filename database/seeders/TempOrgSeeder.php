<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Organization;

class TempOrgSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1) Ensure a test user exists
            $user = User::firstOrCreate(
                ['email' => 'admin@example.com'],
                [
                    'name'     => 'Test Admin',
                    'password' => Hash::make('password'), // change in real env
                ]
            );

            // 2) Ensure an organization exists (name + unique slug)
            $baseName = 'Test Organization';
            $baseSlug = Str::slug($baseName);
            $slug = $baseSlug;
            $i = 1;
            while (Organization::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $i++;
            }

            $org = Organization::firstOrCreate(
                ['slug' => $slug],
                [
                    'name'        => $baseName,
                    'description' => 'Temporary organization for document uploads',
                ]
            );

            // 3) Attach the user as admin in the pivot (organization_user)
            $org->members()->syncWithoutDetaching([
                $user->id => ['role' => 'admin'],
            ]);

            $this->command->info("Seeded user: admin@example.com / password");
            $this->command->info("Seeded org:  {$org->name} (id: {$org->id}, slug: {$org->slug})");
            $this->command->info("Attached membership role: admin");
        });
    }
}
