<?php

declare(strict_types=1);

/**
 * The Community Action Foundation, the contributor on CAF Donation
 * contributions (donations requirement R7; spec BrianPKM
 * 3-Resources/mas-donation-process.md §7).
 *
 * An organization, so no personal data. `update => unmodified`: if the office
 * corrects the name or adds an address in the UI, a deploy leaves it alone.
 * `cleanup => never`: contributions will reference it. Matched on name and
 * type, so an existing contact of exactly this name is adopted, not duplicated.
 */
return [
  [
    'name' => 'Contact_Community_Action_Foundation',
    'entity' => 'Contact',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'contact_type' => 'Organization',
        'organization_name' => 'Community Action Foundation',
      ],
      'match' => ['contact_type', 'organization_name'],
    ],
  ],
];
