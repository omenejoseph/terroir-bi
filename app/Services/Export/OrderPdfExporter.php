<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * The drawer's overflow menu "Print" — a packing-slip/invoice PDF built from
 * the same Order model the drawer itself reads, so it can never disagree with
 * what's on screen.
 */
class OrderPdfExporter
{
    public function download(Order $order): Response
    {
        $order->loadMissing(['customer', 'items.inventoryItem']);

        $pdf = Pdf::loadView('pdf.order', ['order' => $order]);

        return $pdf->download($order->order_number.'.pdf');
    }
}
