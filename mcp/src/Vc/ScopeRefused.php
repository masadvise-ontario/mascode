<?php

namespace Civi\Mascode\Mcp\Vc;

/**
 * VC scope could not be resolved safely, so nothing is returned. The message is the same for every
 * cause (a caller learns nothing about the site); `reason` names the cause for the audit log (T7).
 */
final class ScopeRefused extends \RuntimeException {

  public const MESSAGE = 'Volunteer consultant access is not available right now. Please contact MAS staff.';

  public function __construct(public readonly string $reason, ?\Throwable $previous = NULL) {
    parent::__construct(self::MESSAGE, 0, $previous);
  }

}
