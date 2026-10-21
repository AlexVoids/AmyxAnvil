<?php
declare(strict_types=1);
namespace AmyxAnvilTests;

use pocketmine\entity\AttributeMap;
use pocketmine\entity\ExperienceManager;
use pocketmine\entity\Location;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerCraftingInventory;
use pocketmine\inventory\PlayerCursorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\permission\Permission;
use pocketmine\player\Player;
use pocketmine\world\Position;

final class SyntheticPlayer extends Player{
    public bool $creative=false;
    public bool $spectator=false;
    public bool $allowed=true;
    public function __construct(Position $position){
        $this->server=\pocketmine\Server::getInstance();$this->logger=$this->server->getLogger();
        $this->id=spl_object_id($this);$this->location=Location::fromObject($position,$position->getWorld());
        $this->attributeMap=new AttributeMap();$this->xpManager=new ExperienceManager($this);
        $this->inventory=new PlayerInventory($this);$this->armorInventory=new ArmorInventory($this);$this->offHandInventory=new PlayerOffHandInventory($this);
        $this->cursorInventory=new PlayerCursorInventory($this);$this->craftingGrid=new PlayerCraftingInventory($this);
        $this->networkSession=new SyntheticSession($this);$this->networkSession->manager=new InventoryManager($this,$this->networkSession);
        $this->addPermanentInventories($this->inventory,$this->armorInventory,$this->offHandInventory,$this->cursorInventory,$this->craftingGrid);
        $this->networkSession->manager->syncAll();$this->xpManager->setXpAndProgressNoEvent(30,0.25);
    }
    public function getName(): string{ return 'AnvilTester'; }
    public function isSurvival(bool $literal=false): bool{ return !$this->creative && !$this->spectator; }
    public function isCreative(bool $literal=false): bool{ return $this->creative; }
    public function isAdventure(bool $literal=false): bool{ return false; }
    public function isSpectator(): bool{ return $this->spectator; }
    public function hasFiniteResources(): bool{ return !$this->creative; }
    public function isConnected(): bool{ return true; }
    public function isAlive(): bool{ return true; }
    public function hasPermission(Permission|string $name): bool{ return $this->allowed; }
    public function setUsingItem(bool $value): void{}
    public function __destruct(){}
}
