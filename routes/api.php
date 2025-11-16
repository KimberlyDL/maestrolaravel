<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VerifyEmailController;
use App\Http\Controllers\VerificationNotificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\OAuthExchangeController;





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

// use App\Http\Controllers\Review\ReviewActionController;     // (new) simple index()
// use App\Http\Controllers\Review\ReviewAttachmentController; // (new) store(), index()



use App\Http\Controllers\DocumentShareController;
use App\Http\Controllers\StorageController;

Route::middleware(['auth:api'])->group(function () {



    /** --------------------------------------------------------------- */
    /** ==================== Permission Controller ==================== */
    /** --------------------------------------------------------------- */

    // // Get all available permissions
    // Route::get('/permissions', [PermissionController::class, 'index']);

    // // Organization-specific permission routes
    // Route::prefix('organizations/{organization}')->group(function () {

    //     // Get all members with their permissions
    //     Route::get('/permissions/members', [PermissionController::class, 'memberPermissions']);

    //     // Get specific user's permissions
    //     Route::get('/permissions/users/{user}', [PermissionController::class, 'userPermissions']);

    //     // Grant single permission
    //     Route::post('/permissions/users/{user}/grant', [PermissionController::class, 'grantPermission']);

    //     // Revoke single permission
    //     Route::post('/permissions/users/{user}/revoke', [PermissionController::class, 'revokePermission']);

    //     // Bulk grant permissions (replaces all)
    //     Route::post('/permissions/users/{user}/bulk', [PermissionController::class, 'bulkGrantPermissions']);
    // });


    //     /*
    //  * Example usage in your existing routes:
    //  * 
    //  * Update existing OrgManagementController routes to check permissions:
    //  */

    //     // Example: Update join request approval route
    //     Route::post(
    //         '/organizations/{organization}/join-requests/{request}/approve',
    //         [OrgManagementController::class, 'approveRequest']
    //     )->middleware(['auth:api', 'permission:approve_join_requests']);

    //     // Example: Update announcement creation route
    //     Route::post(
    //         '/organizations/{organization}/announcements',
    //         [OrgManagementController::class, 'createAnnouncement']
    //     )->middleware(['auth:api', 'permission:create_announcements']);




    /** --------------------------------------------------------------- */
    /** ============= Document Storage (Google Drive-like) ============ */
    /** --------------------------------------------------------------- */

    // List documents/folders in organization storage

    Route::get('/storage', [StorageController::class, 'index']);

    // List public documents
    Route::get('/storage/public', [StorageController::class, 'publicIndex']);

    // Get storage statistics
    Route::get('/storage/statistics', [StorageController::class, 'statistics']);

    // Create folder
    Route::post('/storage/folders', [StorageController::class, 'createFolder']);

    // Upload file (uses DocumentController with context=storage)
    Route::post('/storage/upload', [StorageController::class, 'upload']);

    // Get document/folder details
    Route::get('/storage/documents/{document}', [StorageController::class, 'show']);

    // Update document/folder (rename, change visibility, etc.)
    Route::patch('/storage/documents/{document}', [StorageController::class, 'update']);

    // Delete document/folder
    Route::delete('/storage/documents/{document}', [StorageController::class, 'destroy']);

    // Move document/folder
    Route::post('/storage/documents/{document}/move', [StorageController::class, 'move']);

    // Copy document (files only, not folders)
    Route::post('/storage/documents/{document}/copy', [StorageController::class, 'copy']);

    /** Document Sharing */
    Route::get('/storage/documents/{document}/share', [DocumentShareController::class, 'getShare']);
    Route::patch('/storage/documents/{document}/share', [DocumentShareController::class, 'updateShare']);
    Route::post('/storage/documents/{document}/share/revoke', [DocumentShareController::class, 'revokeShare']);

    /** Document Versions (for storage context) */
    Route::post('/storage/documents/{document}/versions', [DocumentController::class, 'addVersion']);
    Route::get('/storage/documents/{document}/versions/{version}/download', [DocumentController::class, 'downloadVersion']);




    /** --------------------------------------------------------------- */
    /** ---------------- Legacy/Review Document Routes ---------------- */
    /** --------------------------------------------------------------- */
    // These remain for backward compatibility with review system

    // Get document details with all versions
    Route::get('/documents/{document}', [DocumentController::class, 'show']);

    // Create new document (context determined by request)
    Route::post('/documents', [DocumentController::class, 'store']);

    // Add new version to existing document
    Route::post('/documents/{document}/versions', [DocumentController::class, 'addVersion']);

    // Download specific document version
    Route::get('/documents/{document}/versions/{version}/download', [DocumentController::class, 'downloadVersion']);

    // List documents in organization (supports context parameter)
    Route::get('/org-documents', [DocumentController::class, 'index']);

    /** Share/Access Control (works for both contexts) */
    Route::get('/documents/{document}/share', [DocumentShareController::class, 'getShare']);
    Route::patch('/documents/{document}/share', [DocumentShareController::class, 'updateShare']);
    Route::post('/documents/{document}/share/revoke', [DocumentShareController::class, 'revokeShare']);






    // Public routes (no auth required)
    Route::get('/storage/public/{token}', [DocumentShareController::class, 'getPublicDocument']);
    Route::get('/storage/public/{token}/download', [DocumentController::class, 'downloadPublicVersion']);
    Route::get('/documents/public/{token}', [DocumentShareController::class, 'getPublicDocument']);
    Route::get('/documents/public/{token}/download', [DocumentController::class, 'downloadPublicVersion']);




    /** --------------------------------------------------------------- */
    /** ------------------ Review Requests (Threads) ------------------ */
    /** --------------------------------------------------------------- */

    // List with filters (publisher, reviewer, status, due range, search)
    Route::get('/reviews', [ReviewRequestController::class, 'index']);

    // Shortcut: reviewer’s assigned threads (optional)
    // Route::get('/reviews/me/assigned', [ReviewRequestController::class, 'assignedToMe']);

    // Create a thread (draft or directly sent depending on payload)
    Route::post('/reviews', [ReviewRequestController::class, 'store']);

    // Read a thread
    Route::get('/reviews/{review}', [ReviewRequestController::class, 'show']);

    // Update thread metadata (subject/body/due, add/remove recipients, etc.)
    Route::patch('/reviews/{review}', [ReviewRequestController::class, 'update']);

    // --- State transitions (map to model helpers) ---
    Route::post('/reviews/{review}/send',   [ReviewRequestController::class, 'send']);    // Draft → Sent
    Route::post('/reviews/{review}/close',  [ReviewRequestController::class, 'close']);   // → Closed
    Route::post('/reviews/{review}/reopen', [ReviewRequestController::class, 'reopen']);  // Final → InReview
    // (Optional) request-changes transition
    Route::post('/reviews/{review}/request-changes', [ReviewRequestController::class, 'requestChanges']);

    // Publisher uploads a **new main version** into this thread (repoint)
    Route::post('/reviews/{review}/versions', [ReviewRequestController::class, 'attachNewVersion']);



    /** --------------------------------------------------------------- */
    /** -------------------- Per-Recipient Actions -------------------- */
    /** --------------------------------------------------------------- */

    // Route::patch(
    //     '/reviews/{review}/recipients/{recipient}',
    //     [ReviewRecipientController::class, 'update']
    // );

    // Remove recipient (reviewer)
    Route::delete('/reviews/{review}/recipients/{recipient}', [ReviewRequestController::class, 'removeRecipient']);

    // Update recipient (for due date changes)
    Route::patch('/reviews/{review}/recipients/{recipient}', [ReviewRecipientController::class, 'update']);

    // Remind recipient
    Route::post('/reviews/{review}/recipients/{recipient}/remind', [ReviewRecipientController::class, 'remind']);




    Route::post('/reviews/{review}/recipients/{recipient}/remind',  [ReviewRecipientController::class, 'remind']);
    Route::post('/reviews/{review}/recipients/{recipient}/approve', [ReviewRecipientController::class, 'approve']);
    Route::post('/reviews/{review}/recipients/{recipient}/decline', [ReviewRecipientController::class, 'decline']);

    // Change name to "view" and use PATCH (idempotent-ish)
    Route::patch('/reviews/{review}/recipients/{recipient}/view',   [ReviewRecipientController::class, 'markViewed']);


    /** --------------------------------------------------------------- */
    /** --------------------- Comments (threaded) --------------------- */
    /** --------------------------------------------------------------- */

    Route::get('/reviews/{review}/comments',  [ReviewCommentController::class, 'index']);
    Route::post('/reviews/{review}/comments', [ReviewCommentController::class, 'store']);
    Route::get(
        '/reviews/{review}/recipients/{recipient}/comments',
        [ReviewCommentController::class, 'recipientComments']
    );

    /** --------------------------------------------------------------- */
    /** --------------------------- Actions --------------------------- */
    /** --------------------------------------------------------------- */

    // Activity log
    Route::get('/reviews/{review}/actions', [ReviewRequestController::class, 'getActivityLog']);

    // /** ---------------- Attachments (extra/supporting files) ---------------- */
    // Route::get('/reviews/{review}/attachments',  [ReviewAttachmentController::class, 'index']); // optional
    // Route::post('/reviews/{review}/attachments', [ReviewAttachmentController::class, 'store']);

    // /** ---------------- Audit trail ---------------- */
    // Route::get('/reviews/{review}/actions', [ReviewActionController::class, 'index']);


    /** =============================================================== */
    /** ======================== Organizations ======================== */
    /** =============================================================== */

    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);

    Route::get('/organizations/{organization}/members', [OrganizationController::class, 'members']);
    Route::post('/organizations/{organization}/members', [OrganizationController::class, 'addMember']);


    Route::delete('/organizations/{organization}/members/{user}', [OrganizationController::class, 'removeMember']);
    Route::patch('/organizations/{organization}/members/{user}/role', [OrganizationController::class, 'updateMemberRole']);

    Route::post('/organizations/join/request', [OrganizationController::class, 'joinRequest']);
    Route::post('/organizations/join/invite', [OrganizationController::class, 'joinViaInvite']);

    Route::post('/organizations/{organization}/generate-invite', [OrganizationController::class, 'generateInviteCode']);
    Route::delete('/organizations/{organization}/remove-invite', [OrganizationController::class, 'removeInviteCode']);

    Route::get('/organizations/my-requests', [OrganizationController::class, 'myRequests']);
    Route::delete('/organizations/requests/{requestId}', [OrganizationController::class, 'cancelRequest']);



    /** --------------------------------------------------------------- */
    /** ---------- Organization Management (Admin Dashboard) ---------- */
    /** --------------------------------------------------------------- */

    Route::prefix('org/{organization}')->group(function () {
        // Dashboard & Overview
        Route::get('/dashboard', [OrgManagementController::class, 'dashboard']);
        Route::get('/overview', [OrgManagementController::class, 'overview']);
        Route::patch('/overview', [OrgManagementController::class, 'updateOverview']);

        // Settings Management (NEW)
        Route::patch('/settings', [OrgManagementController::class, 'updateSettings']);

        // Logo Management
        Route::post('/logo', [OrgManagementController::class, 'uploadLogo']);
        Route::delete('/logo', [OrgManagementController::class, 'deleteLogo']);

        // Members Management
        Route::get('/members', [OrgManagementController::class, 'members']);
        Route::patch('/members/{user}/role', [OrgManagementController::class, 'updateMemberRole']);
        Route::delete('/members/{user}', [OrgManagementController::class, 'removeMember']);

        // Join Requests
        Route::get('/join-requests', [OrgManagementController::class, 'joinRequests']);
        Route::post('/join-requests/{requestId}/approve', [OrgManagementController::class, 'approveRequest']);
        Route::post('/join-requests/{requestId}/decline', [OrgManagementController::class, 'declineRequest']);

        // Invite Management
        Route::post('/generate-invite', [OrgManagementController::class, 'generateInviteCode']);
        Route::delete('/remove-invite', [OrgManagementController::class, 'removeInviteCode']);

        // Announcements
        Route::get('/announcements', [OrgManagementController::class, 'announcements']);
        Route::post('/announcements', [OrgManagementController::class, 'createAnnouncement']);
        Route::patch('/announcements/{announcementId}', [OrgManagementController::class, 'updateAnnouncement']);
        Route::delete('/announcements/{announcementId}', [OrgManagementController::class, 'deleteAnnouncement']);

        // Statistics & Activity
        Route::get('/statistics', [OrgManagementController::class, 'statistics']);
        Route::get('/activity-log', [OrgManagementController::class, 'activityLog']);

        // Data Export (NEW)
        Route::get('/export-data', [OrgManagementController::class, 'exportData']);

        // Archive/Restore (NEW)
        Route::post('/archive', [OrgManagementController::class, 'archiveOrganization']);
        Route::post('/restore', [OrgManagementController::class, 'restoreOrganization']);

        // Ownership Transfer (NEW)
        Route::post('/transfer-ownership', [OrgManagementController::class, 'initiateOwnershipTransfer']);
        Route::post('/transfer-ownership/{transferId}/accept', [OrgManagementController::class, 'acceptOwnershipTransfer']);
        Route::post('/transfer-ownership/{transferId}/decline', [OrgManagementController::class, 'declineOwnershipTransfer']);

        // Leave Organization
        Route::post('/leave', [OrgManagementController::class, 'leave']);




        Route::get('/duty-assignments/me', [DutyAssignmentController::class, 'myAssignments']);

        // Duty Schedules
        Route::get('/duty-schedules', [DutyScheduleController::class, 'index']);
        Route::post('/duty-schedules', [DutyScheduleController::class, 'store']);
        Route::get('/duty-schedules/calendar', [DutyScheduleController::class, 'calendar']);

        // Admin Statistics
        Route::get('/duty-schedules/statistics', [DutyScheduleController::class, 'statistics']);
        // NEW: Member Statistics
        Route::get('/duty-schedules/my-statistics', [DutyScheduleController::class, 'memberStatistics']);

        // Route::get('/duty-schedules/statistics', [DutyScheduleController::class, 'statistics']);
        Route::get('/duty-schedules/{dutySchedule}', [DutyScheduleController::class, 'show']);
        Route::patch('/duty-schedules/{dutySchedule}', [DutyScheduleController::class, 'update']);
        Route::delete('/duty-schedules/{dutySchedule}', [DutyScheduleController::class, 'destroy']);
        Route::post('/duty-schedules/{dutySchedule}/duplicate', [DutyScheduleController::class, 'duplicate']);

        // Assignments
        Route::post('/duty-schedules/{dutySchedule}/assignments', [DutyAssignmentController::class, 'store']);
        Route::patch('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}', [DutyAssignmentController::class, 'update']);
        Route::delete('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}', [DutyAssignmentController::class, 'destroy']);
        Route::post('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}/respond', [DutyAssignmentController::class, 'respond']);
        Route::post('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}/check-in', [DutyAssignmentController::class, 'checkIn']);
        Route::post('/duty-schedules/{dutySchedule}/assignments/{dutyAssignment}/check-out', [DutyAssignmentController::class, 'checkOut']);

        // Availability
        Route::get('/duty-availability', [DutyAvailabilityController::class, 'index']);
        Route::post('/duty-availability', [DutyAvailabilityController::class, 'store']);
        Route::patch('/duty-availability/{dutyAvailability}', [DutyAvailabilityController::class, 'update']);
        Route::delete('/duty-availability/{dutyAvailability}', [DutyAvailabilityController::class, 'destroy']);



        // Swap Requests
        Route::get('/duty-swaps', [DutySwapController::class, 'index']);
        Route::post('/duty-assignments/{dutyAssignment}/swap', [DutySwapController::class, 'store']);
        // NEW: Member swap actions
        Route::post('/duty-swaps/{swapRequest}/accept', [DutySwapController::class, 'accept']);
        Route::post('/duty-swaps/{swapRequest}/decline', [DutySwapController::class, 'decline']);
        // Admin swap review
        Route::post('/duty-swaps/{swapRequest}/cancel', [DutySwapController::class, 'cancel']);
        Route::post('/duty-swaps/{swapRequest}/review', [DutySwapController::class, 'review']);

        // // Swap Requests
        // Route::get('/duty-swaps', [DutySwapController::class, 'index']);
        // Route::post('/duty-assignments/{dutyAssignment}/swap', [DutySwapController::class, 'store']);
        // Route::post('/duty-swaps/{swapRequest}/accept', [DutySwapController::class, 'accept']);
        // Route::post('/duty-swaps/{swapRequest}/decline', [DutySwapController::class, 'decline']);
        // Route::post('/duty-swaps/{swapRequest}/cancel', [DutySwapController::class, 'cancel']);

        // // Admin Swap Review - requires approve_duty_swaps permission
        // Route::post('/duty-swaps/{swapRequest}/review', [DutySwapController::class, 'review'])
        //     ->middleware('can:approveDutySwaps,organization');



        // Templates
        Route::get('/duty-templates', [DutyTemplateController::class, 'index']);
        Route::post('/duty-templates', [DutyTemplateController::class, 'store']);
        Route::patch('/duty-templates/{dutyTemplate}', [DutyTemplateController::class, 'update']);
        Route::delete('/duty-templates/{dutyTemplate}', [DutyTemplateController::class, 'destroy']);

        // // Templates - requires manage_duty_schedules permission
        // Route::get('/duty-templates', [DutyTemplateController::class, 'index']);
        // Route::post('/duty-templates', [DutyTemplateController::class, 'store'])
        //     ->middleware('can:manageDutySchedules,organization');
        // Route::patch('/duty-templates/{dutyTemplate}', [DutyTemplateController::class, 'update'])
        //     ->middleware('can:manageDutySchedules,organization');
        // Route::delete('/duty-templates/{dutyTemplate}', [DutyTemplateController::class, 'destroy'])
        //     ->middleware('can:manageDutySchedules,organization');
    });




    /** ---------------- Announcements ---------------- */
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::post('/announcements', [AnnouncementController::class, 'store']);






    // ========================
    // ========================
    // Me Endpoints
    // ========================
    // ========================

    Route::get('/me',       [AuthController::class, 'me']);
    Route::post('/logout',  [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::put('/me/profile',  [ProfileController::class, 'update']);
    Route::post('/me/avatar',  [ProfileController::class, 'uploadAvatar']);
    Route::post('/me/password', [ProfileController::class, 'changePassword']);
});



// Public auth endpoints
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/login',    [AuthController::class, 'login'])->middleware('throttle:12,1');

Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
Route::post('/reset-password',  [PasswordResetController::class, 'reset']);

Route::post('/email/verification-notification', [VerificationNotificationController::class, 'store'])
    ->middleware('throttle:6,1');

Route::get('/email/verify/{id}/{hash}', [VerifyEmailController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

// Google OAuth (API style, stateless)
Route::prefix('auth')->group(function () {
    Route::get('/google/redirect',  [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/google/callback',  [SocialAuthController::class, 'handleGoogleCallback']);
});

// One-time code → JWT
Route::post('/oauth/exchange', [OAuthExchangeController::class, 'exchange']);





// Misc
Route::post('/upload', [UploadController::class, 'upload']);
