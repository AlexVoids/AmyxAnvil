<?php
declare(strict_types=1);
namespace Amyx\Anvil\rules;

use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\enchantment\Enchantment;

final class EnchantmentRules{
    /** Bedrock ID => [translation suffix, maximum level, anvil multiplier]. */
    private const DEFINITIONS = [
        0=>['protect.all',4,1], 1=>['protect.fire',4,2], 2=>['protect.fall',4,2],
        3=>['protect.explosion',4,4], 4=>['protect.projectile',4,2], 5=>['thorns',3,8],
        6=>['oxygen',3,4], 7=>['waterWalker',3,4], 8=>['waterWorker',1,4],
        9=>['damage.all',5,1], 10=>['damage.undead',5,2], 11=>['damage.arthropods',5,2],
        12=>['knockback',2,2], 13=>['fire',2,4], 14=>['lootBonus',3,4],
        15=>['digging',5,1], 16=>['untouching',1,8], 17=>['durability',3,2],
        18=>['lootBonusDigger',3,4], 19=>['arrowDamage',5,1], 20=>['arrowKnockback',2,4],
        21=>['arrowFire',1,4], 22=>['arrowInfinite',1,8], 23=>['lootBonusFishing',3,4],
        24=>['fishingSpeed',3,4], 25=>['frostWalker',2,4], 26=>['mending',1,4],
        27=>['curse.binding',1,8], 28=>['curse.vanishing',1,8],
        29=>['tridentImpaling',5,2], 30=>['tridentRiptide',3,4], 31=>['tridentLoyalty',3,1],
        32=>['tridentChanneling',1,8], 33=>['crossbowMultishot',1,4],
        34=>['crossbowPiercing',4,1], 35=>['crossbowQuickCharge',3,2],
        36=>['soul_speed',3,8], 37=>['swift_sneak',3,8],
        38=>['heavy_weapon.density',5,1], 39=>['heavy_weapon.breach',4,4],
        40=>['heavy_weapon.windburst',3,4]
    ];

    /** Register only missing vanilla identities, so NBT survives loading and saving.
     * This does not implement combat/movement effects absent from PocketMine itself.
     */
    public static function registerMissing(): void{
        $map=EnchantmentIdMap::getInstance();
        foreach(self::DEFINITIONS as $id=>[$name,$max,$weight]){
            if($map->fromId($id)===null){
                $rarity=match($weight){1=>10,2=>5,4=>2,default=>1};
                $map->register($id,new Enchantment('enchantment.'.$name,$rarity,0,0,$max));
            }
        }
    }

    public static function maxLevel(Enchantment $type): int{
        $id=EnchantmentIdMap::getInstance()->toId($type);
        return self::DEFINITIONS[$id][1] ?? $type->getMaxLevel();
    }

    public static function multiplier(Enchantment $type,bool $book): int{
        $id=EnchantmentIdMap::getInstance()->toId($type);
        // Impaling is half the Java multiplier on Bedrock.
        $weight=self::DEFINITIONS[$id][2] ?? match($type->getRarity()){10=>1,5=>2,2=>4,default=>8};
        return $book ? max(1,intdiv($weight,2)) : $weight;
    }

    public static function compatible(Enchantment $first,Enchantment $second): bool{
        if($first===$second){ return true; }
        $map=EnchantmentIdMap::getInstance();$a=$map->toId($first);$b=$map->toId($second);
        foreach([[0,1,3,4],[9,10,11,38,39],[7,25],[16,18],[22,26],[30,31,32],[33,34]] as $group){
            if(in_array($a,$group,true) && in_array($b,$group,true)){ return false; }
        }
        // Respect additional incompatibilities registered by other plugins on newer PM5.
        return !method_exists($first,'isCompatibleWith') || ($first->isCompatibleWith($second) && $second->isCompatibleWith($first));
    }

    public static function applicable(Enchantment $type,Item $item): bool{
        $id=EnchantmentIdMap::getInstance()->toId($type);
        $name=AnvilCalculator::identifier($item);
        if($name==='enchanted_book'){ return true; }
        $armor=preg_match('/_(helmet|chestplate|leggings|boots)$/D',$name)===1 || in_array($name,['leather_cap','leather_tunic','leather_pants','turtle_shell'],true);
        $helmet=str_ends_with($name,'_helmet') || in_array($name,['leather_cap','turtle_shell'],true);
        $boots=str_ends_with($name,'_boots');
        $sword=str_ends_with($name,'_sword');$axe=str_ends_with($name,'_axe');
        $tool=preg_match('/_(pickaxe|axe|shovel|hoe)$/D',$name)===1;
        if(isset(self::DEFINITIONS[$id])){
            return match($id){
                0,1,3,4,5=>$armor,
                2,7,25,36=>$boots,
                6,8=>$helmet,
                9=>$sword || $axe,
                10,11=>$sword || $axe || $name==='mace',
                12,14=>$sword,
                13=>$sword || $name==='mace',
                15,16=>$tool || $name==='shears',
                18=>$tool,
                17,26=>$item instanceof Durable,
                28=>$item instanceof Durable || in_array($name,['compass','recovery_compass','carved_pumpkin'],true) || str_ends_with($name,'_head') || str_ends_with($name,'_skull'),
                19,20,21,22=>$name==='bow',
                23,24=>$name==='fishing_rod',
                27=>$armor || $name==='elytra' || str_ends_with($name,'_head') || str_ends_with($name,'_skull') || $name==='carved_pumpkin',
                29,30,31,32=>$name==='trident',
                33,34,35=>$name==='crossbow',
                37=>str_ends_with($name,'_leggings') || $name==='leather_pants',
                38,39,40=>$name==='mace',
                default=>false
            };
        }
        if(class_exists(\pocketmine\item\enchantment\AvailableEnchantmentRegistry::class)){
            return \pocketmine\item\enchantment\AvailableEnchantmentRegistry::getInstance()->isAvailableForItem($type,$item);
        }
        // Unregistered custom enchantments need an explicit compatibility implementation.
        return $item->hasEnchantment($type);
    }
}
