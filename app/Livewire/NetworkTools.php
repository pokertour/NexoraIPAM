<?php

namespace App\Livewire;

use App\Support\IpNetwork;
use App\Support\MacAddress;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NetworkTools extends Component
{
    public string $cidr = '';

    public string $mac = '';

    #[Locked]
    public array $network = [];

    #[Locked]
    public array $vendor = [];

    public function mount(MacAddress $registry): void
    {
        $mac = request()->query('mac');
        if (is_string($mac) && MacAddress::normalize($mac) !== null) {
            $this->mac = $mac;
            $this->lookup($registry);
        }
    }

    public function calculate(): void
    {
        $this->network = [];
        $this->resetValidation('cidr');
        $this->validate(['cidr' => ['required', 'string', 'max:49']]);
        $cidr = IpNetwork::normalize(trim($this->cidr));
        if ($cidr === null) {
            throw ValidationException::withMessages(['cidr' => __('Saisissez une adresse IPv4 ou IPv6 suivie de son préfixe, par exemple 192.0.2.15/24.')]);
        }

        $this->network = IpNetwork::details($cidr);
    }

    public function lookup(MacAddress $registry): void
    {
        $this->vendor = [];
        $this->resetValidation('mac');
        $this->validate(['mac' => ['required', 'string', 'max:32']]);
        $mac = MacAddress::normalize($this->mac);
        if ($mac === null) {
            throw ValidationException::withMessages(['mac' => __('Saisissez une adresse MAC de 48 bits, par exemple 00:11:22:33:44:55.')]);
        }

        $this->vendor = $registry->lookup($mac) + ['mac' => $mac];
    }

    public function render(): View
    {
        return view('livewire.network-tools')->layout('components.layouts.app', ['title' => __('Outils')]);
    }
}
