基于 ThinkPHP 5.1 与 Swoole Task 开发的多进程爬虫
===============

### 简介

第一个多进程应用

工作相关，赶时间写得比较烂，不过能用

### 目前存在的问题

向队列推送大量任务时，Task 会过载，详见 [Swoole #8](https://github.com/swoole/rfc-chinese/issues/8)

因此产生的问题为：过载时添加的任务将放至队尾依次执行，当正常添加的任务运行完后，并发骤降，无法以设置的 ``task_worker_num`` 运行。

### 启动队列

```php
php think Task 2>&1 | tee runtime/log.log
```

### 启动抓取进程

```php
php think Run
```

### 调试爬虫类

```php
php think CurlTest
```