<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ReviewRequest;
use App\Models\ReviewRecipient;

// app/Policies/ReviewRequestPolicy.php
class ReviewRequestPolicy
{
    public function view(User $user, ReviewRequest $review)
    {
        $isPublisherSide = $user->organizations()->where('organization_id', $review->publisher_org_id)->exists();
        $isRecipient     = $review->recipients()->where('reviewer_user_id', $user->id)->exists();
        return $isPublisherSide || $isRecipient;
    }

    public function update(User $user, ReviewRequest $review)
    {
        // publisher-side control only
        return $user->organizations()->where('organization_id', $review->publisher_org_id)->exists();
    }

    public function close(User $user, ReviewRequest $review)
    {
        return $this->update($user, $review);
    }
    public function reopen(User $user, ReviewRequest $review)
    {
        return $this->update($user, $review);
    }
    public function attachVersion(User $user, ReviewRequest $review)
    {
        return $this->update($user, $review);
    }

    public function comment(User $user, ReviewRequest $review)
    {
        return $this->view($user, $review);
    }

    // Recipient actions
    public function actAsRecipient(User $user, ReviewRequest $review, ReviewRecipient $rec)
    {
        return $rec->review_request_id === $review->id && $rec->reviewer_user_id === $user->id;
    }

    public function remind(User $user, ReviewRequest $review, ReviewRecipient $rec)
    {
        return $this->update($user, $review);
    }
}
