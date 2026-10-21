<?php
declare(strict_types=1);
namespace Amyx\Anvil;

use Amyx\Anvil\network\AnvilRequestExecutor;
use Amyx\Anvil\compat\Bootstrap;
use pocketmine\block\inventory\AnvilInventory;
use pocketmine\event\Listener;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\inventory\transaction\TransactionException;
use pocketmine\network\PacketHandlingException;
use pocketmine\network\mcpe\handler\ItemStackRequestExecutor;
use pocketmine\network\mcpe\handler\ItemStackRequestProcessException;
use pocketmine\network\mcpe\protocol\ItemStackRequestPacket;
use pocketmine\network\mcpe\protocol\ItemStackResponsePacket;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponse;
use pocketmine\plugin\PluginBase;

final class Main extends PluginBase implements Listener{
    protected function onLoad(): void{ Bootstrap::register(); }
    protected function onEnable(): void{
        $this->getServer()->getPluginManager()->registerEvents($this,$this);
        $this->getLogger()->info('Нативная наковальня включена: имена, ремонт, чары и опыт.');
    }

    public function onOpen(InventoryOpenEvent $event): void{
        if($event->getInventory() instanceof AnvilInventory && !$event->getPlayer()->hasPermission('amyxanvil.use')){ $event->cancel(); }
    }

    /** @priority HIGHEST */
    public function onPacket(DataPacketReceiveEvent $event): void{
        $packet=$event->getPacket();
        if(!$packet instanceof ItemStackRequestPacket){ return; }
        $session=$event->getOrigin();$player=$session->getPlayer();$manager=$session->getInvManager();
        if($player===null || $manager===null || !($window=$player->getCurrentWindow()) instanceof AnvilInventory){ return; }
        $hasAnvil=false;
        foreach($packet->getRequests() as $request){ if(AnvilRequestExecutor::isAnvilRequest($request)){ $hasAnvil=true;break; } }
        if(!$hasAnvil){ return; }
        $event->cancel();$responses=[];
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
                }
                // On old PM5 this method is the only response builder; it remains available on newer PM5.
                $response=static fn()=>$executor->buildItemStackResponse();
            }catch(ItemStackRequestProcessException|TransactionException|PacketHandlingException $e){
                $this->getLogger()->debug('Anvil request rejected: '.$e->getMessage());$manager->requestSyncAll();
            }catch(\Throwable $e){
                $this->getLogger()->logException($e);$manager->requestSyncAll();
            }finally{
                $manager->syncMismatchedPredictedSlotChanges();$manager->setCurrentItemStackRequestId(null);
            }
            $responses[]=$response ?? static fn()=>new ItemStackResponse(ItemStackResponse::RESULT_ERROR,$id);
        }
        // As in current PM5, report final slot contents after the complete packet batch.
        $session->sendDataPacket(ItemStackResponsePacket::create(array_map(static fn(\Closure $build)=>$build(),$responses)));
    }
}
