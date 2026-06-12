<x-filament-panels::page>
    @php($totals = $this->localTotals())
    @php($global = $this->cloudflare['global'] ?? null)

    <x-filament::section>
        <x-slot name="heading">Last {{ $this->days }} days</x-slot>
        <x-slot name="description">
            Requests and tokens come from local usage logs. Click “Load Cloudflare spend”
            for the USD cost from the AI Gateway logs.
            @unless ($this->gatewayConfigured())
                <span class="text-danger-600">Cloudflare gateway is not configured, so cost is unavailable.</span>
            @endunless
        </x-slot>

        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Requests</dt>
                <dd class="text-2xl font-semibold">{{ number_format($totals['requests']) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Prompt tokens</dt>
                <dd class="text-2xl font-semibold">{{ number_format($totals['prompt_tokens']) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Completion tokens</dt>
                <dd class="text-2xl font-semibold">{{ number_format($totals['completion_tokens']) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Cost (Cloudflare)</dt>
                <dd class="text-2xl font-semibold">
                    {{ $global !== null ? '$'.number_format((float) $global['cost_usd'], 2) : '—' }}
                </dd>
            </div>
        </dl>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">By tenant</x-slot>
        <x-slot name="description">Per-tenant usage (tagged via <code>cf-aig-metadata</code>).</x-slot>

        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Tenant</th>
                        <th class="px-4 py-2 text-right font-medium">Requests</th>
                        <th class="px-4 py-2 text-right font-medium">Prompt tokens</th>
                        <th class="px-4 py-2 text-right font-medium">Completion tokens</th>
                        <th class="px-4 py-2 text-right font-medium">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->byTenant() as $row)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="px-4 py-2">{{ $row['tenant'] }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($row['requests']) }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($row['prompt_tokens']) }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($row['completion_tokens']) }}</td>
                            <td class="px-4 py-2 text-right">
                                {{ $row['cost_usd'] !== null ? '$'.number_format($row['cost_usd'], 2) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No AI usage yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
