<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin-editable registration emails (see EmailTemplate): one row per
 * fixed template key, created lazily with lang-file defaults the first time an
 * admin saves it — so the table starts empty and the defaults still apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->string('key', 64)->unique();
            $table->string('subject');
            $table->text('body');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'email_templates';
    }
};
