<?php

namespace App\Enums;

enum RecipientStatus: string
{
    case Pending   = 'pending';
    case Viewed    = 'viewed';
    case Commented = 'commented';
    case Approved  = 'approved';
    case Declined  = 'declined';
    case Expired   = 'expired';

    public function isPending(): bool
    {
        return in_array($this, [self::Pending, self::Viewed, self::Commented]);
    }

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Approved,
            self::Declined,
            self::Expired,
        ], true);
    }
}
