<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\ClosedMessage;
use ConferenceTools\Registration\Models\HomeCardMessage;
use ConferenceTools\Registration\Models\InfoStep;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\AnswerTextSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The admin translations editor: one editor per translatable entity (landing-page
 * step, section, question — a question's options are edited alongside it — or
 * closed-page message) where the admin provides the entity's texts per locale. The entity's own
 * columns keep the base-language text; rows saved here override it for a locale
 * at render time, falling back per TranslatesFields when a locale is missing.
 *
 * A question's (and its options') translations are texts, so they stay
 * editable even once answers are locked; each change runs through
 * {@see AnswerTextSync} so stored answers follow it, and a "preview"
 * submission reports how many would change without saving.
 */
class TranslationController extends Controller
{
    /** URL segment => translatable model. */
    private const TYPES = [
        'step' => InfoStep::class,
        'section' => Section::class,
        'question' => Question::class,
        'closed-message' => ClosedMessage::class,
        'home-card-message' => HomeCardMessage::class,
    ];

    public function __construct(private AnswerTextSync $sync) {}

    /** The translation editor for one entity's translatable fields. */
    public function edit(string $type, int $id)
    {
        return $this->editor($type, $id);
    }

    /** Create or update one locale's texts (empty inputs remove the row). */
    public function save(Request $request, string $type, int $id)
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'max:12', 'regex:/^[a-z]{2,3}([-_][a-z0-9]{2,8})?$/i'],
            'texts' => ['array'],
            'texts.*' => ['array'],
            'texts.*.*' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->synced($request, $type, $id, function (Collection $items) use ($data) {
            foreach ($items as $item) {
                $texts = $data['texts'][$this->itemKey($item)] ?? [];
                foreach ($item->translatableFields() as $field) {
                    $item->storeTranslation($data['locale'], $field, $texts[$field] ?? null);
                }
            }
        });
    }

    /** Remove one locale's translations from an entity. */
    public function destroyLocale(Request $request, string $type, int $id, string $locale)
    {
        return $this->synced($request, $type, $id, function (Collection $items) use ($locale) {
            foreach ($items as $item) {
                $item->translations()->where('locale', $locale)->delete();
            }
        });
    }

    /**
     * Apply $edit to the entity's items — through {@see AnswerTextSync} for a
     * question — and return the refreshed editor, or a preview's count of
     * stored answers it would change.
     */
    private function synced(Request $request, string $type, int $id, callable $edit)
    {
        $entity = $this->entity($type, $id);
        $apply = fn () => $edit($this->items($entity));

        if (! $entity instanceof Question) {
            $apply();

            return $this->editor($type, $id);
        }

        $changes = $this->sync->run($entity, $apply, $request->boolean('preview'));

        return $request->boolean('preview') ? response()->json(['changes' => $changes]) : $this->editor($type, $id);
    }

    /** The translatable entity a route refers to, or 404. */
    private function entity(string $type, int $id): Model
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type]::findOrFail($id);
    }

    /**
     * The entity plus any child entities translated on the same page (a
     * question brings its options along).
     *
     * @return Collection<int, Model>
     */
    private function items(Model $entity): Collection
    {
        return $entity instanceof Question
            ? collect([$entity])->concat($entity->options)
            : collect([$entity]);
    }

    /** The form key an item's inputs post under. */
    private function itemKey(Model $item): string
    {
        return $item instanceof QuestionOption ? 'option-'.$item->id : 'self';
    }

    /**
     * The editor fragment the consoles fetch into their modal; mutations
     * return it refreshed so the modal swaps content in place.
     */
    private function editor(string $type, int $id)
    {
        $items = $this->items($this->entity($type, $id));

        return view('registration::admin.translations.editor', [
            'type' => $type,
            'id' => $id,
            'items' => $items,
            'locales' => $items
                ->flatMap(fn (Model $item) => $item->translations->pluck('locale'))
                ->unique()->sort()->values(),
        ]);
    }
}
