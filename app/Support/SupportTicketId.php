<?php

namespace App\Support;

final class SupportTicketId
{
    // Numeric IDs are accepted for tickets created before UUID columns were restored.
    public const ROUTE_PATTERN = '(?:[0-9]+|[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{12})';
}
