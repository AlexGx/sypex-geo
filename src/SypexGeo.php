<?php

declare(strict_types=1);

namespace SypexGeo;

use function array_values;
use function bin2hex;
use function current;
use function date;
use function explode;
use function floor;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function hexdec;
use function ip2long;
use function is_array;
use function ord;
use function pack;
use function rtrim;
use function str_split;
use function str_starts_with;
use function strpos;
use function substr;
use function unpack;

final class SypexGeo
{
    /**
     * @const int File mode
     */
    public const FILE = 0;

    /**
     * @const int Memory mode
     */
    public const MEMORY = 1;

    /**
     * @const int Batch mode
     */
    public const BATCH = 2;

    private mixed $fh;
    /**
     * @var array<int, string>
     */
    private array $info;
    private int $range;
    private int $db_begin;
    private string $b_idx_str;
    private string $m_idx_str;
    private array $b_idx_arr;
    private array $m_idx_arr;
    private int $m_idx_len;
    private int $db_items;
    private int $country_size;
    private string $db;
    private string $regions_db;
    private string $cities_db;

    private array $id2iso = [
        '', 'AP', 'EU', 'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'CW', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU',
        'AW', 'AZ', 'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BM', 'BN', 'BO', 'BR', 'BS',
        'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
        'CO', 'CR', 'CU', 'CV', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG',
        'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'SX', 'GA', 'GB', 'GD', 'GE', 'GF',
        'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM', 'HN',
        'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JM', 'JO', 'JP', 'KE',
        'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR',
        'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP',
        'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA', 'NC', 'NE', 'NF', 'NG', 'NI',
        'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN',
        'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SE', 'SG',
        'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'ST', 'SV', 'SY', 'SZ', 'TC', 'TD', 'TF',
        'TG', 'TH', 'TJ', 'TK', 'TM', 'TN', 'TO', 'TL', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM',
        'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'RS', 'ZA',
        'ZM', 'ME', 'ZW', 'A1', 'XK', 'O1', 'AX', 'GG', 'IM', 'JE', 'BL', 'MF', 'BQ', 'SS',
    ];

    private bool $batch_mode;

    private bool $memory_mode;
    private int $block_len;
    private int $b_idx_len;
    private int $id_len;
    private int $max_region;
    private int $max_city;
    private int $max_country;

    /**
     * @var string|string[]
     */
    private string|array $pack;

    public function __construct(string $db_file, int $type)
    {
        $this->fh = fopen($db_file, 'rb');
        // ?????????????? ????????????????????, ?????? ???????? ???????? ???????? ????????????
        $header = fread($this->fh, 40); // ?? ???????????? 2.2 ?????????????????? ???????????????????? ???? 8 ????????
        if (!str_starts_with($header, 'SxG')) {
            throw new \RuntimeException('Invalid file start.');
        }
        /** @var array<string, int> $info */
        $info = unpack('Cver/Ntime/Ctype/Ccharset/Cb_idx_len/nm_idx_len/nrange/Ndb_items/Cid_len/nmax_region/nmax_city/Nregion_size/Ncity_size/nmax_country/Ncountry_size/npack_size', substr($header, 3));
        if ($info === false || $info['b_idx_len'] * $info['m_idx_len'] * $info['range'] * $info['db_items'] * $info['time'] * $info['id_len'] === 0) {
            throw new \RuntimeException('Invalid file format.');
        }
        $this->range       = $info['range'];
        $this->b_idx_len   = $info['b_idx_len'];
        $this->m_idx_len   = $info['m_idx_len'];
        $this->db_items    = $info['db_items'];

        $this->id_len      = $info['id_len'];
        $this->block_len   = 3 + $this->id_len;
        $this->max_region  = $info['max_region'];
        $this->max_city    = $info['max_city'];
        $this->max_country = $info['max_country'];
        $this->country_size= $info['country_size'];
        $this->batch_mode  = (bool) ($type & self::BATCH);
        $this->memory_mode = (bool) ($type & self::MEMORY);
        $this->pack        = $info['pack_size'] ? explode("\0", fread($this->fh, $info['pack_size'])) : ''; // @review: can be empty array
        $this->b_idx_str   = fread($this->fh, $info['b_idx_len'] * 4);
        $this->m_idx_str   = fread($this->fh, $info['m_idx_len'] * 4);

        $this->db_begin = ftell($this->fh);
        if ($this->batch_mode) {
            $this->b_idx_arr = array_values(unpack('N*', $this->b_idx_str)); // ?????????????? ?? 5 ??????, ?????? ?? ????????????
            unset($this->b_idx_str);
            $this->m_idx_arr = str_split($this->m_idx_str, 4); // ?????????????? ?? 5 ?????? ?????? ?? ????????????
            unset($this->m_idx_str);
        }
        if ($this->memory_mode) {
            $this->db = fread($this->fh, $this->db_items * $this->block_len);
            $this->regions_db = $info['region_size'] > 0 ? fread($this->fh, $info['region_size']) : '';
            $this->cities_db  = $info['city_size'] > 0 ? fread($this->fh, $info['city_size']) : '';
        }
        $this->info = $info;
        $this->info['regions_begin'] = $this->db_begin + $this->db_items * $this->block_len;
        $this->info['cities_begin']  = $this->info['regions_begin'] + $info['region_size'];
    }

    private function search_idx($ipn, $min, $max)
    {
        if ($this->batch_mode) {
            while ($max - $min > 8) {
                $offset = ($min + $max) >> 1;
                if ($ipn > $this->m_idx_arr[$offset]) {
                    $min = $offset;
                } else {
                    $max = $offset;
                }
            }
            while ($ipn > $this->m_idx_arr[$min] && $min++ < $max) {
            }
        } else {
            while ($max - $min > 8) {
                $offset = ($min + $max) >> 1;
                if ($ipn > substr($this->m_idx_str, $offset*4, 4)) {
                    $min = $offset;
                } else {
                    $max = $offset;
                }
            }
            while ($ipn > substr($this->m_idx_str, $min*4, 4) && $min++ < $max) {
            }
        }
        return  $min;
    }

    private function search_db(string $str, string $ipn, int $min, int $max): int
    {
        if ($max - $min > 1) {
            $ipn = substr($ipn, 1);
            while ($max - $min > 8) {
                $offset = ($min + $max) >> 1;
                if ($ipn > substr($str, $offset * $this->block_len, 3)) {
                    $min = $offset;
                } else {
                    $max = $offset;
                }
            }
            while ($ipn >= substr($str, $min * $this->block_len, 3) && ++$min < $max) {
            }
        } else {
            $min++;
        }
        return hexdec(bin2hex(substr($str, $min * $this->block_len - $this->id_len, $this->id_len))); // review: can be optimized?
    }

    public function get_num(string $ip): int
    {
        $ip1n = (int) $ip; // ???????????? ????????
        if ($ip1n === 0 || $ip1n === 10 || $ip1n === 127 || $ip1n >= $this->b_idx_len || false === ($ipn = ip2long($ip))) {
            return 0;
        }
        $ipn = pack('N', $ipn);
        // ?????????????? ???????? ???????????? ?? ?????????????? ???????????? ????????
        if ($this->batch_mode) {
            $blocks = ['min' => $this->b_idx_arr[$ip1n-1], 'max' => $this->b_idx_arr[$ip1n]];
        } else {
            /** @var array<string, int> $blocks */
            $blocks = unpack('Nmin/Nmax', substr($this->b_idx_str, ($ip1n - 1) * 4, 8));
        }
        if ($blocks['max'] - $blocks['min'] > $this->range) {
            // ???????? ???????? ?? ???????????????? ??????????????
            $part = $this->search_idx($ipn, (int) floor($blocks['min'] / $this->range), (int) floor($blocks['max'] / $this->range)-1);
            // ?????????? ?????????? ?????????? ?? ?????????????? ?????????? ???????????? IP, ???????????? ?????????????? ???????????? ???????? ?? ????
            $min = $part > 0 ? $part * $this->range : 0;
            $max = $part > $this->m_idx_len ? $this->db_items : ($part+1) * $this->range;
            // ?????????? ?????????????????? ?????????? ???????? ???? ?????????????? ???? ?????????????? ?????????? ?????????????? ??????????
            if ($min < $blocks['min']) {
                $min = $blocks['min'];
            }
            if ($max > $blocks['max']) {
                $max = $blocks['max'];
            }
        } else {
            $min = $blocks['min'];
            $max = $blocks['max'];
        }
        $len = $max - $min;
        // ?????????????? ???????????? ???????????????? ?? ????
        if ($this->memory_mode) {
            return $this->search_db($this->db, $ipn, $min, $max);
        }

        fseek($this->fh, $this->db_begin + $min * $this->block_len);
        return $this->search_db(fread($this->fh, $len * $this->block_len), $ipn, 0, $len);
    }

    private function readData(int $seek, int $max, int $type): array // @review: do `type` as constant (REGION, CITY, COUNTRY)
    {
        $raw = '';
        if ($seek && $max) {
            if ($this->memory_mode) {
                $raw = substr($type === 1 ? $this->regions_db : $this->cities_db, $seek, $max);
            } else {
                fseek($this->fh, $this->info[$type === 1 ? 'regions_begin' : 'cities_begin'] + $seek);
                $raw = fread($this->fh, $max);
            }
        }
        return $this->unpack($this->pack[$type], $raw);
    }

    private function parseCity(int $seek, bool $full = false): ?array
    {
        if (!$this->pack) {
            return null;
        }
        $only_country = false;
        if ($seek < $this->country_size) {
            $country = $this->readData($seek, $this->max_country, 0);
            $city = $this->unpack($this->pack[2]);
            $city['lat'] = $country['lat'];
            $city['lon'] = $country['lon'];
            $only_country = true;
        } else {
            $city = $this->readData($seek, $this->max_city, 2);
            $country = ['id' => $city['country_id'], 'iso' => $this->id2iso[$city['country_id']]];
            unset($city['country_id']);
        }
        if ($full) {
            $region = $this->readData($city['region_seek'], $this->max_region, 1);
            if (!$only_country) {
                $country = $this->readData($region['country_seek'], $this->max_country, 0);
            }
            unset($city['region_seek'], $region['country_seek']);
            return ['city' => $city, 'region' => $region, 'country' => $country];
        }

        unset($city['region_seek']);
        return ['city' => $city, 'country' => ['id' => $country['id'], 'iso' => $country['iso']]];
    }

    private function unpack(string $pack, string $item = ''): array
    {
        $unpacked = [];
        $empty = empty($item);
        $_pack = explode('/', $pack);
        $pos = 0;
        foreach ($_pack as $p) {
            [$type, $name] = explode(':', $p);
            $type0 = $type[0];
            if ($empty) {
                $unpacked[$name] = $type0 === 'b' || $type0 === 'c' ? '' : 0;
                continue;
            }

            $l = match ($type0) {
                't', 'T' => 1,
                's', 'n', 'S' => 2,
                'm', 'M' => 3,
                'd' => 8,
                'c' => (int) substr($type, 1),
                'b' => strpos($item, "\0", $pos) - $pos,
                default => 4,
            };
            $val = substr($item, $pos, $l);
            switch ($type0) {
                case 't': $v = unpack('c', $val); break;
                case 'T': $v = unpack('C', $val); break;
                case 's': $v = unpack('s', $val); break;
                case 'S': $v = unpack('S', $val); break;
                case 'm': $v = unpack('l', $val . ((ord($val[2]) >> 7) ? "\xff" : "\0")); break;
                case 'M': $v = unpack('L', $val . "\0"); break;
                case 'i': $v = unpack('l', $val); break;
                case 'I': $v = unpack('L', $val); break;
                case 'f': $v = unpack('f', $val); break;
                case 'd': $v = unpack('d', $val); break;

                case 'n': $v = current(unpack('s', $val)) / (10 ** $type[1]); break;
                case 'N': $v = current(unpack('l', $val)) / (10 ** $type[1]); break;

                case 'c': $v = rtrim($val, ' '); break;
                case 'b': $v = $val; $l++; break;
                default:
                    throw new \RuntimeException('Invalid type passed.');
            }
            $pos += $l;
            $unpacked[$name] = is_array($v) ? current($v) : $v;
        }
        return $unpacked;
    }

    public function get(string $ip): string|array|null
    {
        return $this->max_city ? $this->getCity($ip) : $this->getCountry($ip);
    }

    public function getCountry(string $ip): string
    {
        if ($this->max_city) {
            $tmp = $this->parseCity($this->get_num($ip));
            return $tmp['country']['iso'];
        }
        return $this->id2iso[$this->get_num($ip)];
    }

    public function getCountryId(string $ip): int
    {
        if ($this->max_city) {
            $tmp = $this->parseCity($this->get_num($ip));
            return $tmp['country']['id'];
        }
        return $this->get_num($ip);
    }

    public function getCity(string $ip): ?array
    {
        $seek = $this->get_num($ip);
        return $seek ? $this->parseCity($seek) : null;
    }

    public function getCityFull(string $ip): ?array
    {
        $seek = $this->get_num($ip);
        return $seek ? $this->parseCity($seek, true) : null;
    }

    public function about(): array
    {
        $charset = ['utf-8', 'latin1', 'cp1251'];
        $types   = ['n/a', 'SxGeo Country', 'SxGeo City RU', 'SxGeo City EN', 'SxGeo City', 'SxGeo City Max RU', 'SxGeo City Max EN', 'SxGeo City Max'];
        return [
            'Created' => date('Y.m.d', $this->info['time']),
            'Timestamp' => $this->info['time'],
            'Charset' => $charset[$this->info['charset']],
            'Type' => $types[$this->info['type']],
            'Byte Index' => $this->b_idx_len,
            'Main Index' => $this->m_idx_len,
            'Blocks In Index Item' => $this->range,
            'IP Blocks' => $this->db_items,
            'Block Size' => $this->block_len,
            'City' => [
                'Max Length' => $this->max_city,
                'Total Size' => $this->info['city_size'],
            ],
            'Region' => [
                'Max Length' => $this->max_region,
                'Total Size' => $this->info['region_size'],
            ],
            'Country' => [
                'Max Length' => $this->max_country,
                'Total Size' => $this->info['country_size'],
            ],
        ];
    }
}
