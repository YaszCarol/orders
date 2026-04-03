<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Reviewing = 'reviewing';
    case Blocked = 'blocked';
}
