<?php

namespace Civi\Mascode\Mcp\Vc;

use Civi\Mcp\Tools\Api4DisplayRunner;
use Civi\Mcp\Tools\PublishedDisplay;

/**
 * The VC directory (VC access spec D25, D27; ticket T28): mascode's `MAS_VC_Directory` display — active
 * VCs, name and areas of expertise, email only where the VC opted in — published through the Afform
 * `afsearchMASVcDirectory` that lets a VC run its `acl_bypass`. The boundary is the display, reviewed
 * in mascode (Civi/Mascode/Managed/SavedSearch_MAS_VC_Directory.mgd.php); nothing here widens it.
 *
 * Registered as `vc_directory` by VcTools (T6), withheld from staff.
 */
final class VcDirectory {

  public const SEARCH = 'MAS_VC_Directory';
  public const DISPLAY = 'MAS_VC_Directory_Table';
  public const AFFORM = 'afsearchMASVcDirectory';

  /** Tool argument => SearchKit filter key; every key is a selected alias of the display. */
  public const FILTERS = [
    'name' => [
      'key' => 'display_name',
      'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'Part of the VC\'s name.'],
    ],
    'area' => [
      'key' => 'MAS_Rep.Primary_Area_of_Expertise:label,MAS_Rep.Areas_of_Expertise:label',
      'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'An area of expertise, by its label (e.g. "Finance"); matches the primary or any area.'],
    ],
    'contact_ids' => [
      'key' => 'id',
      'schema' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => ['type' => 'integer', 'minimum' => 1], 'description' => 'Contact IDs to look up, e.g. a coordinator ID from another result.'],
    ],
  ];

  public static function display(?callable $runner = NULL): PublishedDisplay {
    return new PublishedDisplay(self::SEARCH, self::DISPLAY, self::AFFORM, self::FILTERS, $runner ?? new Api4DisplayRunner());
  }

}
