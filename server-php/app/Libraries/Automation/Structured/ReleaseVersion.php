<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Structured;

/**
 * Release-note version numbers: MAJOR.MINOR or MAJOR.MINOR.PATCH, per
 * product (docs/automation/RELEASE_NOTES_AND_GUIDES.md § Versioning).
 *
 *   features (anything a user can newly do or see)   3.4 → 3.5
 *   fixes and small polish only                      3.4 → 3.4.1
 *   a redesign or a new generation of the product    3.4 → 4.0
 *
 * The routine proposes the version; Reach accepts it only when it is one of
 * those three steps up from the highest version already published or in
 * flight for the product — so a version can never go backwards, repeat, or
 * skip (3.4 → 3.7 is refused).
 */
final class ReleaseVersion
{
    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
    ) {
    }

    public static function parse(string $version): ?self
    {
        if (! preg_match('/^(0|[1-9]\d{0,3})\.(0|[1-9]\d{0,3})(?:\.(0|[1-9]\d{0,3}))?$/', trim($version), $m)) {
            return null;
        }

        return new self((int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : 0);
    }

    /** Canonical text: the patch is omitted when it is 0 ("3.5", "3.4.1"). */
    public function __toString(): string
    {
        return $this->major . '.' . $this->minor . ($this->patch > 0 ? '.' . $this->patch : '');
    }

    /** Sortable integer; also the unique key per product. */
    public function sortKey(): int
    {
        return $this->major * 100_000_000 + $this->minor * 10_000 + $this->patch;
    }

    public function compare(self $other): int
    {
        return $this->sortKey() <=> $other->sortKey();
    }

    /** @return array{fix:string, feature:string, major:string} */
    public function nextSteps(): array
    {
        return [
            'fix'     => (string) new self($this->major, $this->minor, $this->patch + 1),
            'feature' => (string) new self($this->major, $this->minor + 1, 0),
            'major'   => (string) new self($this->major + 1, 0, 0),
        ];
    }

    public function isNextStepFrom(self $previous): bool
    {
        return in_array((string) $this, $previous->nextSteps(), true);
    }

    /** "smart-books" + 3.4.1 → "smart-books-v3-4-1" (the hand-written releases' slug style). */
    public function slug(string $prefix): string
    {
        return $prefix . '-v' . str_replace('.', '-', (string) $this);
    }
}
