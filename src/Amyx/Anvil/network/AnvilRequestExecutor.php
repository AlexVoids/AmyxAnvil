<?php
declare(strict_types=1);
namespace Amyx\Anvil\network;

use Amyx\Anvil\rules\AnvilCalculator;
use Amyx\Anvil\rules\AnvilException;
use Amyx\Anvil\rules\AnvilPlan;
use Amyx\Anvil\transaction\AnvilTransaction;
use pocketmine\block\inventory\AnvilInventory;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\handler\ItemStackRequestExecutor;
use pocketmine\network\mcpe\handler\ItemStackRequestProcessException;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
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
use pocketmine\player\Player;

final class AnvilRequestExecutor extends ItemStackRequestExecutor{
    private AnvilPlan $plan;
    private array $consumed=[0=>0,1=>0];
    private bool $started=false;

    public function __construct(private Player $actor,InventoryManager $manager,private ItemStackRequest $anvilRequest,private AnvilInventory $anvil){
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
        }
        return false;
    }

    private static function container(ItemStackRequestSlotInfo $slot): int{
        return method_exists($slot,'getContainerName') ? $slot->getContainerName()->getContainerId() : $slot->getContainerId();
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
            if(self::container($action->getSource())!==ContainerUIIds::CREATED_OUTPUT || $action->getSource()->getSlotId()!==UIInventorySlotOffset::CREATED_ITEM_OUTPUT){
                throw new ItemStackRequestProcessException('Only the calculated output may be transferred');
            }
            [$target]=$this->getBuilderInventoryAndSlot($action->getDestination());
            if($target->getActualInventory()!==$this->actor->getInventory() && $target->getActualInventory()!==$this->actor->getCursorInventory()){
                throw new ItemStackRequestProcessException('Invalid output destination');
            }
            parent::processItemStackRequestAction($action);
            return;
        }
        throw new ItemStackRequestProcessException('Unsupported action in anvil transaction');
    }

    // The non-null return also matches early PM5 where the parent return was not nullable.
    public function generateInventoryTransaction(): InventoryTransaction{
        $transaction=parent::generateInventoryTransaction();
        if($transaction===null || !$this->started || $this->consumed[0]!==$this->plan->input()->getCount() || $this->consumed[1]!==$this->plan->materialCount){
            throw new ItemStackRequestProcessException('Incomplete anvil transaction');
        }
        return new AnvilTransaction($this->actor,$this->anvil,$this->plan,$transaction->getActions());
    }
}
