<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Status</x-slot>
        <x-slot name="description">
            Provider keys are stored in Cloudflare (BYOK); this app authenticates to the
            gateway with <code>CLOUDFLARE_ACCOUNT_ID</code> + <code>CLOUDFLARE_API_TOKEN</code>.
            Use “Configure models” to set a model per capability — AI only turns on when the
            text and vision checks pass.
        </x-slot>

        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <tbody>
                    <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">AI features</td>
                        <td class="px-4 py-2 text-right">
                            @if ($this->aiEnabled())
                                <x-filament::badge color="success">Enabled</x-filament::badge>
                            @else
                                <x-filament::badge color="gray">Disabled</x-filament::badge>
                            @endif
                        </td>
                    </tr>
                    <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">Cloudflare gateway</td>
                        <td class="px-4 py-2 text-right">
                            @if ($this->gatewayConfigured())
                                <x-filament::badge color="success">Configured</x-filament::badge>
                            @else
                                <x-filament::badge color="danger">Missing</x-filament::badge>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Required keys</x-slot>
        <x-slot name="description">
            This app authenticates to Cloudflare with the credentials below. The upstream
            provider keys for your selected models are stored in the
            <strong>Cloudflare dashboard</strong> (AI Gateway → provider keys / BYOK), not here —
            use “Test capabilities” to confirm they reach each provider.
        </x-slot>

        <div class="mb-4 overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <tbody>
                    <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                            Cloudflare account ID <code class="text-xs">CLOUDFLARE_ACCOUNT_ID</code>
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if ($this->accountConfigured())
                                <x-filament::badge color="success">Configured</x-filament::badge>
                            @else
                                <x-filament::badge color="danger">Missing</x-filament::badge>
                            @endif
                        </td>
                    </tr>
                    <tr class="border-b border-gray-200 last:border-0 dark:border-white/10">
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                            Cloudflare API token <code class="text-xs">CLOUDFLARE_API_TOKEN</code>
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if ($this->tokenConfigured())
                                <x-filament::badge color="success">Configured</x-filament::badge>
                            @else
                                <x-filament::badge color="danger">Missing</x-filament::badge>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Provider</th>
                        <th class="px-4 py-2 text-left font-medium">Used for</th>
                        <th class="px-4 py-2 text-left font-medium">Key location</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->requiredKeys() as $row)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ $row['label'] }}</div>
                                <div class="font-mono text-xs text-gray-500">{{ implode(', ', $row['models']) }}</div>
                            </td>
                            <td class="px-4 py-2">{{ implode(', ', $row['capabilities']) }}</td>
                            <td class="px-4 py-2">
                                @if ($row['byok'])
                                    <x-filament::badge color="warning">Store key in Cloudflare</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray">Cloudflare native — no key</x-filament::badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Capabilities</x-slot>
        <x-slot name="description">Test and enable each capability independently — a capability can only be turned on once its test passes.</x-slot>

        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Capability</th>
                        <th class="px-4 py-2 text-left font-medium">Model</th>
                        <th class="px-4 py-2 text-left font-medium">Status</th>
                        <th class="px-4 py-2 text-left font-medium">Last test</th>
                        <th class="px-4 py-2 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->capabilities() as $key => $row)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="px-4 py-2">{{ $row['label'] }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $row['model'] }}</td>
                            <td class="px-4 py-2">
                                @if ($row['enabled'])
                                    <x-filament::badge color="success">Enabled</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray">Disabled</x-filament::badge>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                @php($result = $testResults[$key] ?? null)
                                @if ($result === null)
                                    <span class="text-gray-400">—</span>
                                @elseif ($result['ok'])
                                    <x-filament::badge color="success">Passed</x-filament::badge>
                                @else
                                    <x-filament::badge color="danger" :tooltip="$result['message']">Failed</x-filament::badge>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex items-center justify-end gap-2">
                                    <x-filament::button size="xs" color="gray" wire:click="testCapability('{{ $key }}')" wire:loading.attr="disabled">
                                        Test
                                    </x-filament::button>
                                    @if ($row['enabled'])
                                        <x-filament::button size="xs" color="danger" wire:click="disableCapability('{{ $key }}')" wire:loading.attr="disabled">
                                            Disable
                                        </x-filament::button>
                                    @else
                                        <x-filament::button size="xs" wire:click="enableCapability('{{ $key }}')" wire:loading.attr="disabled">
                                            Enable
                                        </x-filament::button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
