<?php


/**
 * 消息生产/接收类
 * @example
 *  // 生产单条消息,goods管道组
 *  $mq = new MessageQueue();
 *  $mq->product('goods', 111111);
 *  // 生产多条消息
 *  $mq->product('goods', array(111111, 111112));
 *
 *  // 消息队列监听处理脚本,goods管道组,solr管道
 *  <?php
 *      $mq = new MessageQueue('solr');
 *      $mq->watch('goods', function($message) {
 *          $goods_id = intval($message);
 *          // 以下为具体业务处理逻辑
 *          // 
 *          // ...
 *          // 返回true表示已处理完毕，服务器将删除该条消息
 *          return true;
 *      });
 */

require_once dirname(__DIR__).'/BeanstalkClient.php';

class MessageQueue {

    // 订阅者ID
    private $_clientID = null;

    // 订阅者清单
    private $_subscribers = array();

    // beanstalkd连接配置信息
    private $_beanstalkdConfig = array();

    /**
     * beanstalk client
     * @var BeanstalkClient
     */
    private $_beanstalk = null;

    /**
     * 初始化消息客户端
     * @param string $clientID 分配给消息接受端的ID标识
     */
    public function __construct($clientID = null) {
        $this->_clientID = $clientID;
        $this->_setConfig();
    }

    /**
     * 生产消息, 对管道内的所有事件推送消息
     * @param string $queue 队列名 -- 管道组
     * @param [string|array] $messages 消息内容，多条使用数组
     */
    public function product($queue, $messages) {
        try {
            if(!isset($this->_subscribers[$queue])) {
                throw new Exception('queue of "'.$queue.'" havn\'t configured, '
                    .'go '.__DIR__.'/../config/params.php and configure it');
            }
            $beanstalk = $this->getBeanstalkClient();
            if(!is_array($messages)) {
                $messages = array($messages);
            }
            foreach($this->_subscribers[$queue] as $clientID) {
                $beanstalk->useTube($queue.'.'.$clientID);
                foreach($messages as $message) {
                	if(strlen($message)){
                		$beanstalk->put(11, 0, 60, $message);
                		$this->_log('product', $queue, $clientID, $message);
                	}
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        return true;
    }

    /**
     * 根据队列名和事件名投递消息, 只对指定管道和事件推送消息
     * @param  [string] $queue   [队列名]
     * @param  [string] $event   [事件名]
     * @param  [string] $message [消息内容]
     * @param  [int] $delay [延时时间]
     * @return [void]
     */
    public function product_conf($queue, $event, $message, $delay = 0) {
        $beanstalk = $this->getBeanstalkClient();
        $beanstalk->useTube("{$queue}.{$event}");
        $beanstalk->put(11, $delay, 60, $message);
    }

    /**
     * 监听队列并处理消息
     * @param string $queue 订阅的队列名
     * @param function $callback 回调方法(消息处理函数，会将消息内容作为参数给$callback)
     */
    public function watch($queue, $callback) {
        try {

            if(!$this->_checkQueueExist($queue, $this->_clientID)) {
            	$this->_log('checkQueue', $queue, $this->_clientID, '');
                throw new Exception($this->_clientID.' is not allow to access this queue');
            }
            if(!is_object($callback)) {
            	$this->_log('isObject', $queue, $this->_clientID,'');
                throw new Exception('param of callback is not a function');
            }
            $this->_beanstalkdConfig['persistent'] = false;
            $beanstalk = new BeanstalkClient($this->_beanstalkdConfig);
            $beanstalk->connect();
            $beanstalk->watch($queue.'.'.$this->_clientID);
            $retry = 0;
            for(;;) {
                $job = $beanstalk->reserve();
                if($job) {
                    $result = $callback($job['body']);
                    //处理任务
                    if(true === $result) {
                        $beanstalk->delete($job['id']);
                        $this->_log('consume', $queue, $this->_clientID, $job['body']);
                    }else{
                        $beanstalk->bury($job['id'],'');
                        $this->_log('bury', $queue, $this->_clientID, $job['body']);
                    }
                } else {
                    $this->_log('error', $queue, $this->_clientID, $job['body']);
                    // 设置 error_reporting(0) 时watcher脚本会陷入死循环，这里设置重连
                    if ($retry++ >= 10) {
                      $retry = 0;
                      $this->_log('error', $queue, $this->_clientID, 'try to reconnect.');
                        sleep(5); // 等待beanstalkd服务恢复
                        $beanstalk->connect();
                        $beanstalk->watch($queue.'.'.$this->_clientID);
                    }
                }
            }
            $beanstalk->disconnect();
        } catch (Exception $e) {
        	$this->_log('error', $queue, $this->_clientID,'');
            throw new Exception($e->getMessage());
        }
    }


    /**
     * 初始化配置信息
     */
    private function _setConfig() {
        $config = require dirname(__DIR__).'/config/params.php';
        $this->_subscribers = $config['subscribers'];
        $this->_beanstalkdConfig = $config['beanstalkd'];
    }

    /**
     * 检查当前客户端监听的队列是否存在
     * @param string $queue 队列名
     * @param string $clientID 客户端ID
     * @return boolean
     */
    private function _checkQueueExist($queue, $clientID) {
        return isset($this->_subscribers[$queue]) && in_array($clientID, $this->_subscribers[$queue]);
    }

    /**
     * 获取beanstalk client
     * @param  array  $config 连接配置
     * @return BeanstalkClient
     */
    private function getBeanstalkClient()
    {
        if (is_null($this->_beanstalk)) {
            $this->_beanstalk = new BeanstalkClient($this->_beanstalkdConfig);
            $this->_beanstalk->connect();
        }
        try {
            // 检查连接
            $this->_beanstalk->stats();
        } catch (Exception $e) {
            // 若出错则重连
            $this->_beanstalk->connect();
        }
        return $this->_beanstalk;
    }

    /**
     * 记录日志
     * @param string $operation 操作类型
     * @param string $queue 队列名 -- 管道组
     * @param string $clientID 客户端ID -- 管道
     * @param string $message 消息体
     */
    public function _log($operation, $queue, $clientID, $message, $folder = 'mqlog') {
    	$dir = MQ_LOG_PATH;
        (file_exists($dir) && is_dir($dir)) || mkdir($dir, 0777, true);
        $file = $dir.'/'.$queue.'.'.$clientID.'.log';
        $mode = (is_file($file) && filesize($file)/1024/1024 < 20) ? "ab+" : "wb"; // 日志大于20M则清空, 微分销系统稳定之前先手动清log
        $fp = fopen($file , $mode);
        if(flock($fp , LOCK_EX)){
            fwrite($fp , '['.date('Y-m-d H:i:s').'] '.$operation.': '.$message.PHP_EOL);
            flock($fp , LOCK_UN);
            @chmod($file, 0777);
        }
        fclose($fp);
    }

    /**
     * destruct
     * disconnect
     */
    public function __destruct()
    {
        if (!is_null($this->_beanstalk)) {
            $this->_beanstalk->disconnect();
        }
    }
}
