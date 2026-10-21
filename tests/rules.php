<?php
declare(strict_types=1);

$phar=getenv('PMMP_PHAR');
if(!$phar || !is_file($phar)){ fwrite(STDERR,"Set PMMP_PHAR to an official PocketMine-MP 5 PHAR.\n");exit(2); }
require 'phar://'.realpath($phar).'/vendor/autoload.php';
spl_autoload_register(static function(string $class): void{
    $file=dirname(__DIR__).'/src/'.str_replace('\\','/',$class).'.php';if(is_file($file)){ require $file; }
});

use Amyx\Anvil\rules\AnvilCalculator;
use Amyx\Anvil\rules\AnvilException;
use Amyx\Anvil\rules\EnchantmentRules;
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\item\VanillaItems as I;
use pocketmine\block\VanillaBlocks as B;
use pocketmine\item\enchantment\EnchantmentInstance as EI;

\Amyx\Anvil\compat\Bootstrap::register();
$calc=new AnvilCalculator();$tests=[];
function expect(bool $ok,string $message='Assertion failed'): void{ if(!$ok){ throw new RuntimeException($message); } }
function eq(mixed $a,mixed $b): void{ expect($a===$b,var_export($a,true).' !== '.var_export($b,true)); }
function denied(Closure $fn): void{ try{ $fn(); }catch(AnvilException){ return; }throw new RuntimeException('Expected rejection'); }
function ench(int $id,int $level=1): EI{ return new EI(EnchantmentIdMap::getInstance()->fromId($id),$level); }
function book(): \pocketmine\item\Item{ return \Amyx\Anvil\compat\Bootstrap::book(); }
function work(\pocketmine\item\Item $item,int $cost): \pocketmine\item\Item{ $tag=clone $item->getNamedTag();return $item->setNamedTag($tag->setInt('RepairCost',$cost)); }
function nameTest(string $name,Closure $fn): void{ global $tests;$tests[$name]=$fn; }

nameTest('rename Unicode, count and all unrelated NBT survive',function() use($calc): void{
    $input=I::DIAMOND()->setCount(64)->setLore(['История']);$tag=clone $input->getNamedTag();$input->setNamedTag($tag->setString('OtherPlugin','keep'));
    $plan=$calc->calculate($input,I::AIR(),'Мой алмаз');eq(1,$plan->levels);eq(0,$plan->materialCount);eq(64,$plan->result()->getCount());eq('Мой алмаз',$plan->result()->getCustomName());eq('keep',$plan->result()->getNamedTag()->getString('OtherPlugin'));eq(['История'],$plan->result()->getLore());eq('',$input->getCustomName());
});
nameTest('explicit blank removes a name; missing text retains it',function() use($calc): void{
    $input=I::IRON_SWORD()->setDamage(100)->setCustomName('Клинок');
    eq('',$calc->calculate($input,I::AIR(),'')->result()->getCustomName());eq('Клинок',$calc->calculate($input,I::IRON_INGOT(),null)->result()->getCustomName());
});
nameTest('reject empty, no-op, invalid UTF-8, long or control text',function() use($calc): void{
    denied(fn()=>$calc->calculate(I::AIR(),I::AIR(),'a'));denied(fn()=>$calc->calculate(I::DIAMOND(),I::AIR(),null));
    foreach([str_repeat('я',65),"a\nb","a\0b","\xff"] as $s){ denied(fn()=>$calc->calculate(I::DIAMOND(),I::AIR(),$s)); }
    eq(str_repeat('я',64),$calc->calculate(I::DIAMOND(),I::AIR(),str_repeat('я',64))->result()->getCustomName());
});
nameTest('material repair charges only necessary units and leaves the source unchanged',function() use($calc): void{
    $input=I::IRON_SWORD()->setDamage(100);$material=I::IRON_INGOT()->setCount(64);$p=$calc->calculate($input,$material,null);
    eq(2,$p->levels);eq(2,$p->materialCount);eq(0,$p->result()->getDamage());eq(100,$input->getDamage());eq(64,$material->getCount());eq(1,$p->result()->getNamedTag()->getInt('RepairCost'));
});
nameTest('stone repair uses native durability 131, not PM breaking threshold 132',function() use($calc): void{
    $p=$calc->calculate(I::STONE_PICKAXE()->setDamage(65),B::COBBLESTONE()->asItem()->setCount(3),null);eq(3,$p->materialCount);eq(0,$p->result()->getDamage());
});
nameTest('partial material repair leaves remaining damage',function() use($calc): void{
    $p=$calc->calculate(I::DIAMOND_SWORD()->setDamage(1000),I::DIAMOND(),null);eq(610,$p->result()->getDamage());eq(1,$p->levels);
});
nameTest('repair plus rename costs one extra level',function() use($calc): void{
    $p=$calc->calculate(I::IRON_SWORD()->setDamage(100),I::IRON_INGOT()->setCount(2),'Меч');eq(3,$p->levels);eq('Меч',$p->result()->getCustomName());
});
nameTest('wrong material and fully repaired input reject without consuming',function() use($calc): void{
    denied(fn()=>$calc->calculate(I::DIAMOND_SWORD()->setDamage(1),I::IRON_INGOT(),null));denied(fn()=>$calc->calculate(I::DIAMOND_SWORD(),I::DIAMOND(),null));denied(fn()=>$calc->calculate(I::DIAMOND(),I::DIAMOND(),null));
});
nameTest('same item repair includes 12 percent durability bonus',function() use($calc): void{
    $p=$calc->calculate(I::IRON_SWORD()->setDamage(200),I::IRON_SWORD()->setDamage(200),null);eq(120,$p->result()->getDamage());eq(2,$p->levels);eq(1,$p->materialCount);
});
nameTest('book enchant applies with discounted Bedrock cost',function() use($calc): void{
    $p=$calc->calculate(I::DIAMOND_SWORD(),book()->addEnchantment(ench(26)),null);eq(2,$p->levels);eq(1,$p->result()->getEnchantmentLevel(ench(26)->getType()));
});
nameTest('equal enchant levels upgrade and charge only level delta on Bedrock',function() use($calc): void{
    $p=$calc->calculate(I::DIAMOND_SWORD()->addEnchantment(ench(9,3)),book()->addEnchantment(ench(9,3)),null);eq(1,$p->levels);eq(4,$p->result()->getEnchantmentLevel(ench(9)->getType()));
});
nameTest('existing maximum and overlevel enchantments never downgrade',function() use($calc): void{
    denied(fn()=>$calc->calculate(I::DIAMOND_SWORD()->addEnchantment(ench(9,5)),book()->addEnchantment(ench(9,5)),null));
    $p=$calc->calculate(I::DIAMOND_SWORD()->addEnchantment(ench(9,10))->setDamage(1),I::DIAMOND(),null);eq(10,$p->result()->getEnchantmentLevel(ench(9)->getType()));
});
nameTest('enchantment applicability rejects sword protection and bow mending conflict',function() use($calc): void{
    denied(fn()=>$calc->calculate(I::DIAMOND_SWORD(),book()->addEnchantment(ench(0)),null));
    denied(fn()=>$calc->calculate(I::BOW()->addEnchantment(ench(22)),book()->addEnchantment(ench(26)),null));
});
nameTest('multi-enchantment books apply compatible entries and retain existing ones',function() use($calc): void{
    $p=$calc->calculate(I::DIAMOND_PICKAXE()->addEnchantment(ench(16)),book()->addEnchantment(ench(18,3))->addEnchantment(ench(17,3)),null);eq(3,$p->levels);eq(0,$p->result()->getEnchantmentLevel(ench(18)->getType()));eq(1,$p->result()->getEnchantmentLevel(ench(16)->getType()));eq(3,$p->result()->getEnchantmentLevel(ench(17)->getType()));
});
nameTest('two books merge and serialize newly registered vanilla identities',function() use($calc): void{
    $p=$calc->calculate(book()->addEnchantment(ench(14,2)),book()->addEnchantment(ench(14,2)),null);eq(3,$p->result()->getEnchantmentLevel(ench(14)->getType()));eq(2,$p->levels);
    $round=\pocketmine\item\Item::nbtDeserialize($p->result()->nbtSerialize());eq(3,$round->getEnchantmentLevel(ench(14)->getType()));
});
nameTest('prior-work penalty grows as 1, 3, 7 and is inherited from both inputs',function() use($calc): void{
    $p=$calc->calculate(work(I::IRON_SWORD()->setDamage(100),3),work(I::IRON_INGOT(),7),null);eq(11,$p->levels);eq(15,$p->result()->getNamedTag()->getInt('RepairCost'));
});
nameTest('survival too-expensive cap and creative bypass',function() use($calc): void{
    $item=work(I::IRON_SWORD()->setDamage(1),39);denied(fn()=>$calc->calculate($item,I::IRON_INGOT(),null));eq(40,$calc->calculate($item,I::IRON_INGOT(),null,true)->levels);
});
nameTest('rename-only cap is 39 and never increases prior work',function() use($calc): void{
    $p=$calc->calculate(work(I::DIAMOND(),127),I::AIR(),'Имя');eq(39,$p->levels);eq(127,$p->result()->getNamedTag()->getInt('RepairCost'));
});
nameTest('calculated items are defensive copies',function() use($calc): void{
    $p=$calc->calculate(I::DIAMOND(),I::AIR(),'a');$p->result()->setCount(64);eq(1,$p->result()->getCount());$p->input()->setCustomName('b');eq('',$p->input()->getCustomName());
});
nameTest('exhaustive registered vanilla pair conflicts are symmetric',function(): void{
    $map=EnchantmentIdMap::getInstance();for($a=0;$a<=40;++$a){ for($b=0;$b<=40;++$b){ eq(EnchantmentRules::compatible($map->fromId($a),$map->fromId($b)),EnchantmentRules::compatible($map->fromId($b),$map->fromId($a))); } }
});
nameTest('all wear rolls preserve exactly 12 percent damage probability and creative immunity',function(): void{
    for($state=0;$state<=2;++$state){
        $changed=0;for($roll=1;$roll<=100;++$roll){
            $next=\Amyx\Anvil\rules\AnvilWear::afterUse($state,false,$roll);expect($next===$state || $next===$state+1);if($next!==$state){ ++$changed; }
            eq($state,\Amyx\Anvil\rules\AnvilWear::afterUse($state,true,$roll));
        }eq(12,$changed);
    }
});
$failures=0;
foreach($tests as $name=>$test){ try{ $test();echo "PASS $name\n"; }catch(Throwable $e){ ++$failures;fwrite(STDERR,"FAIL $name: ".$e->getMessage()."\n".$e->getTraceAsString()."\n"); } }
echo (count($tests)-$failures).'/'.count($tests)." rule tests passed\n";exit($failures ? 1 : 0);
