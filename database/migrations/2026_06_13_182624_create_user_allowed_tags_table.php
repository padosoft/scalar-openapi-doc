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
        Schema::create('user_allowed_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // On MySQL: binary collation so /Pets ≠ /pets (OpenAPI tags are case-sensitive).
            // SQLite UNIQUE is binary by default; collation keyword not supported there.
            $col = $table->string('tag', 191);
            if (DB::getDriverName() === 'mysql') {
                $col->collation('utf8mb4_bin');
            }
            $table->timestamps();

            $table->unique(['user_id', 'tag']); // leading column covers user_id-only lookups
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_allowed_tags');
    }
};
