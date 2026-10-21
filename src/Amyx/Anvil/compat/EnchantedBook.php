<?php
declare(strict_types=1);
namespace Amyx\Anvil\compat;

/** Early PM5 did not yet register the native enchanted_book item. */
final class EnchantedBook extends \pocketmine\item\Item{
    public function getMaxStackSize(): int{ return 1; }
}
