<?php
namespace App\Service;


use App\Entity\MassFood;
use App\Entity\User;
use App\Entity\Food;
use App\Entity\Virus;
use App\Entity\TreePoint;
use App\Entity\Vector;
use App\Entity\Cell;

use \SAT\Entity\Circle as C;
use \SAT\Entity\Vector as V;
use \SAT\Collision;
//use \SAT\Entity\Response as Response;

class GameLogicService
{
    private $ifRun;
    private $socketServer;

    private $moveloopId;
    private $gameloopId;
    private $sendUpdatesId;

    private $userList;
    private $food;
    private $virus;
    private $massFood;
    private $tmpUser;

    private $initMassLog;


    private $leaderBoard;
    private $leaderBoardChanged;

    public function __construct($socketServer = null){
        $this->socketServer = $socketServer;
        $this->userList = array();
        $this->food = array();
        $this->virus = array();
        $this->massFood = array();

        $this->leaderBoard = array();
        $this->leaderBoardChanged = false;

        $this->initMassLog = log(getConf('defaultPlayerMass'), getConf('slowBase'));
        $this->ifRun = false;
    }

    public function run(){
        $this->moveloopId = \swoole_timer_tick(1000 / 60, function(){$this->moveloop();});
        $this->gameloopId = \swoole_timer_tick(1000, function(){$this->gameloop();});
        $this->sendUpdatesId = \swoole_timer_tick(1000 / getConf('networkUpdateFactor'), function(){$this->sendUpdates();});

        $this->ifRun = true;
    }

    private function send($action, $data, $fd){
        $arr = array(
            'action' => $action,
            'data' => $data
        );
        return $this->socketServer->push($fd, json_encode($arr));
    }

    private function broadcast($action, $data, $except = null){
        foreach($this->userList as &$user){
            if($except && $user->id == $except){
                continue;
            }
            $this->send($action, $data, $user->id);
        }
    }

    private function close($fd){
        return $this->socketServer->close($fd);
    }

    /**
     * 实际处理客户端请求
     * @param $action
     * @param $data
     * @param $fd
     */
    public function deal($action, $data, $fd){
        switch($action) {
            case 'query':
                $index = findIndex($this->userList, $fd);
                if ($index > -1) {
                    return;
                }
                echo 'A user connected! ' . $data['type']."\n";
                $type = $data['type'];
                $this->tmpUser[$fd] = new User($type, $fd, "", $this->userList);
                $this->send('ready', null , $fd);
                break;
            case 'respawn':
                if ($index = findIndex($this->userList, $fd) > -1) {
                    array_splice($this->userList, $index, 1);
                }
                $this->send('welcome', object_to_array($this->tmpUser[$fd]), $fd);
                echo '[INFO] User ' . $this->tmpUser[$fd]->name . " respawned!\n";
                break;
            case 'gotit':
                if (findIndex($this->userList, $data['id']) > -1) {
                    $this->close($fd);
                    //TODO nick name validate
                } else {
                    $this->tmpUser[$data['id']]->name = $data['name'];
                    $this->tmpUser[$data['id']]->screenWidth = $data['screenWidth'];
                    $this->tmpUser[$data['id']]->screenHeight = $data['screenHeight'];
                    $this->tmpUser[$data['id']]->target = new Vector($data['target']['x'], $data['target']['y']);
                    $this->userList[] = &$this->tmpUser[$data['id']];
                    $this->send('playerJoin', array('name' => $data['name'],), $fd);
                    $this->send('gameSetup', array('gameWidth' => getConf('gameWidth'), 'gameHeight' => getConf('gameHeight')), $fd);
                }
                break;
            case 'ping':
                $this->send('pong', array(), $fd);
                break;
            case 'windowResized':
                $this->tmpUser[$fd]->screenWidth = $data['screenWidth'];
                $this->tmpUser[$fd]->screenHeight = $data['screenHeight'];
                break;
            case 'disconnect':
                if (($index = findIndex($this->userList, $fd)) > -1) {
                    array_splice($this->userList, $index, 1);
                    $this->broadcast('playerDisconnect', array('name' => $this->tmpUser[$fd]->name));
                    unset($this->tmpUser[$fd]);
                }
                break;
            case 'playerChat':
                $_sender = preg_replace('/(<([^>]+)>)/i', '', $data['sender']);
                $_message = preg_replace('/(<([^>]+)>)/i', '', $data['message']);
                $this->broadcast('serverSendPlayerChat', array('sender'=> $_sender, 'message'=> substr($_message, 0,35)), $fd);
                break;
            case 'pass' :
                if ($data[0] === getConf('adminPass')) {
                    $this->send('serverMSG', 'Welcome back ' .$this->tmpUser[$fd]->name, $fd);
                    $this->broadcast('serverMSG', $this->tmpUser[$fd]->name.' just logged in as admin!');
                    $this->tmpUser[$fd]->admin = true;
                } else {
                    $this->send('serverMSG', 'Password incorrect, attempt logged.', $fd);
                }
                break;
            case 'kick':
                if ($this->tmpUser[$fd]->admin) {
                    $reason = '';
                    $worked = false;
                    foreach($this->userList as $k => &$user){
                        if ($user->name === $data[0] && !$user->admin && !$worked) {
                            if (count($data) > 1) {
                                for ($f = 1; $f < count($data); $f++) {
                                    if ($f === count($data)) {
                                        $reason = $reason . $data[$f];
                                    } else {
                                        $reason = $reason . $data[$f] . ' ';
                                    }
                                }
                            }
                            $this->send('serverMSG', 'User ' . $user->name . ' was kicked by ' . $this->tmpUser[$fd]->name, $fd);
                            $this->send('kick', $reason, $user->id);
                            $this->close($user->id);
                            array_splice($this->userList, $k ,1);
                            $worked = true;
                            break;
                        }
                    }
                    if (!$worked) {
                        $this->send('serverMSG', 'Could not locate user or user is an admin.', $fd);
                    }
                } else {
                    $this->send('serverMSG', 'You are not permitted to use this command.', $fd);
                }
                break;
            case '0':
                $this->tmpUser[$fd]->lastHeartBeat = time();
                if($data['x'] != $this->tmpUser[$fd]->x || $data['y'] != $this->tmpUser[$fd]->y){
                    $this->tmpUser[$fd]->target = new Vector($data['x'], $data['y']);
                }
                break;
            case '1':
                // Fire food.
                foreach($this->tmpUser[$fd]->cells as $k => &$cell)
                {
                    if((($cell->mass >= getConf('defaultPlayerMass') + getConf('fireFood')) && getConf('fireFood') > 0) || ($cell->mass >= 20 && getConf('fireFood') == 0)){
                        if(getConf('fireFood') > 0){
                            $masa = getConf('fireFood');
                        } else {
                            $masa = $cell->mass * 0.1;
                        }
                        $cell->mass -= $masa;
                        $this->tmpUser[$fd]->massTotal -= $masa;
                        $this->massFood[]= new MassFood($fd, $k,
                            $cell->x,
                            $cell->y,
                            massToRadius($masa),
                            $masa,
                            $this->tmpUser[$fd]->hue,
                            new Vector(
                                $this->tmpUser[$fd]->x - $cell->x + $this->tmpUser[$fd]->target->x,
                                $this->tmpUser[$fd]->y - $cell->y + $this->tmpUser[$fd]->target->y),
                            25);
                    }
                }
                break;
            case '2':
                if(count($this->tmpUser[$fd]->cells) < getConf('limitSplit') && $this->tmpUser[$fd]->massTotal >= getConf('defaultPlayerMass')*2) {
                    //Split single cell from virus
                    if(!is_null($data) && $data >= 0) {
                        $this->splitCell($this->tmpUser[$fd]->cells[$data], $fd);
                    } else {
                        foreach($this->tmpUser[$fd]->cells as &$cell){
                            $this->splitCell($cell, $fd);
                        }
                    }
                    $this->tmpUser[$fd]->lastSplit = time();
                }
                break;
        }
    }

    private function splitCell(&$cell, $fd)
    {
        if ($cell->mass >= getConf('defaultPlayerMass') * 2) {
            $cell->mass = $cell->mass / 2;
            $cell->radius = massToRadius($cell->mass);
            $this->tmpUser[$fd]->cells[] = new Cell($cell->x,$cell->y,$cell->radius,$cell->mass,25);
        }
    }

    private function addFood($toAdd) {
        $radius = massToRadius(getConf('foodMass'));
        while ($toAdd--) {
            $position = getConf('foodUniformDisposition') ? uniformPosition($this->food, $radius) : randomPosition($radius);
            //TODO uniqid ((new Date()).getTime() + '' + food.length) >>> 0,
            $id = 0;
            $mass = random01Float() + 2;
            $this->food[]= new Food($id, $position['x'], $position['y'], $radius, $mass,round(random01Float(0,1) * 360));
        }
    }

    private function addVirus($toAdd) {
        while ($toAdd--) {
            $mass = randomInRange(getConf('virus')['defaultMass']['from'], getConf('virus')['defaultMass']['to']);
            $radius = massToRadius($mass);
            $position = getConf('virusUniformDisposition') ? uniformPosition($this->virus, $radius) : randomPosition($radius);
            //TODO
            $id = 0;
            $fill = getConf('virus')['fill'];
            $stroke = getConf('virus')['stroke'];
            $strokeWidth = getConf('virus')['strokeWidth'];
            $this->virus[] = new Virus($id, $position['x'], $position['y'], $radius, $mass, $fill, $stroke, $strokeWidth);
        }
    }

    private function removeFood($toRem) {
        while ($toRem--) {
            array_pop($this->food);
        }
    }

    private function balanceMass(){
        //实物总质量
        $foodMass = count($this->food) * getConf('foodMass');
        //玩家总质量
        $userMass = array_reduce(array_map(function($v){return $v->massTotal;}, $this->userList),function($a,$b){return $a+$b;},0);
        //地图上玩家+食物的总质量
        $totalMass = $foodMass + $userMass;
        //与游戏总质量的差
        $massDiff = getConf('gameMass') - $totalMass;
        //还要多少能达到最大食物总数
        $maxFoodDiff = getConf('maxFood') - count($this->food);
        //还要多少能达到游戏总质量
        $foodDiff = floor($massDiff/ getConf('foodMass')) - $maxFoodDiff;
        $foodToAdd = min($foodDiff, $maxFoodDiff);
        $foodToRemove = -max($foodDiff, $maxFoodDiff);
        if ($foodToAdd > 0) {
            $this->addFood($foodToAdd);
        } elseif ($foodToRemove > 0) {
            $this->removeFood($foodToRemove);
        }

        $virusToAdd = getConf('maxVirus') - count($this->virus);

        if ($virusToAdd > 0) {
            $this->addVirus($virusToAdd);
        }
    }

    private function tickPlayer(&$currentPlayer, $key){
        if(!isset($this->userList[$key])){
            return false;
        }
        $socketId = $currentPlayer->id;
        //maxHeartbeatInterval按秒计算
        if($currentPlayer->lastHeartBeat < time() - getConf('maxHeartbeatInterval')) {
            $this->send('kick', 'Last heartbeat received over '.getConf('maxHeartbeatInterval'.' ago.'), $socketId);
            $this->close($socketId);
        }
        if($currentPlayer->type == User::TYPE_PLAYER){
            $this->movePlayer($currentPlayer);
        }

        foreach($currentPlayer->cells as $z => &$cell) {
            $playerCircle = new C(new V($cell->x, $cell->y), $cell->radius);

            $foodEatenNum = 0;
            //判断被吃掉的food
            foreach ($this->food as $k => &$f) {
                if (Collision::pointInCircle(new V($f->x, $f->y), $playerCircle)) {
                    array_splice($this->food, $k, 1);
//                    unset($this->food[$k]);
                    $foodEatenNum++;
                }
            }
//            $this->food = array_values($this->food);
            //判断有没有碰到细菌 并且可以让自己分裂(这个cell的质量大于细菌的质量)
            foreach ($this->virus as $k => &$v) {
                if (Collision::pointInCircle(new V($v->x, $v->y), $playerCircle) && $cell->mass > $v->mass) {
                    $this->send('virusSplit', $z, $socketId);
                    break;
                }
            }
            //吃掉吐出来的food
            $masaGanada = 0;
            foreach ($this->massFood as $k => &$m) {
                if (Collision::pointInCircle(new V($m->x, $m->y), $playerCircle)) {
                    if ($m->id == $currentPlayer->id && $m->speed > 0 && $z == $m->num) {
                        //DO NOTHING
                    } elseif ($cell->mass > $m->masa * 1.1) {
                        $masaGanada += $m->masa;
                        array_splice($this->massFood, $k, 1);
//                        unset($this->massFood[$k]);
                    }
                }
            }
//            $this->massFood = array_values($this->massFood);
            if ($cell->speed === null) {
                $cell->speed = 65;
            }
            $masaGanada += $foodEatenNum * getConf('foodMass');
            $cell->mass += $masaGanada;
            $currentPlayer->massTotal += $masaGanada;
            $cell->radius = massToRadius($cell->mass);
            $playerCircle->r = $cell->radius;


//            $tree = new \QuadTrees\QuadTree(new \QuadTrees\QuadTreeBoundingBox(new \QuadTrees\QuadTreeXYPoint(-$cell->radius, -$cell->radius), getConf('gameWidth')+$cell->radius, getConf('gameHeight')+$cell->radius));
//            foreach ($this->userList as &$user) {
//                if($user->type == User::TYPE_SPECTATE || $user->id == $currentPlayer->id){
//                    continue;
//                }
//                $p = new TreePoint($user->x, $user->y, $user);
//                $tree->insert($p);
//            }
//
////            $currentBox = new \QuadTrees\QuadTreeBoundingBox(new \QuadTrees\QuadTreeXYPoint($cell->x, $cell->y), $currentPlayer->w, $currentPlayer->h);
//            $currentBox = new \QuadTrees\QuadTreeBoundingBox(new \QuadTrees\QuadTreeXYPoint($cell->x-$cell->radius, $cell->y-$cell->radius), 2*$cell->radius, 2*$cell->radius);
//            $other = $tree->search($currentBox);
            $playerCollisions = array();
            //遍历与当前玩家的当前cell碰撞的玩家的cells
            foreach ($this->userList as &$u) {
                if($u->id == $currentPlayer->id){
                    continue;
                }
                foreach ($u->cells as $i => &$cell2) {
                    if ($cell2->mass > 10) {
//                        $response = new Response();
                        $collided = Collision::testCircleCircle($playerCircle, new C(new V($cell2->x, $cell2->y), $cell2->radius));
                        //如果碰撞了记录一下 aUser是当前玩家的当前细胞 bUser是要被吃掉的细胞
                        if ($collided) {
                            $playerCollisions[] = array(
//                                'response' => $response,
                                'aUser' => $cell,
                                'bUser' => array(
                                    'id' => $u->id,
                                    'name' => $u->name,
                                    'x' => $cell2->x,
                                    'y' => $cell2->y,
                                    'num' => $i,
                                    'mass' => $cell2->mass
                                )
                            );
                        }
                    }
                }
            }
//            unset($other);
            foreach ($playerCollisions as &$collision) {
                //如果当前玩家的当前细胞的质量大于1.1倍的可以被吃掉的细胞质量 并且半径大于圆心距离的1.75倍 才能吃掉
                if ($collision['aUser']->mass > $collision['bUser']['mass'] * 1 && $collision['aUser']->radius > sqrt(pow($collision['aUser']->x - $collision['bUser']['x'], 2) + pow($collision['aUser']->y - $collision['bUser']['y'], 2)) * 1) {
                    $numUser = findIndex($this->userList, $collision['bUser']['id']);
                    if ($numUser > -1) {
                        if (count($this->userList[$numUser]->cells) > 1) {
                            $this->userList[$numUser]->massTotal -= $collision['bUser']['mass'];
                            array_splice($this->userList[$numUser]->cells, $collision['bUser']['num'], 1);
//                            unset($this->userList[$numUser]->cells[$collision['bUser']['num']]);
                        } else {
                            array_splice($this->userList, $numUser, 1);
//                            unset($this->userList[$numUser]);
                            $this->send("playerDied", array(
                                'name' => $collision['bUser']['name']
                            ),$socketId);
                            $this->send("RIP", null, $collision['bUser']['id']);
                        }
                    }
                    $currentPlayer->massTotal += $collision['bUser']['mass'];
                    $collision['aUser']->mass += $collision['bUser']['mass'];
                }
            }
            unset($playerCollisions);
//            unset($tree);
        }
    }

    private function movePlayer(&$player){
        $x=0;
        $y=0;
        foreach($player->cells as $i => &$cell)
        {
            //目标向量
            $target = array(
                'x'=> $player->x - $cell->x + $player->target->x,
                'y'=> $player->y - $cell->y + $player->target->y
            );
            //距离
            $dist = sqrt(pow($target['y'], 2) + pow($target['x'], 2));
            //弧度
            $deg = atan2($target['y'], $target['x']);
            $slowDown = 1;
            if($cell->speed <= 6.25) {
                //质量越大 就越要减速
                $slowDown = log($cell->mass, getConf('slowBase')) - $this->initMassLog + 1;
            }
            $deltaY = $cell->speed * sin($deg)/ $slowDown;
            $deltaX = $cell->speed * cos($deg)/ $slowDown;

            //大于一定的速度 就要开始减速了
            if($cell->speed > 6.25) {
                $cell->speed -= 0.5;
            }
            //继续修正速度
            if ($dist < (50 + $cell->radius) && $cell->radius != 50) {
                $deltaY *= $dist / (50 + $cell->radius);
                $deltaX *= $dist / (50 + $cell->radius);
            }
            //细胞的新位置
            $cell->y += $deltaY;
            $cell->x += $deltaX;
            // Find best solution. 遍历所有细胞
            foreach($player->cells as $j => &$cell2) {
                if(isset($player->cells[$j]) && $cell2 != $cell) {
                    //两个细胞的圆心距离
                    $distance = sqrt(pow($cell2->y - $cell->y,2) + pow($cell2->x - $cell->x,2));
                    //两个细胞的半径合
                    $radiusTotal = $cell->radius + $cell2->radius;
                    //如果距离小于半径合 说明碰到了
                    if($distance < $radiusTotal) {
                        //如果玩家上次分裂时间 还挺近的 说明是刚分裂需要扩散
                        if($player->lastSplit > time() -  getConf('mergeTimer')) {
                            if($cell->x < $cell2->x) {
                                $cell->x--;
                            } else if($cell->x > $cell2->x) {
                                $cell->x++;
                            }
                            if($cell->y < $cell2->y) {
                                $cell->y--;
                            } else if(($cell->y > $cell2->y)) {
                                $cell->y++;
                            }
                        }//可以合并细胞啦！
                        else if($distance < $radiusTotal / 1.75) {
                            $cell->mass += $cell2->mass;
                            $cell->radius = massToRadius($cell->mass);
                            //j号细胞被i吃掉了
//                            unset($player->cells[$j]);
                            array_splice($player->cells, $j ,1);
                        }
                    }
                }
            }
//            if(count($player->cells) > $i) {
                $borderCalc = $cell->radius / 3;
                if ($cell->x > getConf('gameWidth') - $borderCalc) {
                    $cell->x = getConf('gameWidth') - $borderCalc;
                }
                if ($cell->y > getConf('gameHeight') - $borderCalc) {
                    $cell->y = getConf('gameHeight') - $borderCalc;
                }
                if ($cell->x < $borderCalc) {
                    $cell->x = $borderCalc;
                }
                if ($cell->y < $borderCalc) {
                    $cell->y = $borderCalc;
                }
                $x += $cell->x;
                $y += $cell->y;
//            }
        }
//        $player->cells = array_values($player->cells);
        $player->x = $x/count($player->cells);
        $player->y = $y/count($player->cells);
    }

    private function moveMass(&$mass){
        $deg = atan2($mass->target->y, $mass->target->x);
        $deltaY = $mass->speed * sin($deg);
        $deltaX = $mass->speed * cos($deg);

        $mass->speed -= 0.5;
        if($mass->speed < 0) {
            $mass->speed = 0;
        }
        if (is_numeric($deltaY)) {
            $mass->y += $deltaY;
        }
        if (is_numeric($deltaX)) {
            $mass->x += $deltaX;
        }

        $borderCalc = $mass->radius + 5;

        if ($mass->x > getConf('gameWidth') - $borderCalc) {
            $mass->x = getConf('gameWidth') - $borderCalc;
        }
        if ($mass->y > getConf('gameHeight') - $borderCalc) {
            $mass->y = getConf('gameHeight') - $borderCalc;
        }
        if ($mass->x < $borderCalc) {
            $mass->x = $borderCalc;
        }
        if ($mass->y < $borderCalc) {
            $mass->y = $borderCalc;
        }
    }

    /**
     * 实体移动循环
     */
    private function moveloop(){
        foreach($this->userList as $k => &$user){
            $this->tickPlayer($user, $k);
        }
//        $this->userList = array_values($this->userList);
        foreach($this->massFood as &$food) {
            if($food->speed > 0) {
                $this->moveMass($food);
            }
        }
    }

    /**
     * 游戏逻辑循环
     */
    private function gameloop(){
        if (count($this->userList) > 0){
            //按质量降序 这里重新排列了key
            usort($this->userList, function(User $a, User $b){
                return $b->massTotal - $a->massTotal;
            });

            //更新排行榜
            $topUsers = array();
            $userLen = count($this->userList);
            for($i = 0; $i < min(10, $userLen); $i++){
                if($this->userList[$i]->type == User::TYPE_PLAYER){
                    $topUsers[] = array(
                        "id" => $this->userList[$i]->id,
                        "name" => $this->userList[$i]->name,
                    );
                }
            }
            if(empty($this->leaderBoard) || count($this->leaderBoard) != count($topUsers)){
                $this->leaderBoardChanged = true;
                $this->leaderBoard = $topUsers;
            }else {
                $leaderLen = count($this->leaderBoard);
                for ($i = 0; $i < $leaderLen; $i++) {
                    if ($this->leaderBoard[$i]['id'] != $topUsers[$i]['id']) {
                        $this->leaderBoard = $topUsers;
                        $this->leaderBoardChanged = true;
                        break;
                    }
                }
            }
            //质量流失
            for ($i = 0; $i < $userLen; $i++) {
                //遍历每个玩家所有的细胞
//                $cellLen = count($this->userList[$i]->cells);
//                for($z = 0; $z < $cellLen; $z++) {
                foreach($this->userList[$i]->cells as $z => &$cell){
                    //当前细胞质量流失以后>默认玩家质量 && 当前细胞质量 > 可以流失质量下限 则进行流失
                    if ($cell->mass * (1 - (getConf('massLossRate') / 1000)) > getConf('defaultPlayerMass') && $this->userList[$i]->massTotal > getConf('minMassLoss')) {
                        //流失以后的质量
                        $massLost = $cell->mass * (1 - (getConf('massLossRate') / 1000));
                        $this->userList[$i]->massTotal -= $cell->mass - $massLost;
                        $cell->mass = $massLost;
                    }
                }
            }
        }
        $this->balanceMass();
    }


    /**
     * 发送更新游戏内容循环
     */
    private function sendUpdates(){
        foreach($this->userList as $k => &$user){
            //如果是旁观的话，会有这种情况
            is_null($user->x) && $user->x = getConf('gameWidth')/2;
            is_null($user->y) && $user->y = getConf('gameHeight')/2;

            $visibleFood = array_filter(
                array_map(function($f) use (&$user){
                    if ( $f->x > $user->x - $user->screenWidth/2 - 20 &&
                        $f->x < $user->x + $user->screenWidth/2 + 20 &&
                        $f->y > $user->y - $user->screenHeight/2 - 20 &&
                        $f->y < $user->y + $user->screenHeight/2 + 20) {
                        return $f;
                    }
                }, $this->food), function($f){
                return $f;
            }
            );
            $visibleVirus  = array_filter(
                array_map(function($f) use (&$user){
                    if ( $f->x > $user->x - $user->screenWidth/2 - $f->radius &&
                        $f->x < $user->x + $user->screenWidth/2 + $f->radius &&
                        $f->y > $user->y - $user->screenHeight/2 - $f->radius &&
                        $f->y < $user->y + $user->screenHeight/2 + $f->radius) {
                        return $f;
                    }
                }, $this->virus), function($f){
                return $f;
            }
            );
            $visibleMass = array_filter(
                array_map(function($f) use (&$user){
                    if ( $f->x+$f->radius > $user->x - $user->screenWidth/2 - 20 &&
                        $f->x-$f->radius < $user->x + $user->screenWidth/2 + 20 &&
                        $f->y+$f->radius > $user->y - $user->screenHeight/2 - 20 &&
                        $f->y-$f->radius < $user->y + $user->screenHeight/2 + 20) {
                        return $f;
                    }
                }, $this->massFood), function($f){
                return $f;
            }
            );
            $visibleCells  = array_filter(
                array_map(function($f) use(&$user){
                    foreach($f->cells as &$cell)
                    {
                        if ( $cell->x+$cell->radius > $user->x - $user->screenWidth/2 - 20 &&
                            $cell->x-$cell->radius < $user->x + $user->screenWidth/2 + 20 &&
                            $cell->y+$cell->radius > $user->y - $user->screenHeight/2 - 20 &&
                            $cell->y-$cell->radius < $user->y + $user->screenHeight/2 + 20) {
                            if($f->id != $user->id) {
                                return array(
                                    'id' => $f->id,
                                    'x' => $f->x,
                                    'y' => $f->y,
                                    'cells' => &$f->cells,
                                    'massTotal' => round($f->massTotal),
                                    'hue' => $f->hue,
                                    'name' => $f->name
                                );
                            } else {
                                return array(
                                    'x' => $f->x,
                                    'y' => $f->y,
                                    'cells' => &$f->cells,
                                    'massTotal' => round($f->massTotal),
                                    'hue' => $f->hue,
                                );
                            }
                        }
                    }
                }, $this->userList),
                function($f) {
                    return $f;
                }
            );
            $visibleCells = array_values($visibleCells);
            $visibleFood = array_values($visibleFood);
            $visibleMass = array_values($visibleMass);
            $visibleVirus = array_values($visibleVirus);

            $this->send('serverTellPlayerMove', object_to_array(array(
                $visibleCells, $visibleFood, $visibleMass, $visibleVirus
            )) ,$user->id);

            if ($this->leaderBoardChanged) {
                $this->send('leaderboard', array(
                    'players' => count($this->userList),
                    'leaderboard' => $this->leaderBoard
                ), $user->id);
            }
        }
        $this->leaderBoardChanged = false;
    }


    public function isRunning(){
        return $this->ifRun;
    }
}