<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The numbered "how registering works" steps shown on the public registration
 * landing page. Admins manage them on the admin "Steps" page (add, remove,
 * reorder, hide), so both the texts and the number of steps are configurable.
 * No rows are created here — InfoStepSeeder inserts the default three steps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->string('heading');
            $table->text('body');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'info_steps';
    }
};
