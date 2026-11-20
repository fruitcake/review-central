<?php

declare(strict_types=1);

namespace App\SourceControl;

use App\Support\SecretFilter;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;

/**
 * For {@see FiltersSecrets}: on the request itself, so no way of posting is left
 * out, including one added later.
 */
trait SecretFiltering
{
    protected ?SecretFilter $secrets = null;

    public function keepingOut(SecretFilter $secrets): static
    {
        $this->secrets = $secrets;

        return $this;
    }

    protected function filtered(PendingRequest $request): PendingRequest
    {
        return $request->withRequestMiddleware(fn (RequestInterface $sent): RequestInterface => $this->secrets?->request($sent) ?? $sent);
    }
}
