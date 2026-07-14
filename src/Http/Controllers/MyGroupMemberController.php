<?php

namespace ConferenceTools\Registration\Http\Controllers;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\QuestionnaireValidator;
use ConferenceTools\Registration\Services\QuestionRepository;
use ConferenceTools\Registration\Services\RegistrationMailer;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Services\VisibilityEvaluator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The registrant-facing "Manage Group Members" hub for an already-committed
 * registration: only the group's admin may reach it. Lists every group
 * member — completed registrants read-only, plus every still-pending
 * {@see GroupInvite} with Add/Edit/Remove — mirrors
 * {@see GroupMemberController} (the pre-commit hub, which operates on a
 * wizard draft's JSON instead), reusing the same
 * `resources/views/group-members/{index,form}.blade.php` views (the index
 * view gained a small "registered" flag to render a completed member
 * read-only rather than with edit/remove links).
 *
 * A consumed invite (the member has already registered) is fully locked here:
 * its own answers are vestigial once a real account exists (the actual
 * registrant's name/details live on their own committed Participant-scope
 * answers, entirely separately — see {@see GroupInvite}'s own doc-comment),
 * so edit/update/destroy all 404 for one, not just destroy.
 */
class MyGroupMemberController extends Controller
{
    /** The route-name prefix (before the routeName() config prefix) the shared views build their links from. */
    private const ROUTE_PREFIX = 'mine.group_members';

    public function __construct(
        private QuestionRepository $questions,
        private QuestionnaireValidator $validator,
        private AnswerStore $answers,
        private VisibilityEvaluator $visibility,
        private RegistrationStatus $status,
        private RegistrationMailer $mailer,
        private ReportQuestions $reportQuestions,
    ) {}

    /** The hub: completed members read-only, pending invites with Add/Edit/Remove actions. */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->eligible($user), 404);

        $completed = $user->group->users()
            ->where($user->getKeyName(), '!=', $user->getKey())
            ->get()
            ->map(fn (Model $m) => [
                'id' => null,
                'name' => $this->reportQuestions->fullName($m),
                'email' => null,
                'registered' => true,
            ]);

        $pending = GroupInvite::query()
            ->where('group_id', $user->group_id)
            ->whereNull('consumed_at')
            ->get()
            ->map(fn (GroupInvite $invite) => [
                'id' => $invite->id,
                'name' => $invite->registrationAnswers()->value(Question::GROUP_MEMBER_NAME_KEY),
                'email' => $invite->registrationAnswers()->value(Question::GROUP_MEMBER_EMAIL_KEY),
                'registered' => false,
            ]);

        return view('registration::group-members.index', [
            'members' => $completed->concat($pending)->values(),
            'backUrl' => route($this->routeName('mine')),
            'routePrefix' => self::ROUTE_PREFIX,
        ]);
    }

    /** The add-member form: every configured group-member question. */
    public function create(Request $request)
    {
        abort_unless($this->eligible($request->user()), 404);

        return $this->form([
            'action' => route($this->routeName('mine.group_members.store')),
            'answers' => [],
        ]);
    }

    /** Add a group member to the group, creating an unconsumed invite and emailing it. */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->eligible($user), 404);

        $answers = $this->validator->validate(QuestionScope::GroupMember, $request->all());

        $invite = GroupInvite::create(['group_id' => $user->group_id, 'token' => bin2hex(random_bytes(8))]);
        $this->answers->store(QuestionScope::GroupMember, $invite, $answers);

        // A leader who originally registered solo may only now be declaring
        // an actual group — keep the reporting flag accurate (see
        // RegistrationExportBundle).
        if (! $user->group->is_group) {
            $user->group->update(['is_group' => true]);
        }

        $this->mailer->sendGroupMemberInvites($user, collect([$invite]));

        return redirect()->route($this->routeName('mine.group_members'));
    }

    /** The edit-member form: every configured group-member question, pre-filled. Refused for an already-consumed invite. */
    public function edit(Request $request, GroupInvite $member)
    {
        $user = $request->user();
        abort_unless($this->eligible($user), 404);
        $this->ensureOwnsInvite($user, $member);
        abort_if($member->isConsumed(), 404);

        return $this->form([
            'action' => route($this->routeName('mine.group_members.update'), $member),
            'answers' => $member->registrationAnswers()->values(),
        ]);
    }

    /** Save a pending invite's answers; re-sends the invite email if the email address itself changed. */
    public function update(Request $request, GroupInvite $member)
    {
        $user = $request->user();
        abort_unless($this->eligible($user), 404);
        $this->ensureOwnsInvite($user, $member);
        abort_if($member->isConsumed(), 404);

        $previousEmail = $member->registrationAnswers()->value(Question::GROUP_MEMBER_EMAIL_KEY);

        $answers = $this->validator->validate(QuestionScope::GroupMember, $request->all());
        $this->answers->store(QuestionScope::GroupMember, $member, $answers);

        if (($answers[Question::GROUP_MEMBER_EMAIL_KEY] ?? null) !== $previousEmail) {
            $this->mailer->sendGroupMemberInvites($user, collect([$member]));
        }

        return redirect()->route($this->routeName('mine.group_members'));
    }

    /** Remove a pending invite. Refused for an already-consumed one — a registered member cannot be removed from here. */
    public function destroy(Request $request, GroupInvite $member)
    {
        $user = $request->user();
        abort_unless($this->eligible($user), 404);
        $this->ensureOwnsInvite($user, $member);
        abort_if($member->isConsumed(), 404);

        $member->delete();

        return redirect()->route($this->routeName('mine.group_members'));
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

    /** Whether the signed-in user may manage their group's members: the group's admin, and still eligible to modify. */
    private function eligible(Model $user): bool
    {
        return $user->is_group_admin && $this->status->eligibleToModify($user);
    }

    /** 404 unless the invite actually belongs to this registrant's group. */
    private function ensureOwnsInvite(Model $user, GroupInvite $invite): void
    {
        abort_unless((int) $invite->group_id === (int) $user->group_id, 404);
    }
}
