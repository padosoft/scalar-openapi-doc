<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_allowed_servers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scalar_server_id')
                ->constrained('scalar_servers')
                ->cascadeOnDelete();
            $table->timestamps();

            // Leading user_id column also covers user-only lookups.
            $table->unique(['user_id', 'scalar_server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_allowed_servers');
    }
};
