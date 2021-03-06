<?php

namespace pocketfactions\utils\subcommand\f;

use pocketfactions\faction\Faction;
use pocketfactions\Main;
use pocketfactions\utils\subcommand\Subcommand;
use pocketmine\Player;
use pocketmine\Server;

class Create extends Subcommand{
	public function __construct(Main $main){
		parent::__construct($main, "create");
	}
	public function onRun(array $args, Player $player){
		if(!isset($args[0])){
			return self::WRONG_USE;
		}
		$name = $args[0];
		if(preg_replace($this->main->getFactionNamingRule(), "", $name) !== ""){
			return $this->getMain()->getFactionNameErrorMsg();
		}
		elseif(is_int($this->getMain()->getFList()->getFactionID($name))){
			return "A faction with name \"$name\" already exists!";
		}
		Faction::newInstance($name, $player->getName(), $this->getMain()->getDefaultRanks(),
			$this->getMain()->getDefaultRank(), $this->getMain()->getDefaultAllyRank(),
			$this->getMain()->getDefaultTruceRank(), $this->getMain()->getDefaultStdRank(), $this->main);
		$this->main->getServer()->broadcast("[PF] A new faction called $name has been created!", Server::BROADCAST_CHANNEL_USERS);
		return "";
	}
	public function checkPermission(Player $player){
		return $this->main->getFList()->getFaction($player) === false;
	}
	public function getDescription(){
		return "Create a faction";
	}
	public function getUsage(){
		return "<faction name>";
	}
}
