<?php

namespace ConferenceTools\Registration\Http\Controllers;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Services\DraftGroupMembers;
use ConferenceTools\Registration\Services\QuestionnaireValidator;
use ConferenceTools\Registration\Services\QuestionRepository;
use ConferenceTools\Registration\Services\RegistrationMailer;
use ConferenceTools\Registration\Services\RegistrationWizard;
use ConferenceTools\Registration\Services\VisibilityEvaluator;
use Illuminate\Http\Request;

/**
 * The "Group Member" hub: lets a registrant who answered "Yes" to the seeded
 * {@see Question::GROUP_TRIGGER_KEY} question add, edit and remove the people
 * they're inviting to join their group before completing the wizard. Reached
 * as an out-of-band detour from {@see RegistrationController::register()},
 * not as a step in the wizard's own linear sequence — mirrors
 * {@see GuestController} exactly.
 *
 * Each entry is only a name and email address, collected purely to compose
 * that person's invite email (see {@see RegistrationMailer::sendGroupMemberInvites()})
 * — never the invitee's own registration answers. Held on the registrant's
 * {@see Draft} (via {@see DraftGroupMembers})
 * until the registration itself is committed, at which point each becomes a
 * real {@see GroupInvite} row (see
 * RegistrationController::commit()).
 */
class GroupMemberController extends Controller
{
    /** The route-name prefix (before the routeName() config prefix) the shared views build their links from. */
    private const ROUTE_PREFIX = 'register.group_members';

    public function __construct(
        private RegistrationWizard $wizard,
        private DraftGroupMembers $draftMembers,
        private QuestionRepository $questions,
        private QuestionnaireValidator $validator,
        private VisibilityEvaluator $visibility,
    ) {}

    /** The hub: every group member added so far, with Add/Edit/Remove actions. */
    public function index(Request $request)
    {
        $draft = $this->wizard->draftFor($request->user());
        $answers = $this->wizard->answers($draft);
        abort_unless($this->triggered($answers), 404);

        return view('registration::group-members.index', [
            'members' => collect($this->draftMembers->all($draft))->map(fn (array $member) => [
                'id' => $member['id'],
                'name' => data_get($member['answers'], Question::GROUP_MEMBER_NAME_KEY),
                'email' => data_get($member['answers'], Question::GROUP_MEMBER_EMAIL_KEY),
            ]),
            'backUrl' => route($this->routeName('register')),
            'routePrefix' => self::ROUTE_PREFIX,
        ]);
    }

    /** The add-member form: every configured group-member question. */
    public function create(Request $request)
    {
        $draft = $this->wizard->draftFor($request->user());
        abort_unless($this->triggered($this->wizard->answers($draft)), 404);

        return $this->form([
            'action' => route($this->routeName('register.group_members.store')),
            'answers' => [],
        ]);
    }

    /** Add a group member to the registrant's draft. */
    public function store(Request $request)
    {
        $draft = $this->wizard->draftFor($request->user());
        abort_unless($this->triggered($this->wizard->answers($draft)), 404);

        $answers = $this->validator->validate(QuestionScope::GroupMember, $request->all());
        $this->draftMembers->add($draft, $answers);

        return redirect()->route($this->routeName('register.group_members'));
    }

    /** The edit-member form: every configured group-member question, pre-filled. */
    public function edit(Request $request, string $member)
    {
        $draft = $this->wizard->draftFor($request->user());
        $entry = $this->draftMembers->find($draft, $member);
        abort_if($entry === null, 404);

        return $this->form([
            'action' => route($this->routeName('register.group_members.update'), $member),
            'answers' => $entry['answers'],
        ]);
    }

    /** Save a drafted group member's answers. */
    public function update(Request $request, string $member)
    {
        $draft = $this->wizard->draftFor($request->user());
        $entry = $this->draftMembers->find($draft, $member);
        abort_if($entry === null, 404);

        $answers = $this->validator->validate(QuestionScope::GroupMember, $request->all());
        $this->draftMembers->update($draft, $member, $answers);

        return redirect()->route($this->routeName('register.group_members'));
    }

    /** Remove a drafted group member. */
    public function destroy(Request $request, string $member)
    {
        $draft = $this->wizard->draftFor($request->user());
        abort_if($this->draftMembers->find($draft, $member) === null, 404);

        $this->draftMembers->remove($draft, $member);

        return redirect()->route($this->routeName('register.group_members'));
    }

    /** @param  array{action: string, answers: array}  $data */
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
