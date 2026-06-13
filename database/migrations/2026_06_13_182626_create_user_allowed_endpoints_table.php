<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_allowed_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Uppercase HTTP verb (GET/POST/...); identity of an operation is (method, path).
            $table->string('method', 10);
            // OpenAPI path template, e.g. /orders/{id}.
            $table->string('path', 255);
            $table->timestamps();

            $table->unique(['user_id', 'method', 'path']); // leading column covers user_id-only lookups
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_allowed_endpoints');
    }
};
