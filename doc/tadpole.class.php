<?php

abstract class Tadpole
{

    private $_default_config = [
        'time_zone'      => 'Asia/Shanghai',
        'token_key'      => 'B3_T+X!C~uvPz$QY6E"d0/N&UqLgZ*hn',
        'token_epoch'    => 1513780878,
        'db_dsn'         => 'sqlite:DBNAME.db',
        'db_user'        => null,
        'db_pass'        => null,
        'cookie_encrypt' => true,
        'cookie_expire'  => 3600 * 24 * 10,
    ];

    protected $_extend_config = [];

    private $_cfg = [];

    private $_logger;

    private $_db;

    public function __construct()
    {
        $this->_cfg = array_merge($this->_default_config, $this->_extend_config);
        date_default_timezone_set($this->_cfg['time_zone']);

        set_exception_handler(function ($e) {
            $this->_log('Exception: ' . $e);
            $this->_end();
        });
    }

    public function loadConfig($cfg = [])
    {
        $this->_cfg = array_merge($this->_cfg, $cfg);
    }

    protected function _getConf($key, $default = null)
    {
        return $this->_arrGet($this->_cfg, $key, $default);
    }

    public function __destruct()
    {
        $this->_end();
    }

    public static function isStaticFile()
    {
        $file = $_SERVER['DOCUMENT_ROOT'] . $_SERVER['SCRIPT_NAME'];

        return (file_exists($file) && pathinfo($file, PATHINFO_EXTENSION) != 'php');
    }

    private function _end()
    {
        if ($this->_logger) {
            fclose($this->_logger);
            $this->_logger = null;
        }
    }

    public function run()
    {
        if ('cli-server' == php_sapi_name()) {
            $file = $_SERVER['DOCUMENT_ROOT'] . $_SERVER['SCRIPT_NAME'];

            if (file_exists($file) && pathinfo($file, PATHINFO_EXTENSION) != 'php') {
                return false;
            }
        }

        $this->_fetchRouter();
        $this->_end();

        return true;
    }

    private function _fetchRouter()
    {
        $params = [];

        if ('cli' == php_sapi_name()) {
            $params = $this->_arrGet($GLOBALS, 'argv', []);

            $this->_log('PARAMS: ' . json_encode($params, JSON_UNESCAPED_UNICODE));

            array_shift($params);
            $action = $this->_arrGet($params, 0);
            if ($action) {
                $action = 'cli' . ucfirst($action);
                array_shift($params);
            } else {
                $action = 'cli';
            }
        } else {
            $this->_log('GET: ' . json_encode($_GET, JSON_UNESCAPED_UNICODE));
            $this->_log('POST: ' . json_encode($_POST, JSON_UNESCAPED_UNICODE));
            $this->_log('COOKIE: ' . json_encode($_COOKIE, JSON_UNESCAPED_UNICODE));
            $this->_log('HTTP_REFERER: ' . $this->_arrGet($_SERVER, 'HTTP_REFERER'));

            $action = 'http' . ucfirst($this->_arrGet($_GET, 'action'));
            $params = ('GET' == $_SERVER['REQUEST_METHOD']) ? $_GET : $_POST;
        }

        if (method_exists($this, $action)) {
            $this->$action($params);
        } else {
            $this->_log("method {$action} not found");
        }
    }

    protected function _cookieGet($key)
    {
        if (!isset($_COOKIE, $key)) {
            return null;
        }

        $value = $_COOKIE[$key];
        if (!$this->_cfg['cookie_encrypt']) {
            return $value;
        }

        $info = explode('.', $value);
        if (3 != count($info)) {
            return null;
        }

        list($value, $time, $hash) = $info;

        if ($hash == hash_hmac('sha256', $value . $time, $this->_cfg['token_key'])) {
            return $value;
        }

        return null;
    }

    protected function _cookieSet($key, $value)
    {
        if (null === $value) {
            setcookie($key, '', time() - 1);
            return;
        }

        if ($this->_cfg['cookie_encrypt']) {
            $time = dechex(time() - $this->_cfg['token_epoch']);
            $value = sprintf('%s.%s.%s', $value, $time, hash_hmac('sha256', $value . $time, $this->_cfg['token_key']));
        }

        setcookie($name, $value, time() + $this->_cfg['cookie_expire']);
    }

    protected function _curl($method, $url, $data = [], $headers = [])
    {
        $method = strtoupper($method);

        $headers = array_merge([], $headers);

        $context = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
            ],
        ];

        if ('GET' == $method) {
            $glue = strpos($url, '?') === false ? '?' : '&';
            $url .= $glue . http_build_query($data);
        } else {
            $context['http']['content'] = http_build_query($data);
        }

        $context = stream_context_create($context);

        $result = file_get_contents($url, false, $context);
        return $result;
    }

    protected function _jsonEcho($data = [], $code = 0)
    {
        header('Content-type: application/json');

        $str = json_encode(['code' => $code, 'data' => $data], JSON_UNESCAPED_UNICODE);

        $this->_log('jsonEcho: ' . $str);
        echo $str;
        $this->_end();
        die;
    }

    protected function _dbQuery($sql, $params = [])
    {
        $stmt = $this->_db()->prepare($sql);
        $r = $stmt->execute($params);
        $data = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }
        return $data;
    }

    protected function _dbOne($sql, $params = [])
    {
        $sql .= ' LIMIT 1';
        $data = $this->_dbQuery($sql, $params);
        if (count($data)) {
            return $data[0];
        } else {
            return null;
        }
    }

    protected function _dbExec($sql, $params = [])
    {
        $stmt = $this->_db()->prepare($sql);
        $r = $stmt->execute($params);
        return $r;
    }

    private function _db()
    {
        if (!$this->_db) {
            $dsn = $this->_cfg['db_dsn'];
            $dsn = str_replace('DBNAME',
                $_SERVER['DOCUMENT_ROOT'] . '/db-' . md5($this->_cfg['token_key'] . 'db') . '.db', $dsn);
            $this->_db = new PDO($dsn, $this->_cfg['db_user'], $this->_cfg['db_pass']);
            $this->_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $this->_db;
    }

    protected function _log($msg)
    {
        if (!$this->_logger) {
            $log_file = 'log-' . md5($this->_cfg['token_key'] . 'logger') . '.log';
            $this->_logger = fopen($log_file, 'a');
        }

        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }

        $msg = date("[Y-m-d H:i:s] ") . $msg . "\n";
        fwrite($this->_logger, $msg);
    }

    protected function _arrGet($arr, $key, $default = null)
    {
        return (is_array($arr) && isset($arr[$key])) ? $arr[$key] : $default;
    }
}
