<?php
/**
 * WebSocketServer Class in PHP5
 * based on http://code.google.com/p/phpwebsocket/
 * @author DerFichtl AT gmail.com / @DerFichtl on Twitter
 * @version 1.0.5
 * @license http://creativecommons.org/licenses/by-sa/3.0/at/
 * @see http://bohuco.net/blog
 * @example:
 * 
 * function process($user, $msg, $server) {
 *    // do something
 * }
 * require_once 'WebSocketServer.php';
 * $webSocket = new WebSocketServer("84.38.67.247", 9001, 'process'); // public ip, port, callback function
 * $webSocket->run();
 */
class WebSocketServer {
	
	protected $address = null;
	
	protected $port = null;
	
	protected $users = array();
	
	protected $master = null;
	
	protected $sockets = array();
	
	protected $admins = array();
	
	protected $question = null;
	/**
	 * @var $callback callback function or array('object', 'function')
	 */
	protected $callback = null;
	
	/**
	 * @var $maxConnection int
	 */
	protected $maxConnection = 99;
	
	
	/**
	 * constructor
	 * @param string $address
	 * @param int $port
	 * @param callback $callback
	 */
	public function __construct($address, $port, $callback) {
		
		$this->address = $address;
		$this->port = $port;
		$this->callback = $callback;
		
		$this->connectMaster();		
	}
	
	
	/**
	 * get all connected users
	 * @return array
	 */
	public function getUsers() {
		return $this->users;
	}
	
	public function getAdmins() {
	    return $this->admins;
	}
	
	public function getQuestion() {
	       return $this->question;
	}
	public function addAdmin($user){
	    $this->admins[]=$user;
	}
	public function createQuestion($text,$choices) {
	    $this->question = new Question($text,$choices);
	}
	
	
	
	protected function connectMaster() {
		$this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("socket_create() failed");
		$this->sockets[] = $this->master;
		socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1) or die("socket_option() failed");
		socket_bind($this->master, $this->address, $this->port) or die("socket_bind() failed");
		socket_listen($this->master, 20) or die("socket_listen() failed");
		return $this->master;
	}

	
	/**
	 * start websocket server
	 */
	public function run() {		
		while(true){
			$changed = $this->sockets;
			socket_select($changed, $write=NULL, $except=NULL,NULL);
			foreach($changed as $socket){
		    	if ($socket == $this->master) {
					echo 'Client Connect :: '.$client = socket_accept($this->master);
					echo PHP_EOL.PHP_EOL;
					if($client < 0){ console("socket_accept() failed"); continue; }
					else{ $this->connect($client); }
		    	} else {
					$bytes = @socket_recv($socket, $buffer, 2048, 0);
					if($bytes == 0) {
						$this->disconnect($socket);
					} else {
						$user = $this->getUserBySocket($socket);
		        		if(! $user->handshake) {
		        			$user->doHandshake($buffer);
		        		} else {
		        			$user->lastAction = time();
		        			// call the callback function
		        			if ($this->callback) {
		        				call_user_func($this->callback, $user, $this->unwrap($buffer), $this);
		        			}
		        		}
					}
				}
			}
		}		
		
	}
	
	
	protected function connect($socket) {
		$this->users[] = new WebSocketUser($socket);
		$this->sockets[] = $socket;		
	}
	
	protected function disconnect($socket) {
		if ($this->users) {
			$found=null;
			$n = count($this->users);
			for($i=0;$i<$n;$i++){
				if($this->users[$i]->socket == $socket){ $found=$i; break; }
			}
			if(!is_null($found)){ array_splice($this->users, $found, 1); }
			$index = array_search($socket, $this->sockets);
			socket_close($socket);
			$this->say($socket." DISCONNECTED!");
			if($index>=0){ array_splice($this->sockets, $index, 1); }
		}
	}
	
	
	protected function getUserBySocket($socket) {
		$found=null;
		foreach($this->users as $user) {
			if($user->socket==$socket) {
				$found=$user;
				break;
			}
		}
		return $found;		
	}

	
	/**
	 * send data via socket to users browser
	 * @param resource socket $client
	 * @param string $msg
	 */
	public function send($client, $msg){ 
		$this->say($client."> ".$msg);
		$msg = $this->wrap($msg);
		@socket_write($client, $msg, strlen($msg));
	}
	
	private function wrap($msg="") { return chr(0).$msg.chr(255); }
	private function unwrap($msg="") { return substr($msg, 1, strlen($msg)-2); }

	/** DEBUG METHOD */
	private function say($msg="") { echo $msg."\n"; }
}




/**
 * WebSocketUser Class
 */
class WebSocketUser {
	
	/**
	 * unique user id
	 * @var string
	 */
	public $id = null;
	
	
	public $socket = null;
	
	
	public $handshake = false;
	
	
	public $ip = null;
	
	
	public $lastAction = null;
	
	
	/**
	 * can hold generic user data as key/value array
	 * @var array
	 */
	public $data = array();
	
	
	/**
	 * constructor
	 * @param resource $socket
	 */
	public function __construct($socket) {
		$this->id = uniqid();
		$this->socket = $socket;
		
		socket_getpeername($socket, $ip);
		$this->ip = $ip;
	}
	
	
	/**
	 * add user to socket users, supports protocol-76 and pre protocol-76 handshakes
	 * @see http://tools.ietf.org/html/draft-hixie-thewebsocketprotocol-76
	 * @param string $buffer
	 * @return bool true
	 */
	public function doHandshake($buffer) {
		
		list($resource, $headers, $securityCode) = $this->handleRequestHeader($buffer);

		$securityResponse = '';
		if (isset($headers['Sec-WebSocket-Key1']) && isset($headers['Sec-WebSocket-Key2'])) {
			$securityResponse = $this->getHandshakeSecurityKey($headers['Sec-WebSocket-Key1'], $headers['Sec-WebSocket-Key2'], $securityCode);
		}
		
		if ($securityResponse) {
			$upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
				"Upgrade: WebSocket\r\n" .
				"Connection: Upgrade\r\n" .
				"Sec-WebSocket-Origin: " . $headers['Origin'] . "\r\n" .
				"Sec-WebSocket-Location: ws://" . $headers['Host'] . $resource . "\r\n" .
	            "\r\n".$securityResponse;		
		} else {
			$upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
				"Upgrade: WebSocket\r\n" .
				"Connection: Upgrade\r\n" .
				"WebSocket-Origin: " . $headers['Origin'] . "\r\n" .
				"WebSocket-Location: ws://" . $headers['Host'] . $resource . "\r\n" .
	            "\r\n";		
		}
		
		socket_write($this->socket, $upgrade.chr(0), strlen($upgrade.chr(0)));
		
		$this->handshake = true;
		return true;	
	}

    private function handleSecurityKey($key) {
    	preg_match_all('/[0-9]/', $key, $number);
    	preg_match_all('/ /', $key, $space);
    	if ($number && $space) {
    		return implode('', $number[0]) / count($space[0]);
    	}
    	return '';
    } 

    private function getHandshakeSecurityKey($key1, $key2, $code) {
        return md5(
            pack('N', $this->handleSecurityKey($key1)).
            pack('N', $this->handleSecurityKey($key2)).
            $code,
            true
        );
    }	
	
	private function handleRequestHeader($request) {
		$resource = $code = null;
		preg_match('/GET (.*?) HTTP/', $request, $match) && $resource = $match[1];
		preg_match("/\r\n(.*?)\$/", $request, $match) && $code = $match[1];
		$headers = array();
		foreach(explode("\r\n", $request) as $line) {
			if (strpos($line, ': ') !== false) {
				list($key, $value) = explode(': ', $line);
				$headers[trim($key)] = trim($value);
			}
		}
		return array($resource, $headers, $code);
	}
}

class Question {
    public $text = null;
    public $choices = array();
    public $votes = array();
    public $id = null;
    
    /**
	 * constructor
	 * @param resource $socket
	 */
	public function __construct($t,$c) {
	    $this->text = $t;
    	$this->choices = $c;
        $this->id = uniqid();
        foreach($this->choices as $key=>$choice){
            $this->votes[$key] = 0;
        }
	}
}

