<?php

declare(strict_types=1);

/**
 * VC directory — the active volunteer consultants (VCs), for other VCs: name,
 * areas of expertise, and email ONLY where the VC opted in
 * (MAS_Rep.Share_Email_with_VC_s). Never phone or address. Published to the
 * MAS CiviCRM MCP as a tool (mas-civicrm-mcp-server ticket T28, D27), and the
 * only way a VC reaches another VC's contact details (D25).
 *
 * The display has acl_bypass: a VC's own CiviCRM permissions cannot read other
 * VCs (D17). SearchKit runs an acl_bypass display for a non-administrator only
 * when it is embedded in an Afform they may open, so the MCP and any page run
 * it through afsearchMASVcDirectory (permission `access CiviCRM`).
 *
 * SECURITY. This display is an access boundary, not a report:
 *   - Everything in `select` reaches the caller. SearchDisplay.run returns
 *     every selected value in each row's `data`, shown as a column or not, so
 *     a field is private only if it is NOT selected. Add nothing here that a
 *     VC may not read about every other active VC.
 *   - Every selected alias is also a FILTER any caller can apply, with any
 *     APIv4 operator (`filters: {alias: {"LIKE": "a%"}}`), from the browser as
 *     well as the MCP. That is why the email is selected only as
 *     IF(opted-in, email, NULL): an opted-out VC's row holds NULL, so no
 *     filter or sort on `shared_email` can test their address. Selecting
 *     `email_primary.email` itself, even with the column hidden or rewritten,
 *     would let anyone probe it one character at a time.
 *   - The Afform must hold no af-field, and the display element no `filters`
 *     attribute: each is a further allowed filter key, on a field that need
 *     not be selected.
 *   - Only active VCs may read it: the INNER join on the caller
 *     (user_contact_id) empties the result for anyone else holding
 *     `access CiviCRM` — a withdrawn VC or a non-VC subscriber (T28 review M1).
 *   - The display has no actions, links or editable columns. Inline edit on an
 *     acl_bypass display writes WITHOUT permission checks (InlineEdit.php), so
 *     an editable column here would be an unguarded write.
 *   - update = 'always' re-applies this file when the declaration changes and
 *     on `cv upgrade:db`; a plain `cv flush` does not undo a Search Kit UI
 *     edit. scripts/check-vc-directory.php reports drift between the stored
 *     search and display and this file. Any change here needs a fresh-context
 *     adversarial review.
 */

$select = [
  'id',
  'display_name',
  'MAS_Rep.Primary_Area_of_Expertise:label',
  'MAS_Rep.Areas_of_Expertise:label',
  'IF(MAS_Rep.Share_Email_with_VC_s, email_primary.email, NULL) AS shared_email',
];

$where = [
  ['contact_sub_type', 'CONTAINS', 'MAS_Rep'],
  ['MAS_Rep.VC_Status:name', '=', 'Active'],
  ['is_deleted', '=', FALSE],
  ['is_deceased', '=', FALSE],
];

// Who may read it: the caller must be an active VC themselves (T28 review M1). Every account with
// `access CiviCRM` can open the Afform — including withdrawn VCs and non-VC subscribers — and the
// opted-in emails were shared with VCs, not with them. An INNER join on the caller empties the
// result for anyone else. Not selected, so nothing on it is readable or filterable.
$callerJoin = [
  [
    'Contact AS caller', 'INNER',
    ['caller.id', '=', '"user_contact_id"'],
    ['caller.contact_sub_type', 'CONTAINS', '"MAS_Rep"'],
    ['caller.MAS_Rep.VC_Status:name', '=', '"Active"'],
    ['caller.is_deleted', '=', FALSE],
  ],
];

$columns = [
  ['type' => 'field', 'key' => 'id', 'label' => 'Contact ID', 'sortable' => TRUE],
  ['type' => 'field', 'key' => 'display_name', 'label' => 'Name', 'sortable' => TRUE],
  ['type' => 'field', 'key' => 'MAS_Rep.Primary_Area_of_Expertise:label', 'label' => 'Primary area of expertise', 'sortable' => TRUE],
  ['type' => 'field', 'key' => 'MAS_Rep.Areas_of_Expertise:label', 'label' => 'Areas of expertise', 'sortable' => FALSE],
  ['type' => 'field', 'key' => 'shared_email', 'label' => 'Email (shared by the VC)', 'sortable' => FALSE],
];

return [
  [
    'name' => 'SavedSearch_MAS_VC_Directory',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_VC_Directory',
        'label' => 'MAS VC Directory',
        'description' => 'Active VCs for other VCs: name, expertise, email only if shared. Access boundary (acl_bypass) — see the mascode managed file before editing.',
        'api_entity' => 'Contact',
        'api_params' => [
          'version' => 4,
          'select' => $select,
          'orderBy' => [],
          'where' => $where,
          'groupBy' => [],
          'join' => $callerJoin,
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_MAS_VC_Directory_SearchDisplay_MAS_VC_Directory_Table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_VC_Directory_Table',
        'label' => 'MAS VC Directory',
        'saved_search_id.name' => 'MAS_VC_Directory',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [['display_name', 'ASC']],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => $columns,
          'actions' => FALSE,
          'classes' => ['table', 'table-striped'],
        ],
        'acl_bypass' => TRUE,
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],
];
