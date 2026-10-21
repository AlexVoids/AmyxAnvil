<?php
declare(strict_types=1);
namespace Amyx\Anvil\network;

use Amyx\Anvil\rules\AnvilCalculator;
use Amyx\Anvil\rules\AnvilException;
use pocketmine\block\inventory\AnvilInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\InventorySlotPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\FullContainerName;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\UIInventorySlotOffset;
use pocketmine\player\Player;

/** Client-only preview: never an inventory item and never an ingredient source. */
final class BookPreview{
    private \WeakMap $states;
    private \ReflectionMethod $packetFactory;

    public function __construct(){
        $this->states=new \WeakMap();
        $this->packetFactory=new \ReflectionMethod(InventorySlotPacket::class,'create');
    }

    public function tick(iterable $players): void{
        foreach($players as $player){ $this->refresh($player); }
    }

    public function forget(Player $player): void{ unset($this->states[$player]); }

    public function invalidate(Player $player): void{
        if(isset($this->states[$player])){
            $state=$this->states[$player];$state['valid']=false;$this->states[$player]=$state;
        }
    }

    public function refresh(Player $player): void{
        $window=$player->getCurrentWindow();
        if(!$player->isConnected() || !$window instanceof AnvilInventory){ $this->forget($player);return; }
        $input=$window->getItem(0);$material=$window->getItem(1);
        $old=$this->states[$player] ?? null;$windowId=spl_object_id($window);$creative=$player->isCreative();
        $allowed=!$player->isSpectator() && $player->hasPermission('amyxanvil.use');$level=$player->getXpManager()->getXpLevel();
        if($old!==null && $old['valid'] && $old['window']===$windowId && $old['creative']===$creative && $old['allowed']===$allowed && $old['level']===$level && $old['input']->equalsExact($input) && $old['material']->equalsExact($material)){ return; }
        $result=VanillaItems::AIR();
        if($allowed && AnvilCalculator::identifier($input)==='enchanted_book' && AnvilCalculator::identifier($material)==='enchanted_book'){
            try{ $result=(new AnvilCalculator())->calculate($input,$material,null,$creative)->result(); }
            catch(AnvilException){ /* Invalid, incompatible or too expensive: no preview. */ }
        }
        // Never clear or overwrite another window's crafting result.
        if(!$result->isNull() || ($old!==null && $old['window']===$windowId && $old['visible'])){
            $this->send($player,$result);
        }
        $this->states[$player]=['window'=>$windowId,'input'=>$input,'material'=>$material,'creative'=>$creative,'allowed'=>$allowed,'level'=>$level,'visible'=>!$result->isNull(),'valid'=>true];
    }

    private function send(Player $player,Item $result): void{
        $session=$player->getNetworkSession();$manager=$session->getInvManager();
        if($manager===null){ return; }
        $wrapper=new ItemStackWrapper(0,$session->getTypeConverter()->coreItemStackToNet($result));
        // PM5 has 3/5 arguments; NG also has dynamicContainerSize. Fill the
        // factory's declared fields instead of assuming the newest wire layout.
        $values=['windowId'=>ContainerIds::UI,'inventorySlot'=>UIInventorySlotOffset::CREATED_ITEM_OUTPUT,
            'dynamicContainerSize'=>0,'storage'=>new ItemStackWrapper(0,ItemStack::null()),'item'=>$wrapper];
        $legacyNg=false;
        if(class_exists(FullContainerName::class)){
            $legacyNg=method_exists($session,'getProtocolId') && defined(ProtocolInfo::class.'::PROTOCOL_1_26_20') && $session->getProtocolId()<constant(ProtocolInfo::class.'::PROTOCOL_1_26_20');
            $values['containerName']=new FullContainerName($manager->getCurrentWindowId());
        }
        $args=[];
        foreach($this->packetFactory->getParameters() as $parameter){
            $name=$parameter->getName();
            if(($name==='containerName' || $name==='storage') && $parameter->allowsNull() && !$legacyNg){ $args[]=null; }
            elseif(array_key_exists($name,$values)){ $args[]=$values[$name]; }
            elseif($parameter->isDefaultValueAvailable()){ $args[]=$parameter->getDefaultValue(); }
            else{ throw new \RuntimeException('Unsupported inventory slot field: '.$name); }
        }
        $session->sendDataPacket($this->packetFactory->invokeArgs(null,$args));
    }
}
