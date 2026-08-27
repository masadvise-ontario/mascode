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
     * A queued task carries TWO copies of the rule action, and the one that
     * EXECUTES is the action object's, not the engine's.
     *
     * RuleActionEngine::__construct() stores the row on itself and also hands
     * it to the action via setRuleActionData(); arrays serialize by value, so
     * both land in the payload. execute() calls
     * $this->actionClass->processAction(), so only the action object's copy is
     * ever read.
     *
     * This pins the invariant by doing what the queue does: build a real
     * engine, serialize it, revive it, mutate the ENGINE's copy on the revived
     * object, and assert the action still reads its own. Round 1 of this PR
     * shipped a rewrite that mutated the engine copy and reported success from
     * it; that code would pass a value-semantics assertion, so the test has to
     * go through a real serialize/unserialize round trip to be worth anything.
     */
    public function testExecutionReadsTheActionObjectsCopyNotTheEnginesAfterRoundTrip(): void
    {
        $this->skipIfNoCiviCRM();

        $actionId = (int) \CRM_Core_DAO::singleValueQuery(
            "SELECT id FROM civirule_action WHERE name = 'mas_lifecycle_email'"
        );
        if (!$actionId) {
            $this->markTestSkipped('No mas_lifecycle_email action provisioned in this environment.');
        }

        $ruleAction = [
            'id' => 1,
            'rule_id' => 1,
            'action_id' => $actionId,
            'action_params' => serialize([
                'template' => 'mas_lifecycle_pd_chase__vc',
                'recipient' => 'coordinator',
                'mode' => 'auto',
            ]),
        ];
        $triggerData = new \CRM_Civirules_TriggerData_Edit('Case', 1, [], []);
        $engine = new \CRM_Civirules_ActionEngine_RuleActionEngine($ruleAction, $triggerData);

        // Round-trip exactly as CRM_Queue_Task does.
        $revived = unserialize(serialize($engine));
        $this->assertInstanceOf(\CRM_Civirules_ActionEngine_RuleActionEngine::class, $revived);

        // Mutate the ENGINE's copy on the revived object — what the deleted
        // rewrite did.
        $engineProp = (new \ReflectionObject($revived))->getProperty('ruleAction');
        $engineProp->setAccessible(true);
        $mutated = $engineProp->getValue($revived);
        $mutated['action_params'] = serialize(['mode' => 'propose']);
        $engineProp->setValue($revived, $mutated);

        $this->assertSame(
            'propose',
            unserialize($revived->getRuleAction()['action_params'])['mode'],
            'Sanity: the engine copy really was mutated.'
        );

        // The action object — the copy execute() reads — must be untouched.
        $actionProp = (new \ReflectionObject($revived))->getProperty('actionClass');
        $actionProp->setAccessible(true);
        $action = $actionProp->getValue($revived);
        $read = (new \ReflectionObject($action))->getMethod('getActionParameters');
        $read->setAccessible(true);

        $this->assertSame(
            'auto',
            $read->invoke($action)['mode'],
            'Mutating the engine copy must NOT change what execution reads — '
            . 'a queue rewrite that only touches the engine is a no-op.'
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
