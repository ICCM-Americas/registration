<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Creates the currencies table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            // The short currency code (e.g. USD) is the natural primary key.
            $table->string('code', 3)->primary();
            $table->string('name');
            $table->string('symbol');
            // Multiplier from the base currency to this one.
            $table->decimal('rate', 12, 8);
            // Marks the single base currency every price is expressed in.
            $table->boolean('def')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'currencies';
    }
};
