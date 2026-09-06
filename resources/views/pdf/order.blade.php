<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .muted { color: #666; }
        .header { display: flex; justify-content: space-between; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f4f4f4; text-transform: uppercase; font-size: 10px; }
        .text-right { text-align: right; }
        .total-row td { border-top: 2px solid #1a1a1a; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ $order->tenant?->name ?? config('app.name') }}</h1>
        </div>
        <div class="text-right">
            <h1>Order #{{ $order->order_number }}</h1>
            <p class="muted">{{ $order->created_at?->format('Y-m-d') }}</p>
        </div>
    </div>

    <p>
        <strong>{{ $order->customer?->company_name }}</strong><br>
        @if($order->customer?->contact_name){{ $order->customer->contact_name }}<br>@endif
        @if($order->customer?->address){{ $order->customer->address }}<br>@endif
        {{ trim(($order->customer?->city ?? '').' '.($order->customer?->zip ?? '')) }}
        @if($order->customer?->country)<br>{{ $order->customer->country }}@endif
    </p>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Unit price</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr>
                    <td>{{ $item->inventoryItem?->name ?? $item->custom_description }}</td>
                    <td class="text-right">{{ $item->quantity }}</td>
                    <td class="text-right">{{ $item->unit_price->toMajor() }}</td>
                    <td class="text-right">{{ $item->total->toMajor() }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="3" class="text-right">Total ({{ $order->total_amount->getCurrencyCode() }})</td>
                <td class="text-right">{{ $order->total_amount->toMajor() }}</td>
            </tr>
        </tbody>
    </table>

    @if($order->notes)
        <p class="muted"><strong>Notes:</strong> {{ $order->notes }}</p>
    @endif
</body>
</html>
