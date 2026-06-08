<?php

// file: CRM/Mascode/CiviRules/Form/LifecycleEmail.php

use CRM_Mascode_ExtensionUtil as E;

/**
 * Config form for the "mas: Lifecycle email (propose/auto)" CiviRules action.
 *
 * Edits the rule_action's parameters (template, recipient, mode, source
 * contact) AND its delay (days), so chase cadence and propose/auto
 * graduation are self-service in the CiviRules UI — no script run needed
 * (parent spec Phase 5: Steve approves graduation per rule by flipping
 * the mode here).
 */
class CRM_Mascode_CiviRules_Form_LifecycleEmail extends CRM_CivirulesActions_Form_Form
{
    public function buildQuickForm()
    {
        $this->add('hidden', 'rule_action_id');

        $this->add(
            'select',
            'template',
            E::ts('Message Template'),
            ['' => E::ts('- Select Template -')] + $this->getTemplateOptions(),
            true,
            ['class' => 'crm-select2 huge']
        );

        $this->add(
            'select',
            'recipient',
            E::ts('Recipient'),
            [
                'client_rep' => E::ts('Case Client Rep (the client)'),
                'coordinator' => E::ts('Case Coordinator (the assigned VC)'),
                'custom' => E::ts('Specific contact ID...'),
            ],
            true
        );

        $this->add('text', 'recipient_contact_id', E::ts('Recipient Contact ID'), ['size' => 8]);

        $this->add(
            'select',
            'mode',
            E::ts('Mode'),
            [
                'propose' => E::ts('Propose - write a draft activity for review (default)'),
                'auto' => E::ts('Auto - send the email immediately'),
            ],
            true
        );

        $this->add('text', 'source_contact_id', E::ts('Source Contact ID'), ['size' => 8]);

        $this->add('text', 'delay_days', E::ts('Delay (days)'), ['size' => 6]);

        $this->addFormRule([self::class, 'validateParams']);

        $this->addButtons([
            ['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => true],
            ['type' => 'cancel', 'name' => E::ts('Cancel')],
        ]);
    }

    /**
     * @param array $values
     * @return array|bool
     */
    public static function validateParams(array $values)
    {
        $errors = [];
        if (($values['recipient'] ?? '') === 'custom' && !ctype_digit((string) ($values['recipient_contact_id'] ?? ''))) {
            $errors['recipient_contact_id'] = E::ts('Enter a numeric contact ID for a specific recipient.');
        }
        foreach (['source_contact_id', 'delay_days'] as $f) {
            if (($values[$f] ?? '') !== '' && !ctype_digit((string) $values[$f])) {
                $errors[$f] = E::ts('Must be a whole number (or empty).');
            }
        }
        return $errors ?: true;
    }

    public function setDefaultValues()
    {
        $defaultValues = parent::setDefaultValues();
        $data = unserialize($this->ruleAction->action_params ?? '') ?: [];

        if (!empty($data['template'])) {
            $defaultValues['template'] = (string) $data['template'];
        }
        $recipient = $data['recipient'] ?? '';
        if (is_numeric($recipient)) {
            $defaultValues['recipient'] = 'custom';
            $defaultValues['recipient_contact_id'] = (int) $recipient;
        } elseif ($recipient !== '') {
            $defaultValues['recipient'] = $recipient;
        }
        $defaultValues['mode'] = $data['mode'] ?? 'propose';
        if (!empty($data['source_contact_id'])) {
            $defaultValues['source_contact_id'] = (int) $data['source_contact_id'];
        }

        // Delay: surface an XDays delay as a day count.
        if (!empty($this->ruleAction->delay)) {
            $delay = unserialize($this->ruleAction->delay);
            if ($delay instanceof CRM_Civirules_Delay_XDays) {
                $prop = new ReflectionProperty($delay, 'dayOffset');
                $prop->setAccessible(true);
                $defaultValues['delay_days'] = (int) $prop->getValue($delay);
            }
        }

        return $defaultValues;
    }

    public function postProcess()
    {
        $values = $this->exportValues();

        $params = [
            'template' => $values['template'],
            'recipient' => $values['recipient'] === 'custom'
                ? (int) $values['recipient_contact_id']
                : $values['recipient'],
            'mode' => $values['mode'],
        ];
        if (($values['source_contact_id'] ?? '') !== '') {
            $params['source_contact_id'] = (int) $values['source_contact_id'];
        }

        $delaySerialized = '';
        if (($values['delay_days'] ?? '') !== '' && (int) $values['delay_days'] > 0) {
            $delay = new CRM_Civirules_Delay_XDays();
            $prop = new ReflectionProperty($delay, 'dayOffset');
            $prop->setAccessible(true);
            $prop->setValue($delay, (int) $values['delay_days']);
            $delaySerialized = serialize($delay);
        }

        try {
            civicrm_api3('CiviRuleRuleAction', 'create', [
                'id' => $this->ruleAction->id,
                'action_params' => serialize($params),
                'delay' => $delaySerialized,
            ]);
            Civi::log()->info('LifecycleEmail form - Action parameters saved', [
                'rule_action_id' => $this->ruleAction->id,
                'params' => $params,
                'delay_days' => $values['delay_days'] ?? '',
            ]);
        } catch (Exception $e) {
            Civi::log()->error('LifecycleEmail form - Save failed: ' . $e->getMessage(), [
                'rule_action_id' => $this->ruleAction->id,
            ]);
            CRM_Core_Session::setStatus(E::ts('Failed to save action parameters.'), E::ts('Error'), 'error');
        }

        parent::postProcess();
    }

    /**
     * Active message templates as [msg_title => msg_title], lifecycle ones first.
     * Stored by title (name-stable across environments, like the chase scripts).
     */
    private function getTemplateOptions(): array
    {
        $rows = \Civi\Api4\MessageTemplate::get(false)
            ->addSelect('msg_title')
            ->addWhere('is_active', '=', true)
            ->addWhere('workflow_name', 'IS NULL')
            ->addOrderBy('msg_title', 'ASC')
            ->execute()
            ->column('msg_title');

        $lifecycle = array_filter($rows, fn($t) => str_starts_with($t, 'mas_lifecycle_'));
        $other = array_filter($rows, fn($t) => !str_starts_with($t, 'mas_lifecycle_'));
        $options = [];
        foreach (array_merge($lifecycle, $other) as $title) {
            $options[$title] = $title;
        }
        return $options;
    }
}
