<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Organization;

class AddUserToOrgSeeder extends Seeder
{
    /**
     * Add user ID 2 to a new organization
     */
    public function run(): void
    {
        DB::transaction(function () {
            $userId = 2;

            // Check if user exists
            $user = User::find($userId);
            if (!$user) {
                $this->command->error("User with ID {$userId} not found!");
                return;
            }

            // Create a new organization (avoiding ID 4)
            $baseName = 'Review System Organization';
            $baseSlug = Str::slug($baseName);
            $slug = $baseSlug;
            $i = 1;

            // Generate unique slug
            while (Organization::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $i++;
            }

            $org = Organization::create([
                'name'        => $baseName,
                'slug'        => $slug,
                'description' => 'Organization for document review system',
            ]);

            // Make sure we didn't accidentally get ID 4
            if ($org->id === 4) {
                $this->command->warn("Created org has ID 4, creating another one...");
                $org = Organization::create([
                    'name'        => $baseName . ' - Alternative',
                    'slug'        => $slug . '-alt',
                    'description' => 'Alternative organization for document review system',
                ]);
            }

            // Attach user as admin
            // $user->organizations()->syncWithoutDetaching([
            //     $org->id => ['role' => 'admin'],
            // ]);

            $org->members()->syncWithoutDetaching([
                $user->id => ['role' => 'admin'],
            ]);

            $this->command->info("✓ Created organization: {$org->name}");
            $this->command->info("  - ID: {$org->id}");
            $this->command->info("  - Slug: {$org->slug}");
            $this->command->info("✓ Added user '{$user->name}' (ID: {$userId}) as admin");
            $this->command->info("  - User email: {$user->email}");
        });
    }
}
