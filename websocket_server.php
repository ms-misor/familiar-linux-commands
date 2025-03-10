<?php
set_time_limit(0);
ob_implicit_flush();

// Define WebSocket host and port
$host = '0.0.0.0';  // Listen on all interfaces
$port = 60000;

// Create a TCP/IP socket
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!$socket) {
    die("Socket creation failed: " . socket_strerror(socket_last_error()) . "\n");
}

// Set socket options to reuse the address
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

// Bind the socket to the IP address and port
if (!socket_bind($socket, $host, $port)) {
    die("Socket bind failed: " . socket_strerror(socket_last_error($socket)) . "\n");
}

// Set the backlog queue size to 511
if (!socket_listen($socket, 511)) {
    die("Socket listen failed: " . socket_strerror(socket_last_error($socket)) . "\n");
}

// Set socket to non-blocking mode
socket_set_nonblock($socket);

echo "WebSocket server started on ws://{$host}:{$port}\n";

$clients = [];

while (true) {
    $read = [$socket];
    $write = NULL;
    $except = NULL;

    // Merge all clients with the main socket
    $read = array_merge($read, $clients);

    // Wait for activity on sockets
    if (socket_select($read, $write, $except, 0, 10) > 0) {
        // Accept new client connection
        if (in_array($socket, $read)) {
            $newClient = socket_accept($socket);
            if ($newClient) {
                socket_set_nonblock($newClient);
                $clients[] = $newClient;
                echo "New client connected\n";
            }
            unset($read[array_search($socket, $read)]);
        }

        // Handle communication with clients
        foreach ($clients as $client) {
            if (in_array($client, $read)) {
                $data = socket_read($client, 1024);
                if ($data) {
                    // Perform WebSocket handshake if needed
                    if (strpos($data, 'Upgrade: websocket') !== false) {
                        performHandshake($client, $data);
                        echo "Handshake completed\n";
                        continue;
                    }

                    echo "Received: " . trim($data) . "\n";

                    // Send data back to all connected clients (broadcast)
                    foreach ($clients as $sendClient) {
                        socket_write($sendClient, $data);
                    }
                } else {
                    // Remove client if the connection is closed
                    unset($clients[array_search($client, $clients)]);
                    socket_close($client);
                    echo "Client disconnected\n";
                }
            }
        }
    }

    usleep(10000); // Prevents high CPU usage
}

// Close the server socket when done
socket_close($socket);

/**
 * Perform WebSocket handshake
 */
function performHandshake($client, $headers)
{
    if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $headers, $matches)) {
        $key = trim($matches[1]);
        $acceptKey = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Accept: $acceptKey\r\n\r\n";

        socket_write($client, $upgrade);
    }
}
?>
