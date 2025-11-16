<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case Draft             = 'draft';
    case Sent              = 'sent';
    case InReview          = 'in_review';
    case ChangesRequested  = 'changes_requested';
    case Approved          = 'approved';
    case Declined          = 'declined';
    case Closed            = 'closed';
    case Cancelled         = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Approved,
            self::Declined,
            self::Closed,
            self::Cancelled,
        ], true);
    }
}
