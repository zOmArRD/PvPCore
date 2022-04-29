<?php

declare(strict_types=1);

namespace jkorn\pvpcore\world;

use jkorn\pvpcore\utils\IKBObject;
use jkorn\pvpcore\utils\PvPCKnockback;
use jkorn\pvpcore\utils\IExportedValue;
use jkorn\pvpcore\utils\Utils;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\World;

/**
 * Created by PhpStorm.
 * User: jkorn2324
 * Date: 2019-04-05
 * Time: 11:17
 */
class PvPCWorld implements IKBObject
{

    /** @var World|null */
    private $world;

    /** @var bool */
    private $customKb;

    /** @var PvPCKnockback */
    private $knockbackInfo;
    /** @var string */
    private $localizedWorld;

    /**
     * PvPCWorld constructor.
     * @param string $lvl
     * @param bool $kb
     * @param PvPCKnockback $knockback
     */
    public function __construct(string $lvl, bool $kb, PvPCKnockback $knockback)
    {
        $this->customKb = $kb;
        $this->localizedWorld = $lvl;
        $this->world = Server::getInstance()->getWorldManager()->getWorldByName($lvl);
        $this->knockbackInfo = $knockback;
    }

    /**
     * @return string
     *
     * Gets the localized name of the world.
     */
    public function getLocalizedWorld(): string
    {
        return $this->localizedWorld;
    }

    /**
     * @return World|null
     *
     * Gets the world in the world.
     */
    public function getWorld(): ?World
    {
        return $this->world;
    }

    /**
     * @param World $world
     *
     * Called to reload the world information.
     */
    public function setWorld(World $world): void
    {
        $this->localizedWorld = $world->getFolderName();
        $this->world = $world;
    }


    /**
     * @return bool
     *
     * Determines if the KB is enabled or not.
     */
    public function isKBEnabled(): bool
    {
        return $this->customKb;
    }

    /**
     * @param bool $b
     *
     * Sets the custom kb.
     */
    public function setKBEnabled(bool $b): void
    {
        $this->customKb = $b;
    }

    /**
     * @return PvPCKnockback
     *
     * Gets
     */
    public function getKnockback(): PvPCKnockback
    {
        return $this->knockbackInfo;
    }

    /**
     * @return array
     *
     * Converts the world to an array.
     */
    public function toArray(): array
    {
        return [
            "kbEnabled" => $this->customKb,
            "kbInfo" => $this->getKnockback()->toArray()
        ];
    }

    /**
     * @param $object
     * @return bool
     *
     * Determines if the objects are equivalent.
     */
    public function equals($object): bool
    {
        if ($object instanceof PvPCWorld) {
            $world = $object->getWorld();
            if ($world instanceof World && $this->world instanceof World) {
                return Utils::areWorldsEqual($this->world, $world);
            }

            return $this->knockbackInfo->equals($object->getKnockback());
        }

        return false;
    }

    /**
     * @param Player $player1
     * @param Player $player2
     * @return bool
     *
     * Determines if the players can use the custom knockback.
     */
    public function canUseKnocback(Player $player1, Player $player2): bool
    {
        if (!$this->world instanceof World || !$this->customKb) {
            return false;
        }

        return Utils::areWorldsEqual($player1->getWorld(), $this->world)
            && Utils::areWorldsEqual($player2->getWorld(), $this->world);
    }

    /**
     * @param string $worldName
     * @param array $data
     * @return PvPCWorld|null
     *
     * Decodes the data & turns it into a PvPCWorld object according to the new format.
     */
    public static function decode(string $worldName, array $data): ?PvPCWorld
    {
        if (isset($data["kbEnabled"], $data["kbInfo"])) {
            $kbEnabled = (bool)$data["kbEnabled"];
            $knockback = PvPCKnockback::decode($data["kbInfo"]) ?? new PvPCKnockback();
            return new PvPCWorld(
                $worldName,
                $kbEnabled,
                $knockback
            );
        }

        return null;
    }

    /**
     * @param string $worldName
     * @param $data
     * @return PvPCWorld|null
     *
     * Decodes the data and turns it into a PvPCWorld object based on the old format.
     */
    public static function decodeLegacy(string $worldName, $data): ?PvPCWorld
    {
        $customKB = true;
        $attackDelay = 10;
        $knockback = 0.4;

        if (isset($data["customKb"])) {
            $customKB = $data["customKb"];
        }

        if (isset($data["attack-delay"])) {
            $attackDelay = (int)$data["attack-delay"];
        }

        if (isset($data["knockback"])) {
            $knockback = (float)$data["knockback"];
        }

        return new PvPCWorld(
            $worldName,
            $customKB,
            new PvPCKnockback($knockback, $knockback, $attackDelay)
        );
    }
}