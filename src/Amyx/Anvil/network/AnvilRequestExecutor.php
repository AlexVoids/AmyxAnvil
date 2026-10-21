<?php
declare(strict_types=1);
namespace Amyx\Anvil\network;

use Amyx\Anvil\rules\AnvilCalculator;
use Amyx\Anvil\rules\AnvilException;
use Amyx\Anvil\rules\AnvilPlan;
use Amyx\Anvil\transaction\AnvilTransaction;
use pocketmine\block\inventory\AnvilInventory;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\handler\ItemStackRequestExecutor;
use pocketmine\network\mcpe\handler\ItemStackRequestProcessException;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
use pocketmine\network\mcpe\protocol\types\inventory\FullContainerName;
use pocketmine\network\mcpe\protocol\types\inventory\UIInventorySlotOffset;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftRecipeOptionalStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftingConsumeInputStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftingCreateSpecificResultStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DeprecatedCraftingNonImplementedStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DeprecatedCraftingResultsStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequestSlotInfo;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\PlaceStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\TakeStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponse;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponseContainerInfo;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponseSlotInfo;
use pocketmine\player\Player;

final class AnvilRequestExecutor extends ItemStackRequestExecutor{
    private AnvilPlan $plan;
    private array $consumed=[0=>0,1=>0];
    private bool $started=false;
    /** @var ItemStackRequestSlotInfo[] Virtual result slots actually used by this request. */
    private array $previewSlots=[];
    /** @var array<int,array<int,array{Inventory,Item,Item}>> Initial/final slot snapshots. */
    private array $slotChanges=[];
    /** @var array<int,array<int,array{ItemStackRequestSlotInfo,Inventory,int}>> */
    private array $responseSlots=[];

    public function __construct(private Player $actor,private InventoryManager $manager,private ItemStackRequest $anvilRequest,private AnvilInventory $anvil){
        parent::__construct($actor,$manager,$anvilRequest);
        $optional=null;
        foreach($anvilRequest->getActions() as $action){
            if($action instanceof CraftRecipeOptionalStackRequestAction){
                if($optional!==null){ throw new ItemStackRequestProcessException('Multiple anvil recipes'); }
                $optional=$action;
            }
        }
        $strings=$anvilRequest->getFilterStrings();$name=null;
        if($optional!==null){
            $index=$optional->getFilterStringIndex();
            // Bedrock may leave index 0 even when there are no strings to filter.
            if($index>=0 && $strings!==[]){
                if(!array_key_exists($index,$strings)){ throw new ItemStackRequestProcessException('Invalid rename text index'); }
                $name=$strings[$index];
            }
        }elseif(count($strings)===1){ $name=$strings[0]; }
        try{ $this->plan=(new AnvilCalculator())->calculate($anvil->getItem(0),$anvil->getItem(1),$name,$actor->isCreative()); }
        catch(AnvilException $e){ throw new ItemStackRequestProcessException($e->getMessage(),0,$e); }
    }

    public static function isAnvilRequest(ItemStackRequest $request): bool{
        foreach($request->getActions() as $action){
            if($action instanceof CraftRecipeOptionalStackRequestAction || $action instanceof DeprecatedCraftingNonImplementedStackRequestAction){ return true; }
            if(($action instanceof TakeStackRequestAction || $action instanceof PlaceStackRequestAction) && self::isOutputSlot($action->getSource())){ return true; }
        }
        return false;
    }

    private static function container(ItemStackRequestSlotInfo $slot): int{
        return method_exists($slot,'getContainerName') ? $slot->getContainerName()->getContainerId() : $slot->getContainerId();
    }

    private static function isOutputSlot(ItemStackRequestSlotInfo $slot): bool{
        $container=self::container($slot);
        return ($container===ContainerUIIds::CREATED_OUTPUT && $slot->getSlotId()===UIInventorySlotOffset::CREATED_ITEM_OUTPUT)
            || ($container===ContainerUIIds::ANVIL_RESULT_PREVIEW && in_array($slot->getSlotId(),[3,UIInventorySlotOffset::CREATED_ITEM_OUTPUT],true));
    }

    protected function removeItemFromSlot(ItemStackRequestSlotInfo $slotInfo,int $count): Item{
        if(self::isOutputSlot($slotInfo)){
            // Native anvil results use this preview container as well as CREATED_OUTPUT.
            // Both draw from the SAME server-calculated, finite result stack.
            $item=$this->takeCreatedItem($count);
            if(self::container($slotInfo)===ContainerUIIds::ANVIL_RESULT_PREVIEW){ $this->previewSlots[$slotInfo->getSlotId()]=$slotInfo; }
            return $item;
        }
        [$inventory,$slot]=$this->resolveSlot($slotInfo);
        $item=$this->readSlot($inventory,$slot);
        if($count<1 || $count>$item->getCount()){ throw new ItemStackRequestProcessException('Invalid source count'); }
        $removed=$item->pop($count);
        $this->writeSlot($inventory,$slot,$item);
        return $removed;
    }

    protected function addItemToSlot(ItemStackRequestSlotInfo $slotInfo,Item $item,int $count): void{
        [$inventory,$slot]=$this->resolveSlot($slotInfo);
        $existing=$this->readSlot($inventory,$slot);
        if($count<1 || (!$existing->isNull() && !$existing->canStackWith($item))){ throw new ItemStackRequestProcessException('Invalid destination item'); }
        $total=$existing->getCount()+$count;
        if($total>min($item->getMaxStackSize(),$inventory->getMaxStackSize())){ throw new ItemStackRequestProcessException('Destination stack is full'); }
        $this->writeSlot($inventory,$slot,(clone $item)->setCount($total));
    }

    private function resolveSlot(ItemStackRequestSlotInfo $info): array{
        // Retain core's container/window/slot and stack-network-ID validation.
        [$builder,$slot]=$this->getBuilderInventoryAndSlot($info);
        $inventory=$builder->getActualInventory();
        $this->responseSlots[self::container($info)][$info->getSlotId()]=[$info,$inventory,$slot];
        return [$inventory,$slot];
    }

    private function readSlot(Inventory $inventory,int $slot): Item{
        return clone ($this->slotChanges[spl_object_id($inventory)][$slot][2] ?? $inventory->getItem($slot));
    }

    private function writeSlot(Inventory $inventory,int $slot,Item $item): void{
        $id=spl_object_id($inventory);
        $initial=$this->slotChanges[$id][$slot][1] ?? $inventory->getItem($slot);
        // Unlike some PM5 TransactionBuilderInventory versions, a transition back
        // to the original item (notably AIR -> result -> AIR) MUST reset the diff.
        $this->slotChanges[$id][$slot]=[$inventory,$initial,clone $item];
    }

    protected function processItemStackRequestAction(ItemStackRequestAction $action): void{
        if($action instanceof CraftRecipeOptionalStackRequestAction || $action instanceof DeprecatedCraftingNonImplementedStackRequestAction){
            if(!$this->started){ $this->started=true;$this->setNextCreatedItem($this->plan->result()); }
            return;
        }
        if(!$this->started){ throw new ItemStackRequestProcessException('Anvil recipe must precede inventory changes'); }
        if($action instanceof CraftingConsumeInputStackRequestAction){
            [$inventory,$slot]=$this->getBuilderInventoryAndSlot($action->getSource());
            if($inventory->getActualInventory()!==$this->anvil || !array_key_exists($slot,$this->consumed)){
                throw new ItemStackRequestProcessException('Only anvil inputs may be consumed');
            }
            $required=$slot===0 ? $this->plan->input()->getCount() : $this->plan->materialCount;
            $this->consumed[$slot]+=$action->getCount();
            if($this->consumed[$slot]>$required){ throw new ItemStackRequestProcessException('Too many ingredients consumed'); }
            $this->removeItemFromSlot($action->getSource(),$action->getCount());
            return;
        }
        if($action instanceof CraftingCreateSpecificResultStackRequestAction){
            if($action->getResultIndex()!==0){ throw new ItemStackRequestProcessException('Invalid anvil result index'); }
            return;
        }
        if($action instanceof DeprecatedCraftingResultsStackRequestAction){ return; } // Client output is deliberately unused.
        if($action instanceof TakeStackRequestAction || $action instanceof PlaceStackRequestAction){
            if(!self::isOutputSlot($action->getSource())){
                // Bedrock can move the result to the cursor, then to an inventory slot
                // in ONE request. The second leg is an ordinary, stack-ID checked move.
                [$source]=$this->getBuilderInventoryAndSlot($action->getSource());
                $this->assertPlayerInventory($source->getActualInventory());
            }
            [$target]=$this->getBuilderInventoryAndSlot($action->getDestination());
            $this->assertPlayerInventory($target->getActualInventory());
            parent::processItemStackRequestAction($action);
            return;
        }
        throw new ItemStackRequestProcessException('Unsupported action in anvil transaction');
    }

    private function assertPlayerInventory(Inventory $inventory): void{
        if($inventory!==$this->actor->getInventory() && $inventory!==$this->actor->getCursorInventory()){
            throw new ItemStackRequestProcessException('Invalid output transfer inventory');
        }
    }

    private function consumeImplicitInput(int $slot,int $count): void{
        if($count===0){ return; }
        $offset=UIInventorySlotOffset::ANVIL;
        $networkSlot=is_array($offset) ? array_search($slot,$offset,true) : $offset+$slot;
        if($networkSlot===false){ throw new ItemStackRequestProcessException('Unknown anvil input mapping'); }
        $container=$slot===0 ? ContainerUIIds::ANVIL_INPUT : ContainerUIIds::ANVIL_MATERIAL;
        $info=$this->manager->getItemStackInfo($this->anvil,$slot);
        if($info===null){ throw new ItemStackRequestProcessException('Untracked anvil input'); }
        $source=new ItemStackRequestSlotInfo(class_exists(FullContainerName::class) ? new FullContainerName($container) : $container,$networkSlot,$info->getStackId());
        // Stage the deduction without editing the live inventory. Include these
        // input slots in the response even if the client omitted Consume.
        $this->removeItemFromSlot($source,$count);
        $this->consumed[$slot]=$count;
    }

    // The non-null return also matches early PM5 where the parent return was not nullable.
    public function generateInventoryTransaction(): InventoryTransaction{
        // A station recipe creates a server-owned result. Consume actions are
        // optional protocol acknowledgements on some clients. Fill in ONLY absent
        // input containers; explicitly provided counts/IDs still receive validation.
        $explicit=[0=>false,1=>false];
        foreach($this->anvilRequest->getActions() as $action){
            if($action instanceof CraftingConsumeInputStackRequestAction){
                [$inventory,$slot]=$this->getBuilderInventoryAndSlot($action->getSource());
                if($inventory->getActualInventory()!==$this->anvil || !array_key_exists($slot,$explicit)){
                    throw new ItemStackRequestProcessException('Only anvil inputs may be consumed');
                }
                $explicit[$slot]=true;
            }
        }
        $this->started=true;
        $this->setNextCreatedItem($this->plan->result());
        if(!$explicit[0]){ $this->consumeImplicitInput(0,$this->plan->input()->getCount()); }
        if(!$explicit[1]){ $this->consumeImplicitInput(1,$this->plan->materialCount); }
        parent::generateInventoryTransaction(); // dispatch actions and require the entire finite result to be taken
        if(!$this->started || $this->consumed[0]!==$this->plan->input()->getCount() || $this->consumed[1]!==$this->plan->materialCount){
            throw new ItemStackRequestProcessException('Incomplete anvil transaction');
        }
        $actions=[];
        foreach($this->slotChanges as $slots){
            foreach($slots as $slot=>[$inventory,$initial,$final]){
                if(!$initial->equalsExact($final)){ $actions[]=new SlotChangeAction($inventory,$slot,$initial,$final); }
            }
        }
        return new AnvilTransaction($this->actor,$this->anvil,$this->plan,$actions);
    }

    public function buildItemStackResponse(): ItemStackResponse{
        $containers=[];
        foreach($this->responseSlots as $infos){
            $slots=[];
            foreach($infos as [$info,$inventory,$slot]){
                $stack=$this->manager->getItemStackInfo($inventory,$slot);
                if($stack===null){ continue; } // e.g. the anvil broke and core closed the inventory
                $item=$inventory->getItem($slot);$id=$info->getSlotId();$name=$item->getCustomName();$damage=$item instanceof Durable ? $item->getDamage() : 0;
                $slots[]=method_exists(ItemStackResponseSlotInfo::class,'getFilteredCustomName')
                    ? new ItemStackResponseSlotInfo($id,$id,$item->getCount(),$stack->getStackId(),$name,$name,$damage)
                    : new ItemStackResponseSlotInfo($id,$id,$item->getCount(),$stack->getStackId(),$name,$damage);
            }
            if($slots!==[]){
                $info=reset($infos)[0];
                $containers[]=new ItemStackResponseContainerInfo(method_exists($info,'getContainerName') ? $info->getContainerName() : $info->getContainerId(),$slots);
            }
        }
        // Acknowledge clearing the virtual preview as well as the real input/destination slots.
        // Core's response builder cannot resolve ANVIL_RESULT_PREVIEW to a real inventory.
        $slots=[];
        foreach($this->previewSlots as $slot){
            $id=$slot->getSlotId();
            $slots[]=method_exists(ItemStackResponseSlotInfo::class,'getFilteredCustomName')
                ? new ItemStackResponseSlotInfo($id,$id,0,0,'','',0)
                : new ItemStackResponseSlotInfo($id,$id,0,0,'',0);
        }
        if($slots!==[]){
            $slot=reset($this->previewSlots);
            $container=method_exists($slot,'getContainerName') ? $slot->getContainerName() : $slot->getContainerId();
            $containers[]=new ItemStackResponseContainerInfo($container,$slots);
        }
        return new ItemStackResponse(ItemStackResponse::RESULT_OK,$this->anvilRequest->getRequestId(),$containers);
    }
}
