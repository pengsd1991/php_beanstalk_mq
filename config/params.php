<?php
return array(
        'subscribers' => array(    // 消息订阅者清单
            'tube_group1' => array(      // 商品更新 -- 管道组
                'tube1',            // 通知对象 -- 管道1
                'tube2',    // 通知对象 -- 管道2
            ),
    ),
    'watchScripts' => array(   // 监听脚本清单,全路径
        '/全路径/php_beanstalk_mq/workers/solr_watch.php',  // worke
    ),
    'mqAddressee' => array(    // 邮件函数未实现消息队列报错通知邮件清单
        'pengsidong@gmail.com',
    ),
    'beanstalkd' => array(     // beanstalkd服务配置
        'persistent' => true,  // 是否保持长连接
        'host' => '192.168.2.231', // ip地址
        'port' => 11300,       // 端口号
        'timeout' => 3,        // 连接超时时间
    ),
);