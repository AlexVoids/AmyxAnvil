<?php
declare(strict_types=1);
namespace Amyx\Anvil\rules;

use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\enchantment\EnchantmentInstance;

/** Pure Bedrock anvil calculation; no player, inventory, XP or world mutation. */
final class AnvilCalculator{
    public static function identifier(Item $item): string{
        if($item->isNull()){ return 'air'; }
        return preg_replace('/^minecraft:/','',$item->nbtSerialize()->getString('Name'));
    }

    private static function priorWork(Item $item): int{
        $tag=$item->getNamedTag()->getTag('RepairCost');
        if($tag===null){ return 0; }
        if(!$tag instanceof \pocketmine\nbt\tag\IntTag){ throw new AnvilException('Некорректная история ремонта.'); }
        return max(0,min(1073741823,$tag->getValue()));
    }

    public static function validName(string $name): bool{
        return mb_check_encoding($name,'UTF-8') && mb_strlen($name,'UTF-8')<=64 && preg_match('/[\x00-\x1f\x7f]/u',$name)===0;
    }

    private static function repairMaterial(Item $item,Item $material): bool{
        $name=self::identifier($item);$with=self::identifier($material);
        if(str_starts_with($name,'wooden_') || $name==='shield'){
            return str_ends_with($with,'_planks') || in_array($with,['planks','crimson_planks','warped_planks'],true);
        }
        if(str_starts_with($name,'stone_')){ return in_array($with,['cobblestone','blackstone','cobbled_deepslate'],true); }
        foreach(['iron_'=>'iron_ingot','chainmail_'=>'iron_ingot','golden_'=>'gold_ingot','gold_'=>'gold_ingot','copper_'=>'copper_ingot','diamond_'=>'diamond','netherite_'=>'netherite_ingot','leather_'=>'leather'] as $prefix=>$ingredient){
            if(str_starts_with($name,$prefix)){ return $with===$ingredient; }
        }
        return match($name){
            'turtle_helmet','turtle_shell'=>$with==='turtle_scute' || $with==='scute',
            'elytra'=>$with==='phantom_membrane',
            'mace'=>$with==='breeze_rod',
            default=>false
        };
    }

    private static function durability(Durable $item): int{
        // PM stores the breaking threshold one above the displayed vanilla durability.
        // Its fishing rod threshold is historically 384; the native anvil uses 64.
        return self::identifier($item)==='fishing_rod' ? 64 : max(1,$item->getMaxDurability()-1);
    }

    public function calculate(Item $input,Item $material,?string $name,bool $creative=false): AnvilPlan{
        if($input->isNull() || $input->getCount()>$input->getMaxStackSize()){
            throw new AnvilException('Поместите предмет в первый слот.');
        }
        if($name!==null && !self::validName($name)){ throw new AnvilException('Имя должно содержать не более 64 символов без переносов строк.'); }
        if($input instanceof Durable && $input->getCount()!==1){ throw new AnvilException('Ремонтировать можно один предмет за раз.'); }
        $result=clone $input;$work=0;$used=0;$renamed=false;
        $leftName=self::identifier($input);$rightName=self::identifier($material);
        if(!$material->isNull()){
            if($result instanceof Durable && self::repairMaterial($result,$material)){
                $unit=max(1,intdiv(self::durability($result),4));
                while($result->getDamage()>0 && $used<$material->getCount()){
                    $result->setDamage(max(0,$result->getDamage()-$unit));++$used;++$work;
                }
                if($used===0){ throw new AnvilException('Предмет не нуждается в ремонте.'); }
            }else{
                $book=$rightName==='enchanted_book' && $material->hasEnchantments();
                if(!$book && !($leftName===$rightName && $input instanceof Durable && $material instanceof Durable)){
                    throw new AnvilException('Эти предметы нельзя объединить.');
                }
                if(!$book && $result instanceof Durable && $material instanceof Durable){
                    $maximum=self::durability($result);
                    $damage=max(0,$result->getDamage()+$material->getDamage()-$maximum-intdiv($maximum*12,100));
                    if($damage<$result->getDamage()){ $result->setDamage($damage);$work+=2; }
                }
                foreach($material->getEnchantments() as $instance){
                    $type=$instance->getType();$current=$result->getEnchantmentLevel($type);
                    $level=min(EnchantmentRules::maxLevel($type),$current===$instance->getLevel() ? $current+1 : max($current,$instance->getLevel()));
                    if($level<=$current || !EnchantmentRules::applicable($type,$result)){ continue; }
                    $compatible=true;
                    foreach($result->getEnchantments() as $existing){
                        if(!EnchantmentRules::compatible($type,$existing->getType())){ $compatible=false;break; }
                    }
                    if(!$compatible){ continue; }
                    $result->addEnchantment(new EnchantmentInstance($type,$level));
                    $work+=EnchantmentRules::multiplier($type,$book)*($level-$current);
                    if($input->getCount()>1){ $work=40; }
                }
                if($work===0){ throw new AnvilException('Ремонт или улучшение чар невозможны.'); }
                $used=1;
            }
        }
        if($name!==null){
            if(trim($name)===''){ $name=''; }
            if($name!==$input->getCustomName()){
                $name==='' ? $result->clearCustomName() : $result->setCustomName($name);$renamed=true;
            }
        }
        if($work===0 && !$renamed){ throw new AnvilException('Ничего не изменено.'); }
        $prior=self::priorWork($input);$other=$material->isNull() ? 0 : self::priorWork($material);
        $cost=$prior+$other+$work+($renamed ? 1 : 0);
        if($work===0 && $renamed){ $cost=min(39,$cost); }
        if(!$creative && $cost>=40){ throw new AnvilException('Слишком дорого! Максимум — 39 уровней.'); }
        $next=max($prior,$other);
        if($work>0){ $next=min(2147483647,$next*2+1); }
        $tag=clone $result->getNamedTag();$tag->setInt('RepairCost',$next);$result->setNamedTag($tag);
        return new AnvilPlan(clone $input,clone $material,$result,$cost,$used);
    }
}
