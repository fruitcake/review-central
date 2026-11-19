<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Someone signed in at a provider and may not sign in here. The message is for
 * them, and is shown on the sign-in page.
 */
class SignInRefused extends RuntimeException {}
