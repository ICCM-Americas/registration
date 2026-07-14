<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Creates the groups table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->bigIncrements('id');
            // A group owns only its identity (organization name) and booking
            // state as columns; everything else (website, org type, billing
            // address…) is kept in the EAV answer store.
            $table->string('name');
            $table->boolean('checked_out')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'groups';
    }
};
