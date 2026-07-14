<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin-editable "registration is closed" page messages (see
 * ClosedMessage): one row per fixed key, created with its English default the
 * first time it is needed (ClosedMessage::forKey / ClosedMessageSeeder) so the
 * translations editor always has a row to attach to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->string('key', 64)->unique();
            $table->text('body');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'closed_messages';
    }
};
