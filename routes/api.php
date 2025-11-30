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
use App\Http\Controllers\NotificationController;

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api'])->group(function () {

    /** =============================================================== */
    /** ======================== Organizations ======================== */
    /** =============================================================== */

    #region Org Management
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

    // View members
    Route::get('/organizations/{organization}/members', [OrganizationController::class, 'members']);

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
    /** ---------------- GLOBAL REVIEW ROUTES (Cross-Org) ------------ */
    /** =============================================================== */

    #region Global Review Routes

    // View review globally (if you're submitter or recipient)
    Route::get('/reviews/{review}', [ReviewRequestController::class, 'showGlobal']);

    // Global comments (no org context)
    Route::get('/reviews/{review}/comments', function (Request $request, ReviewRequest $review) {
        return app(ReviewCommentController::class)->index($request, null, $review);
    });

    Route::post('/reviews/{review}/comments', function (Request $request, ReviewRequest $review) {
        return app(ReviewCommentController::class)->store($request, null, $review);
    });

    // Global private thread comments
    Route::get('/reviews/{review}/recipients/{recipient}/comments', function (Request $request, ReviewRequest $review, ReviewRecipient $recipient) {
        return app(ReviewCommentController::class)->recipientComments($request, null, $review, $recipient);
    });

    Route::post('/reviews/{review}/recipients/{recipient}/comments', function (Request $request, ReviewRequest $review, ReviewRecipient $recipient) {
        return app(ReviewCommentController::class)->storeRecipientComment($request, null, $review, $recipient);
    });

    Route::get('/reviews/{review}/comments', [ReviewCommentController::class, 'index']);
    Route::post('/reviews/{review}/comments', [ReviewCommentController::class, 'store']);
    #endregion

    /** =============================================================== */
    /** -------------- GLOBAL DOCUMENT ROUTES (Review Context) ------- */
    /** =============================================================== */

    #region Global Document Routes

    // Get document details with all versions
    Route::get('/documents/{document}', [DocumentController::class, 'show']);

    // Download document version
    Route::get('/documents/{document}/versions/{version}/download', [DocumentController::class, 'downloadVersion']);

    // Get download URL (signed URL generation)
    Route::get('/documents/{document}/versions/{version}/download-url', [DocumentController::class, 'getDownloadUrl']);

    // Secure download endpoint for local storage (fallback)
    Route::get('/documents/{document}/versions/{version}/secure/{token}', [DocumentController::class, 'secureDownload'])
        ->name('documents.secure-download');

    #endregion

    #region Global Share
    Route::get('/shared-documents', [DocumentShareController::class, 'getAllSharedDocuments']);

    // Document sharing (review context)
    Route::prefix('documents/{document}')->group(function () {
        Route::get('/share', [DocumentShareController::class, 'getShare']);
        Route::patch('/share', [DocumentShareController::class, 'updateShare']);
        Route::post('/share/revoke', [DocumentShareController::class, 'revokeShare']);
        Route::get('/share/stats', [DocumentShareController::class, 'getShareStats']);
        Route::get('/share/logs', [DocumentShareController::class, 'getAccessLogs']);
    });
    #endregion

    #region Notification
    // ===========================================
    // NOTIFICATION ROUTES
    // ===========================================

    // 1. Static/Specific routes MUST come before wildcard routes to avoid conflicts
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/delete-all-read', [NotificationController::class, 'deleteAllRead']);
    Route::get('/notifications/preferences', [NotificationController::class, 'getPreferences']);
    Route::put('/notifications/preferences', [NotificationController::class, 'updatePreferences']);

    // 2. Resource/Wildcard routes
    Route::get('/notifications', [NotificationController::class, 'index']);

    // Using {notification} parameter to match the controller's type hint: markAsRead(Notification $notification)
    Route::post('/notifications/{notification}/mark-read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/{notification}/mark-unread', [NotificationController::class, 'markAsUnread']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    #endregion

    /** =============================================================== */
    /** ---------- Organization Management (Admin Dashboard) ---------- */
    /** =============================================================== */

    Route::prefix('org/{organization}')->middleware('org.member')->group(function () {

        /** =============================================================== */
        /** ==================== Permission Management ==================== */
        /** =============================================================== */
        #region Permissions

        // Get all available permissions
        Route::get('/permissions', [PermissionController::class, 'index']);

        // Get all members with their permissions (requires manage_permissions)
        Route::get('/permissions/members', [PermissionController::class, 'memberPermissions'])
            ->middleware('org.permission:manage_permissions');

        // Get specific user's permissions (any member can check - for UI)
        Route::get('/permissions/users/{user}', [PermissionController::class, 'userPermissions']);

        // Grant/revoke (requires manage_permissions)
        Route::post('/permissions/users/{user}/grant', [PermissionController::class, 'grantPermission'])
            ->middleware('org.permission:manage_permissions');

        Route::post('/permissions/users/{user}/revoke', [PermissionController::class, 'revokePermission'])
            ->middleware('org.permission:manage_permissions');

        Route::post('/permissions/users/{user}/bulk', [PermissionController::class, 'bulkGrantPermissions'])
            ->middleware('org.permission:manage_permissions');

        #endregion

        /** =============================================================== */
        /** ============= REVIEW SYSTEM (Simplified Authorization) ======== */
        /** =============================================================== */

        #region Review System

        // --- Document Management ---
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::get('/documents/{document}', [DocumentController::class, 'show']);

        // --- Review CRUD ---
        Route::get('/reviews', [ReviewRequestController::class, 'index']);
        Route::post('/reviews', [ReviewRequestController::class, 'store']);
        Route::get('/reviews/{review}', [ReviewRequestController::class, 'show']);
        Route::patch('/reviews/{review}/details', [ReviewRequestController::class, 'updateDetails']);

        // --- Review Actions ---
        Route::post('/reviews/{review}/close', [ReviewRequestController::class, 'close']);
        Route::post('/reviews/{review}/reopen', [ReviewRequestController::class, 'reopen']);
        Route::post('/reviews/{review}/versions', [ReviewRequestController::class, 'attachNewVersion']);
        Route::get('/reviews/{review}/actions', [ReviewRequestController::class, 'getActivityLog']);

        // --- Comments (Main Thread) ---
        Route::get('/reviews/{review}/comments', [ReviewCommentController::class, 'index']);
        Route::post('/reviews/{review}/comments', [ReviewCommentController::class, 'store']);

        // --- Private Thread Comments (Submitter <-> Specific Reviewer) ---
        Route::get('/reviews/{review}/recipients/{recipient}/comments', [ReviewCommentController::class, 'recipientComments']);
        Route::post('/reviews/{review}/recipients/{recipient}/comments', [ReviewCommentController::class, 'storeRecipientComment']);

        // --- Recipient Management ---
        Route::patch('/reviews/{review}/recipients/{recipient}/due', [ReviewRequestController::class, 'updateRecipientDue']);
        Route::post('/reviews/{review}/recipients/{recipient}/remind', [ReviewRequestController::class, 'remindReviewer']);

        // --- Admin Actions (Approval Workflow) ---
        Route::post('/reviews/{review}/approve', [ReviewRequestController::class, 'approve'])
            ->middleware('org.admin');
        Route::post('/reviews/{review}/reject', [ReviewRequestController::class, 'reject'])
            ->middleware('org.admin');

        #endregion

        /** =============================================================== */
        /** ----------------- INCOMING REVIEWS (Reviewer POV) ------------ */
        /** =============================================================== */

        #region Incoming Reviews

        // List reviews sent TO this organization (Inbox)
        Route::get('/incoming-reviews', [ReviewRequestController::class, 'indexIncoming']);

        // Show a specific incoming review
        Route::get('/incoming-reviews/{review}', [ReviewRequestController::class, 'showIncoming']);

        // Reviewer actions
        Route::patch('/incoming-reviews/{review}/recipients/{recipient}/view', [ReviewRecipientController::class, 'markViewed']);
        Route::post('/incoming-reviews/{review}/recipients/{recipient}/approve', [ReviewRecipientController::class, 'approve']);
        Route::post('/incoming-reviews/{review}/recipients/{recipient}/decline', [ReviewRecipientController::class, 'decline']);

        // Incoming review comments (same handlers, different context)
        Route::get('/incoming-reviews/{review}/comments', [ReviewCommentController::class, 'index']);
        Route::post('/incoming-reviews/{review}/comments', [ReviewCommentController::class, 'store']);
        Route::get('/incoming-reviews/{review}/recipients/{recipient}/comments', [ReviewCommentController::class, 'recipientComments']);
        Route::post('/incoming-reviews/{review}/recipients/{recipient}/comments', [ReviewCommentController::class, 'storeRecipientComment']);

        #endregion

        /** =============================================================== */
        /** ==================== Dashboard Management ===================== */
        /** =============================================================== */

        #region Dashboard

        // Dashboard (any member)
        Route::get('/dashboard', [OrgManagementController::class, 'dashboard']);

        // --- OVERVIEW (All members can access) ---
        Route::get('/overview', [OrgManagementController::class, 'overview']);
        Route::patch('/overview', [OrgManagementController::class, 'updateOverview'])
            ->middleware('org.permission:edit_org_profile');

        // --- ANNOUNCEMENTS (All members can view) ---
        Route::get('/announcements', [OrgManagementController::class, 'announcements']);
        Route::post('/announcements', [OrgManagementController::class, 'createAnnouncement'])
            ->middleware('org.permission:create_announcements');
        Route::patch('/announcements/{announcementId}', [OrgManagementController::class, 'updateAnnouncement'])
            ->middleware('org.permission:edit_announcements');
        Route::delete('/announcements/{announcementId}', [OrgManagementController::class, 'deleteAnnouncement'])
            ->middleware('org.permission:delete_announcements');

        #endregion

        #region Members

        // --- MEMBERS (All members can view) ---
        Route::get('/members', [OrgManagementController::class, 'members']);
        Route::get('/members/{user}', [OrgManagementController::class, 'showMember']);

        // Member management requires permissions
        Route::patch('/members/{user}/role', [OrgManagementController::class, 'updateMemberRole'])
            ->middleware('org.permission:manage_member_roles');
        Route::delete('/members/{user}', [OrgManagementController::class, 'removeMember'])
            ->middleware('org.permission:remove_members');

        #endregion

        #region Settings

        // --- SETTINGS (Requires permissions) ---
        Route::patch('/settings', [OrgManagementController::class, 'updateSettings'])
            ->middleware('org.permission:manage_org_settings');

        // Logo Management
        Route::post('/logo', [OrgManagementController::class, 'uploadLogo'])
            ->middleware('org.permission:upload_org_logo');
        Route::delete('/logo', [OrgManagementController::class, 'deleteLogo'])
            ->middleware('org.permission:upload_org_logo');

        // --- JOIN REQUESTS (Requires permissions) ---
        Route::get('/join-requests', [OrgManagementController::class, 'joinRequests'])
            ->middleware('org.permission:approve_join_requests');
        Route::post('/join-requests/{requestId}/approve', [OrgManagementController::class, 'approveRequest'])
            ->middleware('org.permission:approve_join_requests');
        Route::post('/join-requests/{requestId}/decline', [OrgManagementController::class, 'declineRequest'])
            ->middleware('org.permission:approve_join_requests');

        // --- INVITE CODES (Requires permissions) ---
        Route::post('/generate-invite', [OrgManagementController::class, 'generateInviteCode'])
            ->middleware('org.permission:manage_invite_codes');
        Route::delete('/remove-invite', [OrgManagementController::class, 'removeInviteCode'])
            ->middleware('org.permission:manage_invite_codes');

        // --- STATISTICS & ACTIVITY ---
        Route::get('/statistics', [OrgManagementController::class, 'statistics'])
            ->middleware('org.permission:view_statistics');
        Route::get('/activity-log', [OrgManagementController::class, 'activityLog'])
            ->middleware('org.permission:view_activity_logs');

        // --- DATA EXPORT ---
        Route::get('/export-data', [OrgManagementController::class, 'exportData'])
            ->middleware('org.permission:export_data');

        // --- ARCHIVE/RESTORE ---
        Route::post('/archive', [OrgManagementController::class, 'archiveOrganization'])
            ->middleware('org.permission:archive_organization');
        Route::post('/restore', [OrgManagementController::class, 'restoreOrganization'])
            ->middleware('org.permission:archive_organization');

        // --- OWNERSHIP TRANSFER ---
        Route::post('/transfer-ownership', [OrgManagementController::class, 'initiateOwnershipTransfer'])
            ->middleware('org.permission:transfer_ownership');
        Route::post('/transfer-ownership/{transferId}/accept', [OrgManagementController::class, 'acceptOwnershipTransfer']);
        Route::post('/transfer-ownership/{transferId}/decline', [OrgManagementController::class, 'declineOwnershipTransfer']);

        // --- LEAVE ORGANIZATION ---
        Route::post('/leave', [OrgManagementController::class, 'leave']);

        #endregion

        /** =========================================================== */
        /** ==================== Duty Management ====================== */
        /** =========================================================== */

        #region Duty

        // My assignments (any member with permission)
        Route::get('/duty-assignments/me', [DutyAssignmentController::class, 'myAssignments'])
            ->middleware('org.permission:participate_in_duties');

        // // Availability (any member with permission)
        // Route::get('/duty-availability', [DutyAvailabilityController::class, 'index'])
        //     ->middleware('org.permission:participate_in_duties');
        // Route::post('/duty-availability', [DutyAvailabilityController::class, 'store'])
        //     ->middleware('org.permission:participate_in_duties');
        // Route::patch('/duty-availability/{dutyAvailability}', [DutyAvailabilityController::class, 'update'])
        //     ->middleware('org.permission:participate_in_duties');
        // Route::delete('/duty-availability/{dutyAvailability}', [DutyAvailabilityController::class, 'destroy'])
        //     ->middleware('org.permission:participate_in_duties');

        // Swap Requests - Member actions
        Route::get('/duty-swaps', [DutySwapController::class, 'index'])
            ->middleware('org.permission:participate_in_duties');
        Route::post('/duty-assignments/{dutyAssignment}/swap', [DutySwapController::class, 'store'])
            ->middleware('org.permission:participate_in_duties');
        Route::post('/duty-swaps/{swapRequest}/accept', [DutySwapController::class, 'accept'])
            ->middleware('org.permission:participate_in_duties');
        Route::post('/duty-swaps/{swapRequest}/decline', [DutySwapController::class, 'decline'])
            ->middleware('org.permission:participate_in_duties');
        Route::post('/duty-swaps/{swapRequest}/cancel', [DutySwapController::class, 'cancel'])
            ->middleware('org.permission:participate_in_duties');

        // Member statistics
        // Route::get('/duty-schedules/my-statistics', [DutyScheduleController::class, 'memberStatistics'])
        //     ->middleware('org.permission:participate_in_duties');
        Route::get('/duty-schedules/my-statistics', [DutyScheduleController::class, 'memberStatistics']);

        // === ADMIN ACTIONS (manage_duty_system permission) ===

        // Statistics & Audit Logs (admin only)
        Route::get('/duty-schedules/statistics', [DutyScheduleController::class, 'statistics'])
            ->middleware('org.permission:manage_duty_system');
        Route::get('/duty-audit-logs', [DutyScheduleController::class, 'auditLogs'])
            ->middleware('org.permission:manage_duty_system');

        // View schedules (read-only for participants, full access for admins)
        Route::get('/duty-schedules', [DutyScheduleController::class, 'index'])
            ->middleware('org.permission:participate_in_duties'); // Can view
        Route::get('/duty-schedules/calendar', [DutyScheduleController::class, 'calendar'])
            ->middleware('org.permission:participate_in_duties');
        Route::get('/duty-schedules/{dutySchedule}', [DutyScheduleController::class, 'show'])
            ->middleware('org.permission:participate_in_duties');

        // Manage schedules (admin only)
        Route::post('/duty-schedules', [DutyScheduleController::class, 'store'])
            ->middleware('org.permission:manage_duty_system');
        Route::patch('/duty-schedules/{dutySchedule}', [DutyScheduleController::class, 'update'])
            ->middleware('org.permission:manage_duty_system');
        Route::delete('/duty-schedules/{dutySchedule}', [DutyScheduleController::class, 'destroy'])
            ->middleware('org.permission:manage_duty_system');
        Route::post('/duty-schedules/{dutySchedule}/duplicate', [DutyScheduleController::class, 'duplicate'])
            ->middleware('org.permission:manage_duty_system');

        // Manage assignments (admin only)
        Route::post('/duty-schedules/{dutySchedule}/assignments', [DutyAssignmentController::class, 'store'])
            ->middleware('org.permission:manage_duty_system');
        Route::patch('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}', [DutyAssignmentController::class, 'update'])
            ->middleware('org.permission:manage_duty_system');
        Route::delete('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}', [DutyAssignmentController::class, 'destroy'])
            ->middleware('org.permission:manage_duty_system');

        // Admin swap review (with reassignment capability)
        Route::post('/duty-swaps/{swapRequest}/review', [DutySwapController::class, 'adminReview'])
            ->middleware('org.permission:manage_duty_system');

        // Member self-service (respond, check in/out)
        Route::post('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}/respond', [DutyAssignmentController::class, 'respond'])
            ->middleware('org.permission:participate_in_duties');
        Route::post('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}/check-in', [DutyAssignmentController::class, 'checkIn'])
            ->middleware('org.permission:participate_in_duties');
        Route::post('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}/check-out', [DutyAssignmentController::class, 'checkOut'])
            ->middleware('org.permission:participate_in_duties');

        // Templates (admin only)
        Route::get('/duty-templates', [DutyTemplateController::class, 'index'])
            ->middleware('org.permission:manage_duty_system');
        Route::post('/duty-templates', [DutyTemplateController::class, 'store'])
            ->middleware('org.permission:manage_duty_system');
        Route::patch('/duty-templates/{dutyTemplate}', [DutyTemplateController::class, 'update'])
            ->middleware('org.permission:manage_duty_system');
        Route::delete('/duty-templates/{dutyTemplate}', [DutyTemplateController::class, 'destroy'])
            ->middleware('org.permission:manage_duty_system');

        // // My assignments (any member)
        // Route::get('/duty-assignments/me', [DutyAssignmentController::class, 'myAssignments']);

        // // Duty Schedules
        // Route::get('/duty-schedules', [DutyScheduleController::class, 'index'])
        //     ->middleware('org.permission:view_duty_schedules');
        // Route::post('/duty-schedules', [DutyScheduleController::class, 'store'])
        //     ->middleware('org.permission:create_duty_schedules');
        // Route::get('/duty-schedules/calendar', [DutyScheduleController::class, 'calendar'])
        //     ->middleware('org.permission:view_duty_schedules');
        // Route::get('/duty-schedules/statistics', [DutyScheduleController::class, 'statistics'])
        //     ->middleware('org.permission:view_statistics');
        // Route::get('/duty-schedules/my-statistics', [DutyScheduleController::class, 'memberStatistics']);
        // Route::get('/duty-schedules/{dutySchedule}', [DutyScheduleController::class, 'show'])
        //     ->middleware('org.permission:view_duty_schedules');
        // Route::patch('/duty-schedules/{dutySchedule}', [DutyScheduleController::class, 'update'])
        //     ->middleware('org.permission:edit_duty_schedules');
        // Route::delete('/duty-schedules/{dutySchedule}', [DutyScheduleController::class, 'destroy'])
        //     ->middleware('org.permission:delete_duty_schedules');
        // Route::post('/duty-schedules/{dutySchedule}/duplicate', [DutyScheduleController::class, 'duplicate'])
        //     ->middleware('org.permission:create_duty_schedules');

        // // Assignments
        // Route::post('/duty-schedules/{dutySchedule}/assignments', [DutyAssignmentController::class, 'store'])
        //     ->middleware('org.permission:assign_duties');
        // Route::patch('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}', [DutyAssignmentController::class, 'update'])
        //     ->middleware('org.permission:assign_duties');
        // Route::delete('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}', [DutyAssignmentController::class, 'destroy'])
        //     ->middleware('org.permission:assign_duties');

        // // Member self-service (any member)
        // Route::post('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}/respond', [DutyAssignmentController::class, 'respond']);
        // Route::post('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}/check-in', [DutyAssignmentController::class, 'checkIn']);
        // Route::post('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}/check-out', [DutyAssignmentController::class, 'checkOut']);

        // // Availability (any member)
        // Route::get('/duty-availability', [DutyAvailabilityController::class, 'index']);
        // Route::post('/duty-availability', [DutyAvailabilityController::class, 'store']);
        // Route::patch('/duty-availability/{dutyAvailability}', [DutyAvailabilityController::class, 'update']);
        // Route::delete('/duty-availability/{dutyAvailability}', [DutyAvailabilityController::class, 'destroy']);

        // // Swap Requests
        // Route::get('/duty-swaps', [DutySwapController::class, 'index'])
        //     ->middleware('org.permission:view_duty_schedules');
        // Route::post('/duty-assignments/{dutyAssignment}/swap', [DutySwapController::class, 'store']);
        // Route::post('/duty-swaps/{swapRequest}/accept', [DutySwapController::class, 'accept']);
        // Route::post('/duty-swaps/{swapRequest}/decline', [DutySwapController::class, 'decline']);
        // Route::post('/duty-swaps/{swapRequest}/cancel', [DutySwapController::class, 'cancel']);
        // Route::post('/duty-swaps/{swapRequest}/review', [DutySwapController::class, 'review'])
        //     ->middleware('org.permission:approve_duty_swaps');

        // // Templates
        // Route::get('/duty-templates', [DutyTemplateController::class, 'index'])
        //     ->middleware('org.permission:view_duty_schedules');
        // Route::post('/duty-templates', [DutyTemplateController::class, 'store'])
        //     ->middleware('org.permission:manage_duty_templates');
        // Route::patch('/duty-templates/{dutyTemplate}', [DutyTemplateController::class, 'update'])
        //     ->middleware('org.permission:manage_duty_templates');
        // Route::delete('/duty-templates/{dutyTemplate}', [DutyTemplateController::class, 'destroy'])
        //     ->middleware('org.permission:manage_duty_templates');

        #endregion

        /** =============================================================== */
        /** ============= Document Storage (Google Drive-like) ============ */
        /** =============================================================== */

        #region Storage

        // View Access: Allow Viewers, Contributors, OR Managers
        Route::get('/storage', [StorageController::class, 'index'])
            ->middleware('org.permission:view_storage|contribute_to_storage|manage_storage_system');

        Route::get('/storage/documents/{document}', [StorageController::class, 'show'])
            ->middleware('org.permission:view_storage|contribute_to_storage|manage_storage_system');

        Route::get('/storage/documents/{document}/versions/{version}/download-url', [DocumentController::class, 'getDownloadUrl'])
            ->middleware('org.permission:view_storage|contribute_to_storage|manage_storage_system');

        // Contribution Access: Allow Contributors OR Managers
        Route::post('/storage/folders', [StorageController::class, 'createFolder'])
            ->middleware('org.permission:contribute_to_storage|manage_storage_system');

        Route::post('/storage/upload', [StorageController::class, 'upload'])
            ->middleware('org.permission:contribute_to_storage|manage_storage_system');

        Route::patch('/storage/documents/{document}', [StorageController::class, 'update'])
            ->middleware('org.permission:contribute_to_storage|manage_storage_system');

        Route::post('/storage/documents/{document}/versions', [DocumentController::class, 'addVersion'])
            ->middleware('org.permission:contribute_to_storage|manage_storage_system');

        // Delete: Allow Contributors OR Managers
        // (Controller policy will restrict Contributors to their own files, while Managers can delete all)
        Route::delete('/storage/documents/{document}', [StorageController::class, 'destroy'])
            ->middleware('org.permission:contribute_to_storage|manage_storage_system');

        // Sharing: Allow Contributors OR Managers
        Route::post('/storage/documents/{document}/toggle-share', [DocumentShareController::class, 'toggleShare'])
            ->middleware('org.permission:contribute_to_storage|manage_storage_system');

        Route::get('/storage/documents/{document}/share-status', [DocumentShareController::class, 'getShareStatus'])
            ->middleware('org.permission:view_storage|contribute_to_storage|manage_storage_system');

        // Statistics: All levels can view basic stats
        Route::get('/storage/statistics', [StorageController::class, 'statistics'])
            ->middleware('org.permission:view_storage|contribute_to_storage|manage_storage_system');
        #endregion



        // // View Access (Viewer Role)
        // Route::get('/storage', [StorageController::class, 'index'])
        //     ->middleware('org.permission:view_storage');
        // Route::get('/storage/documents/{document}', [StorageController::class, 'show'])
        //     ->middleware('org.permission:view_storage');
        // Route::get('/storage/documents/{document}/versions/{version}/download-url', [DocumentController::class, 'getDownloadUrl'])
        //     ->middleware('org.permission:view_storage');

        // // Contribution Access (Contributor Role)
        // // Note: 'contribute_to_storage' replaces 'upload_documents' and 'create_folders'
        // Route::post('/storage/folders', [StorageController::class, 'createFolder'])
        //     ->middleware('org.permission:contribute_to_storage');
        // Route::post('/storage/upload', [StorageController::class, 'upload'])
        //     ->middleware('org.permission:contribute_to_storage');
        // Route::patch('/storage/documents/{document}', [StorageController::class, 'update'])
        //     ->middleware('org.permission:contribute_to_storage');
        // Route::post('/storage/documents/{document}/versions', [DocumentController::class, 'addVersion'])
        //     ->middleware('org.permission:contribute_to_storage');

        // // Delete: The controller logic likely checks if the user owns the document. 
        // // If they own it, 'contribute_to_storage' is enough. 
        // // If they don't own it, they need 'manage_storage_system' (Admin).
        // Route::delete('/storage/documents/{document}', [StorageController::class, 'destroy'])
        //     ->middleware('org.permission:contribute_to_storage');

        // // Sharing: Contributors can share their own files
        // Route::post('/storage/documents/{document}/toggle-share', [DocumentShareController::class, 'toggleShare'])
        //     ->middleware('org.permission:contribute_to_storage');
        // Route::get('/storage/documents/{document}/share-status', [DocumentShareController::class, 'getShareStatus'])
        //     ->middleware('org.permission:view_storage');

        // // // Admin Access (Co-admin Role)
        // // Route::get('/storage/statistics', [StorageController::class, 'statistics'])
        // //     ->middleware('org.permission:manage_storage_system');


        // // // Storage Access (Index, Stats)
        // // Route::get('/storage/statistics', [StorageController::class, 'statistics'])
        // //     ->middleware('org.permission:view_statistics');

        // Route::get('/storage/statistics', [StorageController::class, 'statistics'])
        //     ->middleware('org.permission:view_storage');






        // // Storage Management (Create, Upload)
        // Route::post('/storage/folders', [StorageController::class, 'createFolder'])
        //     ->middleware('org.permission:create_folders');


        // // Single Document Operations
        // Route::get('/storage/documents/{document}', [StorageController::class, 'show'])
        //     ->middleware('org.permission:view_storage');
        // Route::patch('/storage/documents/{document}', [StorageController::class, 'update'])
        //     ->middleware('org.permission:upload_documents');
        // Route::delete('/storage/documents/{document}', [StorageController::class, 'destroy'])
        //     ->middleware('org.permission:delete_documents');

        // // SIMPLIFIED SHARING - Just toggle public/org
        // Route::post('/storage/documents/{document}/toggle-share', [DocumentShareController::class, 'toggleShare'])
        //     ->middleware('org.permission:upload_documents'); // Uploader can share
        // Route::get('/storage/documents/{document}/share-status', [DocumentShareController::class, 'getShareStatus'])
        //     ->middleware('org.permission:view_storage');

        // // Storage Document Versions (Download)
        // Route::get('/storage/documents/{document}/versions/{version}/download-url', [DocumentController::class, 'getDownloadUrl'])
        //     ->middleware('org.permission:view_storage');
        // Route::post('/storage/documents/{document}/versions', [DocumentController::class, 'addVersion'])
        //     ->middleware('org.permission:upload_documents');


        #region New Version


        // // Storage Document Sharing (within org context)
        // Route::prefix('storage/documents/{document}')->group(function () {
        //     // Get share configuration
        //     Route::get('/share', [DocumentShareController::class, 'getShare'])
        //         ->middleware('org.permission:view_storage');

        //     // Update share settings (create or update)
        //     Route::patch('/share', [DocumentShareController::class, 'updateShare'])
        //         ->middleware('org.permission:manage_document_sharing');

        //     // Revoke share link
        //     Route::post('/share/revoke', [DocumentShareController::class, 'revokeShare'])
        //         ->middleware('org.permission:manage_document_sharing');

        //     // Get share statistics
        //     Route::get('/share/stats', [DocumentShareController::class, 'getShareStats'])
        //         ->middleware('org.permission:view_statistics');

        //     // Get access logs
        //     Route::get('/share/logs', [DocumentShareController::class, 'getAccessLogs'])
        //         ->middleware('org.permission:view_activity_logs');
        // });
        #endregion

        // // Storage Access (Index, Stats)
        // Route::get('/storage', [StorageController::class, 'index'])
        //     ->middleware('org.permission:view_storage');
        // Route::get('/storage/statistics', [StorageController::class, 'statistics'])
        //     ->middleware('org.permission:view_statistics');

        // // Storage Management (Create, Upload)
        // Route::post('/storage/folders', [StorageController::class, 'createFolder'])
        //     ->middleware('org.permission:create_folders');
        // Route::post('/storage/upload', [StorageController::class, 'upload'])
        //     ->middleware('org.permission:upload_documents');

        // // Single Document Operations
        // Route::get('/storage/documents/{document}', [StorageController::class, 'show'])
        //     ->middleware('org.permission:view_storage');
        // Route::patch('/storage/documents/{document}', [StorageController::class, 'update'])
        //     ->middleware('org.permission:upload_documents');
        // Route::delete('/storage/documents/{document}', [StorageController::class, 'destroy'])
        //     ->middleware('org.permission:delete_documents');
        // Route::post('/storage/documents/{document}/move', [StorageController::class, 'move'])
        //     ->middleware('org.permission:upload_documents');
        // Route::post('/storage/documents/{document}/copy', [StorageController::class, 'copy'])
        //     ->middleware('org.permission:upload_documents');

        // // Storage Document Versions
        // Route::post('/storage/documents/{document}/versions', [DocumentController::class, 'addVersion'])
        //     ->middleware('org.permission:upload_documents');
        // Route::get('/storage/documents/{document}/versions/{version}/download', [DocumentController::class, 'downloadVersion'])
        //     ->middleware('org.permission:view_storage');
        // Route::get('/storage/documents/{document}/versions/{version}/download-url', [DocumentController::class, 'getDownloadUrl'])
        //     ->middleware('org.permission:view_storage');
        // Route::get('/storage/documents/{document}/versions/{version}/secure/{token}', [DocumentController::class, 'secureDownload'])
        //     ->middleware('org.permission:view_storage');

        #endregion
    });

    /** =============================================================== */
    /** ------------ Global Announcements (Authenticated) ------------ */
    /** =============================================================== */

    #region Global Announcements

    // Paginated feed for infinite scroll
    Route::get('/announcements/feed', [AnnouncementController::class, 'feed']);

    // Legacy endpoint (maintained for compatibility)
    Route::get('/announcements', [AnnouncementController::class, 'index']);

    // Create announcement (admin only)
    Route::post('/announcements', [AnnouncementController::class, 'store'])
        ->middleware('org.permission:create_announcements');

    // Update announcement (admin only)
    Route::patch('/announcements/{id}', [AnnouncementController::class, 'update'])
        ->middleware('org.permission:edit_announcements');

    // Delete announcement (admin only)
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy'])
        ->middleware('org.permission:delete_announcements');

    #endregion

    /** =============================================================== */
    /** ------------------ Public Document Sharing ------------------- */
    /** =============================================================== */

    Route::prefix('share')->group(function () {
        // Get shared document metadata
        Route::get('/{token}', [DocumentShareController::class, 'getPublicDocument'])
            ->name('documents.public-access');

        // Get temporary download URL (RECOMMENDED)
        Route::get('/{token}/download-url', [DocumentShareController::class, 'getPublicDownloadUrl'])
            ->name('documents.public-download-url');

        // Secure download endpoint for local storage (fallback)
        Route::get('/{token}/secure/{downloadToken}', [DocumentShareController::class, 'securePublicDownload'])
            ->name('documents.public-secure-download');

        // Legacy direct download (keep for backward compatibility)
        Route::get('/{token}/download', [DocumentShareController::class, 'downloadPublicDocument'])
            ->name('documents.public-download');
    });
    Route::get('/shared-documents', function (\Illuminate\Http\Request $request) {
        // List all public documents + documents shared with user's orgs
        return app(StorageController::class)->publicIndex($request);
    });


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

/** =============================================================== */
/** ----------------------- Auth Endpoints ----------------------- */
/** =============================================================== */

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
