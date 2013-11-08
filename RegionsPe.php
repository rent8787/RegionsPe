<?php

/*
__PocketMine Plugin__
name=Regions
description=RegionsPlugin
version=1.0
author=wies
class=Regions
apiversion=10
*/

class Regions implements Plugin{

	private $api, $players, $path, $regions, $pos;
	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
		$this->pos = array();
		$this->regions = array();
	}
	
	public function init(){
		$this->path = $this->api->plugin->configPath($this);
		$this->api->addHandler("player.move", array($this, "playermove"));
		$this->api->addHandler("player.block.place", array($this, "blockplacebreak"));
		$this->api->addHandler("player.block.break", array($this, "blockplacebreak"));
		$this->api->addHandler("player.block.touch", array($this, "blocktouch"));
		$this->api->addHandler("player.interact", array($this, "playerinteract"));
		$this->api->console->register("region", "Region commands", array($this, "command"));
		$this->config = new Config($this->path."config.yml", CONFIG_YAML, array(
			'defaultflags' => array(
				'build' => 'none',
				'pvp' => 'none',
				'entry' => 'none',
				'welcome' => ' ',
				'chest-access' => 'none',
			),
		));
		$this->config = $this->api->plugin->readYAML($this->path . "config.yml");
		$this->defaultflags = json_encode($this->config['defaultflags']);
		$this->db = new PDO("sqlite:".$this->api->plugin->configPath($this)."regions.db");  
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS regions (
			ID INTEGER PRIMARY KEY AUTOINCREMENT,
			name TEXT NOT NULL,
			x1 INTEGER NOT NULL,
			y1 INTEGER NOT NULL,
			z1 INTEGER NOT NULL,
			x2 INTEGER NOT NULL,
			y2 INTEGER NOT NULL,
			z2 INTEGER NOT NULL,
			level TEXT NOT NULL,
			owners TEXT,
			members TEXT,
			priority INTEGER NOT NULL,
			flags TEXT NOT NULL
		)");
		if($this->db->query("SELECT * FROM regions WHERE ID = 1")->fetch(PDO::FETCH_ASSOC) === false){
			$level = $this->api->level->getDefault()->getName();
			$this->db->exec("INSERT INTO regions (ID, name, x1, y1, z1, x2, y2, z2, level, priority, flags) VALUES (1, 'global', 0, 0, 0, 255, 128, 255, '".$level."', 0, '".$this->defaultflags."')");
		}
	}
	
	public function getflags($x, $y, $z, $level){
		$sql = $this->db->prepare("SELECT * FROM regions WHERE x1 <= :x AND x2 >= :x AND y1 <= :y AND y2 >= :y AND z1 <= :z AND z2 >= :z AND level = :level");
		$sql->bindValue(':x', $x, PDO::PARAM_INT);
		$sql->bindValue(':y', $y, PDO::PARAM_INT);
		$sql->bindValue(':z', $z, PDO::PARAM_INT);
		$sql->bindValue(':level', $level, PDO::PARAM_STR);
		$sql->execute();
		$flags = array();
		while($region = $sql->fetch(PDO::FETCH_ASSOC)){
			$flagsregion = json_decode($region['flags'], true);
			$priority = $region['priority'];
			foreach($flagsregion as $key => $val){
				if($val !== 'none' and $val != ''){
					if($flags[$key][1] < $priority){
						$flags[$key] = array($val, $priority);
					}elseif($flags[$key][1] === $priority and $val === 'deny'){
						$flags[$key] = array($val, $priority);
					}
				}
			}
		}
		foreach($flags as $key => $val){
			$flags[$key] = $val[0];
		}
		return $flags;
	}
	
	public function checkflagpermission($flag, $username, $x, $y, $z, $level){
		$sql = $this->db->prepare("SELECT * FROM regions WHERE x1 <= :x AND x2 >= :x AND y1 <= :y AND y2 >= :y AND z1 <= :z AND z2 >= :z AND level = :level");
		$sql->bindValue(':x', $x, PDO::PARAM_INT);
		$sql->bindValue(':y', $y, PDO::PARAM_INT);
		$sql->bindValue(':z', $z, PDO::PARAM_INT);
		$sql->bindValue(':level', $level, PDO::PARAM_STR);
		$sql->execute();
		$permission = null;
		while($region = $sql->fetch(PDO::FETCH_ASSOC)){
			$flagsregion = json_decode($region['flags'], true);
			$priority = $region['priority'];
			$p = $flagsregion[$flag];
			if($p !== 'none' and $p != ''){
				if($permission !== null){
					if($previouspriority < $priority){
						$permission = $p;
						$previouspriority = $priority;
						$previousregion = $region;
					}elseif($previouspriority === $priority and $val === 'deny'){
						$permission = $p;
						$previouspriority = $priority;
						$previousregion = $region;
					}
				}else{
					$permission = $p;
					$previouspriority = $priority;
					$previousregion = $region;
				}
			}
		}
		if($permission !== null){
			$rank = 'guest';
			if(in_array($username, json_decode($previousregion['members'], true))) $rank = 'member';
			if(in_array($username, json_decode($previousregion['owners'], true))) $rank = 'owner';
			return array('permission' => $permission, 'rank' => $rank);
		}else{
			return false;
		}
	}
	
	public function playermove($data){
		$username = $data->player->username;
		$x = $data->x;
		$y = $data->y;
		$z = $data->z;
		$permission = $this->checkflagpermission('entry', $username, $x, $y, $z, $data->player->level->getName());
		if(($permission !== false) and ($permission['rank'] === 'guest')){
			$data->player->sendChat('You are not allowed to enter this region');
			return false;
		}else{
			
			return true;
		}
	}
	
	public function blockplacebreak($data){
		$username = $data['player']->username;
		$x = $data['target']->x;
		$y = $data['target']->y;
		$z = $data['target']->z;
		$permission = $this->checkflagpermission('build', $username, $x, $y, $z, $data['target']->level->getName());
		if(($permission !== false) and ($permission['permission'] === 'deny') and ($permission['rank'] === 'guest')){
			$data->player->sendChat('You are not allowed to build in this region');
			return false;
		}
	}
	
	public function blocktouch($data){
		$id = $data['target']->getID();
		$username = $data['player']->username;
		if($id == 54 or $id == 61 or $id == 62){
			$x = $data['target']->x;
			$y = $data['target']->y;
			$z = $data['target']->z;
			$permission = $this->checkflagpermission('chest-access', $username, $x, $y, $z, $data['target']->level->getName());
			if(($permission !== false) and ($permission['permission'] == 'deny') and ($permission['rank'] === 'guest')){
				$data['player']->sendChat('Chest-Access is disabled in this region');
				return false;
			}
		}elseif(($data['item']->getID() == 271) and ($this->api->ban->isOp($username))){
			$x = ceil($data['target']->x);
			$y = ceil($data['target']->y);
			$z = ceil($data['target']->z);
			if(isset($this->pos[$username][1]) and !isset($this->pos[$username][2])){
				$this->pos[$username][2] = array($x, $y, $z, $data['player']->level->getName());
				$data['player']->sendChat('Second position set');
				$data['player']->sendChat('x: '.$x.' ,y: '.$y.' ,z: '.$z);
			}elseif(isset($this->pos[$username][2])){
				unset($this->pos[$username][2]);
				$this->pos[$username][1] = array($x, $y, $z, $data['player']->level->getName());
				$data['player']->sendChat('First position set');
				$data['player']->sendChat('x: '.$x.' ,y: '.$y.' ,z: '.$z);
			}else{
				$this->pos[$username][1] = array($x, $y, $z, $data['player']->level->getName());
				$data['player']->sendChat('First position set');
				$data['player']->sendChat('x: '.$x.' ,y: '.$y.' ,z: '.$z);
			}
		}
		return true;
	}
	
	public function playerinteract($data){
		$username = $data['entity']->player->username;
		$x = $data['targetentity']->x;
		$y = $data['targetentity']->y;
		$z = $data['targetentity']->z;
		$permission = $this->checkflagpermission('pvp', $username, $x, $y, $z, $data['targetentity']->level->getName());
		if(($permission !== false) and ($permission['permission'] == 'deny')){
			$data->player->sendChat('PvP is not allowed in this region');
			return false;
		}
		return true;
	}
	
	public function command($cmd, $args, $issuer){
		if($issuer === 'console'){
			$output = 'Run this command in-game';
			return $output;
		}
		$username = $issuer->username;
		if(in_array($args[0], array('define', 'delete', 'expand', 'contract')) and !$this->api->ban->isOp($username)){
			return false;
		}
		switch($args[0]){			
			case 'define':	if(!(isset($this->pos[$username][1]) and isset($this->pos[$username][2]))){
								$output = 'Make a selection first.';
								$output .= 'You can do this with a wooden axe.';
								break;
							}elseif($this->pos[$username][1][3] !== $this->pos[$username][2][3]){
								$output = 'The selection points are in different worlds';
								break;
							}elseif(!isset($args[1])){
								$output = 'Usage: /region define <id>';
								break;
							}
							$nameregion = $args[1];
							$sql = $this->db->prepare("SELECT * FROM regions WHERE name = :name");
							$sql->bindValue(':name', $regionname, PDO::PARAM_STR);
							$sql->execute();
							if($sql->fetch(PDO::FETCH_ASSOC) !== false){
								$output = 'Their already exist a region with that name';
								break;
							}
							$pos1 = $this->pos[$username][1];
							$pos2 = $this->pos[$username][2];
							$min[0] = min($pos1[0], $pos2[0]);
							$max[0] = max($pos1[0], $pos2[0]);
							$min[1] = min($pos1[1], $pos2[1]);
							$max[1] = max($pos1[1], $pos2[1]);
							$min[2] = min($pos1[2], $pos2[2]);
							$max[2]= max($pos1[2], $pos2[2]);
							$sql = $this->db->prepare("INSERT INTO regions (name, x1, y1, z1, x2, y2, z2, level, priority, flags) VALUES (:name, :x1, :y1, :z1, :x2, :y2, :z2, :level, 1, :flags)");
							$sql->bindValue(':name', $nameregion, PDO::PARAM_STR);
							$sql->bindValue(':x1', $min[0], PDO::PARAM_INT);
							$sql->bindValue(':y1', $min[1], PDO::PARAM_INT);
							$sql->bindValue(':z1', $min[2], PDO::PARAM_INT);
							$sql->bindValue(':x2', $max[0], PDO::PARAM_INT);
							$sql->bindValue(':y2', $max[1], PDO::PARAM_INT);
							$sql->bindValue(':z2', $max[2], PDO::PARAM_INT);
							$sql->bindValue(':level', $pos1[3], PDO::PARAM_STR);
							$sql->bindValue(':flags', $this->defaultflags, PDO::PARAM_STR);
							$sql->execute();
							$output = 'You defined this region as '.$nameregion;
							break;
							
			case 'delete':	if(!isset($args[1])){
								$output ='Usage: /region delete <id>';
								break;
							}
							$regionname = $args[1];
							$sql = $this->db->prepare("SELECT * FROM regions WHERE name = :name");
							$sql->bindValue(':name', $regionname, PDO::PARAM_STR);
							$sql->execute();
							$region = $sql->fetch(PDO::FETCH_ASSOC);
							
							$sql->bindValue(':z', $z, PDO::PARAM_INT);
							
							if($region === false){
								$output = 'The region: '.$regionname." doesn't exist";
								break;
							}
							break;
							
			case 'flag':	if(!(isset($args[1]) and isset($args[2]) and isset($args[3]) and ($args[3] == 'allow' or $args[3] == 'none' or $args[3] == 'deny'))){
								$output ='Usage: /r flag <id> <flag> <allow|none|deny>';
								break;
							}
							$regionname = $args[1];
							$flag = $args[2];
							if(!in_array($flag, array('build', 'pvp', 'entry', 'welcome', 'chest-access'))){
								$output = 'The flag: '.$flag." doesn't exist";
								break;
							}
							$sql = $this->db->prepare("SELECT * FROM regions WHERE name = :name");
							$sql->bindValue(':name', $regionname, PDO::PARAM_STR);
							$sql->execute();
							$region = $sql->fetch(PDO::FETCH_ASSOC);
							if($region === false){
								$output = 'The region: '.$regionname." doesn't exist";
								break;
							}
							if(in_array($username, json_decode($region['owners'], true))){
								$flags = json_decode($region['flags'], true);
								$flags[$flag] = $args[3];
								$sql = $this->db->prepare("UPDATE regions SET flags = :flags WHERE ID = :ID");
								$sql->bindValue(':flags', json_encode($flags), PDO::PARAM_STR);
								$sql->bindValue(':ID', $region['ID'], PDO::PARAM_INT);
								$sql->execute();
								$output = 'You set the flag: '.$flag.' to: '.$args[3];
							}else{
								$output = "You're not the owner of this region";
							}
							break;
							
			case 'addowner':
			case 'addmember':
							if(!(isset($args[1]) and isset($args[2]))){
								$output ='Usage: /r <addowner|addmember> <id> <player>';
								break;
							}
							$regionname = $args[1];
							$sql = $this->db->prepare("SELECT * FROM regions WHERE name = :name");
							$sql->bindValue(':name', $regionname, PDO::PARAM_STR);
							$sql->execute();
							$region = $sql->fetch(PDO::FETCH_ASSOC);
							if($region === false){
								$output = 'The region: '.$regionname." doesn't exist";
								break;
							}
							$player = $args[2];
							if(!in_array($username, json_decode($region['owners'], true)) and !$this->api->ban->isOp($username)){
								$output = "You're not the owner of this region";
							}
							if(in_array($player, json_decode($region['owners'],true))){
								$output ='The player: '.$player.' is already an owner of this region';
								break;
							}elseif(in_array($player, json_decode($region['members'], true))){
								$output ='The player: '.$player.' is already an member of this region';
								break;
							}
							switch($args[0]){
								case 'addowner':
									$owners = json_decode($region['owners'], true);
									array_push($owners, $player);
									$sql = $this->db->prepare("UPDATE regions SET owners = :owners WHERE ID = :ID");
									$sql->bindValue(':owners', json_encode($owners), PDO::PARAM_STR);
									$sql->bindValue(':ID', $region['ID'], PDO::PARAM_INT);
									$sql->execute();
									$output = $player.' is now a owner of the region: '.$regionname;
									break;
								case 'addmember':
									$members = json_decode($region['members'], true);
									array_push($members, $player);
									$sql = $this->db->prepare("UPDATE regions SET members = :members WHERE ID = :ID");
									$sql->bindValue(':members', json_encode($members), PDO::PARAM_STR);
									$sql->bindValue(':ID', $region['ID'], PDO::PARAM_INT);
									$sql->execute();
									$output = $player.' is now a member of the region: '.$regionname;
									break;
							}
							break;
							
			case 'removeowner':
			case 'removemember':
							if(!(isset($args[1]) and isset($args[2]))){
								$output ='Usage: /r <removeowner|removemember> <id> <player>';
								break;
							}
							$regionname = $args[1];
							$sql = $this->db->prepare("SELECT * FROM regions WHERE name = :name");
							$sql->bindValue(':name', $regionname, PDO::PARAM_STR);
							$sql->execute();
							$region = $sql->fetch(PDO::FETCH_ASSOC);
							if($region === false){
								$output = 'The region: '.$regionname." doesn't exist";
								break;
							}
							$player = $args[2];
							if(!in_array($username, json_decode($region['owners'], true)) and !$this->api->ban->isOp($username)){
								$output = "You're not the owner of this region";
								break;
							}
							switch($args[0]){
								case 'removeowner':
									$owners = json_decode($region['owners'], true);
									$key = array_search($player, $owners);
									if($key === false){
										$output ='The player: '.$player." isn't a owner of this region";
										break;
									}
									unset($owners[$key]);
									$sql = $this->db->prepare("UPDATE regions SET owners = :owners WHERE ID = :ID");
									$sql->bindValue(':owners', json_encode($owners), PDO::PARAM_STR);
									$sql->bindValue(':ID', $region['ID'], PDO::PARAM_INT);
									$sql->execute();
									$output = $player." isn't a owner anymoreof the region: ".$regionname;
									break;
								case 'removemember':
									$members = json_decode($region['members'], true);
									$key = array_search($player, $members);
									if($key === false){
										$output ='The player: '.$player." isn't a member of this region";
										break;
									}
									unset($members[$key]);
									$sql = $this->db->prepare("UPDATE regions SET members = :members WHERE ID = :ID");
									$sql->bindValue(':members', json_encode($members), PDO::PARAM_STR);
									$sql->bindValue(':ID', $region['ID'], PDO::PARAM_INT);
									$sql->execute();
									$output = $player.' is now a member of the region: '.$regionname;
									break;
							}
							$this->save();
							break;
							
			case 'expand':	if(!isset($args[1])){
								$output = 'Usage: /r expand <up | down> <height>';
								break;
							}
							switch($args[1]){
								case 'up':
									if(isset($args[2]) and is_numeric($args[2])){
										$height = $args[2];
										$this->pos[$username][2][1] = $this->pos[$username][1][1] + $height;
										$output = 'You expanded the selection '.$height.' blocks up';
									}else{
										$output = 'Usage: /r expand <up | down> <height>';
									}
									break;
								case 'down':
									if(isset($args[2]) and is_numeric($args[2])){
										$height = $args[2];
										$this->pos[$username][1][1] = $this->pos[$username][1][1] - $height;
										$output = 'You expanded the selection '.$height.' blocks down';
									}else{
										$output = 'Usage: /r expand <up | down> <height>';
									}
									break;
								case 'vert':
									$this->pos[$username][1][1] = 0;
									$this->pos2[$username][1][1] = 127;
									$output = 'You expanded the selection from bedrock up to the sky';
									break;
								default:
									$output = 'Usage: /r expand <up | down> <height>';
									break;
									
							}
							break;
			
			case 'contract':if(!(isset($args[1]) and isset($args[2]) and is_numeric($args[2]))){
								$output = 'Usage: /r contract <up | down> <height>';
								break;
							}
							switch($args[1]){
								case 'up':
									if(isset($args[2]) and is_numeric($args[2])){
										$height = $args[2];
										$this->pos[$username][1][1] = $this->pos[$username][1][1] + $height;
										$output = 'You shrunk the selection '.$height.' blocks up';
									}else{
										$output = 'Usage: /r contract <up | down> <height>';
									}
									break;
								case 'down':
									if(isset($args[2]) and is_numeric($args[2])){
										$height = $args[2];
										$this->pos[$username][2][1] = $this->pos[$username][1][1] - $height;
										$output = 'You shrunk the selection '.$height.' blocks down';
									}else{
										$output = 'Usage: /r contract <up | down> <height>';
									}
									break;
								default:
									$output = 'Usage: /r contract <up | down> <height>';
									break;
									
							}
							break;
							
			default:		$output = '===[Region Commands]===';
							$output .= "/r define <id> \n";
							$output .= "/r remove <id> \n";
							$output .= "/r flag <id> <flag> <none|allow|deny> \n";
							$output .= "/r addmember <id> <player> \n";
							$output .= "/r removemember <id> <player> \n";
							$output .= "/r addowner <id> <player> \n";
							$output .= "/r removeowner <id> <player> \n";
							$output .= "/r expand <up|down> <height> \n";
							$output .= "/r expand vert\n";
							$output .= "/r contract <up|down> <height> \n";
							break;
		}
		return $output;
	}
	
	public function __destruct(){}
}
?>