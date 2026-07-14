<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Http\Controllers\GroupMemberController;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Services\DraftGroupMembers;
use ConferenceTools\Registration\Services\QuestionnaireValidator;
use ConferenceTools\Registration\Services\QuestionRepository;
use ConferenceTools\Registration\Services\VisibilityEvaluator;
use ConferenceTools\Registration\Support\TestDraft;
use Illuminate\Http\Request;

/**
 * The admin test drive's Group Member hub — mirrors {@see GroupMemberController}
 * exactly, but reads/writes the session-held {@see TestDraft} instead of a
 * real {@see Draft}, so nothing is
 * recorded (see TestRegistrationController's class doc-comment) — no invite
 * rows are ever created or emailed for a test run.
 */
class TestGroupMemberController extends Controller
{
    /** The route-name prefix (before the routeName() config prefix) the shared views build their links from. */
    private const ROUTE_PREFIX = 'admin.test.group_members';

    public function __construct(
        private DraftGroupMembers $draftMembers,
        private QuestionRepository $questions,
        private QuestionnaireValidator $validator,
        private VisibilityEvaluator $visibility,
    ) {}

    /** The test registrant's group member hub. */
    public function index()
    {
        $draft = TestDraft::fromSession();
        abort_unless($this->triggered($draft->answers ?? []), 404);

        return view('registration::group-members.index', [
            'members' => collect($this->draftMembers->all($draft))->map(fn (array $member) => [
                'id' => $member['id'],
                'name' => data_get($member['answers'], Question::GROUP_MEMBER_NAME_KEY),
                'email' => data_get($member['answers'], Question::GROUP_MEMBER_EMAIL_KEY),
            ]),
            'backUrl' => route($this->routeName('admin.test')),
            'routePrefix' => self::ROUTE_PREFIX,
        ]);
    }

    /** Show the add-member form. */
    public function create()
    {
        $draft = TestDraft::fromSession();
        abort_unless($this->triggered($draft->answers ?? []), 404);

        return $this->form([
            'action' => route($this->routeName('admin.test.group_members.store')),
            'answers' => [],
        ]);
    }

    /** Add a group member to the test registration. */
    public function store(Request $request)
    {
        $draft = TestDraft::fromSession();
        abort_unless($this->triggered($draft->answers ?? []), 404);

        $answers = $this->validator->validate(QuestionScope::GroupMember, $request->all());
        $this->draftMembers->add($draft, $answers);

        return redirect()->route($this->routeName('admin.test.group_members'));
    }

    /** Show the edit form for a drafted group member. */
    public function edit(string $member)
    {
        $draft = TestDraft::fromSession();
        $entry = $this->draftMembers->find($draft, $member);
        abort_if($entry === null, 404);

        return $this->form([
            'action' => route($this->routeName('admin.test.group_members.update'), $member),
            'answers' => $entry['answers'],
        ]);
    }

    /** Save a drafted group member's answers. */
    public function update(Request $request, string $member)
    {
        $draft = TestDraft::fromSession();
        abort_if($this->draftMembers->find($draft, $member) === null, 404);

        $answers = $this->validator->validate(QuestionScope::GroupMember, $request->all());
        $this->draftMembers->update($draft, $member, $answers);

        return redirect()->route($this->routeName('admin.test.group_members'));
    }

    /** Remove a drafted group member. */
    public function destroy(string $member)
    {
        $draft = TestDraft::fromSession();
        abort_if($this->draftMembers->find($draft, $member) === null, 404);

        $this->draftMembers->remove($draft, $member);

        return redirect()->route($this->routeName('admin.test.group_members'));
    }

    /** Render the group-member form with the GroupMember-scope questionnaire. */
    private function form(array $data)
    {
        return view('registration::group-members.form', $data + [
            'sections' => $this->questions->sectionsForScope(QuestionScope::GroupMember),
            'evaluator' => $this->visibility,
            'def' => Currency::def(),
            'routePrefix' => self::ROUTE_PREFIX,
        ]);
    }

    /** Whether the draft's answers indicate the registrant is registering a group. */
    private function triggered(array $answers): bool
    {
        return ($answers[Question::GROUP_TRIGGER_KEY] ?? null) === Question::YES_VALUE;
    }
}
