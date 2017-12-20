<?php
    /**
     * @todo tube1更新监听脚本
     */

    header("Content-type: text/html; charset=utf-8");
    set_time_limit(0);
    date_default_timezone_set('Asia/Shanghai');
    require_once __DIR__ . '/../classes/lib_message_queue.php';

    //tube1--管道, tube_group1--管道组
    $queue = isset($_SERVER["argv"][1]) ? $_SERVER["argv"][1] : 'tube_group1';
    $mq    = new MessageQueue('tube1');

    try {
        $mq->watch($queue, function($message) {

            ###
            #   消息处理逻辑
            ##

            return true;
        });
    } catch (Exception $e) {
        $mq->_log('error', 'tube1', $queue, $e->getMessage(), "scripts");
    }

?>