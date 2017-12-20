本项目实现基于beanstalk的php消息队列服务，包括生产与消费消息案例，使用简介如下：
	1、首先安装beanstalk服务，http://kr.github.io/beanstalkd/download.html
	2、php需安装pcntl_fork，支持多线程
	3、修改config中配置,运行日志目录等
	4、启动守护进程daemon.php，启动方式文件头部有说明
	5、查看守护进程是否启动 ps aux|grep daemon  查看wokers是否启动 ps aux|grep workers
	6、生产消息方法，引入类lib_message_queue.php
		// 生产单条消息,goods管道组
 		$mq = new MessageQueue();
 		$mq->product('goods', 111111);
 		// 生产多条消息
 		$mq->product('goods', array(111111, 111112));

文件说明：
	daemon.php  守护进程主脚本，带起workers里的消费脚本
	wokers  	消费脚本目录
	config		配置信息
	classes		beanstalk 操作的相关类

