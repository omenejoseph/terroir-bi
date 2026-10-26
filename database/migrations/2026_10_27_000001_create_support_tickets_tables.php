<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Central, like tenants and plans: platform admins work the queue
        // across every tenant, so a ticket is reached by its tenant_id, not
        // through the tenant scope. See App\Models\SupportTicket.
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('number')->unique(); // "#1042" — what people quote to each other
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete(); // a platform admin
            $table->string('subject');
            $table->string('category', 32);   // App\Enums\SupportTicketCategory
            $table->string('priority', 16)->default('normal'); // App\Enums\SupportTicketPriority
            $table->string('status', 32)->default('open');     // App\Enums\SupportTicketStatus
            $table->string('page_url', 2048)->nullable(); // where in the app it was raised from
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'last_message_at']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignUlid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_staff')->default(false);    // written by a platform admin
            $table->boolean('is_internal')->default(false); // staff-only note, never shown to the tenant
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
