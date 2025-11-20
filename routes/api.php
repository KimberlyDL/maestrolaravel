<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\VerifyEmailController;
use App\Http\Controllers\VerificationNotificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\OAuthExchangeController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\Review\DocumentController;
use App\Http\Controllers\Review\ReviewRequestController;
use App\Http\Controllers\Review\ReviewRecipientController;
use App\Http\Controllers\Review\ReviewCommentController;
use App\Http\Controllers\Review\OrganizationController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Org\OrgManagementController;
use App\Http\Controllers\Duty\DutyScheduleController;
use App\Http\Controllers\Duty\DutyAssignmentController;
use App\Http\Controllers\Duty\DutyAvailabilityController;
use App\Http\Controllers\Duty\DutySwapController;
use App\Http\Controllers\Duty\DutyTemplateController;
use App\Http\Controllers\DocumentShareController;
use App\Http\Controllers\StorageController;

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api'])->group(function () {

    /** =============================================================== */
    /** ==================== Permission Management ==================== */
    /** =============================================================== */

    // Permission routes - OUTSIDE org prefix to match /organizations/ path
    Route::prefix('organizations/{organization}')->group(function () {
        // Get all available permissions (any authenticated user)
        Route::get('/permissions', [PermissionController::class, 'index']);

        // Get all members with their permissions (requires manage_permissions)
        Route::get('/permissions/members', [PermissionController::class, 'memberPermissions'])
            ->middleware('org.permission:manage_permissions');

        // Get specific user's permissions (any member can check - for UI)
        Route::get('/permissions/users/{user}', [PermissionController::class, 'userPermissions'])
            ->middleware('org.member');

        // Grant/revoke (requires manage_permissions)
        Route::post('/permissions/users/{user}/grant', [PermissionController::class, 'grantPermission'])
            ->middleware('org.permission:manage_permissions');

        Route::post('/permissions/users/{user}/revoke', [PermissionController::class, 'revokePermission'])
            ->middleware('org.permission:manage_permissions');

        Route::post('/permissions/users/{user}/bulk', [PermissionController::class, 'bulkGrantPermissions'])
            ->middleware('org.permission:manage_permissions');
    });


    /** =============================================================== */
    /** ============= Document Storage (Google Drive-like) ============ */
    /** =============================================================== */

    // List documents/folders in organization storage
    Route::get('/storage', [StorageController::class, 'index'])
        ->middleware('org.permission:view_storage');

    // List public documents (any authenticated user)
    Route::get('/storage/public', [StorageController::class, 'publicIndex']);

    // Get storage statistics
    Route::get('/storage/statistics', [StorageController::class, 'statistics'])
        ->middleware('org.permission:view_statistics');

    // Create folder
    Route::post('/storage/folders', [StorageController::class, 'createFolder'])
        ->middleware('org.permission:create_folders');

    // Upload file
    Route::post('/storage/upload', [StorageController::class, 'upload'])
        ->middleware('org.permission:upload_documents');

    // Get document/folder details
    Route::get('/storage/documents/{document}', [StorageController::class, 'show'])
        ->middleware('org.permission:view_storage');

    // Update document/folder
    Route::patch('/storage/documents/{document}', [StorageController::class, 'update'])
        ->middleware('org.permission:upload_documents');

    // Delete document/folder
    Route::delete('/storage/documents/{document}', [StorageController::class, 'destroy'])
        ->middleware('org.permission:delete_documents');

    // Move document/folder
    Route::post('/storage/documents/{document}/move', [StorageController::class, 'move'])
        ->middleware('org.permission:upload_documents');

    // Copy document
    Route::post('/storage/documents/{document}/copy', [StorageController::class, 'copy'])
        ->middleware('org.permission:upload_documents');

    // /** Document Sharing */
    // Route::get('/storage/documents/{document}/share', [DocumentShareController::class, 'getShare'])
    //     ->middleware('org.permission:view_storage');

    // Route::patch('/storage/documents/{document}/share', [DocumentShareController::class, 'updateShare'])
    //     ->middleware('org.permission:manage_document_sharing');

    // Route::post('/storage/documents/{document}/share/revoke', [DocumentShareController::class, 'revokeShare'])
    //     ->middleware('org.permission:manage_document_sharing');


    /** Enhanced Document Sharing - Storage Context */
    Route::prefix('storage/documents/{document}')->group(function () {

        // Get share configuration
        // User needs: view_storage permission OR be document owner
        Route::get('/share', [DocumentShareController::class, 'getShare'])
            ->middleware('org.permission:view_storage');

        // Update share settings (password, expiry, download limits, IP restrictions)
        // User needs: manage_document_sharing permission OR be document owner
        Route::patch('/share', [DocumentShareController::class, 'updateShare'])
            ->middleware('org.permission:manage_document_sharing');

        // Revoke share link
        // User needs: manage_document_sharing permission OR be document owner
        Route::post('/share/revoke', [DocumentShareController::class, 'revokeShare'])
            ->middleware('org.permission:manage_document_sharing');

        // Get share statistics (views, downloads, etc.)
        // User needs: view_storage permission OR be document owner
        Route::get('/share/stats', [DocumentShareController::class, 'getShareStats'])
            ->middleware('org.permission:view_storage');

        // Get access logs for this share
        // User needs: view_storage permission OR be document owner
        Route::get('/share/logs', [DocumentShareController::class, 'getAccessLogs'])
            ->middleware('org.permission:view_storage');
    });

    // Maintain backward compatibility with document sharing (review context)
    Route::prefix('documents/{document}')->group(function () {
        Route::get('/share', [DocumentShareController::class, 'getShare']);
        Route::patch('/share', [DocumentShareController::class, 'updateShare']);
        Route::post('/share/revoke', [DocumentShareController::class, 'revokeShare']);
        Route::get('/share/stats', [DocumentShareController::class, 'getShareStats']);
        Route::get('/share/logs', [DocumentShareController::class, 'getAccessLogs']);
    });



    /** Document Versions */
    Route::post('/storage/documents/{document}/versions', [DocumentController::class, 'addVersion'])
        ->middleware('org.permission:upload_documents');

    // Route::get('/storage/documents/{document}/versions/{version}/download', [DocumentController::class, 'downloadVersion'])
    //     ->middleware('org.permission:view_storage');


    // Download specific document version (authenticated users only)
    Route::get(
        '/storage/documents/{document}/versions/{version}/download',
        [DocumentController::class, 'downloadVersion']
    )
        ->middleware('org.permission:view_storage');

    Route::get(
        '/documents/{document}/versions/{version}/download',
        [DocumentController::class, 'downloadVersion']
    );




    /** =============================================================== */
    /** ---------------- Legacy/Review Document Routes ---------------- */
    /** =============================================================== */

    // Get document details with all versions
    Route::get('/documents/{document}', [DocumentController::class, 'show']);

    // Create new document
    Route::post('/documents', [DocumentController::class, 'store'])
        ->middleware('org.permission:create_reviews');

    // Add new version to existing document
    Route::post('/documents/{document}/versions', [DocumentController::class, 'addVersion'])
        ->middleware('org.permission:create_reviews');

    // Download specific document version
    Route::get('/documents/{document}/versions/{version}/download', [DocumentController::class, 'downloadVersion']);

    // List documents in organization
    Route::get('/org-documents', [DocumentController::class, 'index'])
        ->middleware('org.permission:view_reviews');

    /** Share/Access Control */
    Route::get('/documents/{document}/share', [DocumentShareController::class, 'getShare']);
    Route::patch('/documents/{document}/share', [DocumentShareController::class, 'updateShare']);
    Route::post('/documents/{document}/share/revoke', [DocumentShareController::class, 'revokeShare']);

    /** =============================================================== */
    /** ------------------ Review Requests (Threads) ------------------ */
    /** =============================================================== */

    // List with filters
    Route::get('/reviews', [ReviewRequestController::class, 'index'])
        ->middleware('org.permission:view_reviews');

    // Create a thread
    Route::post('/reviews', [ReviewRequestController::class, 'store'])
        ->middleware('org.permission:create_reviews');

    // Read a thread
    Route::get('/reviews/{review}', [ReviewRequestController::class, 'show'])
        ->middleware('org.permission:view_reviews');

    // Update thread metadata
    Route::patch('/reviews/{review}', [ReviewRequestController::class, 'update'])
        ->middleware('org.permission:manage_reviews');

    // State transitions
    Route::post('/reviews/{review}/send', [ReviewRequestController::class, 'send'])
        ->middleware('org.permission:manage_reviews');

    Route::post('/reviews/{review}/close', [ReviewRequestController::class, 'close'])
        ->middleware('org.permission:manage_reviews');

    Route::post('/reviews/{review}/reopen', [ReviewRequestController::class, 'reopen'])
        ->middleware('org.permission:manage_reviews');

    Route::post('/reviews/{review}/request-changes', [ReviewRequestController::class, 'requestChanges'])
        ->middleware('org.permission:manage_reviews');

    // Attach new version
    Route::post('/reviews/{review}/versions', [ReviewRequestController::class, 'attachNewVersion'])
        ->middleware('org.permission:manage_reviews');

    /** =============================================================== */
    /** -------------------- Per-Recipient Actions -------------------- */
    /** =============================================================== */

    // Remove recipient
    Route::delete('/reviews/{review}/recipients/{recipient}', [ReviewRequestController::class, 'removeRecipient'])
        ->middleware('org.permission:assign_reviewers');

    // Update recipient
    Route::patch('/reviews/{review}/recipients/{recipient}', [ReviewRecipientController::class, 'update'])
        ->middleware('org.permission:assign_reviewers');

    // Remind recipient
    Route::post('/reviews/{review}/recipients/{recipient}/remind', [ReviewRecipientController::class, 'remind'])
        ->middleware('org.permission:manage_reviews');

    // Approve/Decline (reviewers can do this)
    Route::post('/reviews/{review}/recipients/{recipient}/approve', [ReviewRecipientController::class, 'approve'])
        ->middleware('org.permission:comment_on_reviews');

    Route::post('/reviews/{review}/recipients/{recipient}/decline', [ReviewRecipientController::class, 'decline'])
        ->middleware('org.permission:comment_on_reviews');

    Route::patch('/reviews/{review}/recipients/{recipient}/view', [ReviewRecipientController::class, 'markViewed'])
        ->middleware('org.permission:view_reviews');

    /** =============================================================== */
    /** --------------------- Comments (threaded) --------------------- */
    /** =============================================================== */

    Route::get('/reviews/{review}/comments', [ReviewCommentController::class, 'index'])
        ->middleware('org.permission:view_reviews');

    Route::post('/reviews/{review}/comments', [ReviewCommentController::class, 'store'])
        ->middleware('org.permission:comment_on_reviews');

    Route::get('/reviews/{review}/recipients/{recipient}/comments', [ReviewCommentController::class, 'recipientComments'])
        ->middleware('org.permission:view_reviews');

    /** =============================================================== */
    /** --------------------------- Actions --------------------------- */
    /** =============================================================== */

    Route::get('/reviews/{review}/actions', [ReviewRequestController::class, 'getActivityLog'])
        ->middleware('org.permission:view_activity_logs');

    /** =============================================================== */
    /** ======================== Organizations ======================== */
    /** =============================================================== */

    // List organizations (my orgs or others)
    Route::get('/organizations', [OrganizationController::class, 'index']);

    // Create organization (any authenticated user)
    Route::post('/organizations', [OrganizationController::class, 'store']);

    // Join requests (any authenticated user)
    Route::post('/organizations/join/request', [OrganizationController::class, 'joinRequest']);
    Route::post('/organizations/join/invite', [OrganizationController::class, 'joinViaInvite']);
    Route::get('/organizations/my-requests', [OrganizationController::class, 'myRequests']);
    Route::delete('/organizations/requests/{requestId}', [OrganizationController::class, 'cancelRequest']);

    // View organization (public or member)
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);

    // View members (requires permission)
    Route::get('/organizations/{organization}/members', [OrganizationController::class, 'members'])
        ->middleware('org.permission:view_members');

    // Add member (admin only)
    Route::post('/organizations/{organization}/members', [OrganizationController::class, 'addMember'])
        ->middleware('org.admin');

    // Remove member (requires permission)
    Route::delete('/organizations/{organization}/members/{user}', [OrganizationController::class, 'removeMember'])
        ->middleware('org.permission:remove_members');

    // Update member role (requires permission)
    Route::patch('/organizations/{organization}/members/{user}/role', [OrganizationController::class, 'updateMemberRole'])
        ->middleware('org.permission:manage_member_roles');

    // Invite code management (requires permission)
    Route::post('/organizations/{organization}/generate-invite', [OrganizationController::class, 'generateInviteCode'])
        ->middleware('org.permission:manage_invite_codes');

    Route::delete('/organizations/{organization}/remove-invite', [OrganizationController::class, 'removeInviteCode'])
        ->middleware('org.permission:manage_invite_codes');

    /** =============================================================== */
    /** ---------- Organization Management (Admin Dashboard) ---------- */
    /** =============================================================== */

    Route::prefix('org/{organization}')->group(function () {

        // Get all members with their permissions (requires manage_permissions)
        Route::get('/permissions/members', [PermissionController::class, 'memberPermissions'])
            ->middleware('org.permission:manage_permissions');

        // Get specific user's permissions (any member can check - for UI)
        Route::get('/permissions/users/{user}', [PermissionController::class, 'userPermissions'])
            ->middleware('org.member'); // ✅ Changed to org.member

        // Grant/revoke (requires manage_permissions)
        Route::post('/permissions/users/{user}/grant', [PermissionController::class, 'grantPermission'])
            ->middleware('org.permission:manage_permissions');

        Route::post('/permissions/users/{user}/revoke', [PermissionController::class, 'revokePermission'])
            ->middleware('org.permission:manage_permissions');

        Route::post('/permissions/users/{user}/bulk', [PermissionController::class, 'bulkGrantPermissions'])
            ->middleware('org.permission:manage_permissions');

        // Dashboard (any member)
        Route::get('/dashboard', [OrgManagementController::class, 'dashboard'])
            ->middleware('org.member');

        // Overview (requires permission to view)
        Route::get('/overview', [OrgManagementController::class, 'overview'])
            // ->middleware('org.permission:view_org_settings');
            ->middleware('org.member');

        Route::patch('/overview', [OrgManagementController::class, 'updateOverview'])
            ->middleware('org.permission:edit_org_profile');

        // Settings Management
        Route::patch('/settings', [OrgManagementController::class, 'updateSettings'])
            ->middleware('org.permission:manage_org_settings');

        // Logo Management
        Route::post('/logo', [OrgManagementController::class, 'uploadLogo'])
            ->middleware('org.permission:upload_org_logo');

        Route::delete('/logo', [OrgManagementController::class, 'deleteLogo'])
            ->middleware('org.permission:upload_org_logo');

        // Members Management
        Route::get('/members', [OrgManagementController::class, 'members'])
            // ->middleware('org.permission:view_members');
            ->middleware('org.member');

        Route::get('/members/{user}', [OrgManagementController::class, 'showMember'])
            ->middleware('org.member');

        Route::patch('/members/{user}/role', [OrgManagementController::class, 'updateMemberRole'])
            ->middleware('org.permission:manage_member_roles');

        Route::delete('/members/{user}', [OrgManagementController::class, 'removeMember'])
            ->middleware('org.permission:remove_members');

        // Join Requests
        Route::get('/join-requests', [OrgManagementController::class, 'joinRequests'])
            ->middleware('org.permission:approve_join_requests');

        Route::post('/join-requests/{requestId}/approve', [OrgManagementController::class, 'approveRequest'])
            ->middleware('org.permission:approve_join_requests');

        Route::post('/join-requests/{requestId}/decline', [OrgManagementController::class, 'declineRequest'])
            ->middleware('org.permission:approve_join_requests');

        // Invite Management
        Route::post('/generate-invite', [OrgManagementController::class, 'generateInviteCode'])
            ->middleware('org.permission:manage_invite_codes');

        Route::delete('/remove-invite', [OrgManagementController::class, 'removeInviteCode'])
            ->middleware('org.permission:manage_invite_codes');

        // Announcements
        Route::get('/announcements', [OrgManagementController::class, 'announcements'])
            // ->middleware('org.permission:view_announcements');
            ->middleware('org.member');

        Route::post('/announcements', [OrgManagementController::class, 'createAnnouncement'])
            ->middleware('org.permission:create_announcements');

        Route::patch('/announcements/{announcementId}', [OrgManagementController::class, 'updateAnnouncement'])
            ->middleware('org.permission:edit_announcements');

        Route::delete('/announcements/{announcementId}', [OrgManagementController::class, 'deleteAnnouncement'])
            ->middleware('org.permission:delete_announcements');

        // Statistics & Activity
        Route::get('/statistics', [OrgManagementController::class, 'statistics'])
            ->middleware('org.permission:view_statistics');

        Route::get('/activity-log', [OrgManagementController::class, 'activityLog'])
            ->middleware('org.permission:view_activity_logs');

        // Data Export
        Route::get('/export-data', [OrgManagementController::class, 'exportData'])
            ->middleware('org.permission:export_data');

        // Archive/Restore
        Route::post('/archive', [OrgManagementController::class, 'archiveOrganization'])
            ->middleware('org.permission:archive_organization');

        Route::post('/restore', [OrgManagementController::class, 'restoreOrganization'])
            ->middleware('org.permission:archive_organization');

        // Ownership Transfer
        Route::post('/transfer-ownership', [OrgManagementController::class, 'initiateOwnershipTransfer'])
            ->middleware('org.permission:transfer_ownership');

        Route::post('/transfer-ownership/{transferId}/accept', [OrgManagementController::class, 'acceptOwnershipTransfer'])
            ->middleware('org.member');

        Route::post('/transfer-ownership/{transferId}/decline', [OrgManagementController::class, 'declineOwnershipTransfer'])
            ->middleware('org.member');

        // Leave Organization (any member)
        Route::post('/leave', [OrgManagementController::class, 'leave'])
            ->middleware('org.member');

        /** =========================================================== */
        /** ==================== Duty Management ====================== */
        /** =========================================================== */

        // My assignments (any member)
        Route::get('/duty-assignments/me', [DutyAssignmentController::class, 'myAssignments'])
            ->middleware('org.member');

        // Duty Schedules
        Route::get('/duty-schedules', [DutyScheduleController::class, 'index'])
            ->middleware('org.permission:view_duty_schedules');

        Route::post('/duty-schedules', [DutyScheduleController::class, 'store'])
            ->middleware('org.permission:create_duty_schedules');

        Route::get('/duty-schedules/calendar', [DutyScheduleController::class, 'calendar'])
            ->middleware('org.permission:view_duty_schedules');

        Route::get('/duty-schedules/statistics', [DutyScheduleController::class, 'statistics'])
            ->middleware('org.permission:view_statistics');

        Route::get('/duty-schedules/my-statistics', [DutyScheduleController::class, 'memberStatistics'])
            ->middleware('org.member');

        Route::get('/duty-schedules/{dutySchedule}', [DutyScheduleController::class, 'show'])
            ->middleware('org.permission:view_duty_schedules');

        Route::patch('/duty-schedules/{dutySchedule}', [DutyScheduleController::class, 'update'])
            ->middleware('org.permission:edit_duty_schedules');

        Route::delete('/duty-schedules/{dutySchedule}', [DutyScheduleController::class, 'destroy'])
            ->middleware('org.permission:delete_duty_schedules');

        Route::post('/duty-schedules/{dutySchedule}/duplicate', [DutyScheduleController::class, 'duplicate'])
            ->middleware('org.permission:create_duty_schedules');

        // Assignments
        Route::post('/duty-schedules/{dutySchedule}/assignments', [DutyAssignmentController::class, 'store'])
            ->middleware('org.permission:assign_duties');

        Route::patch('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}', [DutyAssignmentController::class, 'update'])
            ->middleware('org.permission:assign_duties');

        Route::delete('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}', [DutyAssignmentController::class, 'destroy'])
            ->middleware('org.permission:assign_duties');

        // Member self-service (any member)
        Route::post('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}/respond', [DutyAssignmentController::class, 'respond'])
            ->middleware('org.member');

        Route::post('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}/check-in', [DutyAssignmentController::class, 'checkIn'])
            ->middleware('org.member');

        Route::post('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}/check-out', [DutyAssignmentController::class, 'checkOut'])
            ->middleware('org.member');

        // Availability (any member)
        Route::get('/duty-availability', [DutyAvailabilityController::class, 'index'])
            ->middleware('org.member');

        Route::post('/duty-availability', [DutyAvailabilityController::class, 'store'])
            ->middleware('org.member');

        Route::patch('/duty-availability/{dutyAvailability}', [DutyAvailabilityController::class, 'update'])
            ->middleware('org.member');

        Route::delete('/duty-availability/{dutyAvailability}', [DutyAvailabilityController::class, 'destroy'])
            ->middleware('org.member');

        // Swap Requests
        Route::get('/duty-swaps', [DutySwapController::class, 'index'])
            ->middleware('org.permission:view_duty_schedules');

        Route::post('/duty-assignments/{dutyAssignment}/swap', [DutySwapController::class, 'store'])
            ->middleware('org.member');

        Route::post('/duty-swaps/{swapRequest}/accept', [DutySwapController::class, 'accept'])
            ->middleware('org.member');

        Route::post('/duty-swaps/{swapRequest}/decline', [DutySwapController::class, 'decline'])
            ->middleware('org.member');

        Route::post('/duty-swaps/{swapRequest}/cancel', [DutySwapController::class, 'cancel'])
            ->middleware('org.member');

        Route::post('/duty-swaps/{swapRequest}/review', [DutySwapController::class, 'review'])
            ->middleware('org.permission:approve_duty_swaps');

        // Templates
        Route::get('/duty-templates', [DutyTemplateController::class, 'index'])
            ->middleware('org.permission:view_duty_schedules');

        Route::post('/duty-templates', [DutyTemplateController::class, 'store'])
            ->middleware('org.permission:manage_duty_templates');

        Route::patch('/duty-templates/{dutyTemplate}', [DutyTemplateController::class, 'update'])
            ->middleware('org.permission:manage_duty_templates');

        Route::delete('/duty-templates/{dutyTemplate}', [DutyTemplateController::class, 'destroy'])
            ->middleware('org.permission:manage_duty_templates');



        /** =============================================================== */
        /** ============= Document Storage (Google Drive-like) ============ */
        /** =============================================================== */

        // Storage Access (Index, Stats) - Uses the {organization} parameter
        Route::get('/storage', [StorageController::class, 'index'])
            ->middleware('org.permission:view_storage');

        Route::get('/storage/statistics', [StorageController::class, 'statistics'])
            ->middleware('org.permission:view_statistics');

        // Storage Management (Create, Upload)
        Route::post('/storage/folders', [StorageController::class, 'createFolder'])
            ->middleware('org.permission:create_folders');

        Route::post('/storage/upload', [StorageController::class, 'upload'])
            ->middleware('org.permission:upload_documents');

        // Single Document Operations
        Route::get('/storage/documents/{document}', [StorageController::class, 'show'])
            ->middleware('org.permission:view_storage');

        Route::patch('/storage/documents/{document}', [StorageController::class, 'update'])
            ->middleware('org.permission:upload_documents');

        Route::delete('/storage/documents/{document}', [StorageController::class, 'destroy'])
            ->middleware('org.permission:delete_documents');

        Route::post('/storage/documents/{document}/move', [StorageController::class, 'move'])
            ->middleware('org.permission:upload_documents');

        Route::post('/storage/documents/{document}/copy', [StorageController::class, 'copy'])
            ->middleware('org.permission:upload_documents');

        Route::post('/storage/documents/{document}/versions', [DocumentController::class, 'addVersion'])
            ->middleware('org.permission:upload_documents');

        Route::get(
            '/storage/documents/{document}/versions/{version}/download',
            [DocumentController::class, 'downloadVersion']
        )
            ->middleware('org.permission:view_storage');
    });

    /** =============================================================== */
    /** ------------ Global Announcements (Authenticated) ------------ */
    /** =============================================================== */

    // Paginated feed for infinite scroll (NEW - preferred endpoint)
    Route::get('/announcements/feed', [AnnouncementController::class, 'feed'])
        ->middleware('auth:api');

    // Legacy endpoint (maintained for compatibility)
    Route::get('/announcements', [AnnouncementController::class, 'index'])
        ->middleware('auth:api');

    // Create announcement (admin only)
    Route::post('/announcements', [AnnouncementController::class, 'store'])
        ->middleware(['auth:api', 'org.permission:create_announcements']);

    // Update announcement (admin only)
    Route::patch('/announcements/{id}', [AnnouncementController::class, 'update'])
        ->middleware(['auth:api', 'org.permission:edit_announcements']);

    // Delete announcement (admin only)
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy'])
        ->middleware(['auth:api', 'org.permission:delete_announcements']);

    /** =============================================================== */
    /** ======================== Me Endpoints ========================= */
    /** =============================================================== */

    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::put('/me/profile', [ProfileController::class, 'update']);
    Route::post('/me/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::post('/me/password', [ProfileController::class, 'changePassword']);
});

/*
|--------------------------------------------------------------------------
| Public Routes (No Authentication Required)
|--------------------------------------------------------------------------
*/
Route::prefix('share')->group(function () {

    /**
     * GET /share/{token}
     * Get shared document metadata (public access with security checks)
     * 
     * Security checks performed:
     * - Token validity
     * - Expiry date
     * - IP whitelist (if configured)
     * - Password (if configured)
     * - Download limits
     * 
     * Query Parameters:
     * - password: Required if share has password protection
     * 
     * Example: /api/share/abc123def456?password=myPassword
     */
    Route::get('/{token}', [DocumentShareController::class, 'getPublicDocument'])
        ->name('documents.public-access');

    /**
     * GET /share/{token}/download
     * Download shared document (public access with security checks)
     * 
     * Same security checks as getPublicDocument()
     * Increments download counter if download limit is set
     * Logs the download attempt
     * 
     * Returns file with correct MIME type and filename
     * 
     * Query Parameters:
     * - password: Required if share has password protection
     * 
     * Example: /api/share/abc123def456/download?password=myPassword
     */
    Route::get('/{token}/download', [DocumentShareController::class, 'downloadPublicDocument'])
        ->name('documents.public-download');
});


// Legacy routes for backward compatibility
Route::get('/storage/public/{token}', [DocumentShareController::class, 'getPublicDocument']);
Route::get('/storage/public/{token}/download', [DocumentShareController::class, 'downloadPublicDocument']);
Route::get('/documents/public/{token}', [DocumentShareController::class, 'getPublicDocument']);
Route::get('/documents/public/{token}/download', [DocumentShareController::class, 'downloadPublicDocument']);

// Auth endpoints
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:12,1');
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
Route::post('/reset-password', [PasswordResetController::class, 'reset']);
Route::post('/email/verification-notification', [VerificationNotificationController::class, 'store'])
    ->middleware('throttle:6,1');
Route::get('/email/verify/{id}/{hash}', [VerifyEmailController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

// Google OAuth
Route::prefix('auth')->group(function () {
    Route::get('/google/redirect', [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
});

Route::post('/oauth/exchange', [OAuthExchangeController::class, 'exchange']);

// Misc
Route::post('/upload', [UploadController::class, 'upload']);
