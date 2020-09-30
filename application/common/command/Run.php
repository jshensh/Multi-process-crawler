<?php
namespace app\common\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

use think\facade\Env;

use app\common\service\Platform\Idcsmart;
use app\common\service\TelnetClient\TelnetClient;

class Run extends Command
{
    protected function configure()
    {
        $this->setName('Run')
            // ->addArgument('name', Argument::OPTIONAL, "your name")
            // ->addOption('city', null, Option::VALUE_REQUIRED, 'city name')
            ->setDescription('抓取所有信息');
    }

    protected function execute(Input $input, Output $output)
    {
        try {
            function readData($sock) {
                $data = $sock->read(chr(4));
                $data = substr($data, 0, strlen($data) - 1);
                return json_decode($data, 1);
            }

            $sock = new TelnetClient('unix://' . Env::get('runtime_path') . '/task.sock', 0, 30);
            $sock->write(json_encode(['action' => 'init', 'service' => 'Idcsmart', 'baseUrl' => '', 'username' => '', 'password' => '']));
            var_dump(readData($sock));

            $sock->write(
                json_encode([
                    'action'  => 'getList',
                    'command' => [
                        'getServerList', 'getDatacenters', 'getIpsGroupList', 'getIpsList', 'getSwitchList', 'getHardwareModelList', 'getPurchaseList', 'getHardwareList'
                    ]
                ])
            );
            var_dump(readData($sock));

            while (true) {
                $sock->write(json_encode(['action' => 'stats']));
                $stats = readData($sock);
                $output->writeln("working: {$stats['data']['working']}, successed: {$stats['data']['successed']}, failed: {$stats['data']['failed']}, tasking: {$stats['data']['tasking']}");
                if ($stats['data']['working'] === 0 && $stats['data']['tasking'] === 0 ) {
                    break;
                }
                sleep(1);
            }

            $files = ['datacenters' => ['getCabinetList'], 'switchs' => ['getSwitchDetail', 'getSwitchPortList'], 'servers' => ['getServerDetail']];
            $data = [];

            foreach ($files as $file => $method) {
                $data[$file] = json_decode(file_get_contents(Env::get('runtime_path') . "data/{$file}.json"), 1);
                foreach ($data[$file] as $key => $value) {
                    foreach ($method as $do) {
                        $sock->write(json_encode(['action' => 'getDetail', 'command' => $do, 'params' => [$key]]));
                        // var_dump(readData($sock));
                        readData($sock);
                    }
                }
            }

            while (true) {
                $sock->write(json_encode(['action' => 'stats']));
                $stats = readData($sock);
                $output->writeln("working: {$stats['data']['working']}, successed: {$stats['data']['successed']}, failed: {$stats['data']['failed']}, tasking: {$stats['data']['tasking']}");
                if ($stats['data']['working'] === 0 && $stats['data']['tasking'] === 0 ) {
                    break;
                }
                sleep(1);
            }
        } catch (\Exception $e) {
            $output->writeln($e->getFile() . '(' . $e->getLine() . '): ' . $e->getMessage());
        }
    }
}
