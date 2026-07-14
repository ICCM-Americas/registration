<?php

namespace ConferenceTools\Registration\Models\Concerns;

use ConferenceTools\Registration\Models\Translation;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Makes a model's registrant-facing text fields translatable through the
 * admin-managed {@see Translation} rows. The model's own column holds the
 * base-language text — whatever language it was authored or imported in, not
 * necessarily English — and {@see translate()} resolves a field for a locale:
 *
 *   requested locale → the app fallback locale → the base column.
 *
 * So when only one language exists (just the base text), every locale falls
 * back to that language. Using models must define {@see translatableFields()}.
 */
trait TranslatesFields
{
    /** Delete an entity's translations with it. */
    public static function bootTranslatesFields(): void
    {
        // No polymorphic FK exists, so remove an entity's translations with it.
        static::deleted(fn ($model) => $model->translations()->delete());
    }

    /** The entity's stored field translations. */
    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    /** The fields whose text admins can translate. @return array<int, string> */
    abstract public function translatableFields(): array;

    /** Whether any admin-provided translation exists, in any locale. */
    public function isTranslated(): bool
    {
        return $this->translations->isNotEmpty();
    }

    /** The $field text for the locale (current app locale by default). */
    public function translate(string $field, ?string $locale = null): ?string
    {
        $byLocale = $this->translations
            ->where('field', $field)
            ->keyBy('locale');

        return $byLocale->get($locale ?? app()->getLocale())?->value
            ?? $byLocale->get(config('app.fallback_locale'))?->value
            ?? $this->getAttribute($field);
    }

    /**
     * Store one field's translation, treating an empty value as removal so an
     * admin can clear a translation to re-expose the fallback.
     */
    public function storeTranslation(string $locale, string $field, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            $this->translations()->where('field', $field)->where('locale', $locale)->delete();

            return;
        }

        $this->translations()->updateOrCreate(
            ['field' => $field, 'locale' => $locale],
            ['value' => $value],
        );
    }
}
