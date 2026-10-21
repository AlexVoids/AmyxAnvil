<?php
declare(strict_types=1);
namespace Amyx\Anvil\compat;

use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\StringToItemParser;
use pocketmine\world\format\io\GlobalItemDataHandlers;

final class Bootstrap{
    public static function register(): void{
        \Amyx\Anvil\rules\EnchantmentRules::registerMissing();
        $parser=StringToItemParser::getInstance();
        if($parser->parse('enchanted_book')!==null){ return; }
        $item=new EnchantedBook(new ItemIdentifier(ItemTypeIds::newId()),'Enchanted Book');
        GlobalItemDataHandlers::getSerializer()->map($item,static fn()=>new SavedItemData('minecraft:enchanted_book'));
        GlobalItemDataHandlers::getDeserializer()->map('minecraft:enchanted_book',static fn()=>clone $item);
        $parser->register('enchanted_book',static fn()=>clone $item);
    }
    public static function book(): Item{
        return StringToItemParser::getInstance()->parse('enchanted_book') ?? throw new \LogicException('Book compatibility was not initialized');
    }
}
