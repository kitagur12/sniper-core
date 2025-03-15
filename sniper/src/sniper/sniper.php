<?php

namespace sniper;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\world\Position;
use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\Server;
use pocketmine\item\VanillaItems;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\entity\Skin;
use pocketmine\scheduler\Task;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\utils\TextFormat;
use pocketmine\entity\Villager;
use pocketmine\event\player\PlayerInteractEvent;

class sniper extends PluginBase implements Listener
{
    public array $completion;
    public array $lastshot;

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private $plugin;
            public function __construct($plugin)
            {
                $this->plugin = $plugin;
            }
            public function onRun(): void
            {
                foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
                    $player->getHungerManager()->setFood(20);
                    $this->plugin->completion[$player->getXuid()][(string) microtime(true)] = $player->getPosition();
                    foreach ($this->plugin->completion[$player->getXuid()] as $key => $value) {
                        $delay = microtime(true) - (float) $key;
                        if ($delay > 1.0) {
                            unset($this->plugin->completion[$player->getXuid()][$key]);
                        } else {
                            $world = $player->getWorld();
                            foreach ($world->getEntities() as $entity) {
                                if ($entity instanceof Villager) {
                                    $entity->teleport($value);
                                }
                            }
                        }
                    }
                }
            }
        }, 0);
    }

    public function onJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();
        $name = $player->getName();
        $color = $this->nameColors[$name] ?? TextFormat::WHITE;
        $name = $color . $player->getName();

        $player->setHealth($player->getMaxHealth());
        $player->setNameTag($name);

        $joinMessage = "§f[§a+§f] " . TextFormat::RESET . $name;
        $event->setJoinMessage($joinMessage);
        $this->teleport($player);
        $this->rekit($player);
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $player = $event->getPlayer();
        $name = $player->getName();
        $quitMessage = "§f[§c-§f] " . TextFormat::RESET . $name;
        $event->setQuitMessage($quitMessage);
    }

    public function onPlayerInteract(PlayerInteractEvent $event): void
    {
        $event->cancel();
    }

    public function onDamage(EntityDamageEvent $event): void
    {
        $entity = $event->getEntity();
        if ($entity instanceof Player) {
            $damager = $event instanceof EntityDamageByEntityEvent ? $event->getDamager() : null;
            if ($damager instanceof Player) {

                if ($entity->getHealth() - $event->getFinalDamage() <= 0) {
                    $event->cancel();
                    $this->handlePlayerDeath($entity, $damager);
                }
            } else {
                $event->cancel();
            }
        } else {
            $event->cancel();
        }
    }

    public function handlePlayerDeath(Player $player, ?Player $damager): void
    {
        $player->setHealth($player->getMaxHealth());
        $world = $player->getWorld();
        $player->teleport(new Position(0, 2, 0, $world));

        if ($damager !== null) {
            $damager->setHealth($damager->getMaxHealth());
            $player->setHealth($player->getMaxHealth());
            $this->rekit($player);
            $this->rekit($damager);
            $this->teleport($player);
            $globalMessage = TextFormat::RED . "§l§g» §r§c" . $player->getName() . " §7was Sniped by §d" . $damager->getName();
            foreach (Server::getInstance()->getOnlinePlayers() as $onlinePlayer) {
                $onlinePlayer->sendMessage($globalMessage);
            }
        }
    }

    public function onPlayerItemUse(PlayerItemUseEvent $event): void
    {
        $item = $event->getItem();
        $targetitem = VanillaItems::WOODEN_SWORD();
        if ($item->getVanillaName() !== $targetitem->getVanillaName()) {
            return;
        }
        $player = $event->getPlayer();
        $this->shot($player);
    }

    private function shot($player)
    {
        $last = $this->lastshot[$player->getXuid()] ?? 0;
        /*
        if ($last > microtime(true) - 0.4) {
            $player->sendMessage("§cCooltime...");
            return;
        }
        */
        $this->lastshot[$player->getXuid()] = microtime(true);
        $world = $player->getWorld();
        $direction = $player->getDirectionVector();
        $range = 500;
        $bypasslist = [
            VanillaBlocks::AIR()->asItem()->getVanillaName(),
        ];
        $checkcount = 600;
        $hit = false;
        $positionlist = [];
        foreach ($world->getPlayers() as $players) {
            if ($players !== $player) {
                $positionlist[] = $players->getPosition();
            }
        }
        for ($i = 0; $i < $checkcount; $i++) {
            if (!$hit) {
                $distance = $range / $checkcount;
                $ray = $direction->multiply($distance * $i);
                $ray = $player->getPosition()->add(0, 1.5, 0)->addVector($ray->asVector3());
                $packet = LevelEventPacket::standardParticle(
                    16389,
                    0,
                    $ray->asVector3()
                );
                foreach ($world->getPlayers() as $players) {
                    $players->getNetworkSession()->sendDataPacket($packet);
                    foreach ($positionlist as $targetpos) {
                        $distanceSquared = $ray->distanceSquared($targetpos->add(0, 1, 0));

                        if ($players !== $player) {
                            if ($distanceSquared < 5) {
                                $ping = $player->getNetworkSession()->getPing() / 500;
                                $minDifference = 100000000;
                                if (isset($this->completion[$players->getXuid()])) {
                                    foreach ($this->completion[$players->getXuid()] as $key => $value) {
                                        $difference = abs((string) $key - (microtime(true) - $ping));
                                        if ($difference < $minDifference) {
                                            $minDifference = $difference;
                                            $closest = $value;
                                        }
                                    }
                                    if (isset($closest)) {
                                        $distanceSquared = $ray->distanceSquared($closest);
                                        if ($this->isinRange($closest, $ray)) {
                                            if ($this->isinheadRange($closest, $ray)) {
                                                $event = new EntityDamageByEntityEvent(
                                                    $player,
                                                    $players,
                                                    1,
                                                    7,
                                                );
                                                foreach ($world->getPlayers() as $playerss) {
                                                    $playerss->getNetworkSession()->sendDataPacket(AnimatePacket::boatHack($players->getId(), 4, 1));
                                                }
                                            } else {
                                                $event = new EntityDamageByEntityEvent(
                                                    $player,
                                                    $players,
                                                    1,
                                                    4,
                                                );
                                            }

                                            $players->attack($event);

                                            $hit = true;
                                            $dymmyskin = $player->getSkin();
                                            if ($dymmyskin == null) {
                                                $skinData = str_repeat("\x00", 64 * 32 * 4);
                                                $dymmyskin = new Skin("0", $skinData);
                                            }
                                            /*
                                        $playerLocation = new Location($closest->getX(), $closest->getY(), $closest->getZ(), $world, 0, 0);
                                        $deathEntity = new Human($playerLocation, $dymmyskin);
                                        $deathEntity->spawnTo($player);

                                        $playerLocation = new Location($players->getPosition()->getX(), $players->getPosition()->getY(), $players->getPosition()->getZ(), $world, 0, 0);
                                        $deathEntity = new Human($playerLocation, $dymmyskin);
                                        $deathEntity->spawnTo($player);
                                        */
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                if (!in_array($world->getBlock($ray->add(0, 0, 0)->asVector3())->asItem()->getVanillaName(), $bypasslist)) {
                    $hit = true;
                }
            }
        }
    }

    public function onSend(DataPacketSendEvent $event)
    {
        foreach ($event->getPackets() as $pk) {
            if ($pk instanceof LevelEventPacket) {
                //var_dump($pk);
            }
        }
    }

    private function isinRange($pos1, $pos2): bool
    {
        $xzDistanceSquared = ($pos1->x - $pos2->x) ** 2 + ($pos1->z - $pos2->z) ** 2;
        $yDifference = $pos2->y - $pos1->y;
        return $xzDistanceSquared <= (0.5 ** 2) && $yDifference >= 0 && $yDifference <= 1.8;
    }

    private function isinheadRange($pos1, $pos2): bool
    {
        $xzDistanceSquared = ($pos1->x - $pos2->x) ** 2 + ($pos1->z - $pos2->z) ** 2;
        $yDifference = $pos2->y - $pos1->y;
        return !($xzDistanceSquared <= (0.5 ** 2) && $yDifference >= 0 && $yDifference <= 1.6);
    }

    private function rekit($player): void
    {
        $inventory = $player->getInventory();

        $diamondSword = VanillaItems::WOODEN_SWORD();

        $this->clearAndRemoveItems($inventory, $diamondSword);

        $diamondSword->setUnbreakable();

        $inventory->addItem($diamondSword);
    }

    private function clearAndRemoveItems($inventory, $item): void
    {
        $itemsToRemove = [];
        foreach ($inventory->getContents() as $invItem) {
            if ($invItem->getName() === $item->getName()) {
                $itemsToRemove[] = $invItem;
            }
        }

        foreach ($itemsToRemove as $itemToRemove) {
            $inventory->removeItem($itemToRemove);
        }
    }

    private function teleport($player)
    {
        $tplist = [
            [723, 19, 1499],
            [713, 14, 1525],
            [736, 11, 1525],
            [685, 11, 1519],
            [746, 16, 1461],
            [722, 11, 1449],
            [674, 12, 1472],
            [685, 11, 1518],
            [687, 15, 1489],
            [703, 14, 1494],
            [759, 17, 1444],
            [791, 15, 1442],
            [796, 11, 1474],
            [820, 11, 1474],
            [800, 9, 1512]
        ];
        $tp = $tplist[rand(0, count($tplist) - 1)];
        $position = new Position($tp[0], $tp[1], $tp[2], $player->getWorld());
        $player->teleport($position);
    }
}
