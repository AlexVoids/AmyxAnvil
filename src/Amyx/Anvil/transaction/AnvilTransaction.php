<?php
declare(strict_types=1);
namespace Amyx\Anvil\transaction;

use Amyx\Anvil\rules\AnvilPlan;
use Amyx\Anvil\rules\AnvilWear;
use pocketmine\block\Anvil;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\inventory\AnvilInventory;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\transaction\TransactionCancelledException;
use pocketmine\inventory\transaction\TransactionValidationException;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\world\sound\AnvilBreakSound;
use pocketmine\world\sound\AnvilUseSound;

final class AnvilTransaction extends InventoryTransaction{
    private int $blockState;
    private bool $creative;
    private bool $executing=false;

    public function __construct(Player $player,private AnvilInventory $anvil,private AnvilPlan $plan,array $actions){
        $this->blockState=$anvil->getHolder()->getWorld()->getBlock($anvil->getHolder())->getStateId();
        $this->creative=$player->isCreative();
        parent::__construct($player,$actions);
        $this->addAction(new AnvilTransformAction($plan->result(),$plan->input()));
        if($plan->materialCount>0){
            $this->addAction(new AnvilTransformAction(VanillaItems::AIR(),$plan->material()->setCount($plan->materialCount)));
        }
    }

    private function checkContext(bool $paid=false): void{
        $p=$this->source;$pos=$this->anvil->getHolder();
        if(!$p->isConnected() || !$p->isAlive() || $p->isSpectator() || !$p->hasPermission('amyxanvil.use') || $p->getCurrentWindow()!==$this->anvil || $p->isCreative()!==$this->creative){
            throw new TransactionValidationException('Anvil session is no longer valid');
        }
        if(!$pos->isValid() || $p->getWorld()!==$pos->getWorld() || $p->getPosition()->distanceSquared($pos->add(0.5,0.5,0.5))>64){
            throw new TransactionValidationException('Anvil is out of reach');
        }
        $block=$pos->getWorld()->getBlock($pos);
        if(!$block instanceof Anvil || $block->getStateId()!==$this->blockState){ throw new TransactionValidationException('Anvil was changed or removed'); }
        if(!$this->anvil->getItem(0)->equalsExact($this->plan->input()) || !$this->anvil->getItem(1)->equalsExact($this->plan->material())){
            throw new TransactionValidationException('Anvil inputs changed');
        }
        if(!$paid && !$this->creative && $p->getXpManager()->getXpLevel()<$this->plan->levels){ throw new TransactionValidationException('Not enough experience levels'); }
    }

    public function validate(): void{
        $this->checkContext();
        foreach($this->actions as $action){
            if(!$action instanceof SlotChangeAction && !$action instanceof AnvilTransformAction){ throw new TransactionValidationException('Unexpected anvil action'); }
        }
        parent::validate();
    }

    public function execute(): void{
        if($this->hasExecuted || $this->executing){ throw new TransactionValidationException('Anvil transaction replay'); }
        $this->executing=true;
        try{
            $this->validate();
            if(!$this->callExecuteEvent()){ throw new TransactionCancelledException('Anvil transaction cancelled'); }
            foreach($this->actions as $action){
                if(!$action->onPreExecute($this->source)){ throw new TransactionCancelledException('Anvil action cancelled'); }
            }
            $this->validate();
            $xp=$this->source->getXpManager();$level=$xp->getXpLevel();$progress=$xp->getXpProgress();
            if(!$this->creative){
                if(!$xp->subtractXpLevels($this->plan->levels)){
                    throw new TransactionCancelledException('Experience change cancelled');
                }
                if($xp->getXpLevel()!==$level-$this->plan->levels || $xp->getXpProgress()!==$progress){
                    $xp->setXpAndProgressNoEvent($level,$progress);
                    throw new TransactionCancelledException('Experience event changed the price');
                }
            }
            $snapshots=[];$started=false;
            try{
                // XP listeners may have changed a slot/window. Recheck without the already-paid XP requirement.
                foreach($this->actions as $action){ $action->validate($this->source); }
                $this->checkContext(true);
                foreach($this->getInventories() as $inventory){ $snapshots[]=[$inventory,$inventory->getContents()]; }
                $started=true;
                foreach($this->actions as $action){ $action->execute($this->source); }
                $this->hasExecuted=true;
            }catch(\Throwable $e){
                if($started){ foreach($snapshots as [$inventory,$contents]){ $inventory->setContents($contents); } }
                if(!$this->creative){ $xp->setXpAndProgressNoEvent($level,$progress); }
                throw $e;
            }
            $this->useAnvil();
        }finally{ $this->executing=false; }
    }

    private function useAnvil(): void{
        $pos=$this->anvil->getHolder();$world=$pos->getWorld();$block=$world->getBlock($pos);
        if(!$block instanceof Anvil){ return; }
        $damage=AnvilWear::afterUse($block->getDamage(),$this->creative,random_int(1,100));
        if($damage!==$block->getDamage()){
            if($damage===3){
                $world->setBlock($pos,VanillaBlocks::AIR());
                $world->addSound($pos,new AnvilBreakSound());
                // Core returns unused materials from TemporaryInventory exactly once.
                $this->source->removeCurrentWindow();
                return;
            }
            $block->setDamage($damage);$world->setBlock($pos,$block);
        }
        $world->addSound($pos,new AnvilUseSound());
    }
}
