<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve historical messages privately; no application route accesses them.
        if (Schema::hasTable('tickets')) {
            Schema::rename('tickets', 'archived_support_tickets');
        }
        if (Schema::hasTable('ticket_replies')) {
            Schema::rename('ticket_replies', 'archived_support_ticket_replies');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('archived_support_ticket_replies')) {
            Schema::rename('archived_support_ticket_replies', 'ticket_replies');
        }
        if (Schema::hasTable('archived_support_tickets')) {
            Schema::rename('archived_support_tickets', 'tickets');
        }
    }
};
