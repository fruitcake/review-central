<?php

declare(strict_types=1);

namespace App\Connections;

use App\Exceptions\ConnectionFailed;
use App\Models\Connection;
use Filament\Schemas\Components\Component;

/**
 * One service a tenant can connect: what it is called, what it needs to be told,
 * and whether what it was told works.
 */
interface ConnectionDriver
{
    public function label(): string;

    /**
     * The secrets, stored encrypted. Field names are relative: `access_token`,
     * not `credentials.access_token`.
     *
     * @return array<int, Component>
     */
    public function credentialFields(): array;

    /**
     * What is not a secret, such as a workspace or a site.
     *
     * @return array<int, Component>
     */
    public function settingFields(): array;

    /**
     * Makes one cheap call with the connection's credentials.
     *
     * @throws ConnectionFailed
     */
    public function test(Connection $connection): void;
}
