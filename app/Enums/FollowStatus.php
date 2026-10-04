<?php

namespace App\Enums;

enum FollowStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
}
