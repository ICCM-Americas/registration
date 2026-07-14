<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\BadgeElementType;
use ConferenceTools\Registration\Enums\BadgeSheetSize;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\BadgeLayoutElement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The badge layout designer: lets an admin place badge name, logo, conference
 * name, organization, and up to two static text/image blocks each, positioned
 * freely (by percentage of the card's own size) and independently per
 * {@see BadgeSheetSize}. A size with no elements yet falls back to the Badges
 * report's built-in default rendering — nothing changes until an admin
 * deliberately customizes a size here.
 */
class BadgeLayoutController extends Controller
{
    /** The badge layout editor. */
    public function index(Request $request)
    {
        $size = $this->badgeSheetSize($request);

        return view('registration::admin.logistics.badges.layout', [
            'sizes' => BadgeSheetSize::cases(),
            'size' => $size,
            'elements' => BadgeLayoutElement::where('sheet_size', $size)->get(),
        ]);
    }

    /** Populate the default element layout for a sheet size. */
    public function seedDefaults(Request $request)
    {
        $size = $this->badgeSheetSize($request);

        BadgeLayoutElement::seedDefaults($size);

        return $this->back($size);
    }

    /** Add a layout element. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'sheet_size' => ['required', Rule::in(BadgeSheetSize::values())],
            'type' => ['required', Rule::in(BadgeElementType::values())],
        ]);

        $size = BadgeSheetSize::from($data['sheet_size']);
        $type = BadgeElementType::from($data['type']);

        $existing = BadgeLayoutElement::where('sheet_size', $size)->where('type', $type)->count();

        if ($existing >= $type->maxPerSize()) {
            return back()->withErrors(['type' => __('registration::admin.badge_layout_max_reached')]);
        }

        // Every other element starts near the middle for the admin to drag
        // into place; Prayer Pals defaults to the lower-left corner instead,
        // since that's where it's conventionally worn.
        [$xPct, $yPct, $widthPct, $align] = $type === BadgeElementType::PrayerPalsGroup
            ? [4, 80, 20, 'left']
            : [40, 40, 20, 'center'];

        BadgeLayoutElement::create([
            'sheet_size' => $size,
            'type' => $type,
            'x_pct' => $xPct,
            'y_pct' => $yPct,
            'width_pct' => $widthPct,
            'align' => $align,
            'font_size_pt' => $type->defaultFontSizePt(),
            'bold' => $type === BadgeElementType::BadgeName,
            'italic' => $type === BadgeElementType::Organization,
        ]);

        return $this->back($size);
    }

    /** Save an element's dragged position. */
    public function updatePosition(Request $request, BadgeLayoutElement $element)
    {
        $data = $request->validate([
            'x_pct' => ['required', 'numeric', 'between:0,100'],
            'y_pct' => ['required', 'numeric', 'between:0,100'],
        ]);

        $element->update($data);

        return response()->json(['status' => 'ok']);
    }

    /** Save an element's settings. */
    public function update(Request $request, BadgeLayoutElement $element)
    {
        $rules = [
            'width_pct' => ['required', 'numeric', 'between:1,100'],
            'align' => ['required', Rule::in(['left', 'center', 'right'])],
        ];

        if ($element->type->isTextual()) {
            $rules['font_size_pt'] = ['required', 'integer', 'between:6,72'];
            $rules['bold'] = ['sometimes', 'boolean'];
            $rules['italic'] = ['sometimes', 'boolean'];
        }

        if ($element->type === BadgeElementType::StaticText) {
            $rules['text'] = ['required', 'string', 'max:500'];
        }

        if ($element->type === BadgeElementType::StaticImage) {
            $rules['image'] = ['nullable', 'image', 'max:2048'];
        }

        $data = $request->validate($rules);

        if ($element->type->isTextual()) {
            $data['bold'] = $request->boolean('bold');
            $data['italic'] = $request->boolean('italic');
        }

        if ($element->type === BadgeElementType::StaticImage && $request->hasFile('image')) {
            $file = $request->file('image');
            $data['image_data'] = base64_encode((string) file_get_contents($file->getRealPath()));
            $data['image_mime'] = $file->getMimeType();
        }

        $element->update($data);

        return $this->back($element->sheet_size);
    }

    /** Remove a layout element. */
    public function destroy(BadgeLayoutElement $element)
    {
        $size = $element->sheet_size;
        $element->delete();

        return $this->back($size);
    }

    /** The requested sheet size, defaulting by locale. */
    private function badgeSheetSize(Request $request): BadgeSheetSize
    {
        return BadgeSheetSize::tryFrom((string) $request->query('size'))
            ?? BadgeSheetSize::default($this->isUsLocale());
    }

    /** Redirect back to the editor with a status message. */
    private function back(BadgeSheetSize $size)
    {
        return redirect()->route($this->routeName('admin.logistics.badges.layout'), ['size' => $size->value]);
    }
}
