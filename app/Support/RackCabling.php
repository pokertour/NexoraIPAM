<?php

namespace App\Support;

use App\Models\CableLink;
use App\Models\RackItem;
use Illuminate\Support\Collection;

/**
 * Every cable that touches a rack item: links detected between racked
 * devices (LLDP, MAC tables), confirmed or not by a documented cable, and
 * cables documented by hand to passive items.
 */
class RackCabling
{
    public function __construct(private Topology $topology) {}

    /**
     * @return Collection<int, array{key: string, source: string, confirmed: bool, cable: ?CableLink, ends: array{0: array{item: ?RackItem, name: string, port: ?string, if_index: ?int}, 1: array{item: ?RackItem, name: string, port: ?string, if_index: ?int}}}>
     */
    public function cables(): Collection
    {
        $items = RackItem::with(['rack', 'device'])->get()->keyBy('id');
        $byDevice = $items->filter(fn (RackItem $item): bool => $item->network_device_id !== null)->keyBy('network_device_id');

        $cables = collect();
        foreach ($this->topology->links() as $index => $link) {
            $aItem = $byDevice->get($link['a']->id);
            $bItem = $byDevice->get($link['b']->id);
            if ($aItem === null && $bItem === null) {
                continue;
            }
            $cables->push([
                'key' => $link['cable'] ? 'c'.$link['cable']->id : 'l'.$index,
                'source' => $link['source'],
                'confirmed' => $link['confirmed'],
                'cable' => $link['cable'],
                'ends' => [
                    ['item' => $aItem, 'name' => $link['a']->name, 'port' => $link['a_port']?->name ?? $link['cable']?->a_port, 'if_index' => $link['a_port']?->if_index],
                    ['item' => $bItem, 'name' => $link['b']->name, 'port' => $link['b_port']?->name ?? $link['cable']?->b_port, 'if_index' => $link['b_port']?->if_index],
                ],
            ]);
        }

        // Cables to passive items are not part of the device topology.
        CableLink::with(['a', 'b', 'aDevice', 'bDevice'])->get()
            ->reject(fn (CableLink $cable): bool => $cable->deviceId('a') !== null && $cable->deviceId('b') !== null)
            ->each(function (CableLink $cable) use ($cables, $items): void {
                $a = $items->get($cable->a_rack_item_id);
                $b = $items->get($cable->b_rack_item_id);
                $cables->push([
                    'key' => 'c'.$cable->id,
                    'source' => 'manual',
                    'confirmed' => false,
                    'cable' => $cable,
                    'ends' => [
                        ['item' => $a, 'name' => $a?->displayName() ?? $cable->endName('a'), 'port' => $cable->a_port, 'if_index' => null],
                        ['item' => $b, 'name' => $b?->displayName() ?? $cable->endName('b'), 'port' => $cable->b_port, 'if_index' => null],
                    ],
                ]);
            });

        return $cables->values();
    }

    /**
     * The cables of one item, each seen from that item: the near end first.
     *
     * @param  Collection<int, array<string, mixed>>  $cables
     * @return Collection<int, array<string, mixed>>
     */
    public static function of(RackItem $item, Collection $cables): Collection
    {
        return $cables->map(function (array $cable) use ($item): ?array {
            foreach ([0, 1] as $side) {
                if ($cable['ends'][$side]['item']?->is($item)) {
                    return ['near' => $cable['ends'][$side], 'far' => $cable['ends'][1 - $side]] + $cable;
                }
            }

            return null;
        })->filter()->values();
    }
}
