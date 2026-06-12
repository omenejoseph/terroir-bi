<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Action grants</x-slot>
        <x-slot name="description">
            Fail-closed: the AI compiler and the scenario runner can only use actions granted here.
            Money/identity/platform actions (billing, tenancy, auth, AI) are blocklisted and never appear.
        </x-slot>

        <div class="divide-y divide-gray-200">
            @foreach ($this->actions() as $action)
                <div class="flex items-center justify-between gap-4 py-3">
                    <div class="min-w-0">
                        <p class="truncate font-mono text-sm">{{ str_replace('action:App\\Actions\\', '', $action['key']) }}</p>
                        <p class="truncate text-xs text-gray-500">{{ $action['summary'] }}</p>
                    </div>
                    <div class="shrink-0">
                        @if ($action['granted'])
                            <x-filament::button color="danger" size="sm" outlined
                                wire:click="revoke('{{ addslashes($action['key']) }}')">
                                Revoke
                            </x-filament::button>
                        @else
                            <x-filament::button color="success" size="sm"
                                wire:click="grant('{{ addslashes($action['key']) }}')">
                                Grant
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Built-in seeds &amp; probes (always available)</x-slot>
        <x-slot name="description">
            Given/Then primitives implemented by the runner itself — sandbox-scoped and read-only where applicable.
        </x-slot>

        <div class="divide-y divide-gray-200">
            @foreach ($this->builtIns() as $builtIn)
                <div class="py-2">
                    <p class="font-mono text-sm">{{ $builtIn['key'] }} <x-filament::badge color="gray" class="ml-1">{{ $builtIn['kind'] }}</x-filament::badge></p>
                    <p class="text-xs text-gray-500">{{ $builtIn['summary'] }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
