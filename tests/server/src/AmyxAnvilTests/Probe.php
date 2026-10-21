<?php
declare(strict_types=1);
namespace AmyxAnvilTests;

use Amyx\Anvil\Main;
use Amyx\Anvil\compat\Bootstrap;
use Amyx\Anvil\network\AnvilRequestExecutor;
use pocketmine\block\VanillaBlocks as B;
use pocketmine\block\inventory\AnvilInventory;
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\event\EventPriority;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\player\PlayerExperienceChangeEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems as I;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\network\mcpe\protocol\ItemStackRequestPacket;
use pocketmine\network\mcpe\protocol\ItemStackResponsePacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds as C;
use pocketmine\network\mcpe\protocol\types\inventory\FullContainerName;
use pocketmine\network\mcpe\protocol\types\inventory\UIInventorySlotOffset as O;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftRecipeOptionalStackRequestAction as Recipe;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftingConsumeInputStackRequestAction as Consume;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DeprecatedCraftingNonImplementedStackRequestAction as Legacy;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequestSlotInfo as Slot;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\TakeStackRequestAction as Take;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\PlaceStackRequestAction as Place;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponse;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\Position;

final class Probe extends PluginBase{
    private int $passed=0;
    private array $failures=[];
    private bool $cancelTransaction=false;
    private bool $cancelXp=false;
    private bool $alterXp=false;
    private bool $alterSlot=false;
    protected function onEnable(): void{
        if(getenv('AMYXANVIL_RUN_TESTS')!=='1'){ $this->getServer()->getPluginManager()->disablePlugin($this);return; }
        $pm=$this->getServer()->getPluginManager();
        $pm->registerEvent(InventoryTransactionEvent::class,function(InventoryTransactionEvent $e): void{ if($this->cancelTransaction){ $e->cancel(); } },EventPriority::NORMAL,$this);
        $pm->registerEvent(PlayerExperienceChangeEvent::class,function(PlayerExperienceChangeEvent $e): void{
            if($this->cancelXp){ $e->cancel(); }
            if($this->alterXp){ $e->setNewLevel(0); }
            if($this->alterSlot){ $e->getEntity()->getInventory()->setItem(0,I::STICK()); }
        },EventPriority::NORMAL,$this);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn()=>$this->run()),30);
    }
    private static function ok(bool $ok,string $message='Assertion failed'): void{ if(!$ok){ throw new \RuntimeException($message); } }
    private function test(string $name,\Closure $test): void{
        try{ $test();++$this->passed;$this->getLogger()->info('PASS '.$name); }
        catch(\Throwable $e){ $this->failures[$name]=$e->getMessage();$this->getLogger()->logException($e); }
        finally{ $this->cancelTransaction=$this->cancelXp=$this->alterXp=$this->alterSlot=false; }
    }
    private function fixture(Item $input,?Item $material=null): array{
        $world=$this->getServer()->getWorldManager()->getDefaultWorld();$world->setBlockAt(4,99,4,B::STONE());$world->setBlockAt(4,100,4,B::ANVIL());
        $p=new SyntheticPlayer(new Position(4,100,6,$world));$window=new AnvilInventory(new Position(4,100,4,$world));
        self::ok($p->setCurrentWindow($window));$window->setItem(0,$input);if($material!==null){ $window->setItem(1,$material); }
        $p->getNetworkSession()->getInvManager()->syncAll();return [$p,$window];
    }
    private static function slot(int $container,int $slot,int $id): Slot{
        return new Slot(class_exists(FullContainerName::class) ? new FullContainerName($container) : $container,$slot,$id);
    }
    private function request(SyntheticPlayer $p,AnvilInventory $window,?string $name,int $materials=0,int $output=1,bool $legacy=false,int $inputCount=1): ItemStackRequest{
        $m=$p->getNetworkSession()->getInvManager();
        $inputSlot=is_array(O::ANVIL) ? array_search(0,O::ANVIL,true) : O::ANVIL;
        $materialSlot=is_array(O::ANVIL) ? array_search(1,O::ANVIL,true) : O::ANVIL+1;
        $actions=[$legacy ? new Legacy() : new Recipe(0,$name===null ? -1 : 0),new Consume($inputCount,self::slot(C::ANVIL_INPUT,$inputSlot,$m->getItemStackInfo($window,0)->getStackId()))];
        if($materials>0){ $actions[]=new Consume($materials,self::slot(C::ANVIL_MATERIAL,$materialSlot,$m->getItemStackInfo($window,1)->getStackId())); }
        $actions[]=new Take($output,self::slot(C::CREATED_OUTPUT,O::CREATED_ITEM_OUTPUT,0),self::slot(C::INVENTORY,0,$m->getItemStackInfo($p->getInventory(),0)->getStackId()));
        return new ItemStackRequest(-1,$actions,$name===null ? [] : [$name],0);
    }
    private static function roundTrip(\pocketmine\network\mcpe\protocol\DataPacket $packet): \pocketmine\network\mcpe\protocol\DataPacket{
        $old=class_exists(\pocketmine\network\mcpe\protocol\serializer\PacketSerializer::class);
        $context=[];
        if($old && (new \ReflectionMethod(\pocketmine\network\mcpe\protocol\serializer\PacketSerializer::class,'encoder'))->getNumberOfRequiredParameters()>0){
            $context[] = new \pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext(\pocketmine\network\mcpe\convert\TypeConverter::getInstance()->getItemTypeDictionary());
        }
        $out=$old ? \pocketmine\network\mcpe\protocol\serializer\PacketSerializer::encoder(...$context) : new \pmmp\encoding\ByteBufferWriter();
        $packet->encode($out);$bytes=$old ? $out->getBuffer() : $out->getData();
        $in=$old ? \pocketmine\network\mcpe\protocol\serializer\PacketSerializer::decoder($bytes,0,...$context) : new \pmmp\encoding\ByteBufferReader($bytes);
        $class=$packet::class;$copy=new $class();$copy->decode($in);return $copy;
    }
    private static function lastResponse(SyntheticPlayer $p): ItemStackResponsePacket{
        foreach(array_reverse($p->getNetworkSession()->sent) as $packet){
            if($packet instanceof ItemStackResponsePacket){ return $packet; }
        }
        throw new \RuntimeException('No response');
    }
    private function send(SyntheticPlayer $p,ItemStackRequest $request): int{
        $event=new DataPacketReceiveEvent($p->getNetworkSession(),ItemStackRequestPacket::create([$request]));$event->call();self::ok($event->isCancelled(),'Plugin did not intercept request');
        foreach(array_reverse($p->getNetworkSession()->sent) as $packet){
            if($packet instanceof ItemStackResponsePacket){ return $packet->getResponses()[0]->getResult(); }
        }
        throw new \RuntimeException('No response');
    }
    private function run(): void{
        try{
            $world=$this->getServer()->getWorldManager()->getDefaultWorld();$world->setChunk(0,0,new \pocketmine\world\format\Chunk([],true));
            $this->test('physical anvil opens the native inventory',function() use($world): void{
                [$p,$w]=$this->fixture(I::DIAMOND());$p->removeCurrentWindow();$returned=[];
                $world->getBlockAt(4,100,4)->onInteract(I::AIR(),1,new \pocketmine\math\Vector3(0,0,0),$p,$returned);self::ok($p->getCurrentWindow() instanceof AnvilInventory);
            });
            $this->test('native packet rename consumes input, returns exact result and charges one level',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND()->setLore(['NBT']));self::ok($this->send($p,$this->request($p,$w,'Алмаз'))===ItemStackResponse::RESULT_OK);
                self::ok($w->isSlotEmpty(0));self::ok($p->getInventory()->getItem(0)->getCustomName()==='Алмаз');self::ok($p->getInventory()->getItem(0)->getLore()===['NBT']);self::ok($p->getXpManager()->getXpLevel()===29);self::ok($p->getXpManager()->getXpProgress()===0.25);
            });
            foreach(['rename','repair','enchant'] as $operation){
                $this->test('server consumes ingredients without client Consume actions: '.$operation,function() use($operation): void{
                    $type=EnchantmentIdMap::getInstance()->fromId(26);
                    $input=$operation==='repair' ? I::IRON_SWORD()->setDamage(100) : I::DIAMOND_SWORD();
                    $material=$operation==='repair' ? I::IRON_INGOT()->setCount(8) : ($operation==='enchant' ? Bootstrap::book()->addEnchantment(new EnchantmentInstance($type,1)) : null);
                    [$p,$w]=$this->fixture($input,$material);$name=$operation==='rename' ? 'Без Consume' : null;
                    $r=$this->request($p,$w,$name);$a=array_values(array_filter($r->getActions(),static fn($action)=>!$action instanceof Consume));
                    self::ok($this->send($p,new ItemStackRequest(-1,$a,$r->getFilterStrings(),0))===ItemStackResponse::RESULT_OK);
                    $result=$p->getInventory()->getItem(0);self::ok($w->isSlotEmpty(0));
                    if($operation==='rename'){ self::ok($result->getCustomName()===$name && $p->getXpManager()->getXpLevel()===29); }
                    elseif($operation==='repair'){ self::ok($result->getDamage()===0 && $w->getItem(1)->getCount()===6 && $p->getXpManager()->getXpLevel()===28); }
                    else{ self::ok($result->getEnchantmentLevel($type)===1 && $w->isSlotEmpty(1) && $p->getXpManager()->getXpLevel()===28); }
                });
            }
            $this->test('result can pass through cursor into inventory in the same request',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND());$r=$this->request($p,$w,'Курсор');$a=$r->getActions();
                $a[count($a)-1]=new Take(1,self::slot(C::ANVIL_RESULT_PREVIEW,50,0),self::slot(C::CURSOR,O::CURSOR,0));
                $a[]=new Place(1,self::slot(C::CURSOR,O::CURSOR,-1),self::slot(C::INVENTORY,0,0));
                self::ok($this->send($p,new ItemStackRequest(-1,$a,['Курсор'],0))===ItemStackResponse::RESULT_OK);
                self::ok($p->getCursorInventory()->isSlotEmpty(0) && $p->getInventory()->getItem(0)->getCustomName()==='Курсор');self::ok($w->isSlotEmpty(0) && $p->getXpManager()->getXpLevel()===29);
            });
            $this->test('markerless preview pickup is routed to anvil handler',function(): void{
                [$p,$w]=$this->fixture(I::IRON_SWORD()->setDamage(100),I::IRON_INGOT()->setCount(8));
                $request=new ItemStackRequest(-1,[new Take(1,self::slot(C::ANVIL_RESULT_PREVIEW,50,0),self::slot(C::INVENTORY,0,0))],[],0);
                self::ok($this->send($p,$request)===ItemStackResponse::RESULT_OK);self::ok($w->isSlotEmpty(0) && $w->getItem(1)->getCount()===6);self::ok($p->getInventory()->getItem(0)->getDamage()===0 && $p->getXpManager()->getXpLevel()===28);
            });
            foreach([-155=>8,-179=>8,-203=>7] as $requestId=>$destination){
                $this->test('reported book request '.$requestId.' material first and combined inventory destination',function() use($requestId,$destination): void{
                    $type=EnchantmentIdMap::getInstance()->fromId(0);
                    [$p,$w]=$this->fixture(I::NETHERITE_CHESTPLATE(),Bootstrap::book()->addEnchantment(new EnchantmentInstance($type,1)));
                    $m=$p->getNetworkSession()->getInvManager();
                    $actions=[new Recipe(0,0),new Consume(1,self::slot(1,2,$m->getItemStackInfo($w,1)->getStackId())),new Consume(1,self::slot(0,1,$m->getItemStackInfo($w,0)->getStackId())),new Place(1,self::slot(60,50,$requestId),self::slot(12,$destination,0))];
                    $packet=self::roundTrip(ItemStackRequestPacket::create([new ItemStackRequest($requestId,$actions,[],0)]));
                    self::ok($this->send($p,$packet->getRequests()[0])===ItemStackResponse::RESULT_OK,'Exact reported action sequence rejected');
                    self::ok($w->isSlotEmpty(0) && $w->isSlotEmpty(1));self::ok($p->getInventory()->getItem($destination)->getEnchantmentLevel($type)===1);self::ok($p->getXpManager()->getXpLevel()===29);
                });
            }
            $this->test('book enchants reach client as complete serialized NBT and stack ID remains usable',function(): void{
                $mending=EnchantmentIdMap::getInstance()->fromId(26);$unbreaking=EnchantmentIdMap::getInstance()->fromId(17);
                $book=Bootstrap::book()->addEnchantment(new EnchantmentInstance($mending,1))->addEnchantment(new EnchantmentInstance($unbreaking,3));
                [$p,$w]=$this->fixture(I::NETHERITE_CHESTPLATE()->setLore(['Сохранить']),$book);
                $r=$this->request($p,$w,null,1);$a=$r->getActions();$a[0]=new Recipe(0,0);$a[count($a)-1]=new Take(1,self::slot(C::ANVIL_RESULT_PREVIEW,50,0),self::slot(C::INVENTORY,0,0));
                $incoming=self::roundTrip(ItemStackRequestPacket::create([new ItemStackRequest(-1,$a,[],0)]));
                self::ok($this->send($p,$incoming->getRequests()[0])===ItemStackResponse::RESULT_OK);
                $result=$p->getInventory()->getItem(0);self::ok($result->getEnchantmentLevel($mending)===1 && $result->getEnchantmentLevel($unbreaking)===3 && $result->getLore()===['Сохранить']);
                $afterResponse=false;$sent=false;$manager=$p->getNetworkSession()->getInvManager();
                foreach($p->getNetworkSession()->sent as $packet){
                    if($packet instanceof ItemStackResponsePacket){ self::roundTrip($packet);$afterResponse=true;continue; }
                    if($afterResponse && $packet instanceof \pocketmine\network\mcpe\protocol\InventorySlotPacket && $packet->windowId===0 && $packet->inventorySlot===0 && $packet->item->getStackId()!==0){
                        $decoded=self::roundTrip($packet);$wireItem=$p->getNetworkSession()->getTypeConverter()->netItemStackToCore($decoded->item->getItemStack());
                        self::ok($wireItem->equalsExact($result),'Client did not receive the enchanted result NBT');
                        self::ok($decoded->item->getStackId()===$manager->getItemStackInfo($p->getInventory(),0)->getStackId());$sent=true;
                    }
                }
                self::ok($sent,'No full item update after the response');
                $next=new ItemStackRequest(-2,[new Place(1,self::slot(C::INVENTORY,0,-1),self::slot(C::INVENTORY,1,0))],[],0);
                $executor=new \pocketmine\network\mcpe\handler\ItemStackRequestExecutor($p,$manager,$next);$transaction=$executor->generateInventoryTransaction();$transaction->execute();
                self::ok($p->getInventory()->isSlotEmpty(0) && $p->getInventory()->getItem(1)->equalsExact($result));self::ok($w->isSlotEmpty(0) && $w->isSlotEmpty(1) && $p->getXpManager()->getXpLevel()===25);
            });
            $this->test('book output through cursor and inventory in a batched request remains usable',function(): void{
                $type=EnchantmentIdMap::getInstance()->fromId(26);[$p,$w]=$this->fixture(I::DIAMOND_SWORD(),Bootstrap::book()->addEnchantment(new EnchantmentInstance($type,1)));
                $r=$this->request($p,$w,null,1);$a=$r->getActions();$a[count($a)-1]=new Take(1,self::slot(C::ANVIL_RESULT_PREVIEW,50,0),self::slot(C::CURSOR,O::CURSOR,0));
                $normal=new ItemStackRequest(-2,[new Place(1,self::slot(C::CURSOR,O::CURSOR,-1),self::slot(C::INVENTORY,0,0))],[],0);
                $event=new DataPacketReceiveEvent($p->getNetworkSession(),ItemStackRequestPacket::create([new ItemStackRequest(-1,$a,[],0),$normal]));$event->call();self::ok($event->isCancelled());
                $response=self::lastResponse($p);self::ok(count($response->getResponses())===2);foreach($response->getResponses() as $r){ self::ok($r->getResult()===ItemStackResponse::RESULT_OK); }
                self::ok($p->getInventory()->getItem(0)->getEnchantmentLevel($type)===1 && $p->getCursorInventory()->isSlotEmpty(0) && $w->isSlotEmpty(0) && $w->isSlotEmpty(1) && $p->getXpManager()->getXpLevel()===28);
                $last=end($p->getNetworkSession()->sent);self::ok($last instanceof \pocketmine\network\mcpe\protocol\InventorySlotPacket && $last->inventorySlot===0 && $last->windowId===0,'Final destination must receive full item data');
            });
            $this->test('Bedrock anvil result preview can be taken into the cursor',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND());$r=$this->request($p,$w,'Результат');$a=$r->getActions();
                $a[count($a)-1]=new Take(1,self::slot(C::ANVIL_RESULT_PREVIEW,50,0),self::slot(C::CURSOR,O::CURSOR,0));
                self::ok($this->send($p,new ItemStackRequest(-1,$a,['Результат'],0))===ItemStackResponse::RESULT_OK,'ANVIL_RESULT_PREVIEW was rejected');
                self::ok($p->getCursorInventory()->getItem(0)->getCustomName()==='Результат');self::ok($w->isSlotEmpty(0));self::ok($p->getXpManager()->getXpLevel()===29);
                $packet=self::lastResponse($p);$cleared=false;
                foreach($packet->getResponses()[0]->getContainerInfos() as $container){
                    $id=method_exists($container,'getContainerName') ? $container->getContainerName()->getContainerId() : $container->getContainerId();
                    if($id===C::ANVIL_RESULT_PREVIEW){
                        $slot=$container->getSlots()[0];$cleared=$slot->getSlot()===50 && $slot->getCount()===0 && $slot->getItemStackId()===0;
                    }
                }
                self::ok($cleared,'Client was not told that the virtual result slot is empty');
            });
            $this->test('Bedrock anvil result preview can be shift-transferred after repair',function(): void{
                [$p,$w]=$this->fixture(I::IRON_SWORD()->setDamage(100),I::IRON_INGOT()->setCount(8));$r=$this->request($p,$w,null,2);$a=$r->getActions();
                $a[count($a)-1]=new Place(1,self::slot(C::ANVIL_RESULT_PREVIEW,50,0),self::slot(C::INVENTORY,0,0));
                self::ok($this->send($p,new ItemStackRequest(-1,$a,[],0))===ItemStackResponse::RESULT_OK,'Preview Place was rejected');
                self::ok($p->getInventory()->getItem(0)->getDamage()===0);self::ok($w->getItem(1)->getCount()===6);self::ok($p->getXpManager()->getXpLevel()===28);
            });
            $this->test('enchanted preview result can be collected and cannot be replayed',function(): void{
                $type=EnchantmentIdMap::getInstance()->fromId(26);[$p,$w]=$this->fixture(I::DIAMOND_SWORD(),Bootstrap::book()->addEnchantment(new EnchantmentInstance($type,1)));
                $r=$this->request($p,$w,null,1);$a=$r->getActions();$a[count($a)-1]=new Take(1,self::slot(C::ANVIL_RESULT_PREVIEW,50,0),self::slot(C::INVENTORY,0,0));$request=new ItemStackRequest(-1,$a,[],0);
                self::ok($this->send($p,$request)===ItemStackResponse::RESULT_OK);self::ok($this->send($p,$request)===ItemStackResponse::RESULT_ERROR);
                self::ok($p->getInventory()->getItem(0)->getEnchantmentLevel($type)===1);self::ok($p->getInventory()->getItem(0)->getCount()===1);self::ok($w->isSlotEmpty(0) && $w->isSlotEmpty(1));self::ok($p->getXpManager()->getXpLevel()===28);
            });
            $this->test('legacy physical preview slot three uses the same calculated result',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND());$r=$this->request($p,$w,'Legacy preview',0,1,true);$a=$r->getActions();
                $a[count($a)-1]=new Take(1,self::slot(C::ANVIL_RESULT_PREVIEW,3,0),self::slot(C::HOTBAR,0,0));
                self::ok($this->send($p,new ItemStackRequest(-1,$a,['Legacy preview'],0))===ItemStackResponse::RESULT_OK);self::ok($p->getInventory()->getItem(0)->getCustomName()==='Legacy preview');
            });
            $this->test('split result across preview and created aliases shares one finite output',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND()->setCount(64));$r=$this->request($p,$w,'Split',0,64,false,64);$a=$r->getActions();
                $a[count($a)-1]=new Take(32,self::slot(C::ANVIL_RESULT_PREVIEW,50,0),self::slot(C::INVENTORY,0,0));
                $a[]=new Place(32,self::slot(C::CREATED_OUTPUT,O::CREATED_ITEM_OUTPUT,0),self::slot(C::INVENTORY,1,0));
                self::ok($this->send($p,new ItemStackRequest(-1,$a,['Split'],0))===ItemStackResponse::RESULT_OK);
                self::ok($p->getInventory()->getItem(0)->getCount()===32 && $p->getInventory()->getItem(1)->getCount()===32);self::ok($w->isSlotEmpty(0));self::ok($p->getXpManager()->getXpLevel()===29);
            });
            foreach([true,false] as $previewFirst){
                $this->test('taking a second result through another alias is rejected '.($previewFirst ? 'preview first' : 'created first'),function() use($previewFirst): void{
                    [$p,$w]=$this->fixture(I::DIAMOND());$r=$this->request($p,$w,'Duplicate');$a=$r->getActions();
                    $first=$previewFirst ? C::ANVIL_RESULT_PREVIEW : C::CREATED_OUTPUT;$second=$previewFirst ? C::CREATED_OUTPUT : C::ANVIL_RESULT_PREVIEW;
                    $a[count($a)-1]=new Take(1,self::slot($first,50,0),self::slot(C::INVENTORY,0,0));$a[]=new Take(1,self::slot($second,50,0),self::slot(C::INVENTORY,1,0));
                    self::ok($this->send($p,new ItemStackRequest(-1,$a,['Duplicate'],0))===ItemStackResponse::RESULT_ERROR);
                    self::ok($w->getItem(0)->getCount()===1);self::ok($p->getInventory()->getContents()===[]);self::ok($p->getXpManager()->getXpLevel()===30);
                });
            }
            $this->test('unknown preview slot cannot create an output',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND());$r=$this->request($p,$w,'Wrong slot');$a=$r->getActions();
                $a[count($a)-1]=new Take(1,self::slot(C::ANVIL_RESULT_PREVIEW,49,0),self::slot(C::INVENTORY,0,0));
                self::ok($this->send($p,new ItemStackRequest(-1,$a,['Wrong slot'],0))===ItemStackResponse::RESULT_ERROR);self::ok($w->getItem(0)->getCount()===1);self::ok($p->getInventory()->isSlotEmpty(0));self::ok($p->getXpManager()->getXpLevel()===30);
            });
            $this->test('native packet repair consumes only needed material and costs levels',function(): void{
                [$p,$w]=$this->fixture(I::IRON_SWORD()->setDamage(100),I::IRON_INGOT()->setCount(8));self::ok($this->send($p,$this->request($p,$w,null,2))===ItemStackResponse::RESULT_OK);
                self::ok($p->getInventory()->getItem(0)->getDamage()===0);self::ok($w->getItem(1)->getCount()===6);self::ok($p->getXpManager()->getXpLevel()===28);
            });
            $this->test('empty filter list with Bedrock default index zero still permits repair',function(): void{
                [$p,$w]=$this->fixture(I::IRON_SWORD()->setDamage(100),I::IRON_INGOT()->setCount(2));$r=$this->request($p,$w,null,2);$a=$r->getActions();$a[0]=new Recipe(0,0);
                self::ok($this->send($p,new ItemStackRequest(-1,$a,[],0))===ItemStackResponse::RESULT_OK);self::ok($p->getInventory()->getItem(0)->getDamage()===0);self::ok($p->getXpManager()->getXpLevel()===28);
            });
            $this->test('book result is sent to the client before any pickup without consuming inputs',function(): void{
                $map=EnchantmentIdMap::getInstance();$protection=$map->fromId(0);$unbreaking=$map->fromId(17);
                [$p,$w]=$this->fixture(Bootstrap::book()->addEnchantment(new EnchantmentInstance($protection,4)),Bootstrap::book()->addEnchantment(new EnchantmentInstance($unbreaking,3)));
                $preview=new \Amyx\Anvil\network\BookPreview();$before=count($p->getNetworkSession()->sent);$preview->refresh($p);
                self::ok(count($p->getNetworkSession()->sent)===$before+1,'No server preview packet');
                $packet=end($p->getNetworkSession()->sent);self::ok($packet instanceof \pocketmine\network\mcpe\protocol\InventorySlotPacket && $packet->windowId===\pocketmine\network\mcpe\protocol\types\inventory\ContainerIds::UI && $packet->inventorySlot===50);
                $decoded=self::roundTrip($packet);$book=$p->getNetworkSession()->getTypeConverter()->netItemStackToCore($decoded->item->getItemStack());
                self::ok($book->getEnchantmentLevel($protection)===4 && $book->getEnchantmentLevel($unbreaking)===3 && $book->getCount()===1);
                self::ok(!$w->isSlotEmpty(0) && !$w->isSlotEmpty(1) && $p->getXpManager()->getXpLevel()===30 && $p->getInventory()->getContents()===[] && $p->getCursorInventory()->isSlotEmpty(0));
                $preview->refresh($p);self::ok(count($p->getNetworkSession()->sent)===$before+1,'Unchanged preview was sent repeatedly');
                self::ok($this->send($p,$this->request($p,$w,null,1))===ItemStackResponse::RESULT_OK);
                self::ok($p->getInventory()->getItem(0)->equalsExact($book) && $p->getXpManager()->getXpLevel()===27 && $w->isSlotEmpty(0) && $w->isSlotEmpty(1));
                $preview->refresh($p);$packet=end($p->getNetworkSession()->sent);self::ok($packet->inventorySlot===50 && $packet->item->getItemStack()->getId()===0,'Collected preview was not cleared');
            });
            $this->test('incompatible books clear a previously visible server preview',function(): void{
                $map=EnchantmentIdMap::getInstance();[$p,$w]=$this->fixture(Bootstrap::book()->addEnchantment(new EnchantmentInstance($map->fromId(0),4)),Bootstrap::book()->addEnchantment(new EnchantmentInstance($map->fromId(17),3)));
                $preview=new \Amyx\Anvil\network\BookPreview();$preview->refresh($p);
                $w->setItem(1,Bootstrap::book()->addEnchantment(new EnchantmentInstance($map->fromId(1),4)));$preview->refresh($p);
                $packet=end($p->getNetworkSession()->sent);self::ok($packet->inventorySlot===50 && $packet->item->getItemStack()->getId()===0);
                self::ok($w->getItem(0)->getEnchantmentLevel($map->fromId(0))===4 && $w->getItem(1)->getEnchantmentLevel($map->fromId(1))===4 && $p->getXpManager()->getXpLevel()===30);
            });
            $this->test('closing an anvil does not overwrite another windows result with a stale preview',function(): void{
                $map=EnchantmentIdMap::getInstance();[$p,$w]=$this->fixture(Bootstrap::book()->addEnchantment(new EnchantmentInstance($map->fromId(0),4)),Bootstrap::book()->addEnchantment(new EnchantmentInstance($map->fromId(17),3)));
                $preview=new \Amyx\Anvil\network\BookPreview();$preview->refresh($p);$p->removeCurrentWindow();$before=count($p->getNetworkSession()->sent);$preview->refresh($p);
                self::ok(count($p->getNetworkSession()->sent)===$before && $p->getXpManager()->getXpLevel()===30);
            });
            $this->test('three books merge sequentially through native inventory requests',function(): void{
                $map=EnchantmentIdMap::getInstance();$protection=$map->fromId(0);$unbreaking=$map->fromId(17);$mending=$map->fromId(26);
                [$p,$w]=$this->fixture(Bootstrap::book()->addEnchantment(new EnchantmentInstance($protection,4)),Bootstrap::book()->addEnchantment(new EnchantmentInstance($unbreaking,3)));
                self::ok($this->send($p,$this->request($p,$w,null,1))===ItemStackResponse::RESULT_OK);
                $p->getInventory()->setItem(1,Bootstrap::book()->addEnchantment(new EnchantmentInstance($mending,1)));
                $m=$p->getNetworkSession()->getInvManager();
                $actions=[new Place(1,self::slot(C::INVENTORY,0,$m->getItemStackInfo($p->getInventory(),0)->getStackId()),self::slot(C::ANVIL_INPUT,1,0)),new Place(1,self::slot(C::INVENTORY,1,$m->getItemStackInfo($p->getInventory(),1)->getStackId()),self::slot(C::ANVIL_MATERIAL,2,0))];
                $request=new ItemStackRequest(-2,$actions,[],0);$executor=new \pocketmine\network\mcpe\handler\ItemStackRequestExecutor($p,$m,$request);$transaction=$executor->generateInventoryTransaction();
                $m->setCurrentItemStackRequestId(-2);$m->addTransactionPredictedSlotChanges($transaction);$transaction->execute();$m->syncMismatchedPredictedSlotChanges();$m->setCurrentItemStackRequestId(null);
                $request=$this->request($p,$w,null,1);$request=new ItemStackRequest(-3,$request->getActions(),[],0);
                self::ok($this->send($p,$request)===ItemStackResponse::RESULT_OK);
                $book=$p->getInventory()->getItem(0);self::ok(count($book->getEnchantments())===3 && $book->getEnchantmentLevel($protection)===4 && $book->getEnchantmentLevel($unbreaking)===3 && $book->getEnchantmentLevel($mending)===1);
                self::ok($w->isSlotEmpty(0) && $w->isSlotEmpty(1) && $p->getXpManager()->getXpLevel()===24);
            });
            $this->test('native enchanted-book fusion changes NBT and costs two levels',function(): void{
                $type=EnchantmentIdMap::getInstance()->fromId(26);[$p,$w]=$this->fixture(I::DIAMOND_SWORD(),Bootstrap::book()->addEnchantment(new EnchantmentInstance($type,1)));
                self::ok($this->send($p,$this->request($p,$w,null,1))===ItemStackResponse::RESULT_OK);self::ok($p->getInventory()->getItem(0)->getEnchantmentLevel($type)===1);self::ok($p->getXpManager()->getXpLevel()===28);
            });
            $this->test('legacy anvil marker is supported without accepting client result items',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND());self::ok($this->send($p,$this->request($p,$w,'Legacy',0,1,true))===ItemStackResponse::RESULT_OK);self::ok($p->getInventory()->getItem(0)->getCustomName()==='Legacy');
            });
            $this->test('stack rename requires and produces the whole original count',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND()->setCount(64));self::ok($this->send($p,$this->request($p,$w,'Stack',0,64,false,64))===ItemStackResponse::RESULT_OK);self::ok($p->getInventory()->getItem(0)->getCount()===64);
            });
            foreach(['insufficient XP','full destination','incorrect material consumption','forged extra output','partial input consumption','permission denied','spectator','removed anvil'] as $case){
                $this->test('reject '.$case.' without charging or moving items',function() use($case): void{
                    [$p,$w]=$this->fixture(I::IRON_SWORD()->setDamage(100),I::IRON_INGOT()->setCount(8));$materials=2;$count=1;$input=1;
                    if($case==='insufficient XP'){ $p->getXpManager()->setXpAndProgressNoEvent(0,0.25); }
                    if($case==='full destination'){ $p->getInventory()->setItem(0,I::STICK()->setCount(64)); }
                    if($case==='incorrect material consumption'){ $materials=1; }
                    if($case==='forged extra output'){ $count=2; }
                    if($case==='partial input consumption'){ $input=0; }
                    if($case==='permission denied'){ $p->allowed=false; }
                    if($case==='spectator'){ $p->spectator=true; }
                    $level=$p->getXpManager()->getXpLevel();$before=$p->getInventory()->getItem(0);$request=$this->request($p,$w,null,$materials,$count,false,$input);
                    if($case==='removed anvil'){ $w->getHolder()->getWorld()->setBlock($w->getHolder(),B::AIR()); }
                    self::ok($this->send($p,$request)===ItemStackResponse::RESULT_ERROR);self::ok($w->getItem(0)->getDamage()===100);self::ok($w->getItem(1)->getCount()===8);self::ok($p->getXpManager()->getXpLevel()===$level);self::ok($p->getInventory()->getItem(0)->equalsExact($before));
                });
            }
            foreach(['transaction event','XP event','modified XP price'] as $case){
                $this->test('cancel '.$case.' preserves inputs and XP',function() use($case): void{
                    [$p,$w]=$this->fixture(I::DIAMOND());$this->cancelTransaction=$case==='transaction event';$this->cancelXp=$case==='XP event';$this->alterXp=$case==='modified XP price';
                    self::ok($this->send($p,$this->request($p,$w,'Cancel'))===ItemStackResponse::RESULT_ERROR);self::ok($w->getItem(0)->getCount()===1);self::ok($p->getInventory()->isSlotEmpty(0));self::ok($p->getXpManager()->getXpLevel()===30);
                });
            }
            $this->test('XP callback mutating destination cannot overwrite another item',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND());$this->alterSlot=true;self::ok($this->send($p,$this->request($p,$w,'Cancel'))===ItemStackResponse::RESULT_ERROR);
                self::ok($w->getItem(0)->getCount()===1);self::ok($p->getInventory()->getItem(0)->getTypeId()===I::STICK()->getTypeId());self::ok($p->getXpManager()->getXpLevel()===30);
            });
            $this->test('replaying a successful request cannot duplicate its result',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND());$request=$this->request($p,$w,'Once');self::ok($this->send($p,$request)===ItemStackResponse::RESULT_OK);self::ok($this->send($p,$request)===ItemStackResponse::RESULT_ERROR);self::ok($p->getInventory()->getItem(0)->getCount()===1);self::ok($p->getXpManager()->getXpLevel()===29);
            });
            $this->test('partial output cannot consume or destroy the remainder of a renamed stack',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND()->setCount(64));$request=$this->request($p,$w,'Partial',0,1,false,64);
                self::ok($this->send($p,$request)===ItemStackResponse::RESULT_ERROR);self::ok($w->getItem(0)->getCount()===64 && $p->getInventory()->isSlotEmpty(0) && $p->getXpManager()->getXpLevel()===30);
            });
            $this->test('explicit partial stack consumption cannot yield a complete renamed stack',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND()->setCount(64));$request=$this->request($p,$w,'Half',0,64,false,32);
                self::ok($this->send($p,$request)===ItemStackResponse::RESULT_ERROR);self::ok($w->getItem(0)->getCount()===64 && $p->getInventory()->isSlotEmpty(0) && $p->getXpManager()->getXpLevel()===30);
            });
            $this->test('forged native stack ID is rejected before item or XP changes',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND());$r=$this->request($p,$w,'Forged');$a=$r->getActions();
                $inputSlot=is_array(O::ANVIL) ? array_search(0,O::ANVIL,true) : O::ANVIL;
                $a[1]=new Consume(1,self::slot(C::ANVIL_INPUT,$inputSlot,999999));
                self::ok($this->send($p,new ItemStackRequest(-1,$a,['Forged'],0))===ItemStackResponse::RESULT_ERROR);self::ok($w->getItem(0)->getCount()===1);self::ok($p->getXpManager()->getXpLevel()===30);
            });
            $this->test('result may be taken into the native cursor inventory',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND());$r=$this->request($p,$w,'Cursor');$a=$r->getActions();
                $a[count($a)-1]=new Take(1,self::slot(C::CREATED_OUTPUT,O::CREATED_ITEM_OUTPUT,0),self::slot(C::CURSOR,O::CURSOR,0));
                self::ok($this->send($p,new ItemStackRequest(-1,$a,['Cursor'],0))===ItemStackResponse::RESULT_OK);self::ok($p->getCursorInventory()->getItem(0)->getCustomName()==='Cursor');self::ok($p->getInventory()->isSlotEmpty(0));
            });
            $this->test('mixed batch retains normal inventory transfers and both native responses',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND());$p->getInventory()->setItem(1,I::STICK()->setCount(5));$m=$p->getNetworkSession()->getInvManager();
                $normal=new ItemStackRequest(-2,[new Take(2,self::slot(C::INVENTORY,1,$m->getItemStackInfo($p->getInventory(),1)->getStackId()),self::slot(C::INVENTORY,2,0))],[],0);
                $event=new DataPacketReceiveEvent($p->getNetworkSession(),ItemStackRequestPacket::create([$this->request($p,$w,'Batch'),$normal]));$event->call();self::ok($event->isCancelled());
                $response=self::lastResponse($p);self::ok($response instanceof ItemStackResponsePacket);self::ok(count($response->getResponses())===2);
                foreach($response->getResponses() as $r){ self::ok($r->getResult()===ItemStackResponse::RESULT_OK); }
                self::ok($p->getInventory()->getItem(0)->getCustomName()==='Batch');self::ok($p->getInventory()->getItem(1)->getCount()===3);self::ok($p->getInventory()->getItem(2)->getCount()===2);
            });
            $this->test('core temporary-inventory close returns unused items exactly once',function(): void{
                [$p,$w]=$this->fixture(I::DIAMOND()->setCount(10),I::IRON_INGOT()->setCount(5));$p->removeCurrentWindow();self::ok($w->isSlotEmpty(0) && $w->isSlotEmpty(1));self::ok($p->getInventory()->contains(I::DIAMOND()->setCount(10)));self::ok($p->getInventory()->contains(I::IRON_INGOT()->setCount(5)));self::ok($p->getXpManager()->getXpLevel()===30);$p->removeCurrentWindow();self::ok(count($p->getInventory()->getContents())===2);
            });
            $this->test('creative operation preserves XP and anvil state',function() use($world): void{
                [$p,$w]=$this->fixture(I::DIAMOND());$p->creative=true;$state=$world->getBlockAt(4,100,4)->getStateId();self::ok($this->send($p,$this->request($p,$w,'Creative'))===ItemStackResponse::RESULT_OK);self::ok($p->getXpManager()->getXpLevel()===30);self::ok($world->getBlockAt(4,100,4)->getStateId()===$state);
            });
        }catch(\Throwable $e){ $this->failures['harness']=$e->getMessage();$this->getLogger()->logException($e); }
        file_put_contents($this->getServer()->getDataPath().'amyxanvil-results.json',json_encode(['passed'=>$this->passed,'failures'=>$this->failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));$this->getServer()->shutdown();
    }
}
