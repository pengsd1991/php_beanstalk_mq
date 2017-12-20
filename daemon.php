<?php

/**
 * 脚本调度管理常驻脚本
 * 
 * 该脚本功能为：管理所有消息订阅脚本，负责它们的启动和终止。
 * 脚本会动态读取订阅配置信息(配置信息在/config/params.php的subscribers中)，
 * 如果配置有更改，则调度管理器也会实时进行更新：比如删除了某一项配置后，对应的正在运行的脚本进程也会随之终止；
 * 如有增加的配置项，脚本调度器在下一轮调度过程中就会尝试为它们创建独立的子进程。调度器在监视的过程中会检查每个脚本的进程实例，
 * 如果有多个相同的脚本正在运行，则会杀死并保留最近运行的那个脚本进程。
 * 为防止子进程长时间运行出现僵死状态，守护进程会每隔一段时间(1小时)杀死正在运行的所有子进程，并重新创建，以刷新它们的运行状态
 * 
 * 该调度脚本请以全路径方式启动(见@tutorial)，否则无法检测自身是否已有运行副本
 * @tutorial nohup /usr/local/php/bin/php /全路径／daemon.php > /dev/null 2>&1 &
 */

require_once __DIR__ . '/classes/lib_script_monitor.php';
require_once __DIR__ . '/config/define.php';

class Daemon{

    private $_startTime; // 脚本启动时间
    private $_runnigScripts = array(); // 当前启动的脚本清单
    private $_lastKillAllTime; // 上一次杀死全部脚本时间

    /**
     * 监听器
     * @var object
     */
    private $_monitor;

    const POLLING_INTERVAL_TIME = 5; // 守护脚本轮询时间间隔
    const CHILDREN_RESTART_FREQUENCTY = 3600; // 脚本子进程重启频率(单位：秒)

    public function run() {
        date_default_timezone_set('Asia/Chongqing');
        $this->printHelp();
        $this->showMessage('initializing script ...');

        $this->_startTime = $this->_lastKillAllTime = time();
        $this->_monitor = new ScriptMonitor(120);

        // 检查自身是否正在运行
        ($pid = $this->checkSelfIsRunning()) && die($this->showMessage('already running, pid is: '.$pid));

        // 启动时强制杀死所有子脚本进程
        $this->killAllRunning();

        pcntl_signal(SIGCHLD, SIG_IGN); // 显式声明不关心子进程的结束，防止尸变

        // ps -ef | grep defunct | grep -v grep | wc -l 统计僵尸进程数
        for(;;) {
            $scripts = $this->getScripts(); // 动态读取配置，使后来订阅者立即生效
            $this->checkIfKillRunning($scripts);

            $this->_runnigScripts = $scripts;

            $this->printStatus();
            $this->_monitor->detectScripts($scripts, $this);

            foreach($scripts as $script) {
                $this->checkIsRunning($script) || $this->forkChildProcess($script);
            }

            sleep(self::POLLING_INTERVAL_TIME);

            $this->checkRestartRunning(); // 隔段时间杀死所有脚本子进程，防止长时间运行后脚本僵死
        }
    }

    /**
     * 打印主进程及子进程运行状态
     */
    protected function printStatus() {
        $message = PHP_EOL.str_repeat('==', 15).' daemon is watching '.str_repeat('==', 15);
        $message .= PHP_EOL.'main pid: '.  getmypid();
        $message .= ', memory used: '.(memory_get_usage(true)/1024/1024).'M';
        $message .= ', started at: '.date('Y-m-d H:i:s', $this->_startTime);
        $message .= PHP_EOL.'current runing scripts:';
        foreach($this->_runnigScripts as $script) {
            $pids = $this->findPids($script);
            $message .= PHP_EOL.'['.($pids ? 'pid: '.$pids[0] : 'awaking...').'] '.$script;
        }
        $message .= PHP_EOL.str_repeat('==', 40);
        $this->showMessage($message);
    }
    
    /**
     * 检查因配置变化决定是否需要终止相应的子进程
     * @param array $scripts
     */
    protected function checkIfKillRunning(array $scripts) {
        $needTokills = array_diff($this->_runnigScripts, $scripts);
        foreach($needTokills as $script) {
            $pids = $this->findPids($script);
            if(!$pids) continue;
            $this->showMessage($script);
            foreach($pids as $pid) {
                shell_exec('kill -9 '.$pid);
                $this->showMessage('pid killed: '.$pid.' ['.$script.']');
            }
        }
    }
    
    protected function checkRestartRunning() {
        if((time() - $this->_lastKillAllTime) > self::CHILDREN_RESTART_FREQUENCTY) {
            $this->killAllRunning();
            $this->_lastKillAllTime = time();
        }
    }
    
    /**
     * 检查自身是否已有正在运行的进程
     * @return boolean
     */
    protected function checkSelfIsRunning() {
        $script = __FILE__;
        $cmd =  'ps -ef | awk \'{print $9" "$10"\t"$2}\' | grep "^'.$script.'" | grep -v grep | awk \'{print $2}\'';
        $result = shell_exec($cmd);
        if(!$result) return false;
        $pids = explode(PHP_EOL, trim($result));
        $selfPid = getmypid();
        foreach($pids as $k=>$pid) {
            if(!is_numeric($pid) || $pid == $selfPid) unset($pids[$k]);
        }
        if(count($pids) < 1) return false;
        sort($pids, SORT_NUMERIC);
        return $pids[0];
    }
    
    /**
     * 检查脚本是否在运行
     * @param string $script
     * @return boolean
     */
    protected function checkIsRunning($script) {
        $pids = $this->findPids($script);
        if(!$pids) return false;
        for($i = 0; $i < count($pids) - 1; $i++) { // 干掉历史重复多余进程，只保留最近活跃的一个进程
            shell_exec('kill -9 '.$pids[$i]);
        }
        return true;
    }
    
    protected function killAllRunning() {
        $scripts = $this->getScripts();
        foreach($scripts as $script) {
            $pids = $this->findPids($script);
            if(!$pids) continue;
            for($i = 0; $i < count($pids); $i++) { // 干掉历史重复多余进程，只保留最近活跃的一个进程
                shell_exec('kill -9 '.$pids[$i]);
                $this->showMessage('[pid:'.$pids[$i].'] script stopped: '.$script);
            }
        }
    }
    
    /**
     * 查找脚本对应的进程号
     * @param type $script
     * @return array
     */
    public function findPids($script) {
        $args = explode(' ', trim($script));
        $glues = '';
        for($i = 0; $i < count($args); $i++) {
            $glues[] = '$'.(9+$i);
        }
        $cmd = 'ps -ef | awk \'{print $2" "'.implode('" "', $glues).'}\' | grep " '.$script.'$" | awk \'{print $1}\'';
        $result = shell_exec($cmd);
        if(!$result) return false;
        $pids = explode(PHP_EOL, trim($result));
        foreach($pids as $k=>$pid) {
            if(!is_numeric($pid)) unset($pids[$k]);
        }
        sort($pids, SORT_NUMERIC);
        return $pids;
    }
    
    /**
     * 创建子进程，唤起脚本
     * @param string $script
     * @return type
     */
    protected function forkChildProcess($script) {
        $pid = pcntl_fork();
        if(-1 == $pid) {} elseif($pid) {
            $this->showMessage('[pid:'.$pid.'] script awaked: '.$script);
            /*
             * 等待子进程中断或结束，但这会导致主进程被阻塞，无法对整个订阅脚本进行有效调度管理，
             * 在这里主进程只负责创建子进程调用消息监听脚本，不等待其结束或发生异常，
             * 而监听及重新唤起监听脚本的任务在主进程中专门去完成
             * pcntl_waitpid($pid, $status);
             */
        } else {
            $status = pcntl_exec(PHP_BIN_PATH, explode(' ', $script));
            if(false === $status) {
                die('Execution failure： '.PHP_BIN_PATH.' '.$script);
                // @todo log ...
            }
        }
    }
    
    /**
     * 获取所有消息订阅脚本清单
     * @staticvar array $subscribers
     * @return array
     */
    protected function getScripts() {
        $config = require_once __DIR__.'/config/params.php';
        $watchScripts  = $config['watchScripts'];
        $scripts = array();
        foreach($watchScripts as $script) {
             if(!strlen($script)) continue;
             $scripts[md5($script)] = $script;
        }
        return $scripts;
    }
    
    /**
     * 打印脚本说明文档
     */
    protected function printHelp() {
        $this->showMessage(PHP_EOL.
        "Usage: php daemon.php".PHP_EOL.PHP_EOL.
        "Description:".PHP_EOL.
        "\t脚本调度管理脚本".PHP_EOL.PHP_EOL.
        str_repeat('==', 40).PHP_EOL);
    }
    
    protected function showMessage($message, $error = false) {
        print('[DAEMON]['.date('Y-m-d H:i:s').'] '.($error ? 'error: ' : '').$message.PHP_EOL);
    }
}

$daemon = new Daemon();
$daemon->run();