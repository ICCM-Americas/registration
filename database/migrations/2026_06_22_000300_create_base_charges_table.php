<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flat per-registrant conference charges, expressed in the default currency. The
 * total of all enabled base charges is added to every registrant's cost on top of
 * any priced-option selections (accommodation, products). No rows are seeded, so
 * by default there is no flat fee and prices are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->string('name');
            $table->decimal('amount', 10, 2);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'base_charges';
    }
};
