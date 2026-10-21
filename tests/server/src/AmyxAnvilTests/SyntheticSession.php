<?php
declare(strict_types=1);
namespace AmyxAnvilTests;

use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\player\Player;

/** Only replaces the transport boundary. InventoryManager and transactions are real core objects. */
final class SyntheticSession extends NetworkSession{
    public InventoryManager $manager;
    public array $sent=[];
    public function __construct(private Player $testPlayer){}
    public function getPlayer(): ?Player{ return $this->testPlayer; }
    public function getInvManager(): ?InventoryManager{ return $this->manager; }
    public function getTypeConverter(): TypeConverter{ return TypeConverter::getInstance(); }
    public function sendDataPacket(ClientboundPacket $packet,bool $immediate=false): bool{ $this->sent[]=$packet;return true; }
    public function getLogger(): \Logger{ return \pocketmine\Server::getInstance()->getLogger(); }
    public function isConnected(): bool{ return true; }
    public function __destruct(){}
}
