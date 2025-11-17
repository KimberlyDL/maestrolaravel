<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Member Management
            [
                'name' => 'view_members',
                'display_name' => 'View Members',
                'description' => 'View organization members list',
                'category' => 'members'
            ],
            [
                'name' => 'invite_members',
                'display_name' => 'Invite Members',
                'description' => 'Send invitations to new members',
                'category' => 'members'
            ],
            [
                'name' => 'remove_members',
                'display_name' => 'Remove Members',
                'description' => 'Remove members from organization',
                'category' => 'members'
            ],
            [
                'name' => 'manage_member_roles',
                'display_name' => 'Manage Member Roles',
                'description' => 'Change member roles and permissions',
                'category' => 'members'
            ],
            [
                'name' => 'approve_join_requests',
                'display_name' => 'Approve Join Requests',
                'description' => 'Approve or decline organization join requests',
                'category' => 'members'
            ],

            // Organization Settings
            [
                'name' => 'view_org_settings',
                'display_name' => 'View Organization Settings',
                'description' => 'View organization settings and configuration',
                'category' => 'organization'
            ],
            [
                'name' => 'edit_org_profile',
                'display_name' => 'Edit Organization Profile',
                'description' => 'Edit organization name, description, mission, vision',
                'category' => 'organization'
            ],
            [
                'name' => 'manage_org_settings',
                'display_name' => 'Manage Organization Settings',
                'description' => 'Change organization settings like auto-accept, public profile',
                'category' => 'organization'
            ],
            [
                'name' => 'manage_invite_codes',
                'display_name' => 'Manage Invite Codes',
                'description' => 'Generate and remove organization invite codes',
                'category' => 'organization'
            ],
            [
                'name' => 'upload_org_logo',
                'display_name' => 'Upload Organization Logo',
                'description' => 'Upload or change organization logo',
                'category' => 'organization'
            ],

            // Announcements
            [
                'name' => 'view_announcements',
                'display_name' => 'View Announcements',
                'description' => 'View organization announcements',
                'category' => 'announcements'
            ],
            [
                'name' => 'create_announcements',
                'display_name' => 'Create Announcements',
                'description' => 'Create new announcements for the organization',
                'category' => 'announcements'
            ],
            [
                'name' => 'edit_announcements',
                'display_name' => 'Edit Announcements',
                'description' => 'Edit existing announcements',
                'category' => 'announcements'
            ],
            [
                'name' => 'delete_announcements',
                'display_name' => 'Delete Announcements',
                'description' => 'Delete announcements',
                'category' => 'announcements'
            ],

            // Document Storage
            [
                'name' => 'view_storage',
                'display_name' => 'View Storage',
                'description' => 'View organization document storage',
                'category' => 'storage'
            ],
            [
                'name' => 'upload_documents',
                'display_name' => 'Upload Documents',
                'description' => 'Upload documents to organization storage',
                'category' => 'storage'
            ],
            [
                'name' => 'create_folders',
                'display_name' => 'Create Folders',
                'description' => 'Create folders in organization storage',
                'category' => 'storage'
            ],
            [
                'name' => 'delete_documents',
                'display_name' => 'Delete Documents',
                'description' => 'Delete documents and folders from storage',
                'category' => 'storage'
            ],
            [
                'name' => 'manage_document_sharing',
                'display_name' => 'Manage Document Sharing',
                'description' => 'Control document sharing and access permissions',
                'category' => 'storage'
            ],

            // Review System
            [
                'name' => 'view_reviews',
                'display_name' => 'View Reviews',
                'description' => 'View review requests in the organization',
                'category' => 'reviews'
            ],
            [
                'name' => 'create_reviews',
                'display_name' => 'Create Review Requests',
                'description' => 'Create new review requests',
                'category' => 'reviews'
            ],
            [
                'name' => 'manage_reviews',
                'display_name' => 'Manage Reviews',
                'description' => 'Edit, close, and reopen review requests',
                'category' => 'reviews'
            ],
            [
                'name' => 'assign_reviewers',
                'display_name' => 'Assign Reviewers',
                'description' => 'Assign reviewers to review requests',
                'category' => 'reviews'
            ],
            [
                'name' => 'comment_on_reviews',
                'display_name' => 'Comment on Reviews',
                'description' => 'Add comments to review requests',
                'category' => 'reviews'
            ],

            // Duty Management
            [
                'name' => 'view_duty_schedules',
                'display_name' => 'View Duty Schedules',
                'description' => 'View organization duty schedules',
                'category' => 'duty'
            ],
            [
                'name' => 'create_duty_schedules',
                'display_name' => 'Create Duty Schedules',
                'description' => 'Create new duty schedules',
                'category' => 'duty'
            ],
            [
                'name' => 'edit_duty_schedules',
                'display_name' => 'Edit Duty Schedules',
                'description' => 'Edit existing duty schedules',
                'category' => 'duty'
            ],
            [
                'name' => 'delete_duty_schedules',
                'display_name' => 'Delete Duty Schedules',
                'description' => 'Delete duty schedules',
                'category' => 'duty'
            ],
            [
                'name' => 'assign_duties',
                'display_name' => 'Assign Duties',
                'description' => 'Assign duties to organization members',
                'category' => 'duty'
            ],
            [
                'name' => 'approve_duty_swaps',
                'display_name' => 'Approve Duty Swaps',
                'description' => 'Approve or decline duty swap requests',
                'category' => 'duty'
            ],
            [
                'name' => 'manage_duty_templates',
                'display_name' => 'Manage Duty Templates',
                'description' => 'Create and manage duty schedule templates',
                'category' => 'duty'
            ],

            // Analytics & Reports
            [
                'name' => 'view_statistics',
                'display_name' => 'View Statistics',
                'description' => 'View organization statistics and analytics',
                'category' => 'analytics'
            ],
            [
                'name' => 'export_data',
                'display_name' => 'Export Data',
                'description' => 'Export organization data and reports',
                'category' => 'analytics'
            ],
            [
                'name' => 'view_activity_logs',
                'display_name' => 'View Activity Logs',
                'description' => 'View organization activity and audit logs',
                'category' => 'analytics'
            ],

            // Advanced Management
            [
                'name' => 'archive_organization',
                'display_name' => 'Archive Organization',
                'description' => 'Archive or restore the organization',
                'category' => 'advanced'
            ],
            [
                'name' => 'transfer_ownership',
                'display_name' => 'Transfer Ownership',
                'description' => 'Initiate organization ownership transfers',
                'category' => 'advanced'
            ],
            [
                'name' => 'manage_permissions',
                'display_name' => 'Manage Permissions',
                'description' => 'Grant and revoke permissions for other members',
                'category' => 'advanced'
            ],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                array_merge($permission, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
