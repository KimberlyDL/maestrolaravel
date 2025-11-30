<?php

// namespace App\Policies;

// use App\Models\User;
// use App\Models\ReviewRequest;
// use App\Models\ReviewRecipient;

// // app/Policies/ReviewRequestPolicy.php
// class ReviewRequestPolicy
// {
//     public function view(User $user, ReviewRequest $review)
//     {
//         $isPublisherSide = $user->organizations()->where('organization_id', $review->publisher_org_id)->exists();
//         $isRecipient     = $review->recipients()->where('reviewer_user_id', $user->id)->exists();
//         return $isPublisherSide || $isRecipient;
//     }

//     public function update(User $user, ReviewRequest $review)
//     {
//         // publisher-side control only
//         return $user->organizations()->where('organization_id', $review->publisher_org_id)->exists();
//     }

//     public function close(User $user, ReviewRequest $review)
//     {
//         return $this->update($user, $review);
//     }
//     public function reopen(User $user, ReviewRequest $review)
//     {
//         return $this->update($user, $review);
//     }
//     public function attachVersion(User $user, ReviewRequest $review)
//     {
//         return $this->update($user, $review);
//     }

//     public function comment(User $user, ReviewRequest $review)
//     {
//         return $this->view($user, $review);
//     }

//     // Recipient actions
//     public function actAsRecipient(User $user, ReviewRequest $review, ReviewRecipient $rec)
//     {
//         return $rec->review_request_id === $review->id && $rec->reviewer_user_id === $user->id;
//     }

//     public function remind(User $user, ReviewRequest $review, ReviewRecipient $rec)
//     {
//         return $this->update($user, $review);
//     }
// }

namespace App\Policies;

use App\Models\{User, ReviewRequest, ReviewRecipient};
use App\Enums\ReviewStatus;

class ReviewRequestPolicy
{
    /**
     * Can user view this review?
     * YES if: Submitter OR Admin OR Recipient
     */
    public function view(User $user, ReviewRequest $review): bool
    {
        if ($review->submitted_by === $user->id) return true;
        if ($user->isOrgRole($review->publisher_org_id, ['admin', 'owner'])) return true;
        return $review->recipients()->where('reviewer_user_id', $user->id)->exists();
    }

    /**
     * Can user update/edit this review?
     * YES if: Submitter OR Admin
     * AND: Not rejected
     * AND: Reviewers haven't responded yet
     */
    public function update(User $user, ReviewRequest $review): bool
    {
        // 1. Check Role
        $canUpdate = ($review->submitted_by === $user->id) || 
                     $user->isOrgRole($review->publisher_org_id, ['admin', 'owner']);

        if (!$canUpdate) return false;

        // 2. Lock if Rejected by Admin
        if ($review->approval_status === 'rejected') {
            return false;
        }

        // 3. Lock if any Reviewer has Approved/Declined
        // We check if any recipient status is NOT pending or viewed (i.e., they made a decision)
        $hasResponses = $review->recipients()
            ->whereIn('status', ['approved', 'declined'])
            ->exists();

        if ($hasResponses) {
            return false;
        }

        return true;
    }

    /**
     * Can user delete this review?
     * YES if: Submitter OR Admin
     * AND: (Is Draft OR Is Rejected)
     */
    public function delete(User $user, ReviewRequest $review): bool
    {
        $canDelete = ($review->submitted_by === $user->id) || 
                     $user->isOrgRole($review->publisher_org_id, ['admin', 'owner']);

        if (!$canDelete) return false;

        // Allow delete if Draft
        if ($review->status === ReviewStatus::Draft) return true;

        // Allow delete if Rejected (even if status is Sent)
        if ($review->approval_status === 'rejected') return true;

        // Allow delete if Closed (optional, usually good for cleanup)
        if ($review->status === ReviewStatus::Closed) return true;

        return false;
    }

    public function comment(User $user, ReviewRequest $review): bool
    {
        return $this->view($user, $review);
    }

    public function close(User $user, ReviewRequest $review): bool
    {
        return $this->update($user, $review);
    }

    public function reopen(User $user, ReviewRequest $review): bool
    {
        return $this->update($user, $review);
    }

    public function attachVersion(User $user, ReviewRequest $review): bool
    {
        return $this->update($user, $review);
    }

    public function actAsRecipient(User $user, ReviewRequest $review, ReviewRecipient $recipient): bool
    {
        return $recipient->reviewer_user_id === $user->id && 
               $recipient->review_request_id === $review->id;
    }

    public function remind(User $user, ReviewRequest $review, ReviewRecipient $recipient): bool
    {
        return $this->update($user, $review);
    }
}