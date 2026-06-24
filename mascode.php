<?php

/**
 * I am using Symfony EventDispatcher for the hooks that support it.
 * config, install, and enable happen before the container is built, so I need to use the traditional hooks.
 * caseSummary is expecting a return value, so I need to use the traditional hook.
 */

require_once 'mascode.civix.php';

// CiviCRM autoloads via the classloader section in info.xml

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Civi\Mascode\CompilerPass;

/**
 * Implements hook_civicrm_container() - used to register services via service.yml.
 */
function mascode_civicrm_container(ContainerBuilder $container)
{
    // See mascode/Civi/Mascode/Event/Subscriber for all the Event Dipatcher subscribers
    // that are auto-registered via scan-classes mixin and AutoSubscriber

    // other services like form actions may need to wait until the container is built
    $container->addCompilerPass(new CompilerPass());

    // I don't need to define CiviRule actions as services,
    // as those methods are called directly by CiviRules based on rows in the CiviRules tables.
}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function mascode_civicrm_config(&$config)
{
    _mascode_civix_civicrm_config($config);

    // Workaround for Smarty template path issue with afform extension
    // Ensure afform/core templates are available to prevent
    // "Unable to load template 'file:afform/customGroups/afblock.tpl'" errors
    $smarty = \CRM_Core_Smarty::singleton();
    $afformCorePath = \Civi::paths()->getPath('[civicrm.root]/ext/afform/core/templates/');
    $templateDirs = $smarty->getTemplateDir();

    // Only add if not already present
    if (!in_array($afformCorePath, $templateDirs)) {
        $smarty->addTemplateDir($afformCorePath);
    }
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Registers a lightweight Angular module that ships the shared MAS client-form
 * stylesheet. The five client Afforms `require` this module (see their .aff.json),
 * so css/mas-forms.css loads on their public pages.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function mascode_civicrm_angularModules(&$angularModules)
{
    $angularModules['mascodeForms'] = [
        'ext' => 'mascode',
        'js' => ['ang/mascodeForms.js'],
        'css' => ['css/mas-forms.css'],
    ];
    $angularModules['mascodeVcDetail'] = [
        'ext' => 'mascode',
        'js' => ['ang/mascodeVcDetail.js'],
        'css' => ['css/vc-case-detail.css'],
    ];
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function mascode_civicrm_install(): void
{
    _mascode_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 */
function mascode_civicrm_postInstall()
{
    \Civi\Mascode\Hook\PostInstallOrUpgradeHook::handle();
}

/**
 * Implements hook_civicrm_postUpgrade().
 */
function mascode_civicrm_postUpgrade($op, $queue)
{
    if ($op == 'check') {
        return true;
    } elseif ($op == 'finish') {
        \Civi\Mascode\Hook\PostInstallOrUpgradeHook::handle();
    }
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function mascode_civicrm_enable(): void
{
    _mascode_civix_civicrm_enable();
}

// Need to handle caseSummary as a traditional hook for now as it is expecting a return value
//
function mascode_civicrm_caseSummary($caseId)
{
    return \Civi\Mascode\Hook\CaseSummaryHook::handle($caseId);
}

/**
 * Implements hook_civicrm_searchKitTasks().
 *
 * Adds the "Send draft email" batch task to SearchKit Activity displays —
 * the click-to-send for MAS-lifecycle propose-mode drafts
 * (Civi\Api4\Action\Activity\SendLifecycleDraft).
 */
function mascode_civicrm_searchKitTasks(array &$tasks)
{
    $tasks['Activity']['mascode_send_lifecycle_draft'] = [
        'title' => ts('Send draft email (MAS lifecycle)'),
        'icon' => 'fa-paper-plane',
        'apiBatch' => [
            'action' => 'sendLifecycleDraft',
            'params' => null,
            'confirmMsg' => ts('Send %1 reviewed draft %2 now? Each draft is emailed to its stored recipient and recorded on its case.'),
            'runMsg' => ts('Sending %1 draft %2...'),
            'successMsg' => ts('Sent %1 draft %2.'),
            // No errorMsg: SearchKit's onError falls back to the action's real
            // exception message only when errorMsg is unset, so a failed send
            // shows the actual reason (e.g. "no recipient") instead of a
            // generic notice.
        ],
    ];
}
