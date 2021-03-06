<?php

namespace pocketfactions\utils\subcommand\f;

use legendofmcpe\statscore\StatsCore;
use pocketfactions\faction\Faction;
use pocketfactions\Main;
use pocketfactions\utils\request\FactionJoinRequest;
use pocketfactions\utils\subcommand\Subcommand;
use pocketmine\Player;

class Join extends Subcommand{
	public function __construct(Main $main){
		parent::__construct($main, "join");
	}
	public function onRun(array $args, Player $player){
		if(!isset($args[0])){
			return false;
		}
		if(!(($f = $this->getMain()->getFList()->getFactionBySimilarName($name = array_shift($args))) instanceof Faction)){
			return self::WRONG_FACTION;
		}
		$request = new FactionJoinRequest($player, $f, implode(" ", $args));
		/** @var StatsCore $statsCore */
		$statsCore = $this->getMain()->getServer()->getPluginManager()->getPlugin("StatsCore");
		$statsCore->getRequestList()->add($request);
		return "[PF] Join faction request sent to $f.\n[PF] Preview:\n============\n" . $request->getContent();
	}
	public function checkPermission(Player $player){
		if($this->getMain()->getFList()->getFaction($player) instanceof Faction){
			return false;
		}
		return true;
	}
	public function getDescription(){
		return "Send a join faction request";
	}
	public function getUsage(){
		return "<faction> [extra message]";
	}
	public function getAliases(){
		return ["j"];
	}
}
