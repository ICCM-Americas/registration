<?php

namespace ConferenceTools\Registration\Support\Search;

/** The registration an answer hit belongs to — a registrant, one of their guests, or one of their draft's guests — in a form a checkbox can carry. */
final class SearchTarget
{
    public function __construct(
        public readonly int $userId,
        public readonly ?int $guestId = null,
        public readonly ?string $draftGuestId = null,
    ) {}

    /** Parse an encoded target, or null when it isn't one. */
    public static function decode(string $encoded): ?self
    {
        $parts = explode(':', $encoded, 3);
        $userId = (int) ($parts[1] ?? 0);
        $rest = $parts[2] ?? '';

        return match (true) {
            $userId < 1 => null,
            $parts[0] === 'user' && count($parts) === 2 => new self($userId),
            $parts[0] === 'guest' && ctype_digit($rest) => new self($userId, (int) $rest),
            $parts[0] === 'draft-guest' && $rest !== '' => new self($userId, null, $rest),
            default => null,
        };
    }

    /** The checkbox value: "user:5", "guest:5:12", or "draft-guest:5:{uuid}". */
    public function encode(): string
    {
        return match (true) {
            $this->guestId !== null => 'guest:'.$this->userId.':'.$this->guestId,
            $this->draftGuestId !== null => 'draft-guest:'.$this->userId.':'.$this->draftGuestId,
            default => 'user:'.$this->userId,
        };
    }

    /** Whether this targets a guest (committed or draft) rather than the registrant. */
    public function isGuest(): bool
    {
        return $this->guestId !== null || $this->draftGuestId !== null;
    }
}
