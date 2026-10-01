<?php

declare(strict_types=1);

// File: Civi/Mascode/Event/VcPortalScopeSubscriber.php

namespace Civi\Mascode\Event;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\CiviCase;
use Civi\Api4\SavedSearch;
use Civi\Api4\SearchDisplay;
use Civi\Core\Service\AutoSubscriber;
use Civi\Mascode\Mcp\Vc\Api4VcScopeSource;
use Civi\Mascode\Mcp\Vc\VcScopeResolver;
use Civi\Mascode\Security\VcPortalScope;
use CRM_Mascode_ExtensionUtil as E;

/**
 * Puts the VC portal on the same access rules as the MAS CiviCRM MCP (ticket T12; VC access spec
 * D13): every portal list and case-detail section is limited to the signed-in contact's scope sets,
 * resolved by the same Civi\Mascode\Mcp\Vc\VcScopeResolver the MCP uses.
 *
 * TWO JOBS, both on `civi.api.prepare`:
 *
 * 1. FILL THE PLACEHOLDERS. A portal saved search carries VcPortalScope::clause() placeholders. When
 *    SearchKit runs its query — an APIv4 `get` with checkPermissions FALSE, because portal displays
 *    are acl_bypass — each placeholder in an AND position is replaced by the ids of its set. The
 *    resolver checks the five scope searches against their mascode declaration (drift fails
 *    closed), runs the DECLARATION rather than the stored copy, and refuses when the relationship
 *    cache disagrees with Relationship (the T32 stale-row gate). On any refusal the placeholders are
 *    left in place, and they match nothing. Only permission-off gets are touched: a caller's own
 *    APIv4 request (always checkPermissions TRUE over AJAX) is never rewritten.
 *
 * 2. REFUSE A DRIFTED PORTAL SEARCH. acl_bypass means the stored search IS the access rule, and a
 *    Search Kit UI edit survives `cv flush` (core CRM_Core_ManagedEntities::optimizePlan). So any
 *    SearchDisplay action naming a portal search must name one of its declared displays, and the
 *    stored search and display must equal their declarations in VcPortalScope::DECLARATION_FILES —
 *    otherwise the call is refused before it runs. Without this, deleting the placeholder in the UI
 *    would turn a portal display into "every case" for any signed-in VC.
 *
 * WHO. The contact is CRM_Core_Session::getLoggedInContactID(): on the portal the WordPress login IS
 * the authentication. Staff who open a portal page see their own scope, like anyone else.
 *
 * D22 — CONSENTED. The client-feedback card is limited to `consented`: the in-scope cases whose raw
 * Project_Close_Client.share_with_vc is exactly 'Yes', compared in PHP. SQL equality under the
 * site's collation also matches 'yes' and 'Yes ', which is why the card's own `:name` clause is not
 * enough on its own. Same constant as the MCP (Civi\Mascode\Mcp\Vc\VcScopePolicy::SHARE_YES), not
 * referenced here because that class implements a civicrm_mcp interface and must not load while
 * civicrm_mcp is off; tests/Security/CaseDetailAccessTest.php asserts the two agree.
 */
class VcPortalScopeSubscriber extends AutoSubscriber
{
    public const SHARE_FIELD = 'Project_Close_Client.share_with_vc';
    public const SHARE_YES = 'Yes';

    /** @var array<string, array{searches: array, displays: array}>|null */
    private static ?array $declarations = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'civi.api.prepare' => ['onApiPrepare', 100],
        ];
    }

    /** @param \Civi\API\Event\PrepareEvent $event */
    public function onApiPrepare($event): void
    {
        $request = $event->getApiRequest();
        if (!is_object($request) || !method_exists($request, 'getEntityName')) {
            return;
        }
        // APIv4 parameter getters are magic (__call), so read them through getParams().
        if ($request->getEntityName() === 'SearchDisplay' && method_exists($request, 'getParams')) {
            $params = $request->getParams();
            if (array_key_exists('savedSearch', $params)) {
                $this->refuseDrift($params['savedSearch'], $params['display'] ?? null);
            }
            return;
        }
        if ($request instanceof \Civi\Api4\Generic\AbstractGetAction && $request->getCheckPermissions() === false) {
            $this->fillPlaceholders($request);
        }
    }

    private function fillPlaceholders(\Civi\Api4\Generic\AbstractGetAction $request): void
    {
        $params = $request->getParams();
        $where = (array) ($params['where'] ?? []);
        $hasJoin = array_key_exists('join', $params);
        $join = $hasJoin ? (array) $params['join'] : [];
        $wanted = VcPortalScope::setsIn($where, $join);
        if (!$wanted) {
            return;
        }
        $sets = $this->resolve($wanted);
        if (!$sets) {
            return;
        }
        [$where, $join] = VcPortalScope::substitute($where, $join, $sets);
        $request->setWhere($where);
        if ($hasJoin) {
            $request->setJoin($join);
        }
    }

    /**
     * @param string[] $wanted
     * @return array<string, int[]> empty when the scope is refused
     */
    private function resolve(array $wanted): array
    {
        $contactId = (int) \CRM_Core_Session::getLoggedInContactID();
        try {
            $scope = (new VcScopeResolver(new Api4VcScopeSource()))->resolve($contactId);
        } catch (\Throwable $e) {
            // ScopeRefused carries a reason with no data in it; anything else was already logged by
            // the resolver. Either way the placeholders stay and the display is empty.
            \Civi::log()->warning('VC portal scope refused: ' . $e->getMessage());
            return [];
        }
        $sets = [
            'own' => $scope->ownCases,
            'pool' => $scope->poolCases,
            'cases' => $scope->cases,
            'contacts' => $scope->contacts,
        ];
        if (in_array('consented', $wanted, true)) {
            $sets['consented'] = $this->consented($scope->cases);
        }
        return $sets;
    }

    /**
     * D22: the cases among $cases whose raw share answer is exactly 'Yes'. The SQL clause only
     * narrows the read; the PHP comparison decides.
     *
     * @param int[] $cases
     * @return int[]
     */
    private function consented(array $cases): array
    {
        if (!$cases) {
            return [];
        }
        $out = [];
        $rows = CiviCase::get(false)
            ->addSelect('id', self::SHARE_FIELD)
            ->addWhere('id', 'IN', $cases)
            ->addWhere(self::SHARE_FIELD, '=', self::SHARE_YES)
            ->execute();
        foreach ($rows as $row) {
            if (($row[self::SHARE_FIELD] ?? null) === self::SHARE_YES) {
                $out[] = (int) $row['id'];
            }
        }
        return $out;
    }

    private function refuseDrift(mixed $search, mixed $display): void
    {
        if (!is_string($search)) {
            // An unsaved search (preview mode) needs `manage own search_kit`, which no VC holds.
            return;
        }
        $declared = self::declarations();
        if (!isset($declared['searches'][$search])) {
            return;
        }
        if ($display === null) {
            // The default display has no acl_bypass, so its query keeps checkPermissions TRUE and no
            // placeholder is filled: a VC gets nothing from it.
            return;
        }
        $problem = self::driftOf($search, is_string($display) ? $display : null, $declared);
        if ($problem !== null) {
            \Civi::log()->error("VC portal display refused: $problem");
            throw new UnauthorizedException('This display is unavailable.');
        }
    }

    /**
     * Why the stored $search / $display may not run, or NULL when both equal their declaration.
     * Public for scripts/check-vc-portal.php.
     *
     * @param array{searches: array, displays: array} $declared
     */
    public static function driftOf(string $search, ?string $display, array $declared): ?string
    {
        if ($display === null || !isset($declared['displays'][$search][$display])) {
            return "$search: display is not declared";
        }
        $want = $declared['searches'][$search];
        $stored = SavedSearch::get(false)->addSelect('api_entity', 'api_params')
            ->addWhere('name', '=', $search)->execute()->first();
        if (
            !$stored || $stored['api_entity'] !== $want['api_entity']
            || !VcPortalScope::same($stored['api_params'], $want['api_params'])
        ) {
            return "$search: saved search differs from its declaration";
        }
        $want = $declared['displays'][$search][$display];
        $stored = SearchDisplay::get(false)->addSelect('type', 'settings', 'acl_bypass')
            ->addWhere('saved_search_id.name', '=', $search)
            ->addWhere('name', '=', $display)->execute()->first();
        if (
            !$stored || $stored['type'] !== $want['type'] || (bool) $stored['acl_bypass'] !== (bool) $want['acl_bypass']
            || !VcPortalScope::same($stored['settings'], $want['settings'])
        ) {
            return "$search: display $display differs from its declaration";
        }
        return null;
    }

    /**
     * The portal's declared searches and displays, read from mascode's own files.
     *
     * @return array{searches: array<string, array{api_entity: string, api_params: array}>,
     *   displays: array<string, array<string, array{type: string, settings: array, acl_bypass: bool}>>}
     */
    public static function declarations(): array
    {
        if (self::$declarations !== null) {
            return self::$declarations;
        }
        $out = ['searches' => [], 'displays' => []];
        foreach (VcPortalScope::DECLARATION_FILES as $file) {
            foreach ((array) (include E::path($file)) as $decl) {
                $values = $decl['params']['values'] ?? [];
                if (($decl['entity'] ?? null) === 'SavedSearch') {
                    $out['searches'][$values['name']] = [
                        'api_entity' => $values['api_entity'],
                        'api_params' => $values['api_params'],
                    ];
                } elseif (($decl['entity'] ?? null) === 'SearchDisplay') {
                    $out['displays'][$values['saved_search_id.name']][$values['name']] = [
                        'type' => $values['type'],
                        'settings' => $values['settings'],
                        'acl_bypass' => (bool) ($values['acl_bypass'] ?? false),
                    ];
                }
            }
        }
        return self::$declarations = $out;
    }
}
