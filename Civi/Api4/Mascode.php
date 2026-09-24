<?php

declare(strict_types=1);

// file: Civi/Api4/Mascode.php

namespace Civi\Api4;

/**
 * MAS extension operations that are not CRUD on any one entity.
 *
 * A non-DAO API4 entity: it has no table and no records. It exists so
 * mascode's cross-entity operations are reachable the way every other
 * CiviCRM operation is — `cv api4 Mascode.runVcDigest`, a scheduled `Job`
 * row, or a `civicrm_api4()` call — rather than as a script somebody has to
 * know the path of.
 *
 * Spec: BrianPKM 3-Resources/mascode-vc-monthly-donation-digest-spec.md,
 * approach B1 — "a Job runs plain PHP, so the query, grouping and assembly are
 * testable via `cv api4` independent of cron". The Job itself is P2-1 and sits
 * behind the falsification gate; this entity is what it will call, and what
 * the pilot is driven from before then.
 *
 * @searchable none
 * @package Civi\Api4
 */
class Mascode extends Generic\AbstractEntity
{
    /**
     * Plan (and, from P1-4, send) the monthly VC donation digest.
     *
     * @return \Civi\Api4\Action\Mascode\RunVcDigest
     */
    public static function runVcDigest(bool $checkPermissions = true)
    {
        return (new Action\Mascode\RunVcDigest(static::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }

    /**
     * Close Service Requests stale in "Request RCS" (dry run by default).
     *
     * @return \Civi\Api4\Action\Mascode\CloseStaleServiceRequests
     */
    public static function closeStaleServiceRequests(bool $checkPermissions = true)
    {
        return (new Action\Mascode\CloseStaleServiceRequests(static::getEntityName(), __FUNCTION__))
            ->setCheckPermissions($checkPermissions);
    }

    /**
     * No records, so no fields.
     */
    public static function getFields(bool $checkPermissions = true)
    {
        return (new Generic\BasicGetFieldsAction(static::getEntityName(), __FUNCTION__, static fn() => []))
            ->setCheckPermissions($checkPermissions);
    }

    /**
     * Who may run these.
     *
     * `administer CiviCRM` rather than a case or contact permission: this
     * action reads across every Active project regardless of ACL, and from
     * P1-4 it emails 62 volunteers. There is no sense in which a VC or a
     * client should be able to invoke it.
     *
     * ⚠ A PERMISSION TEST RUN FROM `cv` WILL PASS WHATEVER THIS SAYS.
     * `cv api4` defaults `checkPermissions` to FALSE (mascode memory
     * feedback_cv_api4_checkpermissions_default), so a cv-based check is a
     * false *pass* — the dangerous direction. To test this for real, call it
     * over HTTP as a non-admin, or construct the action with
     * `setCheckPermissions(TRUE)` explicitly.
     */
    public static function permissions(): array
    {
        return [
            'runVcDigest' => ['administer CiviCRM'],
            'closeStaleServiceRequests' => ['administer CiviCRM'],
            'default' => ['administer CiviCRM'],
        ];
    }
}
