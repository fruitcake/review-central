<?php

declare(strict_types=1);

namespace App\SourceControl;

use App\Support\SecretFilter;

/**
 * A host's client that takes the tenant's secrets out of everything it sends:
 * comments, descriptions, tasks, reports, statuses and checks.
 */
interface FiltersSecrets
{
    public function keepingOut(SecretFilter $secrets): static;
}
