<x-filament-panels::page>
    @php($totals = $this->totals())
    @php($selectClass = 'rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5')

    <x-filament::section>
        <x-slot name="heading">Filters</x-slot>

        <div class="flex flex-wrap items-end gap-3">
            <label class="space-y-1">
                <span class="block text-xs font-medium text-gray-500 dark:text-gray-400">Period</span>
                <select wire:model.live="period" class="{{ $selectClass }}">
                    @foreach ($this->periodOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            @if ($period === 'custom')
                <label class="space-y-1">
                    <span class="block text-xs font-medium text-gray-500 dark:text-gray-400">From</span>
                    <input type="date" wire:model.live="from" class="{{ $selectClass }}" />
                </label>
                <label class="space-y-1">
                    <span class="block text-xs font-medium text-gray-500 dark:text-gray-400">To</span>
                    <input type="date" wire:model.live="to" class="{{ $selectClass }}" />
                </label>
            @endif

            <label class="space-y-1">
                <span class="block text-xs font-medium text-gray-500 dark:text-gray-400">Tenant</span>
                <select wire:model.live="tenantId" class="{{ $selectClass }}">
                    <option value="">All tenants</option>
                    @foreach ($this->tenantOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Totals</x-slot>
        <x-slot name="description">
            {{ $this->windowStart()->isoFormat('ll') }} – {{ $this->windowEnd()->isoFormat('ll') }}.
            Requests &amp; tokens are local; use “Load Cloudflare cost” for USD spend over this period.
            @unless ($this->gatewayConfigured())
                <span class="text-danger-600">Cloudflare gateway is not configured, so cost is unavailable.</span>
            @endunless
        </x-slot>

        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Requests</dt>
                <dd class="text-2xl font-semibold tabular-nums">{{ number_format($totals['requests']) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Prompt tokens</dt>
                <dd class="text-2xl font-semibold tabular-nums">{{ number_format($totals['prompt_tokens']) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Completion tokens</dt>
                <dd class="text-2xl font-semibold tabular-nums">{{ number_format($totals['completion_tokens']) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Cost (Cloudflare)</dt>
                <dd class="text-2xl font-semibold tabular-nums">
                    {{ $totals['cost_usd'] !== null ? '$'.number_format($totals['cost_usd'], 4) : '—' }}
                </dd>
            </div>
        </dl>
    </x-filament::section>

    @php($tenants = $this->perTenant())

    <x-filament::section>
        <x-slot name="heading">By tenant</x-slot>
        <x-slot name="description">Per-tenant usage for the selected period (tagged via <code>cf-aig-metadata</code>).</x-slot>

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
                    @forelse ($tenants as $row)
                        @php($cost = $this->tenantCost($row['tenant_id']))
                        <tr class="border-t border-gray-200 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">
                            <td class="px-4 py-2">{{ $row['tenant'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($row['requests']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($row['prompt_tokens']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($row['completion_tokens']) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ $cost !== null ? '$'.number_format($cost, 4) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No AI usage in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($tenants->hasPages())
            <div class="mt-3">
                {{ $tenants->links() }}
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
