<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Organization;

class AddUser3ToOrg4Seeder extends Seeder
{
    /**
     * Add user ID 3 as a member of organization ID 4
     */
    public function run(): void
    {
        DB::transaction(function () {
            $userId = 3;
            $orgId  = 4;

            // Check if user exists
            $user = User::find($userId);
            if (!$user) {
                $this->command->error("❌ User with ID {$userId} not found!");
                return;
            }

            // Check if organization exists
            $org = Organization::find($orgId);
            if (!$org) {
                $this->command->error("❌ Organization with ID {$orgId} not found!");
                return;
            }

            // Attach user as member (non-admin)
            $org->members()->syncWithoutDetaching([
                $user->id => ['role' => 'member'],
            ]);

            $this->command->info("✓ Successfully added user '{$user->name}' (ID: {$user->id})");
            $this->command->info("  → to organization '{$org->name}' (ID: {$org->id}) as MEMBER");
            $this->command->info("  → Email: {$user->email}");
        });
    }
}
