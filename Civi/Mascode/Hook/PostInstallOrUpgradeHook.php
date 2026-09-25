<?php

// file: Civi/Mascode/Hook/PostInstallOrUpgradeHook.php

/**
 * Install mascode settings.
 * Install CiviRules triggers, actions, & conditions.
 * Apply patches to CiviCRM core.
 */

namespace Civi\Mascode\Hook;

use Civi\Mascode\Patches\PatchManager;

class PostInstallOrUpgradeHook
{
    public static function handle(): void
    {
        self::createMascodeSettings();
        self::installCiviRulesComponents();

        self::applyPatches();
    }

    /**
     * Create settings for code generation
     */
    private static function createMascodeSettings(): void
    {
        // Set admin contact for ServiceRequestToProject action only if setting doesn't exist
        $existingAdminId = \Civi::settings()->get('mascode_admin_contact_id');
        if (empty($existingAdminId)) {
            $adminContact = \Civi\Api4\Contact::get(false)
                ->addSelect('id')
                ->addWhere('contact_sub_type', '=', 'MAS_Rep')
                ->addWhere('email_primary.email', '=', 'info@masadvise.org')
                ->execute()
                ->first();

            $adminId = $adminContact['id'] ?? null;
            if ($adminId) {
                \Civi::settings()->set('mascode_admin_contact_id', $adminId);
            }
        }
        // The source contact for system-generated activities. Here as well as in
        // upgrade_5018 because a fresh install never runs upgrade steps.
        try {
            \Civi\Mascode\Util\SystemContact::ensure();
        } catch (\Throwable $e) {
            \Civi::log()->warning('PostInstallOrUpgradeHook.php - could not provision the system contact: ' . $e->getMessage());
        }

        // Don't initialize mascode_last_project or mascode_last_service_request
        // CodeGenerator::generate() will create them if they don't exist
    }

    /**
     * Install CiviRules components using correct absolute paths
     */
    private static function installCiviRulesComponents(): void
    {
        $extensionPath = \CRM_Mascode_ExtensionUtil::path();

        $triggersFile = $extensionPath . '/Civi/Mascode/CiviRules/triggers.json';
        $actionsFile = $extensionPath . '/Civi/Mascode/CiviRules/actions.json';
        $conditionsFile = $extensionPath . '/Civi/Mascode/CiviRules/conditions.json';

        if (file_exists($triggersFile)) {
            \CRM_Civirules_Utils_Upgrader::insertTriggersFromJson($triggersFile);
        } else {
            \Civi::log()->warning("PostInstallOrUpgradeHook.php - Triggers file not found: $triggersFile");
        }

        if (file_exists($actionsFile)) {
            \CRM_Civirules_Utils_Upgrader::insertActionsFromJson($actionsFile);
        } else {
            \Civi::log()->warning("PostInstallOrUpgradeHook.php - Actions file not found: $actionsFile");
        }

        if (file_exists($conditionsFile)) {
            \CRM_Civirules_Utils_Upgrader::insertConditionsFromJson($conditionsFile);
        } else {
            \Civi::log()->warning("PostInstallOrUpgradeHook.php - Conditions file not found: $conditionsFile");
        }
    }

    /**
     * Apply patches to CiviCRM core
     */
    private static function applyPatches(): void
    {
        $results = PatchManager::applyAll();

        foreach ($results as $patchName => $result) {
            $status = $result['success'] ? 'SUCCESS' : 'FAILED';
            $message = $result['message'] ?? '';
            \Civi::log()->info("PostInstallOrUpgradeHook.php - Patch {$patchName}: {$status} - {$message}");
        }
    }
}
