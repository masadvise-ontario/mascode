<?php

declare(strict_types=1);

// file: Civi/Mascode/Event/VcDigestTokenSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Token\Event\TokenRegisterEvent;
use Civi\Token\Event\TokenValueEvent;

/**
 * Provides `{digest.*}` tokens for the monthly VC digest template.
 *
 * Spec: BrianPKM 3-Resources/mascode-vc-monthly-donation-digest-spec.md,
 * component `VcDigestTokenSubscriber`. Ticket: P1-4.
 *
 * Tokens: {digest.project_rows}, {digest.project_count}, {digest.month}
 *
 * WHY A TOKEN PROVIDER AND NOT JUST PHP STRING CONCATENATION
 * ---------------------------------------------------------------------------
 * The body has to stay in a managed MessageTemplate, because the copy is not
 * ours: the spec's Open Questions put "how hard the ask is" with Steve, and
 * the digest wording with Nina. If the rows were assembled in PHP, changing a
 * sentence would need a deploy, and the thing most likely to need changing
 * after the pilot IS the wording.
 *
 * HOW THE ROWS GET HERE
 * ---------------------------------------------------------------------------
 * No merge tag can produce N rows each carrying its own minted link, so the
 * MAILER builds them and passes them through the TokenProcessor row context
 * under `masDigestRows`. This subscriber only renders what it is handed. That
 * split is deliberate: minting a link requires the Afform record and the
 * crypto service, which is mailer work, while this class stays a pure
 * formatter and is unit-testable without either.
 *
 * A row context missing `masDigestRows` yields EMPTY tokens rather than an
 * error, because this subscriber fires for every TokenProcessor in the system
 * — including every unrelated lifecycle email — and a digest token has no
 * meaning there.
 */
class VcDigestTokenSubscriber extends AutoSubscriber
{
    /** Row context key the mailer passes its prepared rows under. */
    public const ROWS_CONTEXT_KEY = 'masDigestRows';

    /** Row context key for the `YYYY-MM` round. */
    public const ROUND_CONTEXT_KEY = 'masDigestRound';

    public static function getSubscribedEvents(): array
    {
        return [
            'civi.token.list' => 'registerTokens',
            'civi.token.eval' => 'evaluateTokens',
        ];
    }

    public function registerTokens(TokenRegisterEvent $e): void
    {
        $e->entity('digest')
            ->register('project_rows', ts('Monthly digest: the project rows, each with its own link'))
            ->register('project_count', ts('Monthly digest: how many projects are listed'))
            ->register('month', ts('Monthly digest: the round, as YYYY-MM'));
    }

    public function evaluateTokens(TokenValueEvent $e): void
    {
        foreach ($e->getRows() as $row) {
            $rows = $row->context[self::ROWS_CONTEXT_KEY] ?? null;
            if (!is_array($rows)) {
                // Not a digest render. Leave the tokens unset rather than
                // writing empty strings: this event fires for every
                // TokenProcessor in the system, and claiming a value here
                // would blank `{digest.*}` in any template that happened to
                // mention it for another reason.
                continue;
            }

            $round = (string) ($row->context[self::ROUND_CONTEXT_KEY] ?? '');

            // text/html: project_rows IS markup. Declaring the format is what
            // stops the TokenProcessor escaping the table it just built.
            $row->format('text/html');
            $row->tokens('digest', 'project_rows', self::renderRows($rows));
            $row->tokens('digest', 'project_count', (string) count($rows));
            $row->tokens('digest', 'month', $round);
        }
    }

    /**
     * @see \Civi\Mascode\Digest\DigestRowRenderer — the HTML lives there so
     *      CI can test it; this class extends AutoSubscriber and cannot load
     *      without CiviCRM.
     */
    public static function renderRows(array $rows): string
    {
        return \Civi\Mascode\Digest\DigestRowRenderer::renderRows($rows);
    }
}
