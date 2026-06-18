<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\WineLot;
use App\Services\Cellar\BlendService;

/** Mint a new lot by blending volume from several source vessels. */
class ExecuteBlendAction
{
    public function __construct(private readonly BlendService $blends) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, string $createdById): WineLot
    {
        return $this->blends->execute($data, $createdById);
    }
}
