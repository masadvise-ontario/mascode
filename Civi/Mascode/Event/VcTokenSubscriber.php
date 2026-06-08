<?php

declare(strict_types=1);

// file: Civi/Mascode/Event/VcTokenSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Token\Event\TokenRegisterEvent;
use Civi\Token\Event\TokenValueEvent;

/**
 * Provides {vc.*} tokens for lifecycle message templates.
 *
 * "Assigned VC" on a MAS case = the active "Case Coordinator is (MAS Rep)"
 * case role (relationship type name_a_b 'Case Coordinator is', contact_id_a).
 * Resolves only when the TokenProcessor row context carries a caseId —
 * exactly the schema LifecycleMailer renders with.
 *
 * Tokens: {vc.display_name}, {vc.first_name}, {vc.email}
 */
class VcTokenSubscriber extends AutoSubscriber
{
    public static function getSubscribedEvents(): array
    {
        return [
            'civi.token.list' => 'registerTokens',
            'civi.token.eval' => 'evaluateTokens',
        ];
    }

    public function registerTokens(TokenRegisterEvent $e): void
    {
        $e->entity('vc')
            ->register('display_name', ts('Assigned VC: Display Name'))
            ->register('first_name', ts('Assigned VC: First Name'))
            ->register('email', ts('Assigned VC: Email'));
    }

    public function evaluateTokens(TokenValueEvent $e): void
    {
        $cache = [];
        foreach ($e->getRows() as $row) {
            $caseId = $row->context['caseId'] ?? null;
            if (empty($caseId)) {
                continue;
            }
            if (!array_key_exists($caseId, $cache)) {
                $cache[$caseId] = self::lookupVc((int) $caseId);
            }
            $vc = $cache[$caseId];
            $row->format('text/plain');
            $row->tokens('vc', 'display_name', $vc['display_name'] ?? '');
            $row->tokens('vc', 'first_name', $vc['first_name'] ?? '');
            $row->tokens('vc', 'email', $vc['email'] ?? '');
        }
    }

    /**
     * Find the assigned VC (Case Coordinator role) for a case.
     */
    private static function lookupVc(int $caseId): array
    {
        $rel = \Civi\Api4\Relationship::get(false)
            ->addSelect('contact_id_a.display_name', 'contact_id_a.first_name', 'contact_id_a.email_primary.email')
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('relationship_type_id:name', '=', 'Case Coordinator is')
            ->addWhere('is_active', '=', true)
            ->addOrderBy('id', 'DESC')
            ->setLimit(1)
            ->execute()
            ->first();

        if (!$rel) {
            return [];
        }
        return [
            'display_name' => $rel['contact_id_a.display_name'] ?? '',
            'first_name' => $rel['contact_id_a.first_name'] ?? '',
            'email' => $rel['contact_id_a.email_primary.email'] ?? '',
        ];
    }
}
