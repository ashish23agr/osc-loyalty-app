<?php

namespace App\Support\Api;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A business-rule refusal, rendered in the standard error envelope.
 *
 * The envelope is fixed by plan section 5.2 — { error: { code, message,
 * details } } — and the React app branches on `code`, never on the message. So
 * the code is the contract and lives here as a named constructor per refusal,
 * rather than being spelled out at each call site where a typo would silently
 * create a code nothing handles.
 *
 * Validation failures do not come through here: they are ValidationException,
 * converted to the same envelope in bootstrap/app.php, because a form request
 * has already produced the per-field detail.
 */
class ApiException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** A record that does not exist, or belongs to another shop. Never 403. */
    public static function notFound(string $message = 'That record does not exist on this shop.'): self
    {
        return new self('not_found', $message, 404);
    }

    /**
     * A merged account is a tombstone: it keeps its history so a dispute can be
     * explained, but nothing may be posted to it or edited on it. The surviving
     * account id is in the details so the console can offer a link rather than
     * a dead end.
     */
    public static function accountMerged(int $accountId, ?int $survivingAccountId): self
    {
        return new self(
            'account_merged',
            'This account was merged. Work on the surviving account instead.',
            409,
            ['account_id' => $accountId, 'merged_into_account_id' => $survivingAccountId],
        );
    }

    public static function adjustmentLimitExceeded(int $requested, int $limit): self
    {
        return new self(
            'adjustment_limit_exceeded',
            'This adjustment is larger than your limit. Ask a Manager to make it.',
            409,
            ['requested_points' => $requested, 'limit_points' => $limit],
        );
    }

    /** The email is the identifier, so it is corrected by a merge, not an edit. */
    public static function emailImmutable(int $accountId): self
    {
        return new self(
            'email_immutable',
            'The email address is the member identifier and cannot be edited. Merge the records instead.',
            409,
            ['account_id' => $accountId],
        );
    }

    public static function duplicateEmail(string $email, int $existingAccountId): self
    {
        return new self(
            'duplicate_email',
            'A member already holds that email address.',
            409,
            ['email' => $email, 'account_id' => $existingAccountId],
        );
    }

    public static function lastAdministrator(string $message): self
    {
        return new self('last_administrator', $message, 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => array_filter([
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details === [] ? null : $this->details,
            ], fn ($value) => $value !== null),
        ], $this->status);
    }
}
