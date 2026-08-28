<?php

declare(strict_types=1);

namespace Civi\Mascode\Submission;

use Civi\Api4\Activity;
use Civi\Api4\CiviCase;
use Civi\Api4\Contact;
use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;

/**
 * Builds a clean, email-client-safe HTML summary of what a client entered on a
 * MAS Afform. Used by AfformSubmitSubscriber to (a) populate the resulting
 * activity's `details` field and (b) form the coordinator/confirmation email body.
 *
 * Labels and option values are resolved from CiviCRM metadata (CustomField titles
 * + the API4 `:label` suffix), so there are no hardcoded question/label maps to
 * drift out of sync with the forms. All entered values are HTML-escaped; only the
 * structural wrapper is trusted markup.
 */
class SubmissionSummaryService
{
    /** @var array<string,string> cached custom group titles */
    private array $groupTitleCache = [];

    /**
     * Build the HTML summary for a submitted form.
     *
     * @param string $formRoute  Afform server_route (e.g. "civicrm/mas-rcs-form")
     * @param array  $submissionData  AfformSubmitSubscriber's per-session data
     *                                (organization_id, primary_contact_id, case_id,
     *                                activity_id, ...)
     * @return string HTML (empty string if nothing to show / unknown form)
     */
    public function buildForForm(string $formRoute, array $submissionData): string
    {
        $cfg = SummaryConfig::forRoute($formRoute);
        if (!$cfg) {
            return '';
        }

        $sections = [];

        if (($cfg['kind'] ?? null) === 'activity') {
            $activityId = (int) ($submissionData['activity_id'] ?? 0);
            if ($activityId) {
                foreach ($cfg['activityGroups'] as $group) {
                    $byField = $this->customGroupFieldRows('Activity', $activityId, $group);
                    if (!$byField) {
                        continue;
                    }
                    $sectionMap = $cfg['activitySections'][$group] ?? null;
                    if ($sectionMap) {
                        // Themed sections mirroring the form layout, plus a
                        // catch-all for any answered field not in the map.
                        $used = [];
                        foreach ($sectionMap as $sectionTitle => $fieldNames) {
                            $rows = [];
                            foreach ($fieldNames as $name) {
                                if (isset($byField[$name])) {
                                    $rows[$byField[$name]['label']] = $byField[$name]['value'];
                                    $used[$name] = true;
                                }
                            }
                            if ($rows) {
                                $sections[] = $this->renderSection($sectionTitle, $rows);
                            }
                        }
                        $rest = array_diff_key($byField, $used);
                        if ($rest) {
                            $rows = [];
                            foreach ($rest as $r) {
                                $rows[$r['label']] = $r['value'];
                            }
                            $sections[] = $this->renderSection($this->groupTitle($group), $rows);
                        }
                    } else {
                        $rows = [];
                        foreach ($byField as $r) {
                            $rows[$r['label']] = $r['value'];
                        }
                        $sections[] = $this->renderSection($this->groupTitle($group), $rows);
                    }
                }
            }
        } elseif (($cfg['kind'] ?? null) === 'case') {
            foreach (($cfg['contactBlocks'] ?? []) as $block) {
                $contactId = (int) ($submissionData[$block['idKey']] ?? 0);
                if (!$contactId) {
                    continue;
                }
                $rows = $this->contactRows($contactId, $block['fields']);
                if ($rows) {
                    $sections[] = $this->renderSection($block['title'], $rows);
                }
            }
            $caseId = (int) ($submissionData['case_id'] ?? 0);

            // Project header — which project this is, before any answers. A VC
            // printing the email needs the MAS code, client and dates on the
            // page; without them the printout is a set of answers with nothing
            // identifying the project they belong to.
            if ($caseId && !empty($cfg['caseHeader'])) {
                $rows = $this->caseHeaderRows($caseId);
                if ($rows) {
                    $sections[] = $this->renderSection($cfg['caseHeaderTitle'] ?? 'Project', $rows);
                }
            }

            // One or more case custom groups. `caseGroups` composes a complete
            // record across groups (the VC record emails); `caseGroup` is the
            // original single-group form echo and still works unchanged.
            $caseGroups = $cfg['caseGroups'] ?? null;
            if ($caseGroups === null && !empty($cfg['caseGroup'])) {
                $caseGroups = [[
                    'group' => $cfg['caseGroup'],
                    'title' => $cfg['caseGroupTitle'] ?? null,
                    'exclude' => $cfg['caseGroupExclude'] ?? [],
                ]];
            }

            foreach (($caseGroups ?? []) as $spec) {
                if (!$caseId || empty($spec['group'])) {
                    continue;
                }
                $rows = $this->customGroupRows('Case', $caseId, $spec['group'], $spec['exclude'] ?? []);
                if ($rows) {
                    $sections[] = $this->renderSection(
                        $spec['title'] ?? $this->groupTitle($spec['group']),
                        $rows
                    );
                }
            }
        }

        if (!$sections) {
            return '';
        }

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222222;">'
            . implode("\n", $sections)
            . '</div>';
    }

    /**
     * Render one activity custom group as label => displayValue rows.
     * Public so it can be exercised directly (cv ev / tests).
     */
    public function renderActivityGroup(int $activityId, string $group): string
    {
        $rows = $this->customGroupRows('Activity', $activityId, $group);
        return $rows ? $this->renderSection($this->groupTitle($group), $rows) : '';
    }

    /**
     * Read a custom group's active fields on an entity and return
     * [fieldLabel => displayValueHtml] for non-empty values, in field order.
     *
     * @param string $entity  'Activity' or 'Case'
     * @param array  $exclude field names to skip (internal/system fields)
     */
    private function customGroupRows(string $entity, int $entityId, string $group, array $exclude = []): array
    {
        $rows = [];
        foreach ($this->customGroupFieldRows($entity, $entityId, $group, $exclude) as $r) {
            $rows[$r['label']] = $r['value'];
        }
        return $rows;
    }

    /**
     * Same as customGroupRows() but keyed by field NAME, so callers can slice
     * the answered fields into themed sections (see SummaryConfig
     * 'activitySections').
     *
     * @return array<string,array{label:string,value:string}>
     */
    private function customGroupFieldRows(string $entity, int $entityId, string $group, array $exclude = []): array
    {
        $fields = CustomField::get(false)
            ->addWhere('custom_group_id:name', '=', $group)
            ->addWhere('is_active', '=', true)
            ->addSelect('name', 'label', 'data_type', 'option_group_id')
            ->addOrderBy('weight', 'ASC')
            ->execute();

        if (!count($fields)) {
            return [];
        }

        $select = [];
        foreach ($fields as $f) {
            if (in_array($f['name'], $exclude, true)) {
                continue;
            }
            $select[] = "$group.{$f['name']}";
            if (!empty($f['option_group_id'])) {
                $select[] = "$group.{$f['name']}:label";
            }
        }
        if (!$select) {
            return [];
        }

        $api = $entity === 'Case' ? CiviCase::get(false) : Activity::get(false);
        $row = $api->setSelect($select)->addWhere('id', '=', $entityId)->execute()->first();
        if (!$row) {
            return [];
        }

        $rows = [];
        foreach ($fields as $f) {
            if (in_array($f['name'], $exclude, true)) {
                continue;
            }
            $value = $this->displayValue($f, $row, "$group.{$f['name']}");
            if ($value !== null && $value !== '') {
                $rows[$f['name']] = [
                    'label' => $this->esc((string) $f['label']),
                    'value' => $value,
                ];
            }
        }
        return $rows;
    }

    /**
     * Read selected core fields off a contact and return label => value rows.
     *
     * @param array $fields list of ['field' => apiField, 'label' => human label]
     */
    /**
     * Identifying header for a PROJECT case: MAS code, client, subject, dates.
     *
     * Client comes via the CaseContact bridge (a case's client is a contact
     * link, not a case field). Reads are defensive — a case type without the
     * Projects custom group must degrade to the core fields rather than throw,
     * because this runs inside an email send that must not fail.
     *
     * @return array<string,string> label => escaped value
     */
    private function caseHeaderRows(int $caseId): array
    {
        $rows = [];

        try {
            $case = CiviCase::get(false)
                ->addSelect('subject', 'start_date', 'end_date', 'status_id:label')
                ->addWhere('id', '=', $caseId)
                ->execute()
                ->first();
        } catch (\Throwable $e) {
            return [];
        }

        if (!$case) {
            return [];
        }

        // MAS project code lives in the Projects custom group; separate read so
        // a case type without that group still yields the core header.
        try {
            $coded = CiviCase::get(false)
                ->addSelect('Projects.MAS_Project_Case_Code')
                ->addWhere('id', '=', $caseId)
                ->execute()
                ->first();
            $code = $coded['Projects.MAS_Project_Case_Code'] ?? null;
            if ($code !== null && $code !== '') {
                $rows['MAS Project Code'] = $this->esc((string) $code);
            }
        } catch (\Throwable $e) {
            // no Projects group on this case type — core fields only
        }

        try {
            $client = \Civi\Api4\CaseContact::get(false)
                ->addSelect('contact_id.display_name')
                ->addWhere('case_id', '=', $caseId)
                ->addOrderBy('id', 'ASC')
                ->setLimit(1)
                ->execute()
                ->first();
            if (!empty($client['contact_id.display_name'])) {
                $rows['Client'] = $this->esc((string) $client['contact_id.display_name']);
            }
        } catch (\Throwable $e) {
            // client link unreadable — omit the row rather than fail the email
        }

        foreach (
            [
                'Project Name' => 'subject',
                'Start Date' => 'start_date',
                'End Date' => 'end_date',
                'Status' => 'status_id:label',
            ] as $label => $key
        ) {
            $value = $case[$key] ?? null;
            if ($value !== null && $value !== '') {
                $rows[$label] = $this->esc((string) $value);
            }
        }

        return $rows;
    }

    private function contactRows(int $contactId, array $fields): array
    {
        $select = array_map(static fn($f) => $f['field'], $fields);
        $row = Contact::get(false)->setSelect($select)->addWhere('id', '=', $contactId)->execute()->first();
        if (!$row) {
            return [];
        }

        $rows = [];
        foreach ($fields as $f) {
            $raw = $row[$f['field']] ?? null;
            if (is_array($raw)) {
                $raw = implode(', ', $raw);
            }
            if ($raw === null || $raw === '') {
                continue;
            }
            $rows[$this->esc($f['label'])] = $this->esc((string) $raw);
        }
        return $rows;
    }

    /**
     * Resolve one custom field's saved value to display-ready (escaped) HTML.
     * Returns null for empty values.
     */
    private function displayValue(array $field, array $row, string $key): ?string
    {
        // Option-backed fields (Radio/Select/CheckBox) — use the resolved label(s).
        if (!empty($field['option_group_id'])) {
            $label = $row["$key:label"] ?? null;
            if (is_array($label)) {
                $label = implode(', ', $label);
            }
            return ($label === null || $label === '') ? null : $this->esc((string) $label);
        }

        $raw = $row[$key] ?? null;
        if ($raw === null || $raw === '' || (is_array($raw) && !$raw)) {
            return null;
        }

        switch ($field['data_type']) {
            case 'Boolean':
                return $raw ? 'Yes' : 'No';
            case 'Date':
                return $this->esc((string) \CRM_Utils_Date::customFormat((string) $raw));
            case 'Money':
                return $this->esc((string) \CRM_Utils_Money::format($raw));
            case 'Memo':
                return nl2br($this->esc((string) $raw));
            case 'Link':
                $url = $this->esc((string) $raw);
                return '<a href="' . $url . '">' . $url . '</a>';
            default:
                if (is_array($raw)) {
                    $raw = implode(', ', $raw);
                }
                return $this->esc((string) $raw);
        }
    }

    /**
     * Render a titled section as an inline-styled (email-safe) two-column table.
     * Title is pre-escaped by callers that pass dynamic group titles; labels and
     * values arriving in $rows are already escaped/safe.
     *
     * @param array<string,string> $rows label => valueHtml
     */
    private function renderSection(string $title, array $rows): string
    {
        $out = '<h3 style="font-size:16px;margin:18px 0 6px;color:#1a4971;'
            . 'border-bottom:2px solid #1a4971;padding-bottom:4px;">' . $this->esc($title) . '</h3>';
        $out .= '<table role="presentation" cellpadding="0" cellspacing="0" '
            . 'style="width:100%;border-collapse:collapse;margin-bottom:8px;">';
        foreach ($rows as $label => $value) {
            $out .= '<tr>'
                . '<td style="padding:5px 12px 5px 0;font-weight:bold;width:38%;'
                . 'vertical-align:top;color:#555555;">' . $label . '</td>'
                . '<td style="padding:5px 0;vertical-align:top;">' . $value . '</td>'
                . '</tr>';
        }
        $out .= '</table>';
        return $out;
    }

    private function groupTitle(string $group): string
    {
        if (!isset($this->groupTitleCache[$group])) {
            $cg = CustomGroup::get(false)
                ->addWhere('name', '=', $group)
                ->addSelect('title')
                ->execute()
                ->first();
            $this->groupTitleCache[$group] = $cg['title'] ?? $group;
        }
        return $this->groupTitleCache[$group];
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
