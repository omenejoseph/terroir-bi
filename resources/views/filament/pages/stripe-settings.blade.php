<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Connection</x-slot>
        <x-slot name="description">
            Credentials are read from the server environment (<code>STRIPE_SECRET</code>,
            <code>STRIPE_WEBHOOK_SECRET</code>). Use “Test connection” above to confirm they reach Stripe.
        </x-slot>

        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-sm text-gray-500 dark:text-gray-400">Secret key</dt>
                <dd>
                    @if ($this->secretConfigured())
                        <x-filament::badge color="success">Configured</x-filament::badge>
                    @else
                        <x-filament::badge color="danger">Missing</x-filament::badge>
                    @endif
                </dd>
            </div>

            <div class="flex items-center justify-between gap-3">
                <dt class="text-sm text-gray-500 dark:text-gray-400">Webhook signing secret</dt>
                <dd>
                    @if ($this->webhookConfigured())
                        <x-filament::badge color="success">Configured</x-filament::badge>
                    @else
                        <x-filament::badge color="warning">Missing</x-filament::badge>
                    @endif
                </dd>
            </div>

            <div class="flex items-center justify-between gap-3 sm:col-span-2">
                <dt class="text-sm text-gray-500 dark:text-gray-400">Checkout success URL</dt>
                <dd class="truncate font-mono text-xs">{{ $this->successUrl() ?: '—' }}</dd>
            </div>

            <div class="flex items-center justify-between gap-3 sm:col-span-2">
                <dt class="text-sm text-gray-500 dark:text-gray-400">Checkout cancel URL</dt>
                <dd class="truncate font-mono text-xs">{{ $this->cancelUrl() ?: '—' }}</dd>
            </div>
        </dl>
    </x-filament::section>

    @if ($account)
        <x-filament::section>
            <x-slot name="heading">Connected account</x-slot>
            <x-slot name="description">Returned by the last successful test.</x-slot>

            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Account ID</dt>
                    <dd class="font-mono text-xs">{{ $account['id'] }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Mode</dt>
                    <dd>
                        @if ($account['livemode'])
                            <x-filament::badge color="success">Live</x-filament::badge>
                        @else
                            <x-filament::badge color="gray">Test</x-filament::badge>
                        @endif
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Business name</dt>
                    <dd class="text-sm">{{ $account['business_name'] ?: '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Country</dt>
                    <dd class="text-sm">{{ $account['country'] ?: '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Default currency</dt>
                    <dd class="text-sm uppercase">{{ $account['default_currency'] ?: '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Charges enabled</dt>
                    <dd>
                        @if ($account['charges_enabled'])
                            <x-filament::badge color="success">Yes</x-filament::badge>
                        @else
                            <x-filament::badge color="warning">No</x-filament::badge>
                        @endif
                    </dd>
                </div>
            </dl>
        </x-filament::section>
    @elseif ($testError)
        <x-filament::section>
            <x-slot name="heading">Last test failed</x-slot>
            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $testError }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
