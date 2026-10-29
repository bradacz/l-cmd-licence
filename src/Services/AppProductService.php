<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppProductRepository;

final class AppProductService
{
    public function __construct(
        private readonly AppProductRepository $bindings = new AppProductRepository()
    ) {
    }

    /** @param int[] $productIds */
    public function sync(int $appId, array $productIds): void
    {
        $target = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        $current = $this->bindings->listProductIdsForApp($appId);

        foreach (array_diff($current, $target) as $productId) {
            $this->bindings->detach($appId, (int) $productId);
        }

        foreach (array_diff($target, $current) as $productId) {
            $this->bindings->attach($appId, (int) $productId);
        }
    }
}
