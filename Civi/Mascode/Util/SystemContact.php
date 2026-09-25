<?php

declare(strict_types=1);

// file: Civi/Mascode/Util/SystemContact.php

namespace Civi\Mascode\Util;

/**
 * The contact that stands for "the system did this".
 *
 * Every activity mascode's automation creates is recorded against this contact
 * as its source ("Reported by"), so the activity tab separates system work from
 * people's work. Before it existed, automation fell back to contact
 * `mascode_admin_contact_id` — the "MAS Administrator" contact behind the shared
 * `info@masadvise.org` login that staff also work under — or to whoever was
 * logged in when a rule fired. Both made a system email look like a person's.
 *
 * Found by `external_identifier`, never by id: the id differs between dev and
 * prod, and a hard-coded id is exactly how the intake FormProcessor came to
 * credit contact 9480 with every web request.
 */
final class SystemContact
{
    public const EXTERNAL_IDENTIFIER = 'mas_automated_system';

    public const EMAIL = 'automated.email@masadvise.org';

    private static ?int $id = null;

    /**
     * The system contact's id.
     *
     * Falls back to `mascode_admin_contact_id` (with a warning) if the contact
     * has not been provisioned yet, so a missing contact degrades to the old
     * behaviour rather than failing a send.
     */
    public static function id(): int
    {
        if (self::$id === null) {
            $found = \Civi\Api4\Contact::get(false)
                ->addSelect('id')
                ->addWhere('external_identifier', '=', self::EXTERNAL_IDENTIFIER)
                // No is_deleted filter: a trashed contact still displays fine as
                // a source, and filtering it out would silently send every
                // system activity back to the shared info@ contact.
                ->execute()
                ->first();
            if ($found) {
                self::$id = (int) $found['id'];
            } else {
                \Civi::log()->warning('SystemContact.php - system contact not provisioned; falling back to mascode_admin_contact_id. Run cv upgrade:db.');
                // Not cached: the next call should find it once provisioned.
                return (int) \Civi::settings()->get('mascode_admin_contact_id');
            }
        }
        return self::$id;
    }

    /**
     * Create the contact if it is missing. Idempotent.
     *
     * Called from upgrade_5018 AND from the post-install hook, because an
     * upgrade step never runs on a fresh install (ext:enable stamps the
     * schema version to the newest revision).
     *
     * @return array{id:int, created:bool}
     */
    public static function ensure(): array
    {
        $existing = \Civi\Api4\Contact::get(false)
            ->addSelect('id', 'is_deleted')
            ->addWhere('external_identifier', '=', self::EXTERNAL_IDENTIFIER)
            ->execute()
            ->first();
        if ($existing) {
            // Trashed by hand, or the losing side of a merge: restore it. This
            // runs only when ensure() does — upgrade_5018 and a fresh install —
            // so a contact trashed later stays trashed. id() still finds it.
            if (!empty($existing['is_deleted'])) {
                \Civi\Api4\Contact::update(false)
                    ->addWhere('id', '=', $existing['id'])
                    ->addValue('is_deleted', false)
                    ->execute();
                \Civi::log()->warning('SystemContact.php - system contact ' . $existing['id'] . ' was in the trash; restored');
            }
            return ['id' => (int) $existing['id'], 'created' => false];
        }

        $contact = \Civi\Api4\Contact::create(false)
            ->addValue('contact_type', 'Individual')
            ->addValue('first_name', 'MAS')
            ->addValue('last_name', 'Automated System')
            ->addValue('external_identifier', self::EXTERNAL_IDENTIFIER)
            ->addValue('source', 'mascode: source of system-generated activities')
            // Nothing should ever mail this contact. The address identifies it;
            // it is not a mailbox anyone reads from CiviCRM.
            ->addValue('do_not_email', true)
            ->addValue('is_opt_out', true)
            ->execute()
            ->first();

        \Civi\Api4\Email::create(false)
            ->addValue('contact_id', $contact['id'])
            ->addValue('email', self::EMAIL)
            ->addValue('is_primary', true)
            ->addValue('location_type_id:name', 'Work')
            ->execute();

        self::$id = (int) $contact['id'];
        return ['id' => self::$id, 'created' => true];
    }
}
