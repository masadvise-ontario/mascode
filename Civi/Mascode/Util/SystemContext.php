<?php

declare(strict_types=1);

// file: Civi/Mascode/Util/SystemContext.php

namespace Civi\Mascode\Util;

/**
 * "The code running now is acting as the system, not as the logged-in person."
 *
 * While a scope is open, SystemActivitySourceSubscriber records every activity
 * created — by mascode OR by core as a side effect — against SystemContact.
 * The side effects are why this is a scope and not a parameter: when
 * automation adds a case role, core writes the "Assign Case Role" activity
 * itself with the LOGGED-IN user as source, and there is no argument to pass.
 *
 * Open a scope only around work nobody chose to do by hand: CiviRules actions,
 * FormProcessor intake, scheduled or swept runs. Never around a person's
 * action — sending a reviewed draft is the reviewer's act, not the system's.
 *
 * A depth counter, so scopes nest (a CiviRules action inside a FormProcessor
 * run) and an inner exit never closes an outer scope.
 */
final class SystemContext
{
    private static int $depth = 0;

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function run(callable $work)
    {
        self::enter();
        try {
            return $work();
        } finally {
            self::leave();
        }
    }

    public static function enter(): void
    {
        self::$depth++;
    }

    public static function leave(): void
    {
        // Never below zero: an unmatched leave must not make a LATER scope
        // close early.
        self::$depth = max(0, self::$depth - 1);
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }

    // --- the decisions SystemActivitySourceSubscriber delegates to. Here, not in
    // the subscriber, because it extends AutoSubscriber and CI cannot load it.

    /** @var array<int|string,true> API request ids that opened a scope. */
    private static array $openRequests = [];

    /**
     * Stamp this write with the system contact? Only an Activity CREATE inside a
     * scope. An edit keeps its source: marking a draft Completed must not
     * re-attribute it.
     */
    public static function shouldStamp(string $entity, string $action): bool
    {
        return $entity === 'Activity' && $action === 'create' && self::isActive();
    }

    /**
     * An api3 call on entity FormProcessor runs a form processor — the entity
     * name is fixed and the ACTION is the form's name. FormProcessorInstance and
     * friends are configuration CRUD by a person, not runs.
     *
     * @param array|object $request
     */
    public static function isFormProcessorRun($request): bool
    {
        $entity = is_array($request) ? ($request['entity'] ?? null)
            : (is_object($request) && method_exists($request, 'getEntityName') ? $request->getEntityName() : null);
        return is_string($entity) && strcasecmp($entity, 'FormProcessor') === 0;
    }

    /**
     * civi.api.prepare: open a scope if this request is a FormProcessor run.
     * Idempotent per request, in case prepare fires more than once.
     *
     * @param array|object $request
     */
    public static function openForApiRequest($request): void
    {
        $key = self::requestKey($request);
        if ($key === null || isset(self::$openRequests[$key]) || !self::isFormProcessorRun($request)) {
            return;
        }
        self::$openRequests[$key] = true;
        self::enter();
    }

    /**
     * civi.api.respond / civi.api.exception: close the scope THIS request
     * opened. Any other request finishing — including nested API calls the
     * form processor makes — leaves it alone.
     *
     * @param array|object $request
     */
    public static function closeForApiRequest($request): void
    {
        $key = self::requestKey($request);
        if ($key === null || !isset(self::$openRequests[$key])) {
            return;
        }
        unset(self::$openRequests[$key]);
        self::leave();
    }

    /** @param array|object $request */
    private static function requestKey($request)
    {
        if (is_array($request)) {
            return $request['id'] ?? null;
        }
        return is_object($request) ? spl_object_id($request) : null;
    }
}
