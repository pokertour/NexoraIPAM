<?php

namespace App\Support;

use App\Jobs\DiscoverSubnet;
use App\Models\Subnet;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DiscoveryQueue
{
    public function __construct(private NetworkDiscovery $discovery) {}

    public function enqueue(int $subnetId, ?int $locationId = null): ?string
    {
        return DB::transaction(function () use ($subnetId, $locationId): ?string {
            $subnet = Subnet::lockForUpdate()->find($subnetId);
            if (! $subnet || ($locationId !== null && $subnet->location_id !== $locationId)) {
                return __('Ce sous-réseau n’est plus disponible à cet emplacement.');
            }
            if ($subnet->scanPending()) {
                return __('Une découverte est déjà en cours pour ce réseau.');
            }
            if ($this->discovery->targets($subnet) === []) {
                return __('Aucune adresse à sonder. Pour une grande plage, ajoutez d’abord les adresses à vérifier.');
            }

            $token = (string) Str::uuid();
            $subnet->forceFill(['scan_token' => $token, 'scan_status' => 'queued', 'scan_requested_at' => now()])->save();
            Audit::record('discovery.queued', $subnet);
            Bus::dispatch((new DiscoverSubnet($subnet->id, $token))->onConnection('database')->onQueue('discovery')->beforeCommit());

            return null;
        }, attempts: 3);
    }
}
