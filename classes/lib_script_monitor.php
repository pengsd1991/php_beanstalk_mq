<?php
class ScriptMonitor
{
    /**
     * 监听脚本集
     * @var array
     */
    private $_scripts;

    /**
     * 发送邮件间隔
     * @var integer
     */
    private $_interval;

    /**
     * 运行计数，小于等于 $_interval
     * @var string
     */
    private $_runCount;

    /**
     * 监听开始时间
     * @var string
     */
    private $_startTime;

    const SCRIPT_STOP    = 0;
    const SCRIPT_RUNNING = 1;
    const SCRIPT_ERROR   = -1;


    /**
     * __construct
     * @param integer $interval
     */
    public function __construct($interval=60)
    {
        $this->_runCount = 0;
        $this->_interval = intval($interval);
        $this->_startTime = date('Y-m-d H:i:s');
    }


    public function detectScripts($scripts, $daemon)
    {
        try {
            $this->_runCount = ($this->_runCount + 1) % $this->_interval;
            $this->log($this->_runCount);
            if (is_null($this->_scripts)) {
                foreach ($scripts as $hash => $script) {
                    $this->addScript($hash, $script);
                }
                return;
            }

            $_scripts = array();
            foreach ($this->_scripts as $_script) {
                $_scripts[] = $_script['script'];
            }

            // 新增
            $newScripts = array_diff($scripts, $_scripts);
            foreach ($newScripts as $hash => $script) {
                $this->addScript($hash, $script);
                unset($scripts[$hash]);
            }

            foreach ($scripts as $hash => $script) {
                $pids = $daemon->findPids($script);
                // 监听脚本启动失败
                if (!$pids && isset($this->_scripts[$hash])) {
                    $this->_scripts[$hash]['status'] = self::SCRIPT_ERROR; // 脚本启动失败
                    continue;
                }
                // 监听脚本启动
                if ($pids && !isset($this->_scripts[$hash]))
                    $this->addScript($hash, $script);

                $this->_scripts[$hash]['status'] = self::SCRIPT_RUNNING;
            }

            // 停止
            $stopScripts = array_diff(array_keys($this->_scripts), array_keys($scripts));
            foreach ($stopScripts as $key => $hash) {
                $this->_scripts[$hash]['status'] = self::SCRIPT_STOP;
            }

            if ($this->_runCount == 2) {
                $this->sendMail();
                $this->clearScripts();
            }
        } catch (Exception $e) {}
    }


    private function clearScripts()
    {
        foreach ($this->_scripts as $hash => $script) {
            if ($script['status'] == self::SCRIPT_STOP)
                unset($this->_scripts[$hash]);
        }
    }

    private function addScript($key, $script)
    {
        $this->_scripts[$key]['script'] = $script;
        $this->_scripts[$key]['status'] = null;
        $this->log('new script: ' . $script);
    }

    //发送邮件
    private function sendMail() {
        #
        #
    }


    public function log($message)
    {
        //$file = '/tmp/mq_monitor.log';
        //file_put_contents($file, date('Y-m-d H:i:s ').$message."\n", FILE_APPEND);
    }
}
