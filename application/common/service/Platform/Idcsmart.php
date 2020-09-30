<?php
namespace app\common\service\Platform;

use think\facade\Env;

use app\common\service\CustomCurl\CustomCurl;

/**
 * Idcsmart 爬虫类
 * @author  jshensh <admin@imjs.work>
 */
class Idcsmart
{
    private $baseUrl = '',
            $username,
            $password,
            $authority,
            $filePath,
            $isLogin = false,
            $cookieJar = [],
            $allowedVersion = ['4.6'];

    private function saveData($path, $obj)
    {
        return file_put_contents($path, json_encode($obj, JSON_UNESCAPED_UNICODE));
    }

    private function setData($key, $arr)
    {
        // $this->mergeData($this->data[$key], $arr);
        // return $arr;
        return [$key => $arr];
    }

    public function getData($key = '')
    {
        $path = $this->filePath;
        $mergeData = function($arr1, $arr2) use (&$mergeData) {
            $merged = $arr1;

            foreach ($arr2 as $key => &$value) {
                if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                    $merged[$key] = $mergeData($merged[$key], $value);
                } else {
                    $merged[$key] = $value;
                }
            }

            return $merged;
        };

        $getFile = [
            'datacenters'  => function() use ($path) {
                return json_decode(file_get_contents("{$path}/datacenters.json"), 1);
            },
            'ipv4_groups'  => function() use ($path) {
                return json_decode(file_get_contents("{$path}/ipv4_groups.json"), 1);
            },
            'ipv4_blocks'  => function() use ($path) {
                return json_decode(file_get_contents("{$path}/ipv4_blocks.json"), 1);
            },
            'cabinets'     => function() use ($path, $mergeData) {
                $re = [];
                foreach(glob("{$path}/cabinets/*.json") as $filename) {
                    $re = $mergeData($re, [basename($filename, '.json') => json_decode(file_get_contents($filename), 1)]);
                }
                return $re;
            },
            'switchs'      => function() use ($path, $mergeData) {
                $re = json_decode(file_get_contents("{$path}/switchs.json"), 1);
                foreach(glob("{$path}/switchs/*.json") as $filename) {
                    $re = $mergeData($re, [basename($filename, '.json') => json_decode(file_get_contents($filename), 1)]);
                }
                return $re;
            },
            'switch_ports' => function() use ($path, $mergeData) {
                $re = [];
                foreach(glob("{$path}/switchs/port/*.json") as $filename) {
                    $re = $mergeData($re, [basename($filename, '.json') => json_decode(file_get_contents($filename), 1)]);
                }
                return $re;
            },
            'part_batches' => function() use ($path) {
                return json_decode(file_get_contents("{$path}/part_batches.json"), 1);
            },
            'servers'      => function() use ($path, $mergeData) {
                $re = json_decode(file_get_contents("{$path}/servers.json"), 1);
                foreach(glob("{$path}/servers/*.json") as $filename) {
                    $re = $mergeData($re, [basename($filename, '.json') => json_decode(file_get_contents($filename), 1)]);
                }
                return $re;
            },
            'hardware'     => function() use ($path) {
                return json_decode(file_get_contents("{$path}/hardware.json"), 1);
            },
        ];

        try {
            $re = [];
            if ($key) {
                return $getFile[$key]();
            }
            foreach ($getFile as $key => $value) {
                $re[$key] = $value();
            }
            return $re;
        } catch (\Exception $e) {
            var_dump($e->getFile() . '(' . $e->getLine() . '): ' . $e->getMessage());
            return false;
        }
    }

    public function getCookieJar($key = '')
    {
        return $this->cookieJar;
    }

    private function getDomValue($str, $name = '') {
        preg_match('/<(select|input|textarea)[^>]*' . ($name ? "name=['\"]?{$name}['\"]?" : '') . '>/is', $str, $matchDom);
        if (!$matchDom) {
            return false;
        }
        if ($matchDom[1] === 'select') {
            $re = [];
            preg_match_all('/<option.*?>.*?<\/option>/', $str, $optionMatches);
            foreach ($optionMatches[0] as $value) {
                if (stripos($value, 'selected') !== false) {
                    preg_match('/value=[\'"](.*?)[\'"]/', $value, $valueMatch);
                    if ($valueMatch) {
                        $re[] = $valueMatch[1];
                    }
                }
            }
            return count($re) === 1 ? $re[0] : $re;
        }
        if ($matchDom[1] === 'input') {
            preg_match('/value=[\'"](.*?)[\'"]/', $str, $valueMatch);
            if ($valueMatch) {
                $re = $valueMatch[1];
            }
            return $re;
        }
        if ($matchDom[1] === 'textarea') {
            preg_match('/<textarea.*?>(.*?)<\/textarea>/is', $str, $valueMatch);
            if ($valueMatch) {
                $re = preg_replace('/^\n/', '', $valueMatch[1]);
            }
            return $re;
        }
        return false;
    }

    private function formatHardwareCustomFields($str, $typeid)
    {
        $foreignFieldMap = [
            '1' => [
                'customfield0' => '核心数',
                'customfield1' => '线程数',
            ],
            '2' => [
                'customfield0' => '容量',
                'customfield1' => '类型',
                'customfield2' => '电压',
                'customfield3' => '高度',
            ],
            '3' => [
                'customfield0' => '容量',
                'customfield1' => '类型',
                'customfield2' => '接口',
            ],
            '4' => [
                'customfield0' => '类型',
                'customfield1' => '端口数',
            ]
        ];
        $originFieldMap = [
            '1' => [
                '品牌' => 'brand',
                '核心' => 'customfield0',
                '线程' => 'customfield1',
            ],
            '2' => [
                '品牌'     => 'brand',
                '类型'     => 'customfield1',
                '电压'     => 'customfield2',
                '高度'     => 'customfield3',
                '容量(GB)' => 'customfield0',
            ],
            '3' => [
                '品牌'     => 'brand',
                '类型'     => 'customfield1',
                '接口'     => 'customfield2',
                '容量(Gb)' => 'customfield0',
            ],
            '4' => [
                '品牌' => 'brand',
            ]
        ];

        $re = [];

        $fields = explode('，', trim($str));
        foreach ($fields as $value2) {
            if (!$value2) {
                continue;
            }
            $tmp = explode('：', $value2);
            if ($originFieldMap[$typeid][$tmp[0]] === 'brand') {
                $re['brand'] = $tmp[1];
            } else {
                $re[$originFieldMap[$typeid][$tmp[0]]] = "{$foreignFieldMap[$typeid][$originFieldMap[$typeid][$tmp[0]]]}：{$tmp[1]}";
            }
        }

        return $re;
    }

    public function __construct($baseUrl, $username, $password = null)
    {
        $url = parse_url($baseUrl);

        if (!$url) {
            throw new \Exception('Base url Error');
        }

        $this->baseUrl  = $baseUrl;

        // $username 传入数组将视为传入 Cookies
        if (is_array($username) && !$password) {
            $this->cookieJar = $username;
            $this->isLogin = true;
        } else {
            $this->username = $username;
            $this->password = $password;
        }

        $this->authority = $url['host'] . ((int)$url['port'] === 443 ? '' : ":{$url['port']}");
        $this->filePath = Env::get('runtime_path') . "data";

        if (!is_dir($this->filePath) && !is_file($this->filePath)) {
            mkdir($this->filePath, 0700);
            mkdir("{$this->filePath}/switchs", 0700);
            mkdir("{$this->filePath}/switchs/port", 0700);
            mkdir("{$this->filePath}/servers", 0700);
            mkdir("{$this->filePath}/cabinets", 0700);
        }

        CustomCurl::setConf('userAgent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/85.0.4183.102 Safari/537.36');
        CustomCurl::setConf('customHeader', [
            "authority: {$this->authority}",
            'accept-language: zh-CN,zh;q=0.9',
            'cache-control: max-age=0',
            'upgrade-insecure-requests: 1',
            'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'sec-fetch-site: same-origin',
            'sec-fetch-mode: navigate',
            'sec-fetch-user: ?1',
            'sec-fetch-dest: document'
        ]);
    }

    public function login()
    {
        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=login&a=login", 'POST')
                        ->clearHeaders()
                        ->setHeader('authority', $this->authority)
                        ->setHeader('accept', 'application/json, text/javascript, */*; q=0.01')
                        ->setHeader('accept-language', 'zh-CN,zh;q=0.9')
                        ->setHeader('x-requested-with', 'XMLHttpRequest')
                        ->setHeader('sec-fetch-mode', 'cors')
                        ->setHeader('sec-fetch-dest', 'empty')
                        ->setHeader('sec-fetch-site', 'same-origin')
                        ->set('referer', "{$this->baseUrl}/index.php?m=login&a=index")
                        ->cookieJar($this->cookieJar)
                        ->set('postFields', [
                            'username' => $this->username,
                            'password' => $this->password,
                            'remember' => '1'
                        ])
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Login Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo(), -1);
        }

        $data = json_decode($curlObj0->getBody(), 1);

        if ($data['status'] !== 'success') {
            return false;
        }

        $this->isLogin = true;
        return true;
    }

    public function getDatacenters()
    {
        if (!$this->isLogin) {
            return false;
        }

        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=cabinet&a=houseListing")
                        ->cookieJar($this->cookieJar)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Get Datacenters Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        preg_match_all('/<div class="house_link box-green" data-url="[^"]+\/index.php\?m=cabinet&a=houseDetailed&id=(\d+)">.*?<h5 class="textStyle" style="float: left;">\r\n\s+(.*?)&nbsp;&nbsp;&nbsp;&nbsp;(.*?)\s+</is', $curlObj0->getBody(), $matches);

        $re = array_combine($matches[1], $matches[3]);
        $this->saveData("{$this->filePath}/datacenters.json", $re);
        return $this->setData('datacenters', $re);
    }

    public function getIpsGroupList($page = 1, $limit = 100)
    {
        if (!$this->isLogin) {
            return false;
        }

        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=ip&a=ipsGroupListing&search=&orderby=id&sorting=desc&listpages={$limit}&page={$page}")
                        ->cookieJar($this->cookieJar)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Get Ips Group List (Page: {$page}, Limit: {$limit}) Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        preg_match_all('/<tr data-device_name="IP分组.*?<td>.*?<\/td>[\r\n\s]+<td>[\r\n\s]+<a href="[^"]+\/index.php\?m=ip&a=ipsListing&search=ipsGroup&key=(\d+)">(.*?)<\/a>/is', $curlObj0->getBody(), $matches);
        if ($page === 1) {
            preg_match('/<p class="table-list-nub">共(\d+)条数据<\/p>/is', $curlObj0->getBody(), $count);
            $re = array_combine($matches[1], $matches[2]);
            $pageCount = ceil((int)$count[1] / $limit);
            for ($page = 2; $page <= $pageCount; $page++) {
                $re = $re + $this->getIpsGroupList($page);
            }
            $this->saveData("{$this->filePath}/ipv4_groups.json", $re);
            return $this->setData('ipv4_groups', $re);
        } else {
            return array_combine($matches[1], $matches[2]);
        }

        return false;
    }

    public function getIpsList($page = 1, $limit = 100)
    {
        if (!$this->isLogin) {
            return false;
        }

        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=ip&a=ipsListing&orderby=ipsection&sorting=asc&listpages={$limit}&page={$page}")
                        ->cookieJar($this->cookieJar)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Get Ips List (Page: {$page}, Limit: {$limit}) Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        preg_match_all('/<tr data-device_name="IP段.*?">(.*?)<\/tr>/is', $curlObj0->getBody(), $ipBlockMatches);
        $ipBlock = [];
        foreach ($ipBlockMatches[1] as $value) {
            preg_match_all("/<td>(.*?)<\/td>/is", $value, $ipBlockRowMatches);
            list($firstIp, $mask) = explode('/', trim(strip_tags($ipBlockRowMatches[1][1])));
            $ipBlock[trim(strip_tags($ipBlockRowMatches[1][0]))] = [
                'first_ip'   => $firstIp,
                'mask'       => $mask,
                'netmask'    => long2ip(0xFFFFFFFF << (32 - $mask) & 0xFFFFFFFF),
                'type'       => trim($ipBlockRowMatches[1][2]),
                'label'      => trim(strip_tags($ipBlockRowMatches[1][3])),
                'group'      => trim($ipBlockRowMatches[1][4]),
                'datacenter' => trim($ipBlockRowMatches[1][6]),
                'auto'       => trim($ipBlockRowMatches[1][7]) === '是' ? 1 : 0,
                'remark'     => trim($ipBlockRowMatches[1][8]),
                'sub_block'  => []
            ];
        }

        preg_match_all('/<tr data-device_name="子网.*?">(.*?)<\/tr>/is', $curlObj0->getBody(), $ipSubBlockMatches);
        foreach ($ipSubBlockMatches[1] as $value) {
            preg_match_all("/<td>(.*?)<\/td>/is", $value, $ipSubBlockRowMatches);
            preg_match("/ipsid=(\d+)/", $ipSubBlockRowMatches[1][0], $ipsidMatch);
            list($firstIp, $mask) = explode('/', trim(strip_tags($ipSubBlockRowMatches[1][0])));
            $ipBlock[$ipsidMatch[1]]['subBlock'][] = [
                'firstIp'    => $firstIp,
                'mask'       => $mask,
                'netmask'    => trim($ipSubBlockRowMatches[1][1]),
                'gateway'    => trim($ipSubBlockRowMatches[1][2]),
                'vlan'       => trim($ipSubBlockRowMatches[1][3]),
                'datacenter' => trim($ipSubBlockRowMatches[1][4]),
                'remark'     => trim($ipSubBlockRowMatches[1][5]),
            ];
        }

        if ($page === 1) {
            preg_match('/<p class="table-list-nub">共(\d+)条数据<\/p>/is', $curlObj0->getBody(), $count);
            $pageCount = ceil((int)$count[1] / $limit);
            for ($page = 2; $page <= $pageCount; $page++) {
                $ipBlock = $ipBlock + $this->getIpsList($page);
            }
            $this->saveData("{$this->filePath}/ipv4_blocks.json", $ipBlock);
            return $this->setData('ipv4_blocks', $ipBlock);
        }

        return $ipBlock;
    }

    public function getCabinetList($id, $page = 1, $limit = 20)
    {
        if (!$this->isLogin) {
            return false;
        }

        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=cabinet&a=houseCabinetListing&id={$id}&search=&orderby=id&sorting=desc&listpages={$limit}&page={$page}")
                        ->cookieJar($this->cookieJar)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Get Cabinet List (ID: {$id}, Page: {$page}, Limit: {$limit}) Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        preg_match_all('/<tr data-device_name="机柜.*?">(.*?)<\/tr>/is', $curlObj0->getBody(), $cabinetMatches);
        $re = [];
        foreach ($cabinetMatches[1] as $value) {
            preg_match_all("/<td[^>]*>(.*?)<\/td>/is", $value, $cabinetRowMatches);
            preg_match_all("/\d+/", $cabinetRowMatches[1][2], $capacityMatches);
            preg_match("/([\.\d]+)(A|KVA)/", trim($cabinetRowMatches[1][3]), $electricMatches);
            if ($electricMatches) {
                if ($electricMatches[2] === 'A') {
                    $electric = $electricMatches[1] / 1000 * 220;
                } else {
                    $electric = $electricMatches[1];
                }
            } else {
                $electric = 0.00;
            }
            $re[trim(strip_tags($cabinetRowMatches[1][0]))] = [
                'name'       => trim(strip_tags($cabinetRowMatches[1][1])),
                'capacity'   => $capacityMatches[0][0],
                'electric'   => $electric,
                'remark'     => trim($cabinetRowMatches[1][10]),
                'created_at' => trim($cabinetRowMatches[1][11]),
            ];
        }
        if ($page === 1) {
            preg_match('/<p class="table-list-nub">共(\d+)条数据<\/p>/is', $curlObj0->getBody(), $count);
            $pageCount = ceil((int)$count[1] / $limit);
            for ($page = 2; $page <= $pageCount; $page++) {
                $re = $re + $this->getCabinetList($id, $page);
            }
            $this->saveData("{$this->filePath}/cabinets/{$id}.json", $re);
            return $this->setData('cabinets', [$id => $re]);
        }
        
        return $re;
    }

    public function getSwitchList($page = 1, $limit = 50)
    {
        if (!$this->isLogin) {
            return false;
        }

        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=switch&a=switchListing&type=all&search=&key=&orderby=id&sorting=desc&listpages={$limit}&page={$page}")
                        ->cookieJar($this->cookieJar)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Get Switch List (Page: {$page}, Limit: {$limit}) Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        preg_match_all('/<tr data-device_name="交换机.*?">(.*?)<\/tr>/is', $curlObj0->getBody(), $switchMatches);
        $re = [];
        foreach ($switchMatches[1] as $value) {
            preg_match_all("/<td[^>]*>(.*?)<\/td>/is", $value, $switchRowMatches);
            preg_match("/<a.*?>(.*?)<\/a>/is", $switchRowMatches[1][2], $nameMatch);
            preg_match("/id=(\d+)[^>]+>(.*?)<\/a>/is", $switchRowMatches[1][6], $cabinetMatch);
            preg_match("/id=(\d+)[^>]+>(.*?)<\/a>/is", $switchRowMatches[1][7], $datacenterMatch);
            $re[trim(strip_tags($switchRowMatches[1][1]))] = [
                'name'       => $nameMatch[1],
                'device'     => trim($switchRowMatches[1][3]),
                'type'       => trim($switchRowMatches[1][4]),
                'cabinet'    => $cabinetMatch ? ['id' => $cabinetMatch[1], 'name' => $cabinetMatch[2]] : [],
                'datacenter' => $datacenterMatch ? ['id' => $datacenterMatch[1], 'name' => $datacenterMatch[2]] : [],
            ];
        }
        if ($page === 1) {
            preg_match('/<p class="table-list-nub">共(\d+)条数据<\/p>/is', $curlObj0->getBody(), $count);
            $pageCount = ceil((int)$count[1] / $limit);
            for ($page = 2; $page <= $pageCount; $page++) {
                $re = $re + $this->getSwitchList($page);
            }
            $this->saveData("{$this->filePath}/switchs.json", $re);
            return $this->setData('switchs', $re);
        }

        return $re;
    }

    public function getSwitchDetail($id)
    {
        if (!$this->isLogin) {
            return false;
        }

        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=switch&a=switchDetailed&id={$id}")
                        ->cookieJar($this->cookieJar)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Get Switch Detail (ID: {$id}) Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        $re = [];
        preg_match_all('/<small class="pull-xs-right">(.*?)<\/small>/is', $curlObj0->getBody(), $matches);
        $snmpVersion = $this->getDomValue($matches[1][16]);
        preg_match('/((2(5[0-5]|[0-4]\d))|[0-1]?\d{1,2})(\.((2(5[0-5]|[0-4]\d))|[0-1]?\d{1,2})){3}/', $matches[1][24], $ipMatch);

        $re['model'] = trim($matches[1][1]);
        $re['snmp_credential'] = ['community' => $this->getDomValue($matches[1][17])];
        $re['snmp_version'] = $snmpVersion ? $snmpVersion[0] : '';
        $re['control_protocol'] = trim(strip_tags($matches[1][11]));
        $re['control_credential'] = [
            'username' => $this->getDomValue($matches[1][9]),
            'password' => $this->getDomValue($matches[1][10]),
        ];
        $re['ip'] = $ipMatch[0];
        $this->saveData("{$this->filePath}/switchs/{$id}.json", $re);
        return $this->setData('switchs', [$id => $re]);
    }

    public function getSwitchPortList($id)
    {
        if (!$this->isLogin) {
            return false;
        }

        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=switch&a=switchPortListing&id={$id}")
                        ->cookieJar($this->cookieJar)
                        ->set('timeout', 60)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Get Switch Port List (ID: {$id}) Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        preg_match_all('/<tr>(.*?)<\/tr>/is', $curlObj0->getBody(), $switchPortMatches);
        $re = [];
        foreach ($switchPortMatches[1] as $value) {
            preg_match_all("/<td[^>]*>(.*?)<\/td>/is", $value, $switchPortRowMatches);

            if (!$switchPortRowMatches[1]) {
                continue;
            }

            $interface = trim(strip_tags($switchPortRowMatches[1][0]));

            if (
                strpos($interface, '.') !== false ||
                strpos($interface, 'NULL') !== false
            ) {
                continue;
            }

            preg_match('/index.php\?m=(server|switch).*?id=(\d+)[^>]*>[\r\n]+\s+(.*?)\s+<\/a>/is', $switchPortRowMatches[1][1], $deviceMatches);

            $vlan = trim(strip_tags($switchPortRowMatches[1][3]));

            $bandwidth = trim(strip_tags($switchPortRowMatches[1][4]));
            $bandwidthArr = explode(' / ', substr($bandwidth, 0, strlen($bandwidth) - 5));
            list($downPort, $upPort) = count($bandwidthArr) > 1 ? $bandwidthArr : [null, null];

            $tmp = [
                'vlan'      => $vlan === '1' ? 0 : $vlan,
                'down_port' => $downPort, // 数据库字段就是这么命名的，不是我搞不清楚 bandwidth 和 port
                'up_port'   => $upPort,   // 数据库字段就是这么命名的，不是我搞不清楚 bandwidth 和 port
            ];

            if ($deviceMatches) {
                $device = [
                    'id' => $deviceMatches[2],
                ];
                if ($deviceMatches[1] === 'server') {
                    preg_match('/^[^*]+/', $deviceMatches[3], $nameMatch);
                    $device['name'] = $nameMatch[0];
                    $device['type'] = 'Server';
                } else {
                    preg_match('/^(.*?)\(端口：(\d+)，(上|下)级\)$/is', trim($deviceMatches[3]), $deviceNameMatch);
                    $device['name'] = $deviceNameMatch[1];
                    $device['port'] = $deviceNameMatch[2];
                    $device['type'] = $deviceNameMatch[3] === '上' ? 'UpLink' : 'DownLink';
                }

                preg_match('/([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})/', $switchPortRowMatches[1][6], $macMatch);
                preg_match('/<span[^>]*?first_show[^>]*?>.*?(?!span)lcs_(on|off).*?(?:(?=span)|$)/is', $switchPortRowMatches[1][9], $disabledMatch);
                preg_match('/lcs_(on|off)/is', $switchPortRowMatches[1][10], $bindMacMatch);

                $tmp = array_merge($tmp, [
                    'device'      => $device,
                    'mac_address' => $macMatch ? $macMatch[0] : '',
                    'remark'      => trim($switchPortRowMatches[1][8]),
                    'disabled'    => $disabledMatch ? $disabledMatch[1] : '0',
                    'bind_mac'    => $bindMacMatch[1]
                ]);
            }

            $re[$interface] = $tmp;
        }
        $this->saveData("{$this->filePath}/switchs/port/{$id}.json", $re);
        return $this->setData('switchs', [$id => ['port' => $re]]);
    }

    public function getHardwareModelList($page = 1, $limit = 1000)
    {
        if (!$this->isLogin) {
            return false;
        }

        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=stock&a=hardwareModelListing&listpages={$limit}&page={$page}")
                        ->cookieJar($this->cookieJar)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Get Hardware Mode List (Page: {$page}, Limit: {$limit}) Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        preg_match_all('/<tr.*?>(.*?)<\/tr>/is', $curlObj0->getBody(), $rowMatches);
        array_shift($rowMatches[1]);

        $re = ['1' => [], '2' => [], '3' => [], '4' => []];
        $deviceMap = ["CPU (C)" => '1', "内存 (M)" => '2', "硬盘 (H)" => '3', "PCI设备 (P)" => '4'];

        foreach ($rowMatches[1] as $key => $value) {
            preg_match_all('/<td.*?>(.*?)<\/td>/is', $value, $tdMatches);
            $typeTd = trim(strip_tags($tdMatches[1][0]));
            if ($typeTd) {
                $reKey = $deviceMap[$typeTd];
                continue;
            }
            
            preg_match('/id=(\d+)/', $tdMatches[1][4], $idMatch);
            $re[$reKey][$idMatch[1]] = array_merge([
                'officalmodel' => trim(strip_tags($tdMatches[1][1])),
                'brand'        => '',
                'customfield0' => '',
                'customfield1' => '',
                'customfield2' => '',
                'customfield3' => '',
            ], $this->formatHardwareCustomFields($tdMatches[1][2], $reKey));
        }
        
        return $re;
    }

    public function getPurchaseList($page = 1, $limit = 100)
    {
        if (!$this->isLogin) {
            return false;
        }

        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=stock&a=purchaseListing&search=&orderby=id&sorting=desc&type=&listpages={$limit}&page={$page}")
                        ->cookieJar($this->cookieJar)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Get Purchase List (Page: {$page}, Limit: {$limit}) Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        preg_match_all('/<tr.*?>(.*?)<\/tr>/is', $curlObj0->getBody(), $rowMatches);
        array_shift($rowMatches[1]);

        $re = [];
        $deviceMap = ["CPU" => '1', "内存" => '2', "硬盘" => '3', "PCI设备" => '4'];

        foreach ($rowMatches[1] as $value) {
            preg_match_all("/<td[^>]*>(.*?)<\/td>/is", $value, $tdMatches);
            // var_dump($tdMatches);

            preg_match('/id=(\d+)">(.*?)</', $tdMatches[1][2], $datacenterMatch);

            $typeid = $deviceMap[trim(strip_tags($tdMatches[1][1]))];
            $re[trim(strip_tags($tdMatches[1][0]))] = array_merge([
                'type_id'       => $typeid,
                'datacenter'    => ['id' => $datacenterMatch[1], 'name' => $datacenterMatch[2]],
                'officalmodel'  => trim(strip_tags($tdMatches[1][3])),
                'brand'         => '',
                'customfield0'  => '',
                'customfield1'  => '',
                'customfield2'  => '',
                'customfield3'  => '',
                'purchase_time' => trim($tdMatches[1][5]),
                'qty'           => trim($tdMatches[1][6])
            ], $this->formatHardwareCustomFields($tdMatches[1][4], $typeid));
        }

        if ($page === 1) {
            preg_match('/<p class="table-list-nub">共(\d+)条数据<\/p>/is', $curlObj0->getBody(), $count);
            $pageCount = ceil((int)$count[1] / $limit);
            for ($page = 2; $page <= $pageCount; $page++) {
                $re = $re + $this->getPurchaseList($page);
            }
        }
        
        $this->saveData("{$this->filePath}/part_batches.json", $re);
        return $this->setData('part_batches', $re);
    }

    public function getServerList($page = 1, $limit = 100, $doNotSetField = false)
    {
        if (!$this->isLogin) {
            return false;
        }

        if (!$doNotSetField) {
            $apiCurlObj = CustomCurl::init("{$this->baseUrl}/index.php?m=common&a=showFieldSave&name=hardwareListing", 'POST')
                            ->setHeader('accept', 'application/json, text/javascript, */*; q=0.01')
                            ->setHeader('x-requested-with', 'XMLHttpRequest')
                            ->setHeader('sec-fetch-mode', 'cors')
                            ->setHeader('sec-fetch-dest', 'empty')
                            ->cookieJar($this->cookieJar)
                            ->set('postFields', [
                                'field' => [
                                    '0' => 'wltag',
                                    '1' => 'zhuip',
                                    '2' => 'mac',
                                    '3' => 'cname',
                                    '4' => 'time',
                                    '5' => 'house'
                                ]
                            ])
                            ->exec();
            if (!$apiCurlObj->getStatus()) {
                throw new \Exception("Get Server List (Page: {$page}, Limit: {$limit}) Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
            }
        }

        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=hardware&a=hardwareListing&type=&orderby=id&sorting=desc&listpages={$limit}&page={$page}")
                        ->cookieJar($this->cookieJar)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception('Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        preg_match_all('/<tr.*?data-device_name="服务器.*?>(.*?)<\/tr>/is', $curlObj0->getBody(), $rowMatches);

        $re = [];

        foreach ($rowMatches[1] as $value) {
            preg_match_all("/<td[^>]*>(.*?)<\/td>/is", $value, $tdMatches);

            if (count($tdMatches[1]) !== 9) {
                continue;
            }

            $tmp = explode('/ ', trim(strip_tags($tdMatches[1][2])));
            if (count($tmp) === 1) {
                $name = trim(strip_tags($tdMatches[1][2]));
            } else {
                list($name, $internalLabel) = $tmp;
            }
            preg_match('/id=(\d+)">(.*?)<\/a>/', $tdMatches[1][3], $datacenterMatch);
            preg_match('/((2(5[0-5]|[0-4]\d))|[0-1]?\d{1,2})(\.((2(5[0-5]|[0-4]\d))|[0-1]?\d{1,2})){3}/', $tdMatches[1][4], $ipMatch);
            preg_match('/([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})/', $tdMatches[1][5], $macMatch);
            preg_match('/id=(\d+)">(.*?)<\/a>/', $tdMatches[1][6], $cabinetMatch);

            $re[trim(strip_tags($tdMatches[1][1]))] = [
                'name'       => preg_replace('/\*$/', '', $name),
                'datacenter' => ['id' => $datacenterMatch[1], 'name' => $datacenterMatch[2]],
                'main_ip'    => $ipMatch ? $ipMatch[0] : '',
                'main_mac'   => $macMatch ? $macMatch[0] : '',
                'cabinet'    => ['id' => $cabinetMatch[1], 'name' => $cabinetMatch[2]],
                'shelf_time' => trim($tdMatches[1][7]),
            ];
        }

        if ($page === 1) {
            preg_match('/<p class="table-list-nub">共(\d+)条数据<\/p>/is', $curlObj0->getBody(), $count);
            $pageCount = ceil((int)$count[1] / $limit);
            for ($page = 2; $page <= $pageCount; $page++) {
                $re = $re + $this->getServerList($page, $limit, true);
            }
            $this->saveData("{$this->filePath}/servers.json", $re);
            return $this->setData('servers', $re);
        }

        return $re;
    }

    public function getServerDetail($id)
    {
        if (!$this->isLogin) {
            return false;
        }

        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=server&a=detailed&id={$id}")
                        ->cookieJar($this->cookieJar)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Get Server (ID: {$id}) Detail Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        $re = [
            'hardware'      => '',
            'parts'         => [],
            'username'      => '',
            'password'      => '',
            'remote_port'   => null,
            'ipmi_metadata' => [],
            'osid'          => '',  // 直接存字符串，不是存 ID
            'ip'            => []
        ];
        preg_match_all('/<small class="pull-xs-right">(.*?)<\/small>/is', $curlObj0->getBody(), $matches);

        // 操作系统
        preg_match('/<span class="show_data operating_system_\d+">(.*?)<\/span>/', $matches[1][10], $osMatch);
        $re['osid'] = $osMatch[1];
        $re['username'] = $this->getDomValue($matches[1][11]);
        $re['remote_port'] = trim(strip_tags($matches[1][12]));
        $re['password'] = $this->getDomValue($matches[1][16]);

        // 服务器型号
        $re['hardware'] = $matches[1][1];

        // IPMI
        preg_match('/<span class="show_data">\r?\n\s+<a[^>]*>(.*?)<\/a>/', $matches[1][7], $ipmiMatch);
        if (isset($ipmiMatch[1])) {
            $re['ipmi_metadata'] = [
                'address'  => $ipmiMatch[1],
                'username' => $this->getDomValue($matches[1][8]),
                'password' => $this->getDomValue($matches[1][9]),
            ];
        }

        // 获取硬件采购信息
        preg_match_all('/((CPU|硬盘|内存)型号|PCI设备)<\/p>\r?\n\s+<small.*?采购信息:<\/p>(.*?)(?=<p class=\'hardware_info\'>实际信息)/is', $curlObj0->getBody(), $hardwareMatches);
        $deviceMap = ["CPU" => '1', "内存" => '2', "硬盘" => '3', "PCI设备" => '4'];
        foreach ($hardwareMatches[3] as $key => $value) {
            preg_match_all('/<p class=\'hardware_info\'>(.*?)（\d{4}\-\d{2}\-\d{2}） x (\d+)<\/p>/', $value, $hardwareDetailMatches);
            foreach ($hardwareDetailMatches[1] as $hardwareKey => $hardware) {
                $re['parts'][] = [
                    'type_id'      => $deviceMap[$hardwareMatches[2][$key] ? $hardwareMatches[2][$key] : $hardwareMatches[1][$key]],
                    'officalmodel' => $hardware,
                    'count'        => $hardwareDetailMatches[2][$hardwareKey]
                ];
            }
        }

        $apiCurlObj = CustomCurl::init('https://example.com:28081/index.php?m=common&a=getServerIp&id=3707')
                        ->cookieJar($this->cookieJar)
                        ->setHeader('accept', 'application/json, text/javascript, */*; q=0.01')
                        ->setHeader('x-requested-with', 'XMLHttpRequest')
                        ->setHeader('sec-fetch-mode', 'cors')
                        ->setHeader('sec-fetch-dest', 'empty')
                        ->exec();

        if (!$apiCurlObj->getStatus()) {
            throw new \Exception("Get Server (ID: {$id}) IP Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        $ipData = json_decode($apiCurlObj->getBody(), 1);
        if ($ipData['status'] !== 'success') {
            throw new \Exception("Get Server (ID: {$id}) IP Error\n" . 'Api Receive Status Error.');
        }

        foreach ($ipData['data'] as $value) {
            $re['ip'][] = $value['ipaddress'];
        }

        $this->saveData("{$this->filePath}/servers/{$id}.json", $re);
        return $this->setData('servers', [$id => $re]);
    }

    public function getHardwareList($page = 1, $limit = 50)
    {
        if (!$this->isLogin) {
            return false;
        }

        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=hardware&a=hardwareAssortListing&search=&orderby=id&sorting=desc&listpages={$limit}&page={$page}")
                        ->cookieJar($this->cookieJar)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Get Hardware List (Page: {$page}, Limit: {$limit}) Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        preg_match_all('/<tr data-device_name="服务器型号.*?">(.*?)<\/tr>/is', $curlObj0->getBody(), $hardwareMatches);
        $re = [];
        foreach ($hardwareMatches[1] as $value) {
            preg_match_all("/<td[^>]*>(.*?)<\/td>/is", $value, $hardwareRowMatches);

            $size = trim($hardwareRowMatches[1][2]);

            $re[trim(strip_tags($hardwareRowMatches[1][0]))] = [
                'name'         => trim($hardwareRowMatches[1][1]),
                'size'         => $size ? substr($size, 0, strlen($size) - 1) : '',
                'node_num'     => trim($hardwareRowMatches[1][3]),
                'row'          => trim($hardwareRowMatches[1][4]),
                'mac_offset'   => trim($hardwareRowMatches[1][5]),
                'ipmi_support' => trim($hardwareRowMatches[1][6]) === '是' ? 1 : 0,

            ];
        }
        if ($page === 1) {
            preg_match('/<p class="table-list-nub">共(\d+)条数据<\/p>/is', $curlObj0->getBody(), $count);
            $pageCount = ceil((int)$count[1] / $limit);
            for ($page = 2; $page <= $pageCount; $page++) {
                $re = $re + $this->getSwitchList($page);
            }
        }

        $this->saveData("{$this->filePath}/hardware.json", $re);
        return $this->setData('hardware', $re);
    }

    public function checkVersion()
    {
        $curlObj0 = CustomCurl::init("{$this->baseUrl}/index.php?m=setting&a=authorize")
                        ->cookieJar($this->cookieJar)
                        ->exec();

        if (!$curlObj0->getStatus()) {
            throw new \Exception("Get Version Error\n" . 'Curl Error: ' . $curlObj0->getCurlErrNo());
        }

        preg_match('/当前版本：([0-9\.]+)/', $curlObj0->getBody(), $versionMatch);

        return in_array($versionMatch[1], $this->allowedVersion);
    }
}