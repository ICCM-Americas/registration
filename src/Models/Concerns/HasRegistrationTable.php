<?php

namespace ConferenceTools\Registration\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Resolves each package model's table name from the configurable table prefix
 * (default "registration_", set in config/registration.php), so package tables
 * never collide with host application tables (notably "groups" and "products").
 *
 * The name is derived from the model's class — e.g. QuestionOption =>
 * "registration_question_options" — so a model never carries an unprefixed
 * base name that still needs prefixing. A model that sets an explicit $table is
 * taken at face value (and NOT prefixed): this also makes resolution idempotent,
 * because Model::newInstance() copies the resolved name back into $table.
 *
 * Foreign-key column names (e.g. group_id) are derived by Eloquent from the
 * related model's class name, not its table, so they remain stable regardless
 * of the prefix. Pivot tables are the one exception and must be named
 * explicitly in the belongsToMany() call.
 */
trait HasRegistrationTable
{
    /** The model's table name, under the package's configured table prefix. */
    public function getTable(): string
    {
        if (isset($this->table)) {
            return $this->table;
        }

        $base = Str::snake(Str::pluralStudly(class_basename($this)));

        return config('registration.tables.prefix').$base;
    }
}
