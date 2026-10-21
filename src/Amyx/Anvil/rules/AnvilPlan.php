<?php
declare(strict_types=1);
namespace Amyx\Anvil\rules;

use pocketmine\item\Item;

/** Immutable by convention: all Item objects are private and returned as clones. */
final class AnvilPlan{
    public function __construct(
        private Item $input,
        private Item $material,
        private Item $result,
        public readonly int $levels,
        public readonly int $materialCount
    ){}
    public function input(): Item{ return clone $this->input; }
    public function material(): Item{ return clone $this->material; }
    public function result(): Item{ return clone $this->result; }
}
