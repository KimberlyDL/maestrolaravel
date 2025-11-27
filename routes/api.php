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


    /** Download specific document version (authenticated) */
    Route::get(
        '/storage/documents/{document}/versions/{version}/download',
        [DocumentController::class, 'downloadVersion']
    )->middleware('org.permission:view_storage');

    Route::get(
        '/documents/{document}/versions/{version}/download',
        [DocumentController::class, 'downloadVersion']
    );


    /** =============================================================== */
    /** ---------------- Document Download (New Method) --------------- */
    /** =============================================================== */

    // Get temporary signed URL for download
    Route::get(
        '/documents/{document}/versions/{version}/download-url',
        [DocumentController::class, 'getDownloadUrl']
    );

    // Secure download endpoint for local storage (fallback)
    Route::get(
        '/documents/{document}/versions/{version}/secure/{token}',
        [DocumentController::class, 'secureDownload']
    )->name('documents.secure-download');

    // Storage context routes (organization-scoped)
    Route::get(
        '/org/{organization}/storage/documents/{document}/versions/{version}/download-url',
        [DocumentController::class, 'getDownloadUrl']
    )->middleware('org.permission:view_storage');

    Route::get(
        '/org/{organization}/storage/documents/{document}/versions/{version}/secure/{token}',
        [DocumentController::class, 'secureDownload']
    )->middleware('org.permission:view_storage');

    #endregion

    /** =============================================================== */
    /** ---------------- Legacy/Review Document Routes ---------------- */
    /** =============================================================== */

    #region Review

    // Get document details with all versions
    Route::get('/documents/{document}', [DocumentController::class, 'show']);

    // Add new version to existing document
    Route::post('/documents/{document}/versions', [DocumentController::class, 'addVersion'])
        ->middleware('org.permission:create_reviews');

    // Download specific document version
    Route::get('/documents/{document}/versions/{version}/download', [DocumentController::class, 'downloadVersion']);

    // ✅ FIXED: List documents in organization (now uses org-scoped route)
    Route::get('/org/{organization}/documents', [DocumentController::class, 'index'])
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

    // // Create a thread
    // Route::post('/reviews', [ReviewRequestController::class, 'store'])
    //     ->middleware('org.permission:create_reviews');

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

    Route::post('/reviews/{review}/comments', [ReviewCommentController::class, 'store'])
        ->middleware('org.permission:comment_on_reviews');

    Route::get('/reviews/{review}/actions', [ReviewRequestController::class, 'getActivityLog'])
        ->middleware('org.permission:view_activity_logs');





    // Global review access (not org-scoped) - for cross-org viewing
    Route::get('/reviews/{review}', [ReviewRequestController::class, 'showGlobal'])
        ->middleware('auth:api');

    Route::get(
        '/reviews/{review}/recipients/{recipient}/comments',
        [ReviewCommentController::class, 'recipientCommentsGlobal']
    )
        ->middleware('auth:api');

    Route::post(
        '/reviews/{review}/recipients/{recipient}/comments',
        [ReviewCommentController::class, 'storeRecipientCommentGlobal']
    )
        ->middleware('auth:api');
    
    #endregion


    /** =============================================================== */
    /** ======================== Organizations ======================== */
    /** =============================================================== */

    #region Org Management
    // List organizations (my orgs or others)
    // @FE ReviewUpload
    Route::get('/organizations', [OrganizationController::class, 'index']);

    // Create organization (any authenticated user)
    Route::post('/organizations', [OrganizationController::class, 'store']);

    // Join requests (any authenticated user)
    Route::post('/organizations/join/request', [OrganizationController::class, 'joinRequest']);
    Route::post('/organizations/join/invite', [OrganizationController::class, 'joinViaInvite']);
    Route::get('/organizations/my-requests', [OrganizationController::class, 'myRequests']);
    Route::delete('/organizations/requests/{requestId}', [OrganizationController::class, 'cancelRequest']);

    // View organization (public or member)
    // gamit din ni doc review docfile uplaod 
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);

    // View members (requires permission)
    // @FE ReviewUpload
    Route::get('/organizations/{organization}/members', [OrganizationController::class, 'members'])
        // ->middleware('org.permission:view_members')
    ;

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

    #endregion
    /** =============================================================== */
    /** ---------- Organization Management (Admin Dashboard) ---------- */
    /** =============================================================== */

    Route::prefix('org/{organization}')->group(function () {

        /** =============================================================== */
        /** ==================== Permission Management ==================== */
        /** =============================================================== */
        #region Permissions


        // Get all available permissions (add this route)
        Route::get('/permissions', [PermissionController::class, 'index'])
            ->middleware('org.member');

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
        #endregion


        /** =============================================================== */
        /** ---------- DOC REVIEW FEATURE (Admin Dashboard) ---------- */
        /** =============================================================== */

        #region Doc Review

        // // Upload new version to review
        // Route::post('/reviews/{review}/versions', [ReviewRequestController::class, 'attachNewVersion'])
        //     ->middleware('org.permission:manage_reviews');


        // // Create a thread
        // // @FE ReviewUpload
        // Route::post('/reviews', [ReviewRequestController::class, 'store'])
        //     ->middleware('org.permission:create_reviews');

        // Route::get('/reviews', [ReviewRequestController::class, 'index'])
        //     ->middleware('org.permission:view_reviews');

        // Route::get('/reviews/{review}', [ReviewRequestController::class, 'show'])
        //     ->middleware('org.permission:view_reviews');

        // Route::get('/reviews/{review}/recipients/{recipient}/comments', [ReviewCommentController::class, 'recipientComments'])
        //     ->middleware('org.permission:view_reviews');

        // Route::get('/reviews/{review}/actions', [ReviewRequestController::class, 'getActivityLog'])
        //     ->middleware('org.permission:view_activity_logs');

        // Route::post('/reviews/{review}/recipients/{recipient}/comments', [ReviewCommentController::class, 'storeRecipientComment'])
        //     ->middleware('org.permission:comment_on_reviews');

        // Route::get('/reviews/{review}/comments', [ReviewCommentController::class, 'index'])
        //     ->middleware('org.permission:view_reviews');




        /** =============================================================== */
        /** ----------------- REVIEWER / INCOMING ROUTES ------------------ */
        /** =============================================================== */

        // List reviews sent TO this organization (Inbox)
        Route::get('/incoming-reviews', [ReviewRequestController::class, 'indexIncoming'])
            ->middleware('org.member'); // Any member of this org can try to access

        // Show a specific incoming review
        Route::get('/incoming-reviews/{review}', [ReviewRequestController::class, 'showIncoming'])
            ->middleware('org.member');

        // Actions on incoming reviews (Approve/Decline)
        // Note: We use the existing ReviewRecipientController but accessed via Org Scope
        Route::patch('/incoming-reviews/{review}/recipients/{recipient}/view', [ReviewRecipientController::class, 'markViewed'])
            ->middleware('org.member');

        Route::post('/incoming-reviews/{review}/recipients/{recipient}/approve', [ReviewRecipientController::class, 'approve'])
            ->middleware('org.member');

        Route::post('/incoming-reviews/{review}/recipients/{recipient}/decline', [ReviewRecipientController::class, 'decline'])
            ->middleware('org.member');

        // Chat for incoming reviews
        Route::get('/incoming-reviews/{review}/recipients/{recipient}/comments', [ReviewCommentController::class, 'recipientComments'])
            ->middleware('org.member');

        Route::post('/incoming-reviews/{review}/recipients/{recipient}/comments', [ReviewCommentController::class, 'storeRecipientComment'])
            ->middleware('org.member');






        // @FE ReviewUpload
        Route::post('/documents', [DocumentController::class, 'store'])
            ->middleware('org.permission:create_reviews');

        // Review listing with filters
        Route::get('/reviews', [ReviewRequestController::class, 'index'])
            ->middleware('org.member'); // All members can list (filtered by role)

        Route::get('/reviews/{review}', [ReviewRequestController::class, 'show'])
            ->middleware('org.member');

        // Create review
        // @FE ReviewUpload
        Route::post('/reviews', [ReviewRequestController::class, 'store'])
            ->middleware('org.permission:create_reviews');

        // Update review details (submitter or admin)
        Route::patch('/reviews/{review}/details', [ReviewRequestController::class, 'updateDetails'])
            ->middleware('org.member');

        // Update recipient due date (submitter or admin)
        Route::patch('/reviews/{review}/recipients/{recipient}/due', [ReviewRequestController::class, 'updateRecipientDue'])
            ->middleware('org.member');

        // Remind reviewer (submitter or admin)
        Route::post('/reviews/{review}/recipients/{recipient}/remind', [ReviewRequestController::class, 'remindReviewer'])
            ->middleware('org.member');

        // ADMIN ONLY: Approve/Reject submissions
        Route::post('/reviews/{review}/approve', [ReviewRequestController::class, 'approve'])
            ->middleware('org.admin'); // Only admins

        Route::post('/reviews/{review}/reject', [ReviewRequestController::class, 'reject'])
            ->middleware('org.admin'); // Only admins

        // Other review actions
        Route::post('/reviews/{review}/close', [ReviewRequestController::class, 'close'])
            ->middleware('org.permission:manage_reviews');

        Route::post('/reviews/{review}/reopen', [ReviewRequestController::class, 'reopen'])
            ->middleware('org.permission:manage_reviews');

        Route::post('/reviews/{review}/versions', [ReviewRequestController::class, 'attachNewVersion'])
            ->middleware('org.permission:manage_reviews');

        // Comments and activity
        Route::get('/reviews/{review}/comments', [ReviewCommentController::class, 'index'])
            ->middleware('org.member');

        Route::get('/reviews/{review}/recipients/{recipient}/comments', [ReviewCommentController::class, 'recipientComments'])
            ->middleware('org.member');

        Route::post('/reviews/{review}/recipients/{recipient}/comments', [ReviewCommentController::class, 'storeRecipientComment'])
            ->middleware('org.member');

        Route::get('/reviews/{review}/actions', [ReviewRequestController::class, 'getActivityLog'])
            ->middleware('org.permission:view_activity_logs');

        #endregion



        #region Dashboard

        // Dashboard (any member)
        Route::get('/dashboard', [OrgManagementController::class, 'dashboard'])
            ->middleware('org.member');

        // ===== OVERVIEW - All members can access =====
        Route::get('/overview', [OrgManagementController::class, 'overview'])
            ->middleware('org.member'); // Changed from org.permission

        Route::patch('/overview', [OrgManagementController::class, 'updateOverview'])
            ->middleware('org.permission:edit_org_profile');

        // ===== ANNOUNCEMENTS - All members can view =====
        Route::get('/announcements', [OrgManagementController::class, 'announcements'])
            ->middleware('org.member'); // Changed to allow all members

        Route::post('/announcements', [OrgManagementController::class, 'createAnnouncement'])
            ->middleware('org.permission:create_announcements');

        Route::patch('/announcements/{announcementId}', [OrgManagementController::class, 'updateAnnouncement'])
            ->middleware('org.permission:edit_announcements');

        Route::delete('/announcements/{announcementId}', [OrgManagementController::class, 'deleteAnnouncement'])
            ->middleware('org.permission:delete_announcements');


        #endregion
        #region Members
        // ===== MEMBERS - All members can view =====
        Route::get('/members', [OrgManagementController::class, 'members'])
            ->middleware('org.member'); // Changed to allow all members

        Route::get('/members/{user}', [OrgManagementController::class, 'showMember'])
            ->middleware('org.member');

        // Member management requires permissions
        Route::patch('/members/{user}/role', [OrgManagementController::class, 'updateMemberRole'])
            ->middleware('org.permission:manage_member_roles');

        Route::delete('/members/{user}', [OrgManagementController::class, 'removeMember'])
            ->middleware('org.permission:remove_members');

        #endregion

        #region Settings Endpoint

        // ===== SETTINGS - Requires permissions =====
        Route::patch('/settings', [OrgManagementController::class, 'updateSettings'])
            ->middleware('org.permission:manage_org_settings');

        // Logo Management
        Route::post('/logo', [OrgManagementController::class, 'uploadLogo'])
            ->middleware('org.permission:upload_org_logo');

        Route::delete('/logo', [OrgManagementController::class, 'deleteLogo'])
            ->middleware('org.permission:upload_org_logo');



        // ===== JOIN REQUESTS - Requires permissions =====
        Route::get('/join-requests', [OrgManagementController::class, 'joinRequests'])
            ->middleware('org.permission:approve_join_requests');

        Route::post('/join-requests/{requestId}/approve', [OrgManagementController::class, 'approveRequest'])
            ->middleware('org.permission:approve_join_requests');

        Route::post('/join-requests/{requestId}/decline', [OrgManagementController::class, 'declineRequest'])
            ->middleware('org.permission:approve_join_requests');


        // ===== INVITE CODES - Requires permissions =====
        Route::post('/generate-invite', [OrgManagementController::class, 'generateInviteCode'])
            ->middleware('org.permission:manage_invite_codes');

        Route::delete('/remove-invite', [OrgManagementController::class, 'removeInviteCode'])
            ->middleware('org.permission:manage_invite_codes');


        // ===== STATISTICS & ACTIVITY =====
        Route::get('/statistics', [OrgManagementController::class, 'statistics'])
            ->middleware('org.permission:view_statistics');

        Route::get('/activity-log', [OrgManagementController::class, 'activityLog'])
            ->middleware('org.permission:view_activity_logs');

        // ===== DATA EXPORT =====
        Route::get('/export-data', [OrgManagementController::class, 'exportData'])
            ->middleware('org.permission:export_data');

        // ===== ARCHIVE/RESTORE =====
        Route::post('/archive', [OrgManagementController::class, 'archiveOrganization'])
            ->middleware('org.permission:archive_organization');

        Route::post('/restore', [OrgManagementController::class, 'restoreOrganization'])
            ->middleware('org.permission:archive_organization');

        // ===== OWNERSHIP TRANSFER =====
        Route::post('/transfer-ownership', [OrgManagementController::class, 'initiateOwnershipTransfer'])
            ->middleware('org.permission:transfer_ownership');

        Route::post('/transfer-ownership/{transferId}/accept', [OrgManagementController::class, 'acceptOwnershipTransfer'])
            ->middleware('org.member');

        Route::post('/transfer-ownership/{transferId}/decline', [OrgManagementController::class, 'declineOwnershipTransfer'])
            ->middleware('org.member');

        // ===== LEAVE ORGANIZATION =====
        Route::post('/leave', [OrgManagementController::class, 'leave'])
            ->middleware('org.member');

        #endregion

        /** =========================================================== */
        /** ==================== Duty Management ====================== */
        /** =========================================================== */
        #region Duty

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

        #endregion





        /** =============================================================== */
        /** ============= Document Storage (Google Drive-like) ============ */
        /** =============================================================== */

        #region Storage
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
        )->middleware('org.permission:view_storage');


    });

    #endregion

    /** =============================================================== */
    /** ------------ Global Announcements (Authenticated) ------------ */
    /** =============================================================== */

    #region Global Announcements
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

    #endregion
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

    // Get shared document metadata
    Route::get('/{token}', [DocumentShareController::class, 'getPublicDocument'])
        ->name('documents.public-access');

    // Get temporary download URL (NEW - RECOMMENDED)
    Route::get('/{token}/download-url', [DocumentShareController::class, 'getPublicDownloadUrl'])
        ->name('documents.public-download-url');

    // Secure download endpoint for local storage (fallback)
    Route::get('/{token}/secure/{downloadToken}', [DocumentShareController::class, 'securePublicDownload'])
        ->name('documents.public-secure-download');

    // Legacy direct download (keep for backward compatibility)
    Route::get('/{token}/download', [DocumentShareController::class, 'downloadPublicDocument'])
        ->name('documents.public-download');
});


// Route::get('/documents/public/{token}', [DocumentShareController::class, 'getPublicDocument']);
// Route::get('/documents/public/{token}/download', [DocumentShareController::class, 'downloadPublicDocument']);

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
