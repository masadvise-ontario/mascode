<?php

declare(strict_types=1);

/**
 * Donation financial types (donations ticket DN-1; spec BrianPKM
 * 3-Resources/mas-donation-process.md, decision Q1).
 *
 * Client Donation and Private Donation replace the single "Donation" type for
 * NEW entries. History is deliberately NOT re-typed: changing financial_type_id
 * on an existing contribution makes core write adjusting financial
 * transactions, which would land in the Treasurer's QuickBooks exports.
 * Reports classify a legacy "Donation" by donor contact type instead.
 *
 * Creating a FinancialType makes core create its default financial accounts
 * (CRM_Financial_BAO_FinancialType::self_hook_civicrm_post), so no
 * FinancialAccount declarations are needed here.
 *
 * Member Dues and Campaign Contribution are DISABLED, not deleted. MAS has no
 * membership fee: its bylaws make every gift a donation, and a "dues" label
 * on a receipted gift is the tax risk the Treasurer raised on 2026-10-01.
 * Neither type is used by a price set or contribution page (checked on dev,
 * 2026-10-03). `cleanup => never` so uninstalling mascode never deletes a type
 * that contributions may reference.
 */
return [
  [
    'name' => 'FinancialType_Client_Donation',
    'entity' => 'FinancialType',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Client Donation',
        'label' => 'Client Donation',
        'description' => 'Donation from a client organization, usually for a MAS project. Set Linked Project.',
        'is_deductible' => TRUE,
        'is_reserved' => FALSE,
        'is_active' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'FinancialType_Private_Donation',
    'entity' => 'FinancialType',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Private Donation',
        'label' => 'Private Donation',
        'description' => 'Personal donation from a VC, board member, past member or private individual. Never a "membership fee".',
        'is_deductible' => TRUE,
        'is_reserved' => FALSE,
        'is_active' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'FinancialType_Member_Dues_Disabled',
    'entity' => 'FinancialType',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Member Dues',
        'is_active' => FALSE,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'FinancialType_Campaign_Contribution_Disabled',
    'entity' => 'FinancialType',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Campaign Contribution',
        'is_active' => FALSE,
      ],
      'match' => ['name'],
    ],
  ],
];
