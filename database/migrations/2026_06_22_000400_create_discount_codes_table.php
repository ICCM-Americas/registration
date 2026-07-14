<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discount codes a registrant may enter against a "discount_code" question. Each
 * code carries a formula that adjusts the final price (see Support\DiscountFormula):
 * +N / -N / =N / *N / N%. The code is matched case-insensitively (ignoring
 * surrounding whitespace), so it is not constrained unique at the database level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->string('code');
            $table->string('formula');
            $table->string('description')->nullable();
            $table->boolean('enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'discount_codes';
    }
};
