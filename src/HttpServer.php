<?php

namespace easydowork\swoole;

use Swoole\Timer;
use Yii;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use yii\base\ExitException;

/**
 * Class HttpServer
 * @property-read Application $app
 * @property-read Server $_server
 * @package easydowork\swoole
 */
class HttpServer
{

    /**
     * @var Application
     */
    public $app;

    /**
     * @var Server
     */
    protected $_server;

    /**
     * HttpServer constructor.
     */
    public function __construct(Application $app)
    {
        $config = Config::getInstance();

        $this->_server = new Server($config->host, $config->port, SWOOLE_PROCESS, $config->sock_type);

        $this->_server->set($config->settings);

        $defaultEvent = [
            'Start'       => [$this, 'onStart'],
            'WorkerStart' => [$this, 'onWorkerStart'],
            'request'     => [$this, 'onRequest'],
        ];

        $eventArray = array_merge($defaultEvent, $config->event);

        foreach ($eventArray as $name => $callback) {
            $this->on($name, $callback);
        }

        $app->swooleServer = $this->_server;

        $this->app = $app;

    }

    /**
     * onStart
     * @param Server $server
     */
    public function onStart(Server $server)
    {
        cli_set_process_title("Yii2 Swoole : master process");
    }

    /**
     * onWorkerStart
     * @param Server $server
     * @param int    $worker_id
     */
    public function onWorkerStart(Server $server, int $worker_id)
    {
        if(function_exists('opcache_reset')){
            opcache_reset();
        }
        if($worker_id >= $server->setting['worker_num']) {
            cli_set_process_title("Yii2 Swoole Work_$worker_id task process");
        } else {
            cli_set_process_title("Yii2 Swoole Work_$worker_id process");
        }
        if($worker_id == 1){
            $webApp = $this->app;
            Timer::tick($webApp->session->getTimeout()*1000, function () use ($webApp){
                return $webApp->session->gcSession($webApp->session->getTimeout());
            });
        }
    }

    /**
     * onRequest
     * @param Request  $request
     * @param Response $response
     * @return false|int
     */
    public function onRequest(Request $request, Response $response)
    {
        try {
            $this->app->getResponse()->setSwooleResponse($response);
            $this->app->getRequest()->setSwooleRequest($request);
            $this->app->run();
        } catch (ExitException $exception) {
            $this->app->end($exception->statusCode, isset($response) ? $response : null);
            $this->app->state = -1;
            return $exception->statusCode;
        } catch (\Exception $exception) {
            $this->app->getErrorHandler()->handleException($exception);
            $this->app->state = -1;
            return false;
        } catch (\Throwable $exception) {
            $this->app->getErrorHandler()->handleError($exception->getCode(),$exception->getMessage(),$exception->getFile(),$exception->getLine());
            return false;
        } finally {

            if(!empty($exception)){
                $this->app->getSession()->close();
            }

            if ($this->app->state == -1) {
                !empty($exception) && Yii::error($exception);
            }
            $this->app->getLog()->getLogger()->flush(true);
        }
    }

    /**
     * getServer
     * @return Server
     */
    public function getServer()
    {
        return $this->_server;
    }

    /**
     * on
     * @param $event
     * @param $callback
     */
    public function on($event, $callback)
    {
        $this->_server->on($event, $callback);
    }

    /**
     * start
     */
    public function start()
    {
        if(Config::getInstance()->beforeStart){
            call_user_func(Config::getInstance()->beforeStart, $this->_server);
        }
$logo = <<<Shell
__   ___ _ ____    ____                     _      
\ \ / (_|_)___ \  / ___|_      _____   ___ | | ___ 
 \ V /| | | __) | \___ \ \ /\ / / _ \ / _ \| |/ _ \
  | | | | |/ __/   ___) \ V  V / (_) | (_) | |  __/
  |_| |_|_|_____| |____/ \_/\_/ \___/ \___/|_|\___|
Shell;
        print_success($logo);
        print_r(PHP_EOL);

        $this->_server->start();
    }

}