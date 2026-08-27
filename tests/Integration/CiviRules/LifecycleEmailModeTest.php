<?php

namespace Civi\Mascode\Test\Integration\CiviRules;

use Civi\Mascode\Test\TestCase;
use Civi\Mascode\CiviRules\Action\LifecycleEmail;

/**
 * Regression tests for the send mode of delayed lifecycle emails.
 *
 * @covers \Civi\Mascode\CiviRules\Action\LifecycleEmail
 * @group integration
 */
class LifecycleEmailModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoCiviCRM();
    }

    /**
     * The copy of ruleAction that a queued action EXECUTES is the action
     * object's, not the engine's.
     *
     * RuleActionEngine::__construct() stores the row on itself and passes it
     * to the action via setRuleActionData(); arrays serialize by value, so a
     * queued task carries two independent copies. execute() calls
     * $this->actionClass->processAction(), so only the action object's copy is
     * ever read. A rewrite of the engine's copy is invisible to execution —
     * which is exactly the bug this test exists to catch, having shipped once
     * and been caught in review (2026-08-27).
     */
    public function testExecutionReadsTheActionObjectsCopyOfRuleAction(): void
    {
        $this->skipIfNoDatabase();

        $ruleAction = [
            'id' => 1,
            'action_id' => 1,
            'action_params' => serialize(['template' => 'x', 'recipient' => 'client_rep', 'mode' => 'auto']),
        ];

        $action = new LifecycleEmail();
        $action->setRuleActionData($ruleAction);

        // Mutating a separate copy must NOT change what the action reads.
        $engineCopy = $ruleAction;
        $engineCopy['action_params'] = serialize(['mode' => 'propose']);

        $read = (new \ReflectionObject($action))->getMethod('getActionParameters');
        $read->setAccessible(true);

        $this->assertSame(
            'auto',
            $read->invoke($action)['mode'],
            'The action object holds its own copy; mutating another copy must not affect it.'
        );
    }

    /**
     * resolveLiveMode() must prefer the CURRENT rule row over the mode baked
     * into a queued snapshot, and fall back safely when it cannot read one.
     */
    public function testResolveLiveModePrefersTheLiveRowAndFallsBackSafely(): void
    {
        $this->skipIfNoDatabase();

        $liveId = (int) \CRM_Core_DAO::singleValueQuery(
            "SELECT ra.id FROM civirule_rule_action ra
               JOIN civirule_action a ON a.id = ra.action_id
              WHERE a.name = 'mas_lifecycle_email' LIMIT 1"
        );
        if (!$liveId) {
            $this->markTestSkipped('No mas_lifecycle_email rule action provisioned in this environment.');
        }
        $liveParams = unserialize((string) \CRM_Core_DAO::singleValueQuery(
            'SELECT action_params FROM civirule_rule_action WHERE id = ' . $liveId
        ));
        $liveMode = $liveParams['mode'] ?? 'propose';

        $action = new LifecycleEmail();
        $ruleActionProp = (new \ReflectionObject($action))->getProperty('ruleAction');
        $ruleActionProp->setAccessible(true);
        $resolve = (new \ReflectionObject($action))->getMethod('resolveLiveMode');
        $resolve->setAccessible(true);

        // A stale snapshot loses to the live row, whichever way round they are.
        $stale = $liveMode === 'auto' ? 'propose' : 'auto';
        $ruleActionProp->setValue($action, ['id' => $liveId]);
        $this->assertSame($liveMode, $resolve->invoke($action, ['mode' => $stale]));

        // No rule action id (a direct, non-CiviRules call): keep the snapshot.
        $ruleActionProp->setValue($action, []);
        $this->assertSame('auto', $resolve->invoke($action, ['mode' => 'auto']));

        // Deleted rule action row: keep the snapshot rather than inventing one.
        $ruleActionProp->setValue($action, ['id' => 2147483647]);
        $this->assertSame('propose', $resolve->invoke($action, ['mode' => 'propose']));
        $this->assertSame('auto', $resolve->invoke($action, ['mode' => 'auto']));
    }
}
