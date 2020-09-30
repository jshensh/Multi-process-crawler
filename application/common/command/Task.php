<?php
namespace app\common\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

use think\facade\Env;

class Task extends Command
{
    protected function configure()
    {
        $this->setName('Task')
            // ->addArgument('name', Argument::OPTIONAL, "your name")
            // ->addOption('city', null, Option::VALUE_REQUIRED, 'city name')
            ->setDescription('爬虫任务队列');
    }

    private function debug($msg) {
        $output = new Output;
        $time = date('Y-m-d H:i:s');
        $output->writeln("[{$time}] " . $msg);
    }

    private function task($serv, $task_id, $from_id, $data, Output $output)
    {
        $this->debug("Method: {$data['method']}, Params: " . json_encode($data['params']) . ", Task ID: {$task_id}");
        $serv->atomic['working']->add();
        $maxRetry = 10;
        for ($count = 1; $count <= $maxRetry; $count++) {
            try {
                // $this->debug(var_export($serv->table['initData']->get('service'), 1));
                $className = "\\app\\common\\service\\Platform\\" . str_replace('/', '\\', $serv->table['initData']->get('service', 'value'));
                $obj = new $className($serv->table['initData']->get('baseUrl', 'value'), json_decode($serv->table['initData']->get('cookieJar', 'value'), 1));
                $recvData = call_user_func_array([$obj, $data['method']], $data['params']);
                $serv->atomic['successed']->add();
                $serv->finish($data);
                break;
            } catch (\Exception $e) {
                $this->debug("Task ID: {$task_id} Failed. Error: " . $e->getFile() . '(' . $e->getLine() . '): ' . $e->getMessage() . ". Retrying {$count} / 10.");
                if ($count >= $maxRetry) {
                    $this->debug("Task ID: {$task_id} Failed. Can't try again.");
                    $serv->atomic['failed']->add();
                    $serv->finish($data);
                }
            }
        }
    }

    private function doInit($serv, $fd, $data, $send)
    {
        if ($serv->table['initData']->get('cookieJar')) {
            return $send(['status' => 'error', 'error' => '系统已初始化']);
        }

        $className = "\\app\\common\\service\\Platform\\" . str_replace('/', '\\', $data['service']);
        if (!class_exists($className)) {
            return $send(['status' => 'error', 'error' => '找不到服务']);
        }
        $object = new $className($data['baseUrl'], $data['username'], $data['password']);

        try {
            if (!$object->login()) {
                return $send(['status' => 'error', 'error' => '登录失败']);
            }
            if (!$object->checkVersion()) {
                return $send(['status' => 'error', 'error' => '不支持导入该版本']);
            }
        } catch (\Exception $e) {
            return $send(['status' => 'error', 'error' => '未知错误']);
        }

        $serv->table['initData']->set('service', ['value' => $data['service']]);
        $serv->table['initData']->set('baseUrl', ['value' => $data['baseUrl']]);
        $serv->table['initData']->set('cookieJar', ['value' => json_encode($object->getCookieJar())]);

        $this->debug("Service {$data['service']} Initialized.");
        return $send(['status' => 'success']);
    }

    private function doGetList($serv, $data, $task, $send)
    {
        if (!$serv->table['initData']->get('cookieJar')) {
            return $send(['status' => 'error', 'error' => '系统未初始化']);
        }
        if ($serv->table['acquiredList']->count()) {
            return $send(['status' => 'error', 'error' => '已执行过 GetList']);
        }

        try {
            foreach ($data['command'] as $value) {
                if (isset($this->acquiredList[$value])) {
                    continue;
                }
                $task(['method' => $value, 'params' => []]);
                $serv->table['acquiredList']->set($value, ['value' => 0]);
            }

            return $send(['status' => 'success']);
        } catch (\Exception $e) {
            return $send(['status' => 'error', 'error' => '任务创建失败']);
        }
    }

    private function doGetDetail($serv, $data, $task, $send)
    {
        if (!$serv->table['initData']->get('cookieJar')) {
            return $send(['status' => 'error', 'error' => '系统未初始化']);
        }

        try {
            $task(['method' => $data['command'], 'params' => $data['params']]);

            return $send(['status' => 'success']);
        } catch (\Exception $e) {
            return $send(['status' => 'error', 'error' => '任务创建失败']);
        }
    }

    private function receive($serv, $fd, $from_id, $data, Output $output)
    {
        /**
         * $data = ['action' => 'init', 'service' => 'Idcsmart', 'baseUrl' => '', 'username' => '', 'password' => ''];
         * $data = ['action' => 'getList', 'command' => ['getDatacenters', 'getIpsGroupList', ...]]; // 只允许执行一次
         * $data = ['action' => 'getDetail', 'command' => 'getServerDetail', 'params' => [3707]];
         * $data = ['action' => 'stats'];
         */

        $data = json_decode($data, 1);

        $send = function($msg) use ($serv, $fd) {
            return $serv->send($fd, json_encode($msg, JSON_UNESCAPED_UNICODE) . chr(4));
        };

        $task = function($data = '') use ($serv) {
            return $serv->task($data);
        };

        switch ($data['action']) {
            case 'init':
                return $this->doInit($serv, $fd, $data, $send);
            case 'getList':
                return $this->doGetList($serv, $data, $task, $send);
            case 'getDetail':
                return $this->doGetDetail($serv, $data, $task, $send);
            case 'stats':
                $stats = [];
                foreach ($serv->atomic as $key => $value) {
                    $stats[$key] = $value->get();
                }
                $serverStats = $serv->stats();
                $stats['tasking'] = $serverStats['tasking_num'];
                return $send(['status' => 'success', 'data' => $stats]);
            default:
                return;
        }
        // $output->writeln($data);
        // $task_id = $serv->task($data);
        // $this->send($serv, $fd, $task_id);
    }

    private function finish($serv, $task_id, $data, Output $output)
    {
        // $this->debug(var_export($data, 1));
        $serv->atomic['working']->sub();
        $this->debug("Task ID: {$task_id} Finished.");
    }

    protected function execute(Input $input, Output $output)
    {
        /**
         * $initDataTable 基础配置表，用于实例化爬虫类
         * |------------------------------------|
         * | key       | value                  |
         * |------------------------------------|
         * | service   | Idcsmart               |
         * | baseUrl   | https://               |
         * | cookieJar | json_encode(cookieJar) |
         * |------------------------------------|
         */
        $initDataTable = new \Swoole\Table(4);
        $initDataTable->column('value', \Swoole\Table::TYPE_STRING, 512);
        $initDataTable->create();

        /**
         * $acquiredListTable 记录已由 getList 方法执行过的操作
         * |------------------------------|
         * | key                  | value |
         * |------------------------------|
         * | getDatacenters       | 0     |
         * | getIpsGroupList      | 0     |
         * | getIpsList           | 0     |
         * | getSwitchList        | 0     |
         * | getHardwareModelList | 0     |
         * | getPurchaseList      | 0     |
         * | getServerList        | 0     |
         * | getHardwareList      | 0     |
         * |------------------------------|
         */
        $acquiredListTable = new \Swoole\Table(16);
        $acquiredListTable->column('count', \Swoole\Table::TYPE_INT, 1);
        $acquiredListTable->create();

        $workingAtomic = new \Swoole\Atomic();
        $successedAtomic = new \Swoole\Atomic();
        $failedAtomic = new \Swoole\Atomic();

        $serv = new \Swoole\Server(Env::get('runtime_path') . 'task.sock', 0, SWOOLE_PROCESS, SWOOLE_SOCK_UNIX_STREAM);
        $serv->table = ['initData' => $initDataTable, 'acquiredList' => $acquiredListTable];
        $serv->atomic = ['working' => $workingAtomic, 'successed' => $successedAtomic, 'failed' => $failedAtomic];

        $serv->set(array('task_worker_num' => 8));

        $serv->on('receive', function($serv, $fd, $from_id, $data) use ($output) {
            $this->receive($serv, $fd, $from_id, $data, $output);
        });

        $serv->on('task', function ($serv, $task_id, $from_id, $data) use ($output) {
            $this->task($serv, $task_id, $from_id, $data, $output);
        });

        $serv->on('finish', function ($serv, $task_id, $data) use ($output) {
            $this->finish($serv, $task_id, $data, $output);
        });

        $serv->start();
    }
}