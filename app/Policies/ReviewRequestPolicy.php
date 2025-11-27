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

class ReviewRequestPolicy
{
    /**
     * SIMPLIFIED: Can user view this review?
     * YES if: Submitter OR Recipient (in any organization)
     */
    public function view(User $user, ReviewRequest $review): bool
    {
        // Submitter can always view
        if ($review->submitted_by === $user->id) {
            return true;
        }
        
        // Recipient can view
        return $review->recipients()
            ->where('reviewer_user_id', $user->id)
            ->exists();
    }

    /**
     * SIMPLIFIED: Can user update/edit this review?
     * YES if: Submitter OR Admin of publisher org
     */
    public function update(User $user, ReviewRequest $review): bool
    {
        // Submitter can update (hierarchical - creator has full control)
        if ($review->submitted_by === $user->id) {
            return true;
        }
        
        // Admin of publisher org can update
        return $review->publisher && $review->publisher->isUserAdmin($user->id);
    }

    /**
     * SIMPLIFIED: Can user comment on this review?
     * YES if: Can view the review (submitter or recipient)
     */
    public function comment(User $user, ReviewRequest $review): bool
    {
        return $this->view($user, $review);
    }

    /**
     * SIMPLIFIED: Can user close this review?
     * YES if: Submitter OR Admin of publisher org
     */
    public function close(User $user, ReviewRequest $review): bool
    {
        return $this->update($user, $review);
    }

    /**
     * SIMPLIFIED: Can user reopen this review?
     * YES if: Submitter OR Admin of publisher org
     */
    public function reopen(User $user, ReviewRequest $review): bool
    {
        return $this->update($user, $review);
    }

    /**
     * SIMPLIFIED: Can user attach new version?
     * YES if: Submitter OR Admin of publisher org
     */
    public function attachVersion(User $user, ReviewRequest $review): bool
    {
        return $this->update($user, $review);
    }

    /**
     * SIMPLIFIED: Can user act as recipient (approve/decline)?
     * YES if: User is THIS SPECIFIC recipient
     */
    public function actAsRecipient(User $user, ReviewRequest $review, ReviewRecipient $recipient): bool
    {
        return $recipient->reviewer_user_id === $user->id && 
               $recipient->review_request_id === $review->id;
    }

    /**
     * SIMPLIFIED: Can user send reminder to recipient?
     * YES if: Submitter OR Admin of publisher org
     */
    public function remind(User $user, ReviewRequest $review, ReviewRecipient $recipient): bool
    {
        return $this->update($user, $review);
    }
}