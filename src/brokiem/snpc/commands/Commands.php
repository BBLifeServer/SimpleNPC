<?php

declare(strict_types=1);

namespace brokiem\snpc\commands;

use brokiem\snpc\entity\BaseNPC;
use brokiem\snpc\entity\CustomHuman;
use brokiem\snpc\entity\WalkingHuman;
use brokiem\snpc\manager\NPCManager;
use brokiem\snpc\SimpleNPC;
use brokiem\snpc\task\async\SpawnHumanNPCTask;
use EasyUI\element\Button;
use EasyUI\element\Input;
use EasyUI\utils\FormResponse;
use EasyUI\variant\CustomForm;
use EasyUI\variant\SimpleForm;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\Player;
use pocketmine\plugin\Plugin;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;
use slapper\entities\SlapperEntity;
use slapper\entities\SlapperHuman;

class Commands extends PluginCommand
{
    public function __construct(string $name, Plugin $owner)
    {
        parent::__construct($name, $owner);
        $this->setDescription("SimpleNPC commands");
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return true;
        }

        /** @var SimpleNPC $plugin */
        $plugin = $this->getPlugin();

        if (isset($args[0])) {
            switch (strtolower($args[0])) {
                case "ui":
                    if (!$sender->hasPermission("snpc.ui")) {
                        return true;
                    }

                    //TODO: Add form
                    break;
                case "reload":
                    if (!$sender->hasPermission("snpc.reload")) {
                        return true;
                    }

                    $plugin->reloadConfig();
                    $sender->sendMessage(TextFormat::GREEN . "SimpleNPC Config reloaded successfully!");
                    break;
                case "spawn":
                case "add":
                    if (!$sender instanceof Player) {
                        $sender->sendMessage("Only player can run this command!");
                        return true;
                    }

                    if (!$sender->hasPermission("snpc.spawn")) {
                        return true;
                    }

                    if (isset($args[1])) {
                        if (in_array(strtolower($args[1]) . "_snpc", SimpleNPC::$npcType, true)) {
                            if ($args[1] . "_snpc" === SimpleNPC::ENTITY_HUMAN) {
                                if (isset($args[4])) {
                                    if (!preg_match('/https?:\/\/[^?]*\.png(?![\w.\-_])/', $args[4])) {
                                        $sender->sendMessage(TextFormat::RED . "Invalid skin url file format! (Only PNG Supported)");
                                        return true;
                                    }

                                    $plugin->getServer()->getAsyncPool()->submitTask(new SpawnHumanNPCTask($args[2], $sender->getName(), $plugin->getDataFolder(), $args[3] === "true", $args[4]));
                                    $sender->sendMessage(TextFormat::DARK_GREEN . "Creating " . ucfirst($args[1]) . " NPC with nametag $args[2] for you...");
                                    return true;
                                }

                                if (isset($args[3])) {
                                    $plugin->getServer()->getAsyncPool()->submitTask(new SpawnHumanNPCTask($args[2], $sender->getName(), $plugin->getDataFolder(), $args[3] === "true"));
                                    $sender->sendMessage(TextFormat::DARK_GREEN . "Creating " . ucfirst($args[1]) . " NPC with nametag $args[2] for you...");
                                    return true;
                                }

                                if (isset($args[2])) {
                                    $plugin->getServer()->getAsyncPool()->submitTask(new SpawnHumanNPCTask($args[2], $sender->getName(), $plugin->getDataFolder()));
                                    $sender->sendMessage(TextFormat::DARK_GREEN . "Creating " . ucfirst($args[1]) . " NPC with nametag $args[2] for you...");
                                    return true;
                                }

                                $plugin->getServer()->getAsyncPool()->submitTask(new SpawnHumanNPCTask(null, $sender->getName(), $plugin->getDataFolder()));
                            } else {
                                if (isset($args[2])) {
                                    NPCManager::createNPC(strtolower($args[1]) . "_snpc", $sender, $args[2]);
                                    $sender->sendMessage(TextFormat::DARK_GREEN . "Creating " . ucfirst($args[1]) . " NPC with nametag $args[2] for you...");
                                    return true;
                                }

                                NPCManager::createNPC(strtolower($args[1]) . "_snpc", $sender);
                            }
                            $sender->sendMessage(TextFormat::DARK_GREEN . "Creating " . ucfirst($args[1]) . " NPC without nametag for you...");
                        } else {
                            $sender->sendMessage(TextFormat::RED . "Invalid entity type or entity not registered!");
                        }
                    } else {
                        $sender->sendMessage(TextFormat::RED . "Usage: /snpc spawn <type> optional: <nametag> <canWalk> <skinUrl>");
                    }
                    break;
                case "delete":
                case "remove":
                    if (!$sender->hasPermission("snpc.remove")) {
                        return true;
                    }

                    if (isset($args[1]) and is_numeric($args[1])) {
                        $entity = $plugin->getServer()->findEntity((int)$args[1]);

                        if ($entity instanceof BaseNPC || $entity instanceof CustomHuman) {
                            if (!$entity->isFlaggedForDespawn()) {
                                $entity->flagForDespawn();
                                $sender->sendMessage(TextFormat::GREEN . "The NPC was successfully removed!");
                            }
                        } else {
                            $sender->sendMessage(TextFormat::YELLOW . "SimpleNPC Entity with ID: " . $args[1] . " not found!");
                        }

                        return true;
                    }

                    if (!isset($plugin->removeNPC[$sender->getName()])) {
                        $plugin->removeNPC[$sender->getName()] = true;
                        $sender->sendMessage(TextFormat::DARK_GREEN . "Hit the npc that you want to delete or remove");
                    } else {
                        unset($plugin->removeNPC[$sender->getName()]);
                        $sender->sendMessage(TextFormat::GREEN . "Remove npc by hitting has been canceled");
                    }
                    break;
                case "edit":
                case "manage":
                    if (!$sender->hasPermission("snpc.edit") or !$sender instanceof Player) {
                        return true;
                    }

                    if (!isset($args[1]) or !is_numeric($args[1])) {
                        $sender->sendMessage(TextFormat::RED . "Usage: /snpc edit <id>");
                        return true;
                    }

                    $entity = $plugin->getServer()->findEntity((int)$args[1]);

                    $customForm = new CustomForm("Manage NPC");
                    $simpleForm = new SimpleForm("Manage NPC");

                    if ($entity instanceof BaseNPC || $entity instanceof CustomHuman) {
                        $editUI = new SimpleForm("Manage NPC", "§aID:§2 $args[1]\n§aClass: §2" . get_class($entity) . "\n§aNametag: §2" . $entity->getNameTag() . "\n§aPosition: §2" . $entity->getFloorX() . "/" . $entity->getFloorY() . "/" . $entity->getFloorZ());

                        $editUI->addButton(new Button("Add Command", null, function (Player $sender) use ($customForm) {
                            $customForm->addElement("addcmd", new Input("Enter the command here"));
                            $sender->sendForm($customForm);
                        }));

                        $editUI->addButton(new Button("Remove Command", null, function (Player $sender) use ($customForm) {
                            $customForm->addElement("removecmd", new Input("Enter the command here"));
                            $sender->sendForm($customForm);
                        }));

                        $editUI->addButton(new Button("Change Nametag", null, function (Player $sender) use ($customForm) {
                            $customForm->addElement("changenametag", new Input("Enter the new nametag here"));
                            $sender->sendForm($customForm);
                        }));

                        $editUI->addButton(new Button("Change Skin\n(Only Human NPC)", null, function (Player $sender) use ($customForm) {
                            $customForm->addElement("changeskin", new Input("Enter the skin URL or online player name"));
                            $sender->sendForm($customForm);
                        }));

                        $editUI->addButton(new Button("Command list", null, function (Player $sender) use ($editUI, $entity, $simpleForm) {
                            $commands = $entity->namedtag->getCompoundTag("Commands");
                            $cmds = "This NPC (ID: {$entity->getId()}) does not have any commands.";
                            if ($commands !== null && $commands->getCount() > 0) {
                                $cmds = TextFormat::AQUA . "NPC ID: {$entity->getId()} Command list ({$commands->getCount()})\n";

                                /** @var StringTag $stringTag */
                                foreach ($commands as $stringTag) {
                                    $cmds .= TextFormat::GREEN . "- " . $stringTag->getValue() . "\n";
                                }
                            }

                            $simpleForm->setHeaderText($cmds);
                            $simpleForm->addButton(new Button("Print", null, function (Player $sender) use ($cmds) {
                                $sender->sendMessage($cmds);
                            }));
                            $simpleForm->addButton(new Button("< Back", null, function (Player $sender) use ($editUI) {
                                $sender->sendForm($editUI);
                            }));
                            $sender->sendForm($simpleForm);
                        }));

                        $editUI->addButton(new Button("Teleport", null, function (Player $sender) use ($simpleForm, $entity) {
                            $simpleForm->addButton(new Button("You to Entity", null, function (Player $sender) use ($entity): void {
                                $sender->teleport($entity->getLocation());
                                $sender->sendMessage(TextFormat::GREEN . "Teleported!");
                            }));
                            $simpleForm->addButton(new Button("Entity to You", null, function (Player $sender) use ($entity): void {
                                $entity->teleport($sender->getLocation());
                                if ($entity instanceof WalkingHuman) {
                                    $entity->randomPosition = $entity->asVector3();
                                }
                                $sender->sendMessage(TextFormat::GREEN . "Teleported!");
                            }));

                            $sender->sendForm($simpleForm);
                        }));

                        $customForm->setSubmitListener(function (Player $player, FormResponse $response) use ($plugin, $entity) {
                            $addcmd = $response->getInputSubmittedText("addcmd");
                            $rmcmd = $response->getInputSubmittedText("removecmd");
                            $chnmtd = $response->getInputSubmittedText("changenametag");
                            $skin = $response->getInputSubmittedText("changeskin");

                            if ($rmcmd !== "") {
                                $commands = $entity->namedtag->getCompoundTag("Commands") ?? new CompoundTag("Commands");

                                $commands->removeTag($rmcmd, $rmcmd);
                                $entity->namedtag->setTag($commands);
                                $player->sendMessage(TextFormat::GREEN . "Successfully remove command '$rmcmd' (NPC ID: " . $entity->getId() . ")");
                            } elseif ($addcmd !== "") {
                                $commands = $entity->namedtag->getCompoundTag("Commands") ?? new CompoundTag("Commands");

                                if ($commands->hasTag($addcmd)) {
                                    $player->sendMessage(TextFormat::RED . "'$addcmd' command has already been added.");
                                    return true;
                                }

                                $commands->setString($addcmd, $addcmd);
                                $entity->namedtag->setTag($commands);
                                $player->sendMessage(TextFormat::GREEN . "Successfully added command '$addcmd' (NPC ID: " . $entity->getId() . ")");
                            } elseif ($chnmtd !== "") {
                                $player->sendMessage(TextFormat::GREEN . "Successfully change npc nametag from '{$entity->getNameTag()}' to '$chnmtd'  (NPC ID: " . $entity->getId() . ")");

                                $entity->setNameTag($chnmtd);
                                $entity->setNameTagAlwaysVisible();
                            } elseif ($skin !== "") {
                                if (!$entity instanceof CustomHuman) {
                                    $player->sendMessage(TextFormat::RED . "Only human NPC can change skin!");
                                    return true;
                                }

                                $pSkin = $player->getServer()->getPlayerExact($skin);

                                if ($pSkin instanceof Player) {
                                    $entity->setSkin($pSkin->getSkin());
                                    $entity->sendSkin($player->getServer()->getOnlinePlayers());

                                    $player->sendMessage(TextFormat::GREEN . "Successfully change npc skin (NPC ID: " . $entity->getId() . ")");
                                    return true;
                                }

                                if (!preg_match('/https?:\/\/[^?]*\.png(?![\w.\-_])/', $skin)) {
                                    $player->sendMessage(TextFormat::RED . "Invalid skin url file format! (Only PNG Supported)");
                                    return true;
                                }

                                $plugin->getServer()->getAsyncPool()->submitTask(new SpawnHumanNPCTask($entity->getNameTag(), $player->getName(), $plugin->getDataFolder(), !($entity->namedtag->getShort("Walk") === 0), $skin, $entity->namedtag->getCompoundTag("Commands"), null, $entity->getLocation()));
                                if (!$entity->isFlaggedForDespawn()) {
                                    $entity->flagForDespawn();
                                }
                                $player->sendMessage(TextFormat::GREEN . "Successfully change npc skin (NPC ID: " . $entity->getId() . ")");
                            } else {
                                $player->sendMessage(TextFormat::RED . "Please enter a valid value!");
                            }

                            return true;
                        });

                        $sender->sendForm($editUI);
                    } else {
                        $sender->sendMessage(TextFormat::YELLOW . "SimpleNPC Entity with ID: " . $args[1] . " not found!");
                    }
                    break;
                case "migrate":
                    if (!$sender->hasPermission("snpc.migrate")) {
                        return true;
                    }

                    if (!$sender instanceof Player) {
                        return true;
                    }

                    if ($plugin->getServer()->getPluginManager()->getPlugin("Slapper") !== null) {
                        if (!isset($args[1]) && !isset($plugin->migrateNPC[$sender->getName()])) {
                            $plugin->migrateNPC[$sender->getName()] = true;

                            $plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($plugin, $sender): void {
                                if (isset($plugin->migrateNPC[$sender->getName()])) {
                                    unset($plugin->migrateNPC[$sender->getName()]);
                                    $sender->sendMessage(TextFormat::YELLOW . "Migrating NPC Cancelled! (10 seconds)");
                                }
                            }), 10 * 20);

                            $sender->sendMessage(TextFormat::RED . " \nAre you sure want to migrate your NPC from Slapper to SimpleNPC? \nThis will replace the slapper NPCs with the new Simple NPCs\n\nIf yes, run /migrate confirm, if no, run /migrate cancel\n\n ");
                            $sender->sendMessage(TextFormat::RED . "NOTE: Make sure all the worlds with the Slapper NPC have been loaded!");
                            return true;
                        }

                        if (isset($plugin->migrateNPC[$sender->getName()], $args[1]) && $args[1] === "confirm") {
                            unset($plugin->migrateNPC[$sender->getName()]);
                            $sender->sendMessage(TextFormat::DARK_GREEN . "Migrating NPC... Please wait...");

                            foreach ($plugin->getServer()->getLevels() as $level) {
                                $entity = array_map(static function (Entity $entity) {
                                }, array_filter($level->getEntities(), static function (Entity $entity): bool {
                                    return $entity instanceof SlapperHuman or $entity instanceof SlapperEntity;
                                }));

                                if (count($entity) === 0) {
                                    $sender->sendMessage(TextFormat::RED . "Migrating failed: No Slapper-NPC found!");
                                    return true;
                                }

                                $error = 0;
                                foreach ($level->getEntities() as $entity) {
                                    if ($entity instanceof SlapperEntity) {
                                        /** @phpstan-ignore-next-line */
                                        if (NPCManager::createNPC(AddActorPacket::LEGACY_ID_MAP_BC[$entity::TYPE_ID], $sender, $entity->getNameTag(), $entity->namedtag->getCompoundTag("Commands"))) {
                                            if (!$entity->isFlaggedForDespawn()) {
                                                $entity->flagForDespawn();
                                            }
                                        } else {
                                            ++$error;
                                        }
                                    } elseif ($entity instanceof SlapperHuman) {
                                        $plugin->getServer()->getAsyncPool()->submitTask(new SpawnHumanNPCTask($entity->getNameTag(), $sender->getName(), $plugin->getDataFolder(), false, null, $entity->namedtag->getCompoundTag("Commands"), $entity->getSkin(), $entity->getLocation()));
                                        // TODO: QueueSytem (don't spam async task)
                                        if (!$entity->isFlaggedForDespawn()) {
                                            $entity->flagForDespawn();
                                        }
                                    }
                                }

                                if ($error === 0) {
                                    $sender->sendMessage(TextFormat::GREEN . "The migration was successful, you can safely remove the Slapper plugin now");
                                } else {
                                    $sender->sendMessage(TextFormat::RED . "(" . $error . " error found) " . TextFormat::YELLOW . "It seems that the migration is not going well, please fix the error so that it can be fully migrated. Don't delete Slapper Plugin now");
                                }
                            }

                            return true;
                        }

                        if (isset($plugin->migrateNPC[$sender->getName()], $args[1]) && $args[1] === "cancel") {
                            $sender->sendMessage(TextFormat::GREEN . "Migrating NPC cancelled!");
                            unset($plugin->migrateNPC[$sender->getName()]);
                            return true;
                        }
                    } else {
                        $sender->sendMessage(TextFormat::RED . "Slapper plugin is missing, cannnot migrating.");
                    }
                    break;
                case "list":
                    if (!$sender->hasPermission("snpc.list")) {
                        return true;
                    }

                    foreach ($plugin->getServer()->getLevels() as $world) {
                        $entityNames = array_map(static function (Entity $entity): string {
                            return TextFormat::YELLOW . "ID: (" . $entity->getId() . ") " . TextFormat::DARK_GREEN . $entity->getNameTag() . " §7-- §b" . $entity->getLevel()->getFolderName() . ": " . $entity->getFloorX() . "/" . $entity->getFloorY() . "/" . $entity->getFloorZ();
                        }, array_filter($world->getEntities(), static function (Entity $entity): bool {
                            return $entity instanceof BaseNPC or $entity instanceof CustomHuman;
                        }));

                        $sender->sendMessage("§csNPC List and Location: (" . count($entityNames) . ")\n §3- " . implode("\n - ", $entityNames));
                    }
                    break;
                default:
                    $sender->sendMessage("§7---- ---- [ §3SimpleNPC§7 ] ---- ----\n§bAuthor: @brokiem\n§3Source Code: github.com/brokiem/SimpleNPC\nVersion " . $this->getPlugin()->getDescription()->getVersion() . "\n\n§eCommand List:\n§2» /snpc spawn <type> <nametag> <canWalk> <skinUrl>\n§2» /snpc edit <id>\n§2» /snpc remove <id>\n§2» /snpc migrate <confirm | cancel>\n§2» /snpc list\n§7---- ---- ---- - ---- ---- ----");
                    break;
            }
        } else {
            $sender->sendMessage("§7---- ---- [ §3SimpleNPC§7 ] ---- ----\n§bAuthor: @brokiem\n§3Source Code: github.com/brokiem/SimpleNPC\nVersion " . $this->getPlugin()->getDescription()->getVersion() . "\n\n§eCommand List:\n§2» /snpc spawn <type> <nametag> <canWalk> <skinUrl>\n§2» /snpc edit <id>\n§2» /snpc remove <id>\n§2» /snpc migrate <confirm | cancel>\n§2» /snpc list\n§7---- ---- ---- - ---- ---- ----");
        }

        return parent::execute($sender, $commandLabel, $args);
    }
}