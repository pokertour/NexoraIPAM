<?php

namespace App\Console\Commands;

use App\Models\NetworkDevice;
use App\Support\SnmpClient;
use Illuminate\Console\Command;
use RuntimeException;

class SnmpDump extends Command
{
    protected $signature = 'nexora:snmp-dump {device : Identifiant ou nom de l’équipement}';

    protected $description = 'Affiche les tables SNMP lues par NexoraIPAM sur un équipement, pour diagnostiquer un modèle mal reconnu';

    /** @var array<string, array{0: string, 1: bool}> */
    private const TABLES = [
        'system' => ['1.3.6.1.2.1.1', false],
        'interfaces' => ['1.3.6.1.2.1.2.2.1', false],
        'interfaces-x' => ['1.3.6.1.2.1.31.1.1.1', false],
        'bridge' => ['1.3.6.1.2.1.17.1.4', false],
        'vlan-static' => ['1.3.6.1.2.1.17.7.1.4.3', true],
        'vlan-current' => ['1.3.6.1.2.1.17.7.1.4.2', true],
        'vlan-pvid' => ['1.3.6.1.2.1.17.7.1.4.5', false],
        'entity' => ['1.3.6.1.2.1.47.1', false],
        'lldp' => ['1.0.8802.1.1.2.1', false],
    ];

    public function handle(SnmpClient $snmp): int
    {
        $key = (string) $this->argument('device');
        $device = ctype_digit($key) ? NetworkDevice::find($key) : NetworkDevice::where('name', $key)->first();
        if (! $device) {
            $this->error('Équipement introuvable.');

            return self::FAILURE;
        }

        $this->line('# '.$device->name.' · '.$device->host.':'.$device->port.' · SNMP v'.$device->snmp_version);
        foreach (self::TABLES as $label => [$oid, $hex]) {
            $this->newLine();
            $this->line('## '.$label.' ('.$oid.')');
            try {
                $values = $snmp->walk($device, $oid, $hex);
            } catch (RuntimeException $exception) {
                $this->warn($exception->getMessage());

                continue;
            }
            if ($values === []) {
                $this->line('(vide)');
            }
            foreach ($values as $suffix => $value) {
                $this->line($oid.'.'.$suffix.' = '.$value);
            }
        }

        return self::SUCCESS;
    }
}
