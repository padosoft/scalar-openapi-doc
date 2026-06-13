<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_logs', function (Blueprint $table): void {
            $table->id();
            // SET NULL on user delete: the audit row must survive user deletion.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Email snapshot, kept readable after the user is deleted.
            $table->string('email', 191);
            $table->string('event', 20); // login | logout | failed
            $table->string('ip_address', 45)->nullable(); // IPv6-capable
            $table->string('user_agent', 512)->nullable();
            // Audit rows are immutable: created_at only, no updated_at.
            // NOT NULL with DB default ensures every audit row has a timestamp even if
            // the insert bypasses Eloquent's timestamping.
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('event');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_logs');
    }
};
