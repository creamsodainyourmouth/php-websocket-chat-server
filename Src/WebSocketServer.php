<?php

namespace Src;
use Src\ServerSelectLoop;
use Src\SocketWrapper as SW;
use Generator;

abstract class WebSocketServer extends ServerSelectLoop
{
    // IT IS A WEBSOCKET CLIENT LEVEL LOGIC

    // TODO: Create WebSocketConfig ?
    const MAX_FRAME_PAYLOAD_ENCODING_SIZE = 5750;
    const MAX_FRAME_PAYLOAD_ACCEPTED_SIZE = 16384;
    const MIN_FRAME_BYTES_FOR_DECODING = 2;

    const DECODING_STATUS_OK = 0;
    const DECODING_STATUS_LESS_2_BYTES = 1;
    const DECODING_STATUS_MSG_TO_BIG = 2;
    const DECODING_STATUS_CLIENT_NOT_MASKED = 3;
    const DECODING_STATUS_FRAME_NOT_WHOLE = 4;
    const DECODING_STATUS_NOT_UTF8 = 5;


    const HANDSHAKE_SUCCESSFULLY_CODE = 101;
    const HANDSHAKE_NOT_WHOLE_RECVD_YET_CODE = 100;
    const HANDSHAKE_INCORRECT_CODE = 400;


    const MASK_LEN = 4;
    const FIN = 128;
    const MASK = 128;


    const OPCODE_CONTINUATION = 0;
    const OPCODE_TEXT = 1;
    const OPCODE_BINARY = 2;
    const OPCODE_CLOSE = 8;
    const OPCODE_PING = 9;
    const OPCODE_PONG = 10;


    const CLOSURE_NORMAL_CODE = 1000;
    const CLOSURE_GOING_AWAY_CODE = 1001;
    const CLOSURE_PROTOCOL_ERROR_CODE = 1002;
    const CLOSURE_UNACCEPTED_DATA_TYPE_CODE = 1003;
    const CLOSURE_WRONG_DATA_TYPE_CODE = 1007;
    const CLOSURE_POLICY_ERROR_CODE = 1008;
    const CLOSURE_MSG_TO_BIG_CODE = 1009;


    const CLIENT_INITIATOR_CLOSURE = 20;
    const SERVER_INITIATOR_CLOSURE = 21;


    // [RFC 6455]: A connection is defined to initially be in a CONNECTING state.
    // Socket has been created. The connection is not yet open.
    const CONNECTION_STATE_CONNECTING = 22;

    // [RFC 6455]: If the server's response is validated as provided for above, it is
    //   said that _The WebSocket Connection is Established_ and that the
    //   WebSocket Connection is in the OPEN state. If the server finishes these steps without aborting
    //   the WebSocket handshake, the server considers
    //   the WebSocket connection to be established and that the WebSocket
    //   connection is in the OPEN state.  At this point, the server may begin
    //   sending (and receiving) data.
    const CONNECTION_STATE_OPEN = 23;

    // [RFC 6455]: Upon either sending or receiving a Close control frame, it is said that _The WebSocket Closing
    // Handshake is Started_ and that the WebSocket connection is in the CLOSING state.
    const CONNECTION_STATE_CLOSING = 24;

    // [RFC 6455]: When the underlying TCP connection is closed, it is said that _The WebSocket Connection is Closed_ and
    // that the WebSocket connection is in the CLOSED state.
    const CONNECTION_STATE_CLOSED = 25;



    private int $pid;

    public function __construct($ws_server_sock) {
        $this->ws_server_sock = $ws_server_sock;
        $this->pid = posix_getpid();
    }

    public function on_opened_TCP_connection(string $clientID)
    {
        $this->init_websocket_connection($clientID);
    }

    /**
     * @param string $clientID
     * @return bool
     *
     * Returns false if processing on WebSocket level can not be continued for any reason
     * and need to close TCP-connection. Otherwise, returns true.
     */
    public function on_new_data(string $clientID): bool
    {
        // TODO: Function is to big, need to split in logical parts:
        // handshake_handler, decoding_handler, opcode_handler.

        if ($this->clients[$clientID][self::WS_CONNECTION_STATE] === self::CONNECTION_STATE_CONNECTING) {
            // not handshake finished yet
            // TODO: If all data of client's handshake received, but it invalids (incorrect client), we need close this socket.

            $handshake_status_code = $this->handshake($clientID);
            if ($handshake_status_code === self::HANDSHAKE_SUCCESSFULLY_CODE) {
                $this->clients[$clientID][self::WS_CONNECTION_STATE] = self::CONNECTION_STATE_OPEN;
                $this->clients[$clientID][self::RECV_BUFF] = "";
                $this->clients[$clientID][self::RECV_BUFF_CONTAINS] = 0;
                $this->on_opened_websocket_connection($clientID);
                return true;
            }
            elseif ($handshake_status_code === self::HANDSHAKE_NOT_WHOLE_RECVD_YET_CODE) {
                // And wait for whole client's handshake
                return true;
            }
            elseif ($handshake_status_code === self::HANDSHAKE_INCORRECT_CODE) {
                $this->send_http_bad_request($clientID);
                $this->on_aborted_websocket_connection($clientID);
                return false;
            }
            // TODO: In this and similar cases return CRITICAL_ERROR_CODE and close tcp.
            else {
                $this->on_critical_error_websocket_connection($clientID);
                return false;
            }
        }

        // handshake successfully finished

        while (self::DECODING_STATUS_FRAME_NOT_WHOLE !== ($decoded = $this->decode_data($clientID))[0]) {
            // If there is a reason CLOSE websocket connection.
            // Returns true, we need to get CLOSE in response from client.
            switch ($decoded[0]) {
                case self::DECODING_STATUS_CLIENT_NOT_MASKED:
                    $this->send_close_frame_to_client(
                        $clientID,self::CLOSURE_PROTOCOL_ERROR_CODE, "Client not masked"
                    );
                    return true;

                case self::DECODING_STATUS_MSG_TO_BIG:
                    $this->send_close_frame_to_client(
                        $clientID,self::CLOSURE_MSG_TO_BIG_CODE, "Message to big"
                    );
                    return true;

                case self::DECODING_STATUS_NOT_UTF8:
                    $this->send_close_frame_to_client(
                        $clientID,self::CLOSURE_UNACCEPTED_DATA_TYPE_CODE, "Message not UTF-8"
                    );
                    return true;
            }

            // If it is ok, and can continue to communicate
            $frame = $decoded[1];
            switch ($frame['opcode']) {
                case self::OPCODE_TEXT:
                case self::OPCODE_CONTINUATION:
                    $this->on_message($clientID, $frame['payload'], $frame['opcode'], $frame['fin']);
                    break;

                case self::OPCODE_BINARY:
                    $this->send_close_frame_to_client(
                        $clientID, self::CLOSURE_UNACCEPTED_DATA_TYPE_CODE, "Can not accept binary"
                    );
                    return true;

                case self::OPCODE_CLOSE:
                    $this->process_close($clientID, $frame['payload'], $frame['opcode'], $frame['fin']);
                    $this->destroy_websocket_connection($clientID);
                    $this->on_closed_websocket_connection($clientID);
                    return false;

                case self::OPCODE_PING:
                    $this->process_ping($clientID, $frame['payload'], $frame['opcode'], $frame['fin']);
                    break;

                case self::OPCODE_PONG:
                    $this->process_pong($clientID, $frame['payload'], $frame['opcode'], $frame['fin']);
                    break;

                default:
                    $this->send_close_frame_to_client(
                        $clientID,self::CLOSURE_POLICY_ERROR_CODE, "");
                    return true;
            }
        }

        // If frame is not whole, and need to wait for next data of frame OR just continue communication.
        return true;
    }

    protected function generate_ws_ping_frame()
    {
        return $this->encode_data("PING", self::OPCODE_PING)->current();
    }

    protected function generate_ws_close_frame(int $close_code, string $close_reason) {
        $payload = pack("n", $close_code);
        $close_reason = mb_convert_encoding($close_reason, "UTF-8");
        if (false === $close_reason) {
            echo "Error generate_ws_close_frame(): Error encoding to UTF-8\n";
            return false;
        }
        $payload .= $close_reason;
        return $this->encode_data($payload, self::OPCODE_CLOSE)->current();
    }

    // TODO: Can make wrapper func for this. Injected data can be sent between other non control data.
    protected function send_close_frame_to_client(string $clientID, int $close_code, string $close_reason): bool
    {
                // [RFC 6455] The application MUST NOT send any more data frames after sending a Close frame.
        /*
                If an endpoint receives a Close frame and did not previously send a
                Close frame, the endpoint MUST send a Close frame in response.  (When
                sending a Close frame in response, the endpoint typically echos the
                status code it received.)  It SHOULD do so as soon as practical.  An
                endpoint MAY delay sending a Close frame until its current message is
                sent (for instance, if the majority of a fragmented message is
                already sent, an endpoint MAY send the remaining fragments before
                sending a Close frame).  However, there is no guarantee that the
                endpoint that has already sent a Close frame will continue to process data.
         */

        $this->clients[$clientID][self::WS_CONNECTION_STATE] = self::CONNECTION_STATE_CLOSING;

        // If CLOSE frame sent is already, then not will be sent
        if (true === $this->clients[$clientID][self::WS_CLOSE_IS_SENT]) return true;

        $close_frame = $this->generate_ws_close_frame($close_code, $close_reason);
        if (false === $close_frame) return false;


        if (false === $this->send_all_at_once($clientID, $close_frame, strlen($close_frame))) {
            return false;
        }
        $this->clients[$clientID][self::WS_CLOSE_IS_SENT] = true;
        if ($this->clients[$clientID][self::WS_CLOSE_IS_RECVD] && $this->clients[$clientID][self::WS_CLOSE_IS_SENT]) {
            SW::Socket_shutdown($this->clients[$clientID][self::SOCKET], 1);
        }
        return true;
    }

    protected function send_message_to_client(string $clientID, $message, $opcode=self::OPCODE_TEXT) {
        $frames_generator = $this->encode_data($message, $opcode);
        foreach ($frames_generator as $frame) {
            // TODO: Make method write_to_buffer
            $this->clients[$clientID][self::SEND_BUFF] .= $frame;
            $this->clients[$clientID][self::SEND_BUFF_CONTAINS] += strlen($frame);
        }
    }

    protected function send_message_to_all_client_of(string $senderID, array &$receiversIDs, $message, $opcode=self::OPCODE_TEXT) {
        foreach ($receiversIDs as $clientID) {
            $frames_generator = $this->encode_data($message, $opcode);
            foreach ($frames_generator as $frame) {
                // TODO: Make method write_to_buffer
                $this->clients[$clientID][self::SEND_BUFF] .= $frame;
                $this->clients[$clientID][self::SEND_BUFF_CONTAINS] += strlen($frame);
            }
        }
    }

    public function on_closed_TCP_connection(string $clientID)
    {
        // TODO: On Chat Level clientID there is in USERS array yet, and we can notify other users
        // or make it in on_closed_websocket_connection
    }

    public function on_select_exception(string $clientID)
    {
        // TODO: Implement on_error() method.
    }

    private function handshake(string $clientID): int
    {
        // TODO: Implement check client's handshake headers.
        // And if we received only part of handshake?
        $clients_headers = &$this->clients[$clientID][self::RECV_BUFF];
        if (false === $pos = strpos($clients_headers, "\r\n\r\n")) {
            return self::HANDSHAKE_NOT_WHOLE_RECVD_YET_CODE;
        }

        $headers = explode("\r\n", substr($clients_headers, 0, $pos));

        // REQUIRED: start line + |Host| + |Connection| + |Upgrade| + |Sec-WS-Key| + |Sec-WS-Version|
        if (count($headers) < 6) {
            return self::HANDSHAKE_INCORRECT_CODE;
        }

        $start_line = explode(" ", $headers[0]);
        $start_line_parts_count = count($start_line);
        // TODO: Or !== 3
        if ($start_line_parts_count < 3) {
            return self::HANDSHAKE_INCORRECT_CODE;
        }
        $http_method = $start_line[0];
        $http_version = explode("/", $start_line[$start_line_parts_count - 1]);

        if ( !isset($http_version[1]) || (float)$http_version[1] < 1.1 || $http_method !== "GET") {
            return self::HANDSHAKE_INCORRECT_CODE;
        }

        unset($headers[0]); // delete start line, it already processed
        $parsed = [];
        foreach ($headers as $header) {
            $splited = explode(":", $header);
            if ( !isset($splited[1])) return false;
            $parsed[strtolower(trim($splited[0]))] = trim($splited[1]);
        }

        if (
            !isset($parsed['host'])
            || !isset($parsed['connection'])
            || !isset($parsed['upgrade'])
            || !isset($parsed['sec-websocket-key'])
            || !isset($parsed['sec-websocket-version'])
            || strlen(base64_decode($parsed['sec-websocket-key'])) !== 16
            // Sec-WebSocket-Version should be 13?
        ) return self::HANDSHAKE_INCORRECT_CODE;

        $sec_accept = base64_encode(
            sha1($parsed['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)
        );

        $server_handshake = "HTTP/1.1 101 Switching Protocols\r\n" .
                            "Upgrade: websocket\r\n" .
                            "Connection: Upgrade\r\n" .
                            "Sec-WebSocket-Accept: $sec_accept\r\n\r\n";
        $handshake_length = strlen($server_handshake);

        // TODO: Or to it ordinary approach with write and socket_select() ?

        if (false === $this->send_all_at_once($clientID, $server_handshake, $handshake_length)) {
            echo "Error socket during handshake\n";
            return false;
        }

        echo "Successfully handshake of client $clientID\n";
        return self::HANDSHAKE_SUCCESSFULLY_CODE;
    }

    /**
     * @param string $clientID
     * @return array
     *
     * Returns array [DECODING_STATUS, null|decoded_data].
     * If decoding was successfully, second elem will be decoded data, otherwise - null.
     */
    private function decode_data(string $clientID): array
    {
//        if (false ===isset($this->clients[$clientID])) {
//            return false;
//        }

        $data = $this->clients[$clientID][self::RECV_BUFF];
        // On first step of decoding we want to receive at least 2 bytes:
        // 1 byte (FIN bit + OPCODE) + 1 byte (MASK bit + PAYLOAD len).
        // This is enough to make a FRAME (even it is not a whole and will be built by parts of data).
        // If is received less than 2 bytes then just wait for next.

        $data_len = strlen($data);
        // TODO: Are there any cases for returning DECODING_STATUS_LESS_2_BYTES ?
//        if ($data_len < self::MIN_FRAME_BYTES_FOR_DECODING) return [self::DECODING_STATUS_LESS_2_BYTES, null];

        if ($data_len < self::MIN_FRAME_BYTES_FOR_DECODING) return [self::DECODING_STATUS_FRAME_NOT_WHOLE, null];
        $byte0 = ord(substr($data, 0, 1));
        $byte1 = ord(substr($data, 1, 1));

        // 10000001 & 10000000 = 10000000 is FIN (128 dec)
        // 00000001 & 10000000 = 00000000 is not FIN (0 dec)
        $fin_bit = $byte0 & self::FIN;

        // 10000001 & 00001111 = 00000001 is TEXT frame (%x1 hex)
        // 00000000 & 00001111 = 00000000 is CONTINUATION frame (%x0 hex)
        // 10001000 & 00001111 = 00001000 is CONTROL CLOSE frame (%x8 hex)
        // etc.
        $opcode = $byte0 & 15;

        // 0_______ & 10000000 = 0
        $mask_bit = $byte1 & self::MASK;
        if ($mask_bit === 0) return [self::DECODING_STATUS_CLIENT_NOT_MASKED, null]; // client SHOULD send MASKED frames

        // 1_______ & 01111111
        $payload_len = $byte1 & 127;


        if ($payload_len === 126) {
            if ($data_len < 4) return [self::DECODING_STATUS_FRAME_NOT_WHOLE, null];
            $payload_offset = 8;
            $real_payload_len = unpack("nlen", substr($data, 2, 2))['len'];
            $mask = substr($data, 4, 4);
        } elseif ($payload_len === 127) {
            return [self::DECODING_STATUS_MSG_TO_BIG, null];
            // Now APP constraint of MAX_FRAME_PAYLOAD_ACCEPTED_SIZE is 16384 bytes.
            // if extended length is 127, then read payload length more 65 535 bytes.
            if ($data_len < 10) return [self::DECODING_STATUS_FRAME_NOT_WHOLE, null];
            $payload_offset = 14;
            $real_payload_len = unpack("nlen", substr($data, 2, 8))['len'];
            $mask = substr($data, 10, 4);
        } else {
            $mask = substr($data, 2, 4);
            $payload_offset = 6;
            $real_payload_len = $payload_len;
        }

        $frame_len = $payload_offset + $real_payload_len;

        if ($real_payload_len > self::MAX_FRAME_PAYLOAD_ACCEPTED_SIZE) {
            return [self::DECODING_STATUS_MSG_TO_BIG, null];
        }

        // If bytes in program recv buffer less, than length of whole frame.
        // Whole frame not was received yet.
        if ($data_len < $frame_len) {
            return [self::DECODING_STATUS_FRAME_NOT_WHOLE, null];
        } else {
            $this->clients[$clientID][self::RECV_BUFF] = substr($data, $frame_len);
            $this->clients[$clientID][self::RECV_BUFF_CONTAINS] -= $frame_len;
        }

        $unmasked_payload = "";
        $decoded_data = [];

        for ($byte = $payload_offset; $byte < $frame_len ; ++$byte) {
            $mask_byte = $byte - $payload_offset;
            if (isset($data[$byte])) {
                $unmasked_payload .= $data[$byte] ^ $mask[$mask_byte % 4];
            }
        }

        if ($opcode === self::OPCODE_CLOSE) {
            $is_UTF8 = mb_detect_encoding(substr($unmasked_payload, 2), "UTF-8", true);
        }
        else {
            $is_UTF8 = mb_detect_encoding($unmasked_payload, "UTF-8", true);
        }
        if (false === $is_UTF8) {
            return [self::DECODING_STATUS_NOT_UTF8, null];
        }

        $decoded_data['payload'] = $unmasked_payload;
        $decoded_data['fin'] = $fin_bit !== 0;

        if ($opcode === self::OPCODE_CONTINUATION) {
            $decoded_data['opcode'] = self::OPCODE_CONTINUATION;
        }
        elseif ($opcode === self::OPCODE_TEXT) {
            $decoded_data['opcode'] = self::OPCODE_TEXT;
        }
        elseif ($opcode === self::OPCODE_CLOSE) {
            $decoded_data['opcode'] = self::OPCODE_CLOSE;
            $decoded_data['payload'] = [];
            // TODO: Process PHP: Warning if no close code
            $decoded_data['payload']['close_code'] = unpack("ncode", substr($unmasked_payload, 0, 2))['code'];
            $decoded_data['payload']['reason'] = substr($unmasked_payload, 2);
        }
        elseif ($opcode === self::OPCODE_PING) {
            $decoded_data['opcode'] = self::OPCODE_PING;
        }
        elseif ($opcode === self::OPCODE_PONG) {
            $decoded_data['opcode'] = self::OPCODE_PONG;
        }
        else {
            $decoded_data['opcode'] = -1;
        }

        return [self::DECODING_STATUS_OK, $decoded_data];
    }

    private function encode_data($payload, $opcode = self::OPCODE_TEXT): Generator
    {
        $payload_len = strlen($payload);
        $frame_count = ceil($payload_len / self::MAX_FRAME_PAYLOAD_ENCODING_SIZE);
        $last_frame = $frame_count - 1;
        $remainder = $payload_len % self::MAX_FRAME_PAYLOAD_ENCODING_SIZE;
        $last_frame_payload_len = ($remainder) != 0 ? ($remainder) : ($payload_len != 0 ? self::MAX_FRAME_PAYLOAD_ENCODING_SIZE : 0);
        if ($frame_count === 0.0) $frame_count = 1;

        for ($frame = 0; $frame < $frame_count; ++$frame) {
            $is_fin = $frame == $last_frame;
            $opcode = ($frame != 0) ? self::OPCODE_CONTINUATION : $opcode;
            $payload_frame_len = ($frame != $last_frame) ? self::MAX_FRAME_PAYLOAD_ENCODING_SIZE : $last_frame_payload_len;

            $byte0 = ($is_fin) ? $opcode | 128 : $opcode;

            if ($payload_frame_len <= 125) {
//                $payload_len_self_value = $payload_frame_len;
                // TODO: There was a bug! if $extended == 0)
                $extended = "";
                $byte1 = $payload_frame_len;
            }
            elseif ($payload_frame_len <= 65535) {
//                $payload_len_self = 126;
                $extended = pack('n', $payload_frame_len);
//                $payload_len_self_len = 2;
                $byte1 = 126;
            }
            else {
//                $payload_len_self = 127;
                $extended = pack('J', $payload_frame_len);
                // first bit should be 0
                $extended[0] = $extended[0] & 127;
//                $payloadLengthExtendedLength = 8;
                $byte1 = 127;
            }

            yield chr($byte0) . chr($byte1) . $extended . substr(
                $payload, $frame * self::MAX_FRAME_PAYLOAD_ENCODING_SIZE, $payload_frame_len
                );

        }
    }

    private function process_close(string $clientID, $payload, $opcode, $fin)
    {
        /*
         [RFC: 6455]
         Upon either sending or receiving a Close control frame, it is said that
         _The WebSocket Closing Handshake is Started_ and that the  WebSocket connection is in the CLOSING state.

         When the underlying TCP connection is closed, it is said that _The
         WebSocket Connection is Closed_ and that the WebSocket connection is
         in the CLOSED state.  If the TCP connection was closed after the
         WebSocket closing handshake was completed, the WebSocket connection
         is said to have been closed _cleanly_.

         Upon receiving such a frame, the other peer sends a Close frame in response, if it has not already sent one.

         After sending a control frame indicating the connection should be
         closed, a peer does not send any further data; after receiving a
         control frame indicating the connection should be closed, a peer
         discards any further data received.
        */


        $this->clients[$clientID][self::WS_CONNECTION_STATE] = self::CONNECTION_STATE_CLOSING;
        $this->clients[$clientID][self::WS_CLOSE_IS_RECVD] = true;

        $close_code = $payload['close_code'];
        $close_reason = $payload['reason'];
        $initiator = $this->clients[$clientID][self::WS_CLOSE_IS_SENT] ? self::SERVER_INITIATOR_CLOSURE : self::CLIENT_INITIATOR_CLOSURE;

//        $this->clients[$clientID][self::SEND_BUFF] = "";

        // If CLOSE frame sent is already, then not will be sent

        switch ($close_code) {
            case self::CLOSURE_NORMAL_CODE:
                $this->send_close_frame_to_client($clientID, $close_code, "bye-bye");
                $this->on_normal_closure($clientID, $initiator, $close_reason);
                break;

            case self::CLOSURE_GOING_AWAY_CODE:
                // Do not need to send CLOSE in response, because client sent TCP-FIN already
                $this->on_client_go_away_closure($clientID, self::CLIENT_INITIATOR_CLOSURE, $close_reason);
                break;

            case self::CLOSURE_PROTOCOL_ERROR_CODE:
                $this->send_close_frame_to_client($clientID, $close_code, $close_reason);
                $this->on_protocol_error_closure($clientID, $initiator, $close_reason);
                break;

            case self::CLOSURE_UNACCEPTED_DATA_TYPE_CODE:
                $this->send_close_frame_to_client($clientID, $close_code, $close_reason);
                $this->on_unexpected_data_type_closure($clientID, $initiator, $close_reason);
                break;

            case self::CLOSURE_MSG_TO_BIG_CODE:
                $this->send_close_frame_to_client($clientID, $close_code, $close_reason);
                $this->on_msg_to_big_closure($clientID, $initiator, $close_reason);
                break;

            case self::CLOSURE_WRONG_DATA_TYPE_CODE:
                $this->send_close_frame_to_client($clientID, $close_code, $close_reason);
                $this->on_wrong_data_type_closure($clientID, $initiator, $close_reason);
                break;

            case self::CLOSURE_POLICY_ERROR_CODE:
                $this->send_close_frame_to_client($clientID, $close_code, $close_reason);
                $this->on_undefined_error_closure($clientID, $initiator, $close_reason);
                break;

            default:
                $close_code = 4000;
                $close_reason = 'no_reason';
                $this->send_close_frame_to_client($clientID, $close_code, $close_reason);
                $this->on_unexpected_code_closure($clientID, $initiator, $close_reason);
        }
    }

    private function process_ping(string $clientID, $payload, $opcode, $fin)
    {

    }

    private function process_pong(string $clientID, $payload, $opcode, $fin)
    {

    }

    public function on_error_socket_send(string $clientID)
    {

    }

    private function send_ping_frame_to_all_clients()
    {
        $frame = $this->generate_ws_ping_frame();
        foreach ($this->clients as $clientID => $client_data) {
            if ($this->clients[$clientID][self::WS_CONNECTION_STATE] === self::CONNECTION_STATE_OPEN) {
                $this->send_all_at_once(
                    $clientID,
                    $frame,
                    strlen($frame)
                );
            }
        }
    }

    public function on_need_ping()
    {
        $this->send_ping_frame_to_all_clients();
    }

    abstract function on_opened_websocket_connection(string $clientID);

//    abstract function on_message(string $clientID, string|array $message, string $type, bool $fin);
    abstract function on_message(string $senderID, string $message, string $type, bool $fin);

    abstract function on_close(string $clientID, string $close_msg);

    protected function on_normal_closure(string $clientID, int $initiator, string $close_reason)
    {
    }

    protected function on_client_go_away_closure(string $clientID, int $initiator, $close_reason)
    {
    }

    private function init_websocket_connection(string $clientID)
    {
        $this->clients[$clientID][self::WS_CONNECTION_STATE] = self::CONNECTION_STATE_CONNECTING;
        $this->clients[$clientID][self::WS_CLOSE_IS_RECVD] =  false;
        $this->clients[$clientID][self::WS_CLOSE_IS_SENT] =  false;
    }

    private function destroy_websocket_connection(string $clientID)
    {
        $this->clients[$clientID][self::WS_CONNECTION_STATE] = self::CONNECTION_STATE_CLOSED;
    }

    protected function on_protocol_error_closure(string $clientID, int $initiator, $close_reason) {}

    protected function on_unexpected_data_type_closure(string $clientID, int $initiator, $close_reason) {}

    protected function on_wrong_data_type_closure(string $clientID, int $initiator, $close_reason) {}

    protected function on_msg_to_big_closure(string $clientID, int $initiator, $close_reason) {}

    protected function on_undefined_error_closure($clientID, int $initiator, $close_reason) {}

    private function send_http_bad_request(string $clientID)
    {
        $http = "HTTP/1.1 400 Bad Request\r\n\r\n";
        $this->send_all_at_once($clientID, $http, strlen($http));
    }

    private function on_critical_error_websocket_connection(string $clientID)
    {
    }

    private function on_aborted_websocket_connection(string $clientID)
    {
    }

    abstract function on_closed_websocket_connection(string $clientID);

    private function on_unexpected_code_closure(string $clientID, int $initiator, $close_reason) {}


}