<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Services\AdminSearch;
use ConferenceTools\Registration\Support\Search\InvalidSearchPattern;
use ConferenceTools\Registration\Support\Search\SearchOptions;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The admin Search page: finds a term across questions, options, rules,
 * reports, and registrants' answers, each result linking to where it's edited.
 */
class SearchController extends Controller
{
    /** The search form, with paged results once a search was submitted. */
    public function index(Request $request, AdminSearch $search): View
    {
        if (! $request->has('q')) {
            return $this->page(new SearchOptions);
        }

        $validator = $this->validator($request->query());
        if ($validator->fails()) {
            return $this->page(SearchOptions::fromInput($request->query()))->withErrors($validator);
        }

        $options = SearchOptions::fromInput($validator->validated());

        try {
            $results = $search->paginate($options, fn (string $pageName) => $request->query($pageName, 1));
        } catch (InvalidSearchPattern $e) {
            return $this->page($options)->withErrors(['q' => __('registration::admin.search_invalid_regex', ['error' => $e->getMessage()])]);
        }

        return $this->page($options, $results);
    }

    /** Validation for the search form's input. */
    public static function validator(array $input): ValidatorContract
    {
        return Validator::make($input, [
            'q' => ['required', 'string', 'max:500'],
            'in' => ['required', 'array'],
            'in.*' => [Rule::in(SearchOptions::places())],
            'regex' => ['nullable', 'boolean'],
            'case' => ['nullable', 'boolean'],
            'translations' => ['nullable', 'boolean'],
        ], [
            'in.required' => __('registration::admin.search_choose_place'),
        ]);
    }

    /** The page for the given options and results (null before a search). */
    private function page(SearchOptions $options, ?array $results = null): View
    {
        return view('registration::admin.search.index', [
            'options' => $options,
            'results' => $results,
            'categories' => SearchOptions::CATEGORIES,
            'returnTo' => request()->getSchemeAndHttpHost().request()->getRequestUri(),
        ]);
    }
}
