<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Send an announcement</x-slot>
        <x-slot name="description">
            Use “Compose announcement” above to push a message to every active member of the
            chosen tenants (or everyone). It appears in each user’s in-app notification bell and,
            for devices that have enabled notifications, as a web push.
        </x-slot>

        <ul class="list-inside list-disc text-sm text-gray-500 dark:text-gray-400">
            <li>Leave the audience empty to reach all tenants.</li>
            <li>Announcements are informational — they don’t deep-link anywhere.</li>
            <li>Delivery is best-effort: the in-app feed is always written; push reaches opted-in devices.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
