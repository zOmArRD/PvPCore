<?php
/**
 * Created by PhpStorm.
 * User: jkorn2324
 * Date: 2020-06-11
 * Time: 23:11
 */

declare(strict_types=1);

namespace jkorn\pvpcore\player;

use jkorn\pvpcore\PvPCore;
use jkorn\pvpcore\utils\PvPCKnockback;
use jkorn\pvpcore\utils\Utils;
use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\form\Form;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\entity\effect\VanillaEffects;
use stdClass;

class PvPCPlayer extends Player
{

    /** @var stdClass|null - The pvp area info. */
    private $pvpAreaInfo = null;
    /** @var bool - Determines if player is looking at a form. */
    private $lookingAtForm = false;

    /**
     * Sets the first position of the area information.
     */
    public function setFirstPos(): void
    {
        if ($this->pvpAreaInfo === null) {
            $this->pvpAreaInfo = new stdClass();
        }
        $this->pvpAreaInfo->firstPos = $this->getLocation();

        $this->sendMessage(Utils::getPrefix() . TextFormat::GREEN . " Successfully set the first position of the PvPArea.");
    }

    /**
     * Sets the second position of the area information.
     */
    public function setSecondPos(): void
    {
        if ($this->pvpAreaInfo === null) {
            $this->pvpAreaInfo = new stdClass();
        }

        $this->pvpAreaInfo->secondPos = $this->getLocation();

        $this->sendMessage(Utils::getPrefix() . TextFormat::GREEN . " Successfully set the second position of the PvPArea.");
    }


    /**
     * @return stdClass|null
     *
     * Gets the area information of the player.
     */
    public function getAreaInfo(): ?stdClass
    {
        return $this->pvpAreaInfo;
    }

    /**
     * @param string $name
     *
     * Creates the area based on the name.
     */
    public function createArea(string $name): void
    {
        if (PvPCore::getAreaHandler()->createArea($this->pvpAreaInfo, $name, $this)) {
            $this->sendMessage(Utils::getPrefix() . TextFormat::GREEN . " Successfully created a new PvPArea.");
            $this->pvpAreaInfo = null;
        }
    }

    /**
     * @param Entity $attacker
     * @param float $damage
     * @param float $x
     * @param float $z
     * @param float $base
     *
     * Gives the player knockback values.
     */
    public function fixKnockBack(Entity $attacker, float $damage, float $x, float $z, float $base = 0.4): void
    {
        $xzKB = $base;
        $yKb = $base;
        if ($attacker instanceof Player) {
            $knockback = Utils::getKnockbackFor($this, $attacker);
            if ($knockback instanceof PvPCKnockback) {
                $xzKB = $knockback->getXZKb();
                $yKb = $knockback->getYKb();
            }
        }

        $f = sqrt($x * $x + $z * $z);
        if ($f <= 0) {
            return;
        }
        if (mt_rand() / mt_getrandmax() > $this->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)->getValue()) {
            $f = 1 / $f;

            $motion = clone $this->motion;

            $motion->x /= 2;
            $motion->y /= 2;
            $motion->z /= 2;
            $motion->x += $x * $f * $xzKB;
            $motion->y += $yKb;
            $motion->z += $z * $f * $xzKB;

            if ($motion->y > $yKb) {
                $motion->y = $yKb;
            }

            $this->setMotion($motion);
        }
    }

    /**
     * @param EntityDamageEvent $source
     *
     * Called when the player gets attacked, overriden to change the attack speed.
     */
    public function attack(EntityDamageEvent $source): void
    {
        if($this->noDamageTicks > 0){
            $source->cancel();
        }

        if($this->effectManager->has(VanillaEffects::FIRE_RESISTANCE()) && (
                $source->getCause() === EntityDamageEvent::CAUSE_FIRE
                || $source->getCause() === EntityDamageEvent::CAUSE_FIRE_TICK
                || $source->getCause() === EntityDamageEvent::CAUSE_LAVA
            )
        ){
            $source->cancel();
        }

        $this->applyDamageModifiers($source);

        if($source instanceof EntityDamageByEntityEvent && (
            $source->getCause() === EntityDamageEvent::CAUSE_BLOCK_EXPLOSION ||
            $source->getCause() === EntityDamageEvent::CAUSE_ENTITY_EXPLOSION)
        ){
            //TODO: knockback should not just apply for entity damage sources
            //this doesn't matter for TNT right now because the PrimedTNT entity is considered the source, not the block.
            $base = $source->getKnockBack();
            $source->setKnockBack($base - min($base, $base * $this->getHighestArmorEnchantmentLevel(VanillaEnchantments::BLAST_PROTECTION()) * 0.15));
        }

        $source->call();
        if($source->isCancelled()){
            return;
        }

        $this->setLastDamageCause($source);

        $this->setHealth($this->getHealth() - $source->getFinalDamage());

        if($source->isCancelled()){
            return;
        }

        $this->attackTime = $source->getAttackCooldown();

        if($source instanceof EntityDamageByChildEntityEvent){
            $e = $source->getChild();
            if($e !== null){
                $motion = $e->getMotion();
                $this->fixKnockBack($source->getEntity(), $source->getFinalDamage(), $motion->x, $motion->z, $source->getKnockBack());
            }
        }elseif($source instanceof EntityDamageByEntityEvent){
            $e = $source->getDamager();
            if($e !== null){
                $deltaX = $this->location->x - $e->location->x;
                $deltaZ = $this->location->z - $e->location->z;
                $this->fixKnockBack($source->getEntity(), $source->getFinalDamage(), $deltaX, $deltaZ, $source->getKnockBack());
            }
        }

        if($this->isAlive()){
            $this->applyPostDamageEffects($source);
            $this->doHitAnimation();
        }

        if ($source->isCancelled()) {
            return;
        }

        $attackSpeed = $source->getAttackCooldown();
        if ($source instanceof EntityDamageByEntityEvent) {
            $damager = $source->getDamager();
            if ($damager instanceof Player) {
                $knockback = Utils::getKnockbackFor($this, $damager);
                if($knockback !== null) {
                    $attackSpeed = $knockback->getSpeed();
                }
            }
        }

        if($attackSpeed < 0) {
            $attackSpeed = 0;
        }

        // Sets the attack time/delay to the speed.
        $this->attackTime = $attackSpeed;
    }

    /**
     * @param int $formId
     * @param mixed $responseData
     * @return bool
     *
     * Handled when the form was submitted.
     */
    public function onFormSubmit(int $formId, $responseData): bool
    {
        $this->lookingAtForm = false;
        return parent::onFormSubmit($formId, $responseData);
    }

    /**
     * @param Form $form
     *
     * Sends the form to the player to be processed.
     */
    public function sendForm(Form $form): void
    {
        if(!$this->lookingAtForm)
        {
            $this->lookingAtForm = true;
            parent::sendForm($form);
        }
    }
}
