<?php

namespace pocketfactions\utils\subcommand\f;

use pocketfactions\faction\Faction;
use pocketfactions\Main;
use pocketmine\Player;

class Homes extends FactionMemberSubcommand{
	public function __construct(Main $main){
		parent::__construct($main, "homes");
	}
	public function getDescription(){
		return "View all homes of your faction";
	}
	public function getUsage(){
		return "";
	}
	public function checkPermission(Faction $faction, Player $player){
		return count($faction->getHomes()) > 0 and $this->getMain()->getMaxHomes() > 0;
	}
	public function onRun(array $args, Faction $faction, Player $player){
		return "Homes of $faction: " . implode(", ", array_keys($faction->getHomes()));
	}
}
