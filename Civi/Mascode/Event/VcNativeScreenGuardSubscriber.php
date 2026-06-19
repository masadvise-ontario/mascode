<?php

// File: Civi/Mascode/Event/VcNativeScreenGuardSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Core\Event\GenericHookEvent;

/**
 * Blocks VC Portal users (WordPress Subscribers / non-staff) from reaching
 * native CiviCRM contact and case admin screens — even by typing the URL.
 *
 * Volunteer Consultants do all their work through the file-backed VC Portal
 * afforms (civicrm/mas/*, civicrm/my-cases-report, civicrm/sent-for-assignment,
 * civicrm/mas-pdef-vc, civicrm/mas-pclose-vc). The portal's SearchKit displays
 * run acl_bypass=TRUE, so the portal does NOT rely on CiviCRM ACLs — which also
 * means native screens are not ACL-protected for these low-privilege users.
 * Rather than rebuild contact-based ACLs (the retired VC_ACL approach — a known
 * rabbit hole), this guard denies the native screens outright for anyone lacking
 * a staff-level permission and sends them back to the portal.
 *
 * Staff (Editor / Administrator — hold "view all contacts" / "edit all contacts"
 * / "administer CiviCRM") are unaffected. Only full PAGE loads are guarded;
 * SearchKit/afform AJAX + API calls do not run pageRun, so the portal is intact.
 *
 * Spec: ~/gdrive-brianpkm/3-Resources/mascode-vc-portal-security-spec.md
 */
class VcNativeScreenGuardSubscriber extends AutoSubscriber
{
    /** Native menu-path prefixes that VCs must not reach. */
    private const GUARDED_PREFIXES = ['civicrm/contact', 'civicrm/case'];

    public static function getSubscribedEvents(): array
    {
        return [
            'hook_civicrm_pageRun' => 'onPageRun',
        ];
    }

    public function onPageRun(GenericHookEvent $event): void
    {
        $path = \CRM_Utils_System::currentPath() ?? '';

        $guarded = false;
        foreach (self::GUARDED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $guarded = true;
                break;
            }
        }
        if (!$guarded) {
            return;
        }

        // Staff bypass — anyone who can see all contacts or administer CiviCRM.
        if (
            \CRM_Core_Permission::check('view all contacts')
            || \CRM_Core_Permission::check('edit all contacts')
            || \CRM_Core_Permission::check('administer CiviCRM')
        ) {
            return;
        }

        // Non-staff (VC Subscriber): deny the native screen, return to the portal.
        \CRM_Core_Error::statusBounce(
            ts('That screen is not available from the Volunteer Consultant portal. Use “My Cases” to open a case.'),
            \CRM_Utils_System::url('civicrm/my-cases-report'),
            ts('Access denied')
        );
    }
}
