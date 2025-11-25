<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Review\SimpleReviewController;

/*
|--------------------------------------------------------------------------
| V2 Reviews Routes (Sub-File)
|--------------------------------------------------------------------------
|
| These routes are automatically prefixed with '/v2' and use the 
| 'auth:api' middleware via the parent routes/api.php file.
|
*/

// All routes defined here will start with /api/v2/reviews
Route::prefix('reviews')->group(function () {
    // Review Management
    Route::get('/', [SimpleReviewController::class, 'index']);
    Route::post('/', [SimpleReviewController::class, 'store']);
    Route::get('/{review}', [SimpleReviewController::class, 'show']);

    // Version management
    Route::post('/{review}/versions', [SimpleReviewController::class, 'uploadVersion']);

    // Review actions
    Route::post('/{review}/close', [SimpleReviewController::class, 'close']);
    Route::post('/{review}/reopen', [SimpleReviewController::class, 'reopen']);
    Route::post('/{review}/remind', [SimpleReviewController::class, 'sendReminder']);

    // Comments
    Route::get('/{review}/comments', [SimpleReviewController::class, 'getComments']);
    Route::post('/{review}/comments', [SimpleReviewController::class, 'postComment']);

    // Reviewer actions
    Route::patch('/{review}/view', [SimpleReviewController::class, 'markViewed']);
    Route::post('/{review}/approve', [SimpleReviewController::class, 'approve']);
    Route::post('/{review}/decline', [SimpleReviewController::class, 'decline']);
});
