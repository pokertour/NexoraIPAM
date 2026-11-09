<?php

namespace App\Support\DeviceApi;

/**
 * RouterOS binary API without TLS (api service, port 8728). Credentials and
 * data cross the network in clear text: for isolated management networks.
 */
class RouterOsApiPlain extends RouterOsApi
{
    protected bool $tls = false;

    protected string $service = 'api';
}
