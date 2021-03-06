<?php declare(strict_types=1);
/**
 * Copyright (c) 2019.
 */

namespace Metahash;

use Exception;

class Crypto
{
    const TORRENT_FETCH_HISTORY_LIMIT = 9999;

    public $net;
    /**
     * @var Ecdsa
     */
    private $ecdsa;
    private $curl;
    private $proxy = ['url' => 'proxy.net-%s.metahashnetwork.com', 'port' => 9999];
    private $torrent = ['url' => 'tor.net-%s.metahashnetwork.com', 'port' => 5795];
    private $hosts = [];

    public function __construct($ecdsa)
    {
        $this->ecdsa = $ecdsa;
        $this->curl = \curl_init();
    }

    public function generate()
    {
        $data = $this->ecdsa->getKey();
        $data['address'] = $this->ecdsa->getAdress($data['public']);

        return $data;
    }

    public function checkAdress($address)
    {
        return $this->ecdsa->checkAdress($address);
    }

    public function create($address): bool
    {
        try {
            if ($host = $this->getConnectionAddress('PROXY')) {
                $host = $host . '/?act=addWallet&p_addr=' . $address;
                $curl = $this->curl;
                \curl_setopt($curl, CURLOPT_URL, $host);
                \curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                \curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 1);
                \curl_setopt($curl, CURLOPT_TIMEOUT, 2);
                \curl_setopt($curl, CURLOPT_POST, 1);
                \curl_setopt($curl, CURLOPT_HTTPGET, false);

                $result = \curl_exec($curl);
                if (\strstr($result, 'Transaction accepted.')) {
                    return true;
                }
            }
        } catch (Exception $e) {
            //
        }

        return false;
    }

    public function getConnectionAddress($node = null)
    {
        if (isset($this->hosts[$node]) && !empty($this->hosts[$node])) {
            return $this->hosts[$node];
        }

        $node_url = null;
        $node_port = null;

        switch ($node) {
            case 'PROXY':
                $node_url = \sprintf($this->proxy['url'], $this->net);
                $node_port = $this->proxy['port'];
                break;
            case 'TORRENT':
                $node_url = \sprintf($this->torrent['url'], $this->net);
                $node_port = $this->torrent['port'];
                break;
            default:
                // empty
                break;
        }

        if ($node_url) {
            $list = \dns_get_record($node_url, DNS_A);
            $host_list = [];
            foreach ($list as $val) {
                switch ($node) {
                    case 'PROXY':
                        if ($res = $this->checkHost($val['ip'] . ':' . $node_port)) {
                            $host_list[$val['ip'] . ':' . $node_port] = 1;
                        }
                        break;
                    case 'TORRENT':
                        $host_list[$val['ip'] . ':' . $node_port] = $this->torGetLastBlock($val['ip'] . ':' . $node_port);
                        break;
                    default:
                        // empty
                        break;
                }
            }

            \arsort($host_list);
            $keys = \array_keys($host_list);
            if (\count($keys)) {
                $this->hosts[$node] = $keys[0];
                return $this->hosts[$node];
            }
        }


        return false;
    }

    private function checkHost($host): bool
    {
        if (!empty($host)) {
            $curl = $this->curl;
            \curl_setopt($curl, CURLOPT_URL, $host);
            \curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            \curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 1);
            \curl_setopt($curl, CURLOPT_TIMEOUT, 1);
            \curl_setopt($curl, CURLOPT_POST, 1);
            \curl_setopt($curl, CURLOPT_POSTFIELDS, '{"id":"1","method":"","params":[]}');
            \curl_exec($curl);
            $code = \curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if ($code > 0 && $code < 500) {
                return true;
            }
        }

        return false;
    }

    private function torGetLastBlock($host)
    {
        if (!empty($host)) {
            $curl = $this->curl;
            \curl_setopt($curl, CURLOPT_URL, $host);
            \curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            \curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 1);
            \curl_setopt($curl, CURLOPT_TIMEOUT, 1);
            \curl_setopt($curl, CURLOPT_POST, 1);
            \curl_setopt($curl, CURLOPT_POSTFIELDS, '{"id":"1","method":"get-count-blocks","params":[]}');
            $res = \curl_exec($curl);

            if ($res === false) {
                return 0;
            }

            $res = \json_decode($res, true);

            if (isset($res['result']['count_blocks'])) {
                return (int)$res['result']['count_blocks'];
            }
        }

        return 0;
    }

    public function fetchHistory(string $address, int $beginTx = 1, int $countTx = self::TORRENT_FETCH_HISTORY_LIMIT)
    {
        if ($countTx > self::TORRENT_FETCH_HISTORY_LIMIT) {
            throw new \Exception('Too many transaction in one request. Maximum is ' . self::TORRENT_FETCH_HISTORY_LIMIT);
        }

        return $this->queryTorrent(
            'fetch-history',
            [
                'address' => $address,
                'beginTx' => $beginTx,
                'countTxs' => $countTx
            ]
        );
    }

    public function fetchFullHistory(string $address)
    {
        $result['balance'] = $this->fetchBalance($address)['result'];
        if ($result['balance']['count_txs'] <= self::TORRENT_FETCH_HISTORY_LIMIT) {
            $result['result'] = $this->fetchHistory($address)['result'];
        } else {
            $result['result'] = [];
            for ($begin = 1; $begin <= $result['balance']['count_txs']; $begin += self::TORRENT_FETCH_HISTORY_LIMIT) {
                $result['result'] = array_merge(
                    $result['result'],
                    $this->fetchHistory($address, $begin, self::TORRENT_FETCH_HISTORY_LIMIT)['result']
                );
            }
        }
        return $result;
    }

    private function queryTorrent($method, $data = [])
    {
        try {
            $query = [
                'id'     => \time(),
                'method' => \trim($method),
                'params' => $data,
            ];
            $query = \json_encode($query);
            $url = $this->getConnectionAddress('TORRENT');

            if ($url) {
                $curl = $this->curl;
                \curl_setopt($curl, CURLOPT_URL, $url);
                \curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                \curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 300);
                \curl_setopt($curl, CURLOPT_TIMEOUT, 300);
                \curl_setopt($curl, CURLOPT_POST, 1);
                \curl_setopt($curl, CURLOPT_POSTFIELDS, $query);
                /* curl_setopt($curl, CURLOPT_VERBOSE, true);*/

                $result = \curl_exec($curl);

                if ($result === false) {
                    throw new \Exception('cURL Error: ' . \curl_error($curl));
                }
                $result = \json_decode($result, true);

                return $result;
            }
            throw new \Exception('The proxy service is not available. Maybe you have problems with DNS.');
        } catch (Exception $e) {
            throw new \Exception($e->getMessage());
        }

        return false;
    }

    public function getTx($hash)
    {
        return $this->queryTorrent('get-tx', ['hash' => $hash]);
    }

    public function sendTx($to, $value, $fee = '', $nonce = 1, $data = '', $key = '', $sign = '')
    {
        $txData = [
            'to'     => $to,
            'value'  => \strval($value),
            'fee'    => \strval($fee),
            'nonce'  => \strval($nonce),
            'data'   => $data,
            'pubkey' => $key,
            'sign'   => $sign,
        ];

        return $this->queryProxy('mhc_send', $txData);
    }

    private function queryProxy($method, $data = [])
    {
        try {
            $query = [
                'id'     => \time(),
                'method' => \trim($method),
                'params' => $data,
            ];
            $query = \json_encode($query);
            $url = $this->getConnectionAddress('PROXY');


            if ($url) {
                $curl = $this->curl;
                \curl_setopt($curl, CURLOPT_URL, $url);
                \curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                \curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 1);
                \curl_setopt($curl, CURLOPT_TIMEOUT, 3);
                \curl_setopt($curl, CURLOPT_POST, 1);
                \curl_setopt($curl, CURLOPT_POSTFIELDS, $query);

                $result = \curl_exec($curl);
                $err = \curl_error($curl);
                $code = \curl_getinfo($curl, CURLINFO_HTTP_CODE);


                $result = \json_decode($result, true);

                return $result;
            }
            throw new \Exception('The proxy service is not available. Maybe you have problems with DNS.');
        } catch (Exception $e) {
            throw new \Exception($e->getMessage());
        }

        return false;
    }

    public function getNonce($address)
    {
        $res = $this->fetchBalance($address);

        return (isset($res['result']['count_spent'])) ? \intval($res['result']['count_spent']) + 1 : 1;
    }

    public function fetchBalance($address)
    {
        return $this->queryTorrent('fetch-balance', ['address' => $address]);
    }

    public function makeSign($address, $value, $nonce, $fee = 0, $data = '')
    {
        $a = (\substr($address, 0, 2) === '0x') ? \substr($address, 2) : $address; // адрес
        $b = IntHelper::VarUInt(\intval($value), true); // сумма
        $c = IntHelper::VarUInt(\intval($fee), true); // комиссия
        $d = IntHelper::VarUInt(\intval($nonce), true); // нонс

        $f = $data; // дата
        $data_length = \strlen($f);
        $data_length = ($data_length > 0) ? $data_length / 2 : 0;
        $e = IntHelper::VarUInt(\intval($data_length), true); // счетчик для даты

        $sign_text = $a . $b . $c . $d . $e . $f;


        return \hex2bin($sign_text);
    }

    public function sign($sign_text, $private_key)
    {
        return $this->ecdsa->sign($sign_text, $private_key);
    }

    public function getBlockByNumber(int $number, int $type)
    {
        try {
            return $this->queryTorrent('get-block-by-number', ['number' => $number, 'type' => $type]);
        } catch (Exception $exception) {
            \var_dump($exception->getTrace());
            return [];
        }
    }

    public function getBlockByHash(string $hash, int $type)
    {
        try {
            return $this->queryTorrent('get-block-by-hash', ['hash' => $hash, 'type' => $type]);
        } catch (Exception $exception) {
            \var_dump($exception->getTrace());
            return [];
        }
    }
}
