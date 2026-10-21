<?php
declare(strict_types=1);
namespace Amyx\Anvil\transaction;

use pocketmine\inventory\transaction\action\InventoryAction;
use pocketmine\player\Player;

/** Server-created accounting entry for the exact, independently calculated transformation.
 * Never constructed from a client-supplied item. The enclosing transaction validates the plan.
 */
final class AnvilTransformAction extends InventoryAction{
    public function validate(Player $source): void{}
    public function execute(Player $source): void{}
}
