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

class CurlTest extends Command
{
    protected function configure()
    {
        $this->setName('CurlTest')
            // ->addArgument('name', Argument::OPTIONAL, "your name")
            // ->addOption('city', null, Option::VALUE_REQUIRED, 'city name')
            ->setDescription('开发测试使用');
    }

    protected function execute(Input $input, Output $output)
    {
        try {
            $idcsmart = new Idcsmart('https://example.com:28081', '', '');
            // $idcsmart->login();

            // file_put_contents(Env::get('root_path') . 'output/hardware.json', json_encode($idcsmart->getHardwareList(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            // $output->writeln('Hardware OK');

            // file_put_contents(Env::get('root_path') . 'output/datacenters.json', json_encode($idcsmart->getDatacenters(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            // $output->writeln('Datacenters OK');

            // file_put_contents(Env::get('root_path') . 'output/ipsGroup.json', json_encode($idcsmart->getIpsGroupList(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            // $output->writeln('IpsGroup OK');

            // file_put_contents(Env::get('root_path') . 'output/ipblock.json', json_encode($idcsmart->getIpsList(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            // $output->writeln('Ipblock OK');

            // file_put_contents(Env::get('root_path') . 'output/cabinet(id_1).json', json_encode($idcsmart->getCabinetList(1), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            // $output->writeln('Cabinet Detail OK');

            // file_put_contents(Env::get('root_path') . 'output/switch.json', json_encode($idcsmart->getSwitchList(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            // $output->writeln('Switch OK');

            // file_put_contents(Env::get('root_path') . 'output/switch_port(id_193).json', json_encode($idcsmart->getSwitchPortList(193), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            // $output->writeln('Switch Port List OK');

            // // var_dump($idcsmart->getHardwareModelList()); // 我方系统没有该功能

            // file_put_contents(Env::get('root_path') . 'output/purchase.json', json_encode($idcsmart->getPurchaseList(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            // $output->writeln('Purchase OK');

            // file_put_contents(Env::get('root_path') . 'output/server.json', json_encode($idcsmart->getServerList(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            // $output->writeln('Server OK');

            // file_put_contents(Env::get('root_path') . 'output/server(id_970).json', json_encode($idcsmart->getServerDetail(970), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            // $output->writeln('Server Detail OK');

            file_put_contents(Env::get('runtime_path') . "data/all.json", json_encode($idcsmart->getData(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $output->writeln($e->getFile() . '(' . $e->getLine() . '): ' . $e->getMessage());
        }
    }
}