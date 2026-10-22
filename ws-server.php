<?php
/**
 * Standalone Pure-PHP WebSocket Daemon Server (RFC 6455)
 * 
 * Zero dependencies. Run via CLI if persistent background processes are available:
 * php ws-server.php [port]
 * 
 * Default port: 8080
 * Broadcasts file, folder, and parameter update events in real-time across all connected clients.
 */

if (php_sapi_name() !== 'cli') {
    die("WebSocket server must be run via PHP CLI.\n");
}

$port = isset($argv[1]) ? (int)$argv[1] : 8080;
$address = '0.0.0.0';

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
if (!@socket_bind($server, $address, $port)) {
    die("Unable to bind to {$address}:{$port}\n");
}
socket_listen($server);

echo "===========================================\n";
echo " Realtime Storage WebSocket Server\n";
echo " Listening on ws://{$address}:{$port}\n";
echo " Press Ctrl+C to terminate\n";
echo "===========================================\n\n";

$clients = [$server];
$handshakes = [];

while (true) {
    $read = $clients;
    $write = null;
    $except = null;

    if (socket_select($read, $write, $except, null) < 1) {
        continue;
    }

    // New connection
    if (in_array($server, $read)) {
        $newSocket = socket_accept($server);
        $clients[] = $newSocket;
        $handshakes[(int)$newSocket] = false;
        echo "[+] Client connected: #" . (int)$newSocket . "\n";
        unset($read[array_search($server, $read)]);
    }

    foreach ($read as $socket) {
        $socketId = (int)$socket;
        $bytes = @socket_recv($socket, $buffer, 4096, 0);

        if ($bytes === 0 || $bytes === false) {
            echo "[-] Client disconnected: #" . $socketId . "\n";
            unset($clients[array_search($socket, $clients)]);
            unset($handshakes[$socketId]);
            @socket_close($socket);
            continue;
        }

        // Perform WebSocket Handshake if pending
        if (!$handshakes[$socketId]) {
            if (preg_match('/Sec-WebSocket-Key: (.*)\r\n/', $buffer, $matches)) {
                $secKey = trim($matches[1]);
                $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
                $upgradeResponse = "HTTP/1.1 101 Switching Protocols\r\n" .
                                   "Upgrade: websocket\r\n" .
                                   "Connection: Upgrade\r\n" .
                                   "Sec-WebSocket-Accept: $secAccept\r\n\r\n";
                socket_write($socket, $upgradeResponse, strlen($upgradeResponse));
                $handshakes[$socketId] = true;
                echo "[*] Handshake completed for client #" . $socketId . "\n";

                // Send welcome
                $welcome = encodeFrame(json_encode(['type' => 'connected', 'id' => $socketId]));
                @socket_write($socket, $welcome, strlen($welcome));
            }
            continue;
        }

        // Decode incoming WebSocket frame
        $decoded = decodeFrame($buffer);
        if ($decoded === null) continue;

        // Opcode 8 = connection close
        if ($decoded['opcode'] === 8) {
            unset($clients[array_search($socket, $clients)]);
            unset($handshakes[$socketId]);
            @socket_close($socket);
            echo "[-] Client closed connection: #" . $socketId . "\n";
            continue;
        }

        // Opcode 9 = Ping, respond with Pong (10)
        if ($decoded['opcode'] === 9) {
            $pong = encodeFrame($decoded['payload'], 10);
            @socket_write($socket, $pong, strlen($pong));
            continue;
        }

        $message = $decoded['payload'];
        if (!empty($message)) {
            echo "[msg] From #" . $socketId . ": " . $message . "\n";
            // Broadcast message to all other connected clients
            $frame = encodeFrame($message);
            foreach ($clients as $clientSocket) {
                if ($clientSocket !== $server && $clientSocket !== $socket && !empty($handshakes[(int)$clientSocket])) {
                    @socket_write($clientSocket, $frame, strlen($frame));
                }
            }
        }
    }
}

function decodeFrame($data) {
    $bytes = unpack('C*', $data);
    if (empty($bytes)) return null;

    $firstByte = $bytes[1];
    $secondByte = $bytes[2];

    $opcode = $firstByte & 0x0F;
    $isMasked = ($secondByte & 0x80) === 0x80;
    $payloadLength = $secondByte & 0x7F;

    $offset = 3;
    if ($payloadLength === 126) {
        $payloadLength = ($bytes[3] << 8) + $bytes[4];
        $offset = 5;
    } elseif ($payloadLength === 127) {
        return null; // Very large frames not needed for control signals
    }

    if ($isMasked) {
        $mask = array_slice($bytes, $offset, 4);
        $offset += 4;
        $payload = '';
        for ($i = 0; $i < $payloadLength; $i++) {
            $payload .= chr($bytes[$offset + $i] ^ $mask[($i % 4) + 1]);
        }
    } else {
        $payload = substr($data, $offset - 1, $payloadLength);
    }

    return ['opcode' => $opcode, 'payload' => $payload];
}

function encodeFrame($payload, $opcode = 1) {
    $frame = '';
    $frame .= chr(0x80 | ($opcode & 0x0F));
    $length = strlen($payload);

    if ($length <= 125) {
        $frame .= chr($length);
    } elseif ($length <= 65535) {
        $frame .= chr(126) . pack('n', $length);
    } else {
        $frame .= chr(127) . pack('J', $length);
    }

    $frame .= $payload;
    return $frame;
}
