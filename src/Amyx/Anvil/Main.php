<?php
declare(strict_types=1);
namespace Amyx\Anvil;

use Amyx\Anvil\network\AnvilRequestExecutor;
use Amyx\Anvil\network\BookPreview;
use Amyx\Anvil\compat\Bootstrap;
use pocketmine\block\inventory\AnvilInventory;
use pocketmine\event\Listener;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\inventory\transaction\TransactionException;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\network\PacketHandlingException;
use pocketmine\network\mcpe\handler\ItemStackRequestExecutor;
use pocketmine\network\mcpe\handler\ItemStackRequestProcessException;
use pocketmine\network\mcpe\protocol\ItemStackRequestPacket;
use pocketmine\network\mcpe\protocol\ItemStackResponsePacket;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponse;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Main extends PluginBase implements Listener{
    private int $lastRejectionLog=0;
    private BookPreview $bookPreview;
    protected function onLoad(): void{ Bootstrap::register(); }
    protected function onEnable(): void{
        $this->bookPreview=new BookPreview();
        $this->getServer()->getPluginManager()->registerEvents($this,$this);
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void{
            $this->bookPreview->tick($this->getServer()->getOnlinePlayers());
        }),5);
        $this->getLogger()->info('Нативная наковальня включена: имена, ремонт, чары и опыт.');
    }

    public function onOpen(InventoryOpenEvent $event): void{
        if($event->getInventory() instanceof AnvilInventory && !$event->getPlayer()->hasPermission('amyxanvil.use')){ $event->cancel(); }
    }

    public function onClose(InventoryCloseEvent $event): void{ $this->bookPreview->forget($event->getPlayer()); }
    public function onQuit(PlayerQuitEvent $event): void{ $this->bookPreview->forget($event->getPlayer()); }

    /** @priority HIGHEST */
    public function onPacket(DataPacketReceiveEvent $event): void{
        $packet=$event->getPacket();
        if(!$packet instanceof ItemStackRequestPacket){ return; }
        $session=$event->getOrigin();$player=$session->getPlayer();$manager=$session->getInvManager();
        if($player===null || $manager===null || !($window=$player->getCurrentWindow()) instanceof AnvilInventory){ return; }
        $hasAnvil=false;
        foreach($packet->getRequests() as $request){ if(AnvilRequestExecutor::isAnvilRequest($request)){ $hasAnvil=true;break; } }
        if(!$hasAnvil){ return; }
        $event->cancel();$responses=[];$changedSlots=[];
        if(count($packet->getRequests())>80){ $manager->requestSyncAll();return; }
        foreach($packet->getRequests() as $request){
            $id=$request->getRequestId();$response=null;
            try{
                if(count($request->getActions())>60){ throw new ItemStackRequestProcessException('Too many actions'); }
                $current=$player->getCurrentWindow();
                $executor=AnvilRequestExecutor::isAnvilRequest($request)
                    ? ($current instanceof AnvilInventory ? new AnvilRequestExecutor($player,$manager,$request,$current) : throw new ItemStackRequestProcessException('Anvil was closed'))
                    : new ItemStackRequestExecutor($player,$manager,$request);
                $transaction=$executor->generateInventoryTransaction();
                if($transaction!==null){
                    $player->setUsingItem(false);$manager->setCurrentItemStackRequestId($id);
                    $manager->addTransactionPredictedSlotChanges($transaction);
                    $transaction->execute();
                    foreach($transaction->getActions() as $action){
                        if($action instanceof SlotChangeAction){
                            $inventory=$action->getInventory();$slot=$action->getSlot();
                            $changedSlots[spl_object_id($inventory)][$slot]=[$inventory,$slot];
                        }
                    }
                }
                // On old PM5 this method is the only response builder; it remains available on newer PM5.
                $response=static fn()=>$executor->buildItemStackResponse();
            }catch(ItemStackRequestProcessException|TransactionException|PacketHandlingException $e){
                $this->recordRejection($request,$e,$window);$this->bookPreview->invalidate($player);$manager->requestSyncAll();
            }catch(\Throwable $e){
                $this->getLogger()->logException($e);$this->bookPreview->invalidate($player);$manager->requestSyncAll();
            }finally{
                $manager->syncMismatchedPredictedSlotChanges();$manager->setCurrentItemStackRequestId(null);
            }
            $responses[]=$response ?? static fn()=>new ItemStackResponse(ItemStackResponse::RESULT_ERROR,$id);
        }
        // As in current PM5, report final slot contents after the complete packet batch.
        $session->sendDataPacket(ItemStackResponsePacket::create(array_map(static fn(\Closure $build)=>$build(),$responses)));
        // A response contains counts, names and damage, but NOT enchantment NBT.
        // Send the authoritative item after acknowledging the request. syncSlot
        // preserves stack IDs (unlike syncContents), including batched request IDs.
        foreach($changedSlots as $slots){
            foreach($slots as [$inventory,$slot]){
                if($manager->getItemStackInfo($inventory,$slot)!==null){
                    $manager->syncSlot($inventory,$slot,$session->getTypeConverter()->coreItemStackToNet($inventory->getItem($slot)));
                }
            }
        }
    }

    private function recordRejection(ItemStackRequest $request,\Throwable $error,AnvilInventory $anvil): void{
        $this->getLogger()->debug('Anvil request rejected: '.$error->getMessage());
        if(!AnvilRequestExecutor::isAnvilRequest($request) || time()-$this->lastRejectionLog<5){ return; }
        $this->lastRejectionLog=time();$actions=[];
        foreach($request->getActions() as $action){
            $description=['type'=>(new \ReflectionClass($action))->getShortName()];
            foreach(['getCount','getRecipeId','getFilterStringIndex'] as $method){
                if(method_exists($action,$method)){ $description[$method]=$action->$method(); }
            }
            foreach(['getSource','getDestination'] as $method){
                if(method_exists($action,$method)){
                    $slot=$action->$method();
                    $description[$method]=[method_exists($slot,'getContainerName') ? $slot->getContainerName()->getContainerId() : $slot->getContainerId(),$slot->getSlotId(),$slot->getStackId()];
                }
            }
            $actions[]=$description;
        }
        $path=$this->getDataFolder().'anvil-errors.log';
        clearstatcache(true,$path);
        if(is_file($path) && filesize($path)>262144 && !@rename($path,$path.'.old')){ @file_put_contents($path,''); }
        // No player names, rename strings or client item NBT are recorded.
        $inputs=[];
        foreach([0,1] as $slot){
            $item=$anvil->getItem($slot);
            $inputs[]=['type'=>$item->getTypeId(),'count'=>$item->getCount(),'enchantmentCount'=>count($item->getEnchantments())];
        }
        @file_put_contents($path,json_encode([
            'time'=>gmdate('c'),'plugin'=>$this->getDescription()->getVersion(),
            'server'=>$this->getServer()->getVersion(),'engine'=>$this->getServer()->getName(),
            'engineVersion'=>$this->getServer()->getPocketMineVersion(),'api'=>$this->getServer()->getApiVersion(),
            'request'=>$request->getRequestId(),'error'=>$error->getMessage(),
            'errorAt'=>basename($error->getFile()).':'.$error->getLine(),'inputs'=>$inputs,'actions'=>$actions
        ],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL,FILE_APPEND|LOCK_EX);
        $this->getLogger()->warning('Наковальня отклонила запрос: '.$error->getMessage().'. Подробности: plugins/AmyxAnvil/anvil-errors.log');
    }
}
