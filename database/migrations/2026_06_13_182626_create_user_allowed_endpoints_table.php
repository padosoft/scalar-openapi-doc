<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            // On MySQL/MariaDB: binary collation so /Pets ≠ /pets (OpenAPI paths are
            // case-sensitive). SQLite UNIQUE is binary by default; the collation
            // keyword is not supported there.
            $col = $table->string('path', 255);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $col->collation('utf8mb4_bin');
            }
            $table->timestamps();

            $table->unique(['user_id', 'method', 'path']); // leading column covers user_id-only lookups
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_allowed_endpoints');
    }
};
