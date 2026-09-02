<?php

namespace App\Domain\Identity;

use RuntimeException;

/**
 * Thrown when a change would leave a shop with no Administrator.
 *
 * A shop with none can no longer change its own rules or roles, and recovering
 * from that needs a developer with database access.
 */
class LastAdministratorException extends RuntimeException {}
