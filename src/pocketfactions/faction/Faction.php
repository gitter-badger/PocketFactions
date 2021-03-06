<?php

namespace pocketfactions\faction;

use legendofmcpe\statscore\request\Requestable;
use legendofmcpe\statscore\StatsCore;
use pocketfactions\Main;
use pocketfactions\utils\IFaction;
use pocketmine\inventory\InventoryHolder;
use pocketmine\level\Position;
use pocketmine\Player;
use pocketmine\Server;
use xecon\account\DummyInventory;
use xecon\account\Loan;
use xecon\entity\Entity;

class Faction implements InventoryHolder, Requestable, IFaction{
	use Entity;
	const BANK = "Bank";
	const CASH = "Cash";
	const CHAT_ADMIN = Rank::P_CHAT_ADMIN;
	const CHAT_ANNOUNCEMENT = Rank::P_CHAT_ANNOUNCEMENT;
	const CHAT_ALL = Rank::P_CHAT_ALL;
	/** @var Main */
	protected $main;
	/** @var string $name */
	protected $name;
	/** @var string $motto */
	protected $motto;
	/** @var int $id */
	protected $id;
	/** @var string $founder */
	protected $founder;
	/** @var Rank[] $ranks indexed by internal rank IDs */
	protected $ranks;
	/** @var int internal rank ID of the default, ally, truce and standard (neutral/enemy/wilderness) rank */
	protected $defaultRank, $allyRank, $truceRank, $stdRank;
	/**
	 * This array is a list indexed with lowercase member names and filled with integers of the member's rank ID.
	 * Use <code>array_keys()</code> to get a plain list of members.
	 * @var int[] $members
	 */
	protected $members;
	/** @var int $lastActive */
	protected $lastActive;
	/** @var int $reputation The net reputation value of a faction */
	protected $reputation;
	/** @var Chunk[] $chunks numerically keyed chunks with undefined order (possibly, but not sure, sequence of claiming) */
	protected $chunks;
	/** @var \pocketmine\level\Position[] $homes with keys as name strings */
	protected $homes = [];
	/** @var bool */
	protected $whitelist;
	/** @var */
	protected $econEnt;
	/** @var Server */
	public $server;
	//////////////////
	// constructors //
	//////////////////
	/**
	 * @param string $name
	 * @param string $founder name of the faction founder
	 * @param Rank[] $ranks
	 * @param int $defaultRankIndex the default rank's key in $ranks
	 * @param int $allyRankIndex
	 * @param int $truceRankIndex
	 * @param int $stdRankIndex
	 * @param Main $main
	 * @param Position|Position[] $home the home position of the faction
	 * @param string $motto
	 * @param bool $whitelist
	 * @param int|bool $id
	 * @param int $reputation
	 * @return Faction
	 */
	public static function newInstance($name, $founder, array $ranks, $defaultRankIndex, $allyRankIndex, $truceRankIndex, $stdRankIndex, Main $main, $home, $motto = "", $whitelist = true, $id = false, $reputation = 0){
		if(!is_int($id)){
			$id = self::nextID($main);
		}
		$data = [
			"name" => $name,
			"motto" => $motto,
			"id" => $id,
			"founder" => strtolower($founder),
			"ranks" => $ranks,
			"default-rank" => $defaultRankIndex,
			"ally-rank" => $allyRankIndex,
			"truce-rank" => $truceRankIndex,
			"std-rank" => $stdRankIndex,
			"members" => [],
			"last-active" => time(),
			"chunks" => [],
			"homes" => (array) $home,
			"base-chunk" => Chunk::fromObject($home),
			"whitelist" => $whitelist,
			"reputation" => $reputation
		];
		$faction = new Faction($data, $main);
		$main->getFList()->add($faction);
		return $faction;
	}
	public function __construct(array $args, Main $main){
		$this->name = $args["name"];
		$this->motto = $args["motto"];
		$this->id = $args["id"];
		$this->founder = $args["founder"];
		$this->ranks = $args["ranks"];
		$this->defaultRank = $args["default-rank"];
		$this->allyRank = $args["ally-rank"];
		$this->truceRank = $args["truce-rank"];
		$this->stdRank = $args["std-rank"];
		$this->members = $args["members"];
		$this->lastActive = $args["last-active"];
		$this->chunks = $args["chunks"];
		$this->homes = $args["homes"];
		$this->main = $main;
		$this->whitelist = $args["whitelist"];
		$this->reputation = isset($args["reputation"]) ? $args["reputation"]:0;
		$this->server = $this->main->getServer();
		$levels = [];
		foreach($this->chunks as $chunk){
			$level = $chunk->getLevel();
			if(!isset($levels[$level])){
				$levels[] = $level;
				if(!$this->server->isLevelLoaded($level)){
					if(!$this->server->isLevelGenerated($this->server->loadLevel($level))){
						$this->server->generateLevel($level, $this->main->getLevelGenerationSeed());
					}
					$this->server->loadLevel($level);
				}
			}
		}
		$this->initializeXEconEntity($main->getXEcon($this->server));
	}
	///////////////////
	// API functions //
	////////////////////
	//// Getters and Setters
	/**
	 * @return string
	 */
	public function getName(){
		return $this->name;
	}
	/**
	 * @param string $name
	 */
	public function setName($name){
		$this->name = $name;
		$op = $this->getMain()->getFList()->getDb()->prepare("UPDATE factions SET name = :name WHERE id = :id;");
		$op->bindValue(":name", $name);
		$op->bindValue(":id", $this->id);
		$op->execute();
	}
	/**
	 * @return string
	 */
	public function getMotto(){
		return $this->motto;
	}
	public function setMotto($motto){
		$this->motto = $motto;
	}
	/**
	 * @return int
	 */
	public function getID(){
		return $this->id;
	}
	/**
	 * @return string
	 */
	public function getFounder(){
		return $this->founder;
	}
	/**
	 * @return Rank[]
	 */
	public function getRanks(){
		return $this->ranks;
	}
	public function setRanks(array $ranks){ // raw
		$this->ranks = $ranks;
	}
	/**
	 * @return Rank
	 */
	public function getDefaultRank(){
		return $this->ranks[$this->defaultRank];
	}
	public function setDefaultRank($id){
		$this->defaultRank = $id;
	}
	public function getTruceRank(){
		return $this->ranks[$this->truceRank];
	}
	public function setTruceRank($id){
		$this->truceRank = $id;
	}
	public function getAllyRank(){
		return $this->ranks[$this->allyRank];
	}
	public function setAllyRank($id){
		$this->allyRank = $id;
	}
	public function getStdRank(){
		return $this->ranks[$this->stdRank];
	}
	public function setStdRank($id){
		$this->stdRank = $id;
	}
	/**
	 * @param bool $raw
	 * @return string[]|int[] an array of names of members if <code>$raw === false</code>, an array of rank IDs indexed by member names otherwise
	 */
	public function getMembers($raw = false){
		return $raw === false ? array_keys($this->members):$this->members;
	}
	/**
	 * @param int[] $members An array of rank IDs indexed by player names
	 */
	public function setMembers(array $members){
		$this->members = $members;
		$sql = $this->getMain()->getFList()->getDb();
		$op = $sql->prepare("DELETE FROM factions_members WHERE factionid = :fid;");
		$op->bindValue(":fid", $this->id);
		$op->execute();
		foreach($members as $member => $r){
			$op = $sql->prepare("INSERT INTO factions_members (factionid, lowname) VALUES (:fid, '$member');");
			$op->bindValue(":fid", $this->id);
			$op->execute();
		}
	}
	/**
	 * @return Chunk[]
	 */
	public function getChunks(){
		return $this->chunks;
	}
	/**
	 * @param Player|string $member
	 * @return Rank
	 */
	public function getMemberRank($member){
		if($member instanceof Player){
			$member = $member->getName();
		}
		$member = strtolower($member);
		if(isset($this->members[$member])){
			return $this->ranks[$this->members[$member]];
		}
		else{
			$result = $this->getMain()->getFList()->getDb()->prepare("SELECT factionid FROM factions_players WHERE lowname = :lowname;");
			$result->bindValue(":lowname", $member);
			$result = $result->execute();
			$data = $result->fetchArray(SQLITE3_ASSOC);
			if(!is_array($data)){
				return $this->getStdRank();
			}
			else{
				$faction = $this->getMain()->getFList()->getFaction($data["factionid"]);
				$rel= $this->getMain()->getFList()->getFactionsState($this, $faction);
				switch($rel){
					case State::REL_ALLY:
						return $this->getAllyRank();
					case State::REL_TRUCE:
						return $this->getTruceRank();
					default:
						return $this->getStdRank();
				}
			}
		}
	}
	public function getRankByName($name){
		foreach($this->ranks as $rank){
			if($rank->getName() === $name){
				return $rank;
			}
		}
		return null;
	}
	/**
	 * @return bool
	 */
	public function isWhitelisted(){
		return $this->whitelist;
	}
	/**
	 * @param bool $white
	 */
	public function setWhitelisted($white){
		$this->whitelist = $white;
		$op = $this->getMain()->getFList()->getDb()->prepare("UPDATE factions SET open = :open WHERE id = :id;");
		$op->bindValue(":open", $white ? 0:1);
		$op->bindValue(":id", $this->id);
		$op->execute();
	}
	/**
	 * @return bool
	 */
	public function isOpen(){
		return !$this->whitelist;
	}
	/**
	 * @param bool $open
	 */
	public function setOpen($open){
		$this->setWhitelisted(!$open);
	}
	/**
	 * @param string|bool $name name of the home
	 * @return Position|bool
	 */
	public function getHome($name = false){
		if($this->getMain()->getMaxHomes() === 1){
			return array_values($this->homes)[0]; // force order the array in ascending integers and get the first one
		}
		return isset($this->homes[$name]) ? $this->homes[$name]:false;
	}
	/**
	 * @return Position[]
	 */
	public function getHomes(){
		return $this->homes;
	}
	/**
	 * @param string $name
	 * @param Position $pos
	 */
	public function setHome($name = "default", Position $pos){
		$this->homes[$name] = Position::fromObject($pos, $pos->getLevel());
		$db = $this->getMain()->getFList()->getDb();
		$op = $db->prepare("INSERT OR REPLACE INTO factions_homes (x, y, z, name, fid) VALUES (:x, :y, :z, :name, :id);");
		$op->bindValue(":x", $pos->getX());
		$op->bindValue(":y", $pos->getY());
		$op->bindValue(":z", $pos->getZ());
		$op->bindValue(":name", $name);
		$op->bindValue(":id", $this->id);
		$op->execute();
	}
	/**
	 * @param string $name
	 * @return bool Whether the removal succeeded
	 */
	public function rmHome($name = "default"){
		if(!isset($this->homes[$name])){
			return false;
		}
		unset($this->homes[$name]);
		$db = $this->getMain()->getFList()->getDb();
		$op = $db->prepare("DELETE FROM factions_homes WHERE fid = :fid AND name = :name;");
		$op->bindValue(":fid", $this->getID());
		$op->bindValue(":name", $name);
		$op->execute();
		return true;
	}
	/**
	 * @return int
	 */
	public function getLastActive(){
		return $this->lastActive;
	}
	public function setActiveNow(){
		$this->lastActive = time();
		$op = $this->getMain()->getFList()->getDb()->prepare("UPDATE factions SET lastactive = :lastactive WHERE id = :id;");
		$op->bindValue(":lastactive", $this->lastActive);
		$op->bindValue(":id", $this->id);
		$op->execute();
	}
	public function hasChunk(Chunk $chunk){
		foreach($this->chunks as $cchunk){
			if($chunk->equals($cchunk)){
				return true;
			}
		}
		return false;
	}
	public function powerClaimable(){
		$power = $this->getPower();
		return (int) ($power / $this->main->getClaimSingleChunkPower());
	}
	public function getPower(){
		$power = $this->getReputation();
		foreach($this->members as $mbr => $rank){
			$statsCore = $this->getMain()->getServer()->getPluginManager()->getPlugin("StatsCore");
			if(!($statsCore instanceof StatsCore) or $statsCore->isDisabled()){
				$this->main->getLogger()->error("StatsCore is not found or is disabled.");
			}
			$micro = $statsCore->getLog()->getTotalOnline($mbr);
			$power += (((int) ($micro / 60 / 60)) * $this->main->getPowerGainPerOnlineHour());
			$power -= $statsCore->getLog()->getOfflineDays($mbr);
			// TODO add kills and deaths factors
		}
		return $power;
	}
	public function getNetReputation(){
		return $this->reputation;
	}
	public function getReputation(){
		$out = $this->reputation;
		$data = [
			State::REL_ALLY => 0,
			State::REL_TRUCE => 0,
			State::REL_ENEMY => 0
		];
		$db = $this->getMain()->getFList()->getDb();
		$op = $db->prepare("SELECT relid FROM factions_rels WHERE smallid = :id OR largeid = :id;");
		$op->bindValue(":id", $this->getID());
		$result = $op->execute();
		while(is_array($array = $result->fetchArray(SQLITE3_ASSOC))){
			switch($array["relid"]){
				case State::REL_ALLY:
					$data[State::REL_ALLY]++;
					break;
				case State::REL_ENEMY:
					$data[State::REL_ENEMY]++;
					break;
				case State::REL_TRUCE:
					$data[State::REL_TRUCE]++;
					break;
			}
		}
		foreach([State::REL_ENEMY, State::REL_ALLY, State::REL_TRUCE] as $state){
			$out += $this->getMain()->getRelationReputationModifiers()[$state] * $data[$state];
		}
		return $out;
	}
	public function addReputation($amount){
		$this->reputation += $amount;
	}
	public function loseReputation($amount){
		$this->reputation -= $amount;
	}
	public function hasMember($name){
		if($name instanceof Player){
			$name = $name->getName();
		}
		return isset($this->members[strtolower($name)]);
	}
	public function canClaimMore(){
		return count($this->chunks) + 1 <= $this->powerClaimable();
	}
	/**
	 * @param Position $pos
	 * @return bool
	 */
	public function isCentreLocation(Position $pos){ // true if a home is here
		foreach($this->getHomes() as $home){
			if($pos->getX() >> 4 === $home->getX() >> 4 and $pos->getX() >> 4 === $home->getX() >> 4){
				return true;
			}
		}
		return false;
	}
	//// Runnable API functions; Command-redirected functions
	/**
	 * @param Player|string $newMember
	 * @param string $method
	 * @return bool|string
	 */
	public function join($newMember, $method){
		if($newMember instanceof Player){
			$newMember = $newMember->getName();
		}
		$this->members[strtolower($newMember)] = $this->getDefaultRank();
		$this->sendMessage("$newMember has joined the faction. Method: $method");
		$this->main->getFList()->onMemberJoin($this, $newMember);
		return true;
	}
	/**
	 * @param string $memberName
	 */
	public function kick($memberName){
		unset($this->members[strtolower($memberName)]);
		$this->main->getFList()->onMemberKick(strtolower($memberName));
	}
	/**
	 * @param Chunk $chunk
	 * @param Player $player
	 * @return bool|string true on success, or message string on failure
	 */
	public function claim(Chunk $chunk, Player $player){
		if(!$this->canClaimMore()){
			return "Your faction doesn't have enough power to claim more chunks!";
		}
		$charge = $this->main->getChunkClaimFee();
		$account = $this->getAccount($charge["account"]);
		if(!$account->canPay($charge["amount"])){
			return "Not enough money to claim a chunk";
		}
		$account->pay($this->getMain()->getXEconService(), $charge["amount"], "Charge for claiming a chunk");
		if($account->getAmount() < 0){
			$this->sendMessage("[WARNING] The faction bank is now overdrafted! You will have to pay interest if you don't repay it ASAP!");
		}
		$this->forceClaim($chunk);
		$this->sendMessage("$player has claimed a new chunk.", self::CHAT_ANNOUNCEMENT);
		return true;
	}
	public function forceClaim(Chunk $chunk){
		$this->chunks[] = $chunk;
		$this->main->getFList()->onChunkClaimed($this, $chunk);
	}
	/**
	 * @param Chunk $chunk
	 * @return bool|string
	 */
	public function unclaim(Chunk $chunk){
		if(($result = $this->forceUnclaim($chunk)) !== true){
			return $result;
		}
		$refund = $this->getMain()->getChunkUnclaimRepay();
		$account = $this->getAccount($refund["account"]);
		$this->getMain()->getXEconService()->pay($account, $refund["amount"], "Refund for unclaiming a chunk");
		return true;
	}
	/**
	 * @param Chunk $chunk
	 * @return bool|string
	 */
	public function forceUnclaim(Chunk $chunk){
		$id = false;
		foreach($this->chunks as $i => $c){
			if($c->equals($chunk)){
				$id = $i;
			}
		}
		if($id === false){
			return "This chunk is not the territory of $this.";
		}
		unset($this->chunks[$id]);
		$this->getMain()->getFList()->onChunkUnclaimed($chunk);
		return true;
	}
	/**
	 * Unclaims all chunks in once
	 */
	public function unclaimAll(){
		$chunks = count($this->chunks);
		$this->chunks = [];
		$this->getMain()->getFList()->onAllChunksUnclaimed($this);
		$refund = $this->getMain()->getChunkUnclaimRepay();
		$account = $this->getAccount($refund["account"]);
		$this->getMain()->getXEconService()->pay($account, $refund["amount"] * $chunks, "Refund for unclaiming all chunks");
	}
	public function addLoan_faction($type){
		if(is_array($loan = $this->main->getLoan($type))){
			/** @var Entity $this */ // I don't know why I need to do this.
			$loanObj = new Loan($this->getMain()->getXEconLoanService(), $loan["amount"], $this, time() + $loan["horizon"] * 3600, $loan["interest"]);
			$loanObj->setName($type." ".$loanObj->getName());
			$cnt = 1;
			foreach($this->loans as $acc){
				if(strstr($acc->getName(), " ", true) === $type){
					$cnt++;
				}
			}
			if($cnt > $loan["maximum"]){
				return "Maximum limit for loan $type exceeded";
			}
			$liabilities = 0;
			foreach($this->getLoans() as $l){
				$liabilities += $l;
			}
			if(max(0, -$this->getAccount(self::BANK)) + $liabilities + $loan["amount"] > $this->getMain()->getMaxLiability()){
				return "Maximum liability exceeded";
			}
			$this->getLoans()[$loanObj->getName()] = $loanObj;
			return true;
		}
		else{
			return "Unknown loan type. Use /f loan types to see a list of loan types in this server.";
		}
	}
	public function __toString(){
		return $this->getName();
	}
	public function getDisplayName(){
		return "faction ".$this->name; // TODO better representations
	}
	/////////////////////////
	// Inherited functions //
	/////////////////////////
	public function sendMessage($message, $level = self::CHAT_ADMIN){
		$fs = true;
		if($level === self::CHAT_ALL){
			$inbox = Main::getStatsCore($this->main->getServer())->getOfflineInbox();
			foreach($this->getMembers() as $member){
				if(($p = $this->getMain()->getServer()->getPlayerExact($member)) instanceof Player){
					$p->sendMessage("[PF] $message");
				}
				else{
					$inbox->addMessage($this->getMain(), $member, "[PF] $message");
				}
			}
		}
		foreach($this->getMain()->getServer()->getOnlinePlayers() as $player){
			$rank = $this->getMemberRank($player);
			if(($rank instanceof Rank) and $rank->hasPerm($level)){
				$player->sendMessage("[$this] $message");
			}
			if($player->getName() === $this->founder){
				$fs = false;
			}
		}
		if($fs){
			Main::getStatsCore($this->main->getServer())->getOfflineInbox()->addMessage($this->main, $this->founder, $message);
		}
	}
	// xEcon-related
	public function getInventory(){
		return new DummyInventory($this, "Faction Account"); // TODO replace the dummy placeholder // e.g. Chest inventory
	}
	public function getEconomicEntity(){
		return $this;
	}
	public function initializeDefaultAccounts(){
		$this->addAccount("Cash", $this->main->getDefaultCash(), $this->main->getMaxCash());
		$this->addAccount("Bank", $this->main->getDefaultBank(), $this->main->getMaxBank(), -$this->main->getMaxBankOverdraft());
	}
	public function getAbsolutePrefix(){
		return "PocketFactions^^";
	}
	public function isAvailable(){
		return true; // TODO
	}
	public function getRequestableIdentifier(){
		return "PocketFactions Faction #".$this->getID();
	}
	public function canBuild(Player $player, Position $pos){
		return $this->getMemberRank($player)->hasPerm(Rank::P_BUILD) and (!$this->isCentreLocation($pos) or $this->getMemberRank($player)->hasPerm(Rank::P_BUILD_CENTRE));
	}
	public function getMain(){
		return $this->main;
	}
	/**
	 * @param Main $main
	 * @return int The next unique faction ID
	 */
	public static function nextID(Main $main){
		$fid = $main->getCleanSaveConfig()->get("next-fid");
		$main->getCleanSaveConfig()->set("next-fid", $fid + 1);
		$main->getCleanSaveConfig()->save();
		return $fid;
	}
}
