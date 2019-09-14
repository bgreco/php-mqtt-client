<?php

declare(strict_types=1);

namespace PhpMqtt\Client;

use DateInterval;
use DateTime;
use PhpMqtt\Client\Contracts\Repository;
use PhpMqtt\Client\Exceptions\ConnectingToBrokerFailedException;
use PhpMqtt\Client\Exceptions\DataTransferException;
use PhpMqtt\Client\Exceptions\UnexpectedAcknowledgementException;
use PhpMqtt\Client\Repositories\MemoryRepository;
use Psr\Log\LoggerInterface;

/** @noinspection PhpDocMissingThrowsInspection */

class MQTTClient
{
    const EXCEPTION_CONNECTION_FAILED = 0001;
    const EXCEPTION_TX_DATA           = 0101;
    const EXCEPTION_RX_DATA           = 0102;
    const EXCEPTION_ACK_CONNECT       = 0201;
    const EXCEPTION_ACK_PUBLISH       = 0202;
    const EXCEPTION_ACK_SUBSCRIBE     = 0203;

    const QOS_AT_LEAST_ONCE = 0;
    const QOS_AT_MOST_ONCE  = 1;
    const QOS_EXACTLY_ONCE  = 2;

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var string */
    private $clientId;

    /** @var ConnectionSettings|null */
    private $settings;

    /** @var string|null */
    private $caFile;

    /** @var resource|null */
    private $socket;

    /** @var DateTime|null */
    private $lastPingAt;

    /** @var int */
    private $messageId = 1;

    /** @var Repository */
    private $repository;

    /** @var LoggerInterface */
    private $logger;

    /**
     * Constructs a new MQTT client which subsequently supports publishing and subscribing.
     *
     * @param string               $host
     * @param int                  $port
     * @param string|null          $clientId
     * @param string|null          $caFile
     * @param Repository|null      $repository
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        string $host,
        int $port = 1883,
        string $clientId = null,
        string $caFile = null,
        Repository $repository = null,
        LoggerInterface $logger = null
    )
    {
        if ($repository === null) {
            $repository = new MemoryRepository();
        }

        $this->host       = $host;
        $this->port       = $port;
        $this->clientId   = $clientId ?? $this->generateRandomClientId();
        $this->caFile     = $caFile;
        $this->repository = $repository;
        $this->logger     = new Logger($logger);
    }

    /**
     * Connect to the MQTT broker using the given credentials and settings.
     * If no custom settings are passed, the client will use the default settings.
     * See {@see ConnectionSettings} for more details about the defaults.
     *
     * @param string|null        $username
     * @param string|null        $password
     * @param ConnectionSettings $settings
     * @param bool               $sendCleanSessionFlag
     * @return void
     * @throws ConnectingToBrokerFailedException
     */
    public function connect(
        string $username = null,
        string $password = null,
        ConnectionSettings $settings = null,
        bool $sendCleanSessionFlag = false
    ): void
    {
        $this->logger->info(sprintf('Connecting to MQTT broker [%s:%s].', $this->host, $this->port));

        $this->settings = $settings ?? new ConnectionSettings();

        $this->establishSocketConnection();
        $this->performConnectionHandshake($username, $password, $sendCleanSessionFlag);
    }

    /**
     * Opens a socket that connects to the host and port set on the object.
     *
     * @return void
     * @throws ConnectingToBrokerFailedException
     */
    protected function establishSocketConnection(): void
    {
        if ($this->hasCertificateAuthorityFile()) {
            $this->logger->info(sprintf('Using certificate authority file [%s] to verify peer name.', $this->caFile));

            $socketContext = stream_context_create([
                'ssl' => [
                'verify_peer_name' => true,
                    'cafile' => $this->getCertificateAuthorityFile(),
                ],
            ]);
            $connectionString = 'tls://' . $this->getHost() . ':' . $this->getPort();
            $this->socket     = stream_socket_client($connectionString, $errorCode, $errorMessage, 60, STREAM_CLIENT_CONNECT, $socketContext);
        } else {
            $connectionString = 'tcp://' . $this->getHost() . ':' . $this->getPort();
            $this->socket     = stream_socket_client($connectionString, $errorCode, $errorMessage, 60, STREAM_CLIENT_CONNECT);
        }

        if ($this->socket === false) {
            $this->logger->error(sprintf('Establishing a connection with the MQTT broker using connection string [%s] failed.', $connectionString));
            throw new ConnectingToBrokerFailedException($errorCode, $errorMessage);
        }

        stream_set_timeout($this->socket, $this->settings->getSocketTimeout());
        stream_set_blocking($this->socket, $this->settings->wantsToBlockSocket());
    }

    /**
     * Sends a connection message over the socket and processes the response.
     * If the socket connection is not established, an exception is thrown.
     *
     * @param string|null $username
     * @param string|null $password
     * @param bool        $sendCleanSessionFlag
     * @return void
     * @throws ConnectingToBrokerFailedException
     */
    protected function performConnectionHandshake(string $username = null, string $password = null, bool $sendCleanSessionFlag = false): void
    {
        try {
            $i = 0;
            $buffer = '';

            // protocol header
            $buffer .= chr(0x00); $i++; // length of protocol name 1
            $buffer .= chr(0x06); $i++; // length of protocol name 2
            $buffer .= chr(0x4d); $i++; // protocol name: M
            $buffer .= chr(0x51); $i++; // protocol name: Q
            $buffer .= chr(0x49); $i++; // protocol name: I
            $buffer .= chr(0x73); $i++; // protocol name: s
            $buffer .= chr(0x64); $i++; // protocol name: d
            $buffer .= chr(0x70); $i++; // protocol name: p
            $buffer .= chr(0x03); $i++; // protocol version (3.1.1)

            // connection flags
            $flags   = $this->buildConnectionFlags($username, $password, $sendCleanSessionFlag);
            $buffer .= chr($flags); $i++;

            // keep alive settings
            $buffer .= chr($this->settings->getKeepAlive() >> 8); $i++;
            $buffer .= chr($this->settings->getKeepAlive() & 0xff); $i++;

            // client id (connection identifier)
            $clientIdPart = $this->buildLengthPrefixedString($this->clientId);
            $buffer      .= $clientIdPart;
            $i           += strlen($clientIdPart);

            // last will topic and message
            if ($this->settings->hasLastWill()) {
                $topicPart = $this->buildLengthPrefixedString($this->settings->getLastWillTopic());
                $buffer   .= $topicPart;
                $i        += strlen($topicPart);

                $messagePart = $this->buildLengthPrefixedString($this->settings->getLastWillMessage());
                $buffer     .= $messagePart;
                $i          += strlen($messagePart);
            }

            // credentials
            if ($username !== null) {
                $usernamePart = $this->buildLengthPrefixedString($username);
                $buffer      .= $usernamePart;
                $i           .= strlen($usernamePart);
            }
            if ($password !== null) {
                $passwordPart = $this->buildLengthPrefixedString($password);
                $buffer      .= $passwordPart;
                $i           .= strlen($passwordPart);
            }

            // message type and message length
            $header = chr(0x10) . chr($i);

            // send the connection message
            $this->logger->info('Sending connection handshake to MQTT broker.');
            $this->writeToSocket($header . $buffer);

            // read and process the acknowledgement
            $acknowledgement = $this->readFromSocket(4);
            // TODO: improve response handling for better error handling (connection refused, etc.)
            if (ord($acknowledgement[0]) >> 4 === 2 && $acknowledgement[3] === chr(0)) {
                $this->logger->info(sprintf('Connection with MQTT broker at [%s:%s] established successfully.', $this->host, $this->port));
                $this->lastPingAt = microtime(true);
            } else {
                $this->logger->error(sprintf('The MQTT broker at [%s:%s] refused the connection.', $this->host, $this->port));
                throw new ConnectingToBrokerFailedException(self::EXCEPTION_CONNECTION_FAILED, 'A connection could not be established.');
            }
        } catch (DataTransferException $e) {
            $this->logger->error(sprintf('While connecting to the MQTT broker at [%s:%s], a transfer error occurred.', $this->host, $this->port));
            throw new ConnectingToBrokerFailedException(
                self::EXCEPTION_CONNECTION_FAILED,
                'A connection could not be established due to data transfer issues.'
            );
        }
    }

    /**
     * Builds the connection flags from the inputs and settings.
     *
     * @param string|null $username
     * @param string|null $password
     * @param bool        $sendCleanSessionFlag
     * @return int
     */
    protected function buildConnectionFlags(string $username = null, string $password = null, bool $sendCleanSessionFlag = false): int
    {
        $flags = 0;

        if ($sendCleanSessionFlag) {
            $this->logger->debug('Using the [clean session] flag for the MQTT connection.');
            $flags += 1 << 1; // set the `clean session` flag
        }

        if ($this->settings->hasLastWill()) {
            $this->logger->debug('Using the [will] flag for the MQTT connection.');
            $flags += 1 << 2; // set the `will` flag

            if ($this->settings->requiresQualityOfService()) {
                $this->logger->debug(sprintf('Using QoS level [%s] for the MQTT connection.', $this->settings->getQualityOfServiceLevel()));
                $flags += $this->settings->getQualityOfServiceLevel() << 3; // set the `qos` bits
            }

            if ($this->settings->requiresMessageRetention()) {
                $this->logger->debug('Using the [retain] flag for the MQTT connection.');
                $flags += 1 << 5; // set the `retain` flag
            }
        }

        if ($password !== null) {
            $this->logger->debug('Using the [password] flag for the MQTT connection.');
            $flags += 1 << 6; // set the `has password` flag
        }

        if ($username !== null) {
            $this->logger->debug('Using the [username] flag for the MQTT connection.');
            $flags += 1 << 7; // set the `has username` flag
        }

        return $flags;
    }

    /**
     * Sends a ping to the MQTT broker.
     *
     * @return void
     * @throws DataTransferException
     */
    public function ping(): void
    {
        $this->logger->debug('Sending ping to the MQTT broker to keep the connection alive.', [
            'broker' => sprintf('%s:%s', $this->host, $this->port),
        ]);

        $this->writeToSocket(chr(0xc0) . chr(0x00));
    }

    /**
     * Sends a disconnect and closes the socket.
     *
     * @return void
     * @throws DataTransferException
     */
    public function close(): void
    {
        $this->logger->info(sprintf('Closing the connection to the MQTT broker at [%s:%s].', $this->host, $this->port));

        $this->disconnect();

        if ($this->socket !== null && is_resource($this->socket)) {
            stream_socket_shutdown($this->socket, STREAM_SHUT_WR);
        }
    }

    /**
     * Sends a disconnect message to the MQTT broker.
     *
     * @return void
     * @throws DataTransferException
     */
    protected function disconnect(): void
    {
        $this->logger->debug('Sending disconnect package to the MQTT broker.', ['broker' => sprintf('%s:%s', $this->host, $this->port)]);

        $this->writeToSocket(chr(0xe0) . chr(0x00));
    }

    /**
     * Publishes the given message on the given topic. If the additional quality of service
     * and retention flags are set, the message will be published using these settings.
     *
     * @param string $topic
     * @param string $message
     * @param int    $qualityOfService
     * @param bool   $retain
     * @return void
     * @throws DataTransferException
     */
    public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
    {
        $messageId = null;

        if ($qualityOfService > 0) {
            $messageId = $this->nextMessageId();
            $this->repository->addNewPendingPublishedMessage($messageId, $topic, $message, $qualityOfService, $retain);
        }

        $this->publishMessage($topic, $message, $qualityOfService, $retain, $messageId);
    }

    /**
     * Builds and publishes a message.
     * 
     * @param string   $topic
     * @param string   $message
     * @param int      $qualityOfService
     * @param bool     $retain
     * @param int|null $messageId
     * @param bool     $isDuplicate
     * @return void
     * @throws DataTransferException
     */
    protected function publishMessage(
        string $topic,
        string $message,
        int $qualityOfService,
        bool $retain,
        int $messageId = null,
        bool $isDuplicate = false
    ): void
    {
        $this->logger->debug('Publishing an MQTT message.', [
            'broker' => sprintf('%s:%s', $this->host, $this->port),
            'topic' => $topic,
            'message' => $message,
            'qos' => $qualityOfService,
            'retain' => $retain,
            'message_id' => $messageId,
            'is_duplicate' => $isDuplicate,
        ]);

        $i      = 0;
        $buffer = '';

        $topicPart = $this->buildLengthPrefixedString($topic);
        $buffer   .= $topicPart;
        $i        += strlen($topicPart);

        if ($messageId !== null)
        {
            $buffer .= $this->encodeMessageId($messageId); $i += 2;
        }

        $buffer .= $message;
        $i      += strlen($message);

        $cmd = 0x30;
        if ($retain) {
            $cmd += 1 << 0;
        }
        if ($qualityOfService > 0) {
            $cmd += $qualityOfService << 1;
        }
        if ($isDuplicate) {
            $cmd += 1 << 3;
        }

        $header = chr($cmd) . $this->encodeMessageLength($i);

        $this->writeToSocket($header . $buffer);
    }

    /**
     * Subscribe to the given topic with the given quality of service.
     *
     * @param string   $topic
     * @param callable $callback
     * @param int      $qualityOfService
     * @return void
     * @throws DataTransferException
     */
    public function subscribe(string $topic, callable $callback, int $qualityOfService = 0): void
    {
        $this->logger->debug('Subscribing to an MQTT topic.', [
            'broker' => sprintf('%s:%s', $this->host, $this->port),
            'topic' => $topic,
            'qos' => $qualityOfService,
        ]);

        $i         = 0;
        $buffer    = '';
        $messageId = $this->nextMessageId();
        $buffer   .= $this->encodeMessageId($messageId); $i += 2;

        $topicPart = $this->buildLengthPrefixedString($topic);
        $buffer   .= $topicPart;
        $i        += strlen($topicPart);
        $buffer   .= chr($qualityOfService); $i++;

        $this->repository->addNewTopicSubscription($topic, $callback, $messageId, $qualityOfService);

        $cmd    = 0x80 | ($qualityOfService << 1);
        $header = chr($cmd) . chr($i);

        $this->writeToSocket($header . $buffer);
    }

    /**
     * Unsubscribe from the given topic.
     *
     * @param string $topic
     * @return void
     * @throws DataTransferException
     */
    public function unsubscribe(string $topic): void
    {
        $messageId = $this->nextMessageId();

        $this->repository->addNewPendingUnsubscribeRequest($messageId, $topic);

        $this->sendUnsubscribeRequest($messageId, $topic);
    }

    /**
     * Sends an unsubscribe request to the broker.
     *
     * @param int    $messageId
     * @param string $topic
     * @param bool   $isDuplicate
     * @throws DataTransferException
     */
    protected function sendUnsubscribeRequest(int $messageId, string $topic, bool $isDuplicate = false): void
    {
        $this->logger->debug('Unsubscribing from an MQTT topic.', [
            'broker' => sprintf('%s:%s', $this->host, $this->port),
            'message_id' => $messageId,
            'topic' => $topic,
            'is_duplicate' => $isDuplicate,
        ]);

        $i      = 0;
        $buffer = $this->encodeMessageId($messageId); $i += 2;

        $topicPart = $this->buildLengthPrefixedString($topic);
        $buffer   .= $topicPart;
        $i        += strlen($topicPart);

        $cmd    = 0xa2 | ($isDuplicate ? 1 << 3 : 0);
        $header = chr($cmd) . chr($i);

        $this->writeToSocket($header . $buffer);
    }

    /**
     * Runs an event loop that handles messages from the server and calls the registered
     * callbacks for published messages.
     *
     * @param bool $allowSleep
     * @return void
     * @throws UnexpectedAcknowledgementException
     * @throws DataTransferException
     */
    public function loop(bool $allowSleep = true): void
    {
        $this->logger->debug('Starting MQTT client loop.');

        $lastRepublishedAt    = microtime(true);
        $lastReunsubscribedAt = microtime(true);

        while (true) {
            $buffer = null;
            $byte   = $this->readFromSocket(1, true);

            if (strlen($byte) === 0) {
                if($allowSleep){
                    usleep(100000); // 100ms
                }
            } else {
                $cmd        = (int)(ord($byte) / 16);
                $multiplier = 1;
                $value      = 0;

                do {
                    $digit       = ord($this->readFromSocket(1));
                    $value      += ($digit & 127) * $multiplier;
                    $multiplier *= 128;
                } while (($digit & 128) !== 0);

                if ($value) {
                    $buffer = $this->readFromSocket($value);
                }

                if ($cmd) {
                    switch($cmd){
                        // TODO: implement remaining commands
                        case 2:
                            throw new UnexpectedAcknowledgementException(self::EXCEPTION_ACK_CONNECT, 'We unexpectedly received a connection acknowledgement.');
                        case 3:
                            $this->handlePublishedMessage($buffer);
                            break;
                        case 4:
                            $this->handlePublishAcknowledgement($buffer);
                            break;
                        case 9:
                            $this->handleSubscribeAcknowledgement($buffer);
                            break;
                        case 11:
                            $this->handleUnsubscribeAcknowledgement($buffer);
                            break;
                        case 12:
                            $this->handlePingRequest();
                            break;
                        case 13;
                            $this->handlePingAcknowledgement();
                            break;
                    }

                    $this->lastPingAt = microtime(true);
                }
            }

            if ($this->lastPingAt < (microtime(true) - $this->settings->getKeepAlive())) {
                $this->ping();
            }

            if (1 < (microtime(true) - $lastRepublishedAt)) {
                $this->republishPendingMessages();
                $lastRepublishedAt = microtime(true);
            }

            if (1 < (microtime(true) - $lastReunsubscribedAt)) {
                $this->republishPendingUnsubscribeRequests();
                $lastReunsubscribedAt = microtime(true);
            }
        }
    }

    /**
     * Handles a received message. The buffer contains the whole message except
     * command and length. The message structure is:
     *
     *   [topic-length:topic:message]+
     *
     * @param string $buffer
     * @return void
     */
    protected function handlePublishedMessage(string $buffer): void
    {
        $topicLength = (ord($buffer[0]) << 8) + ord($buffer[1]);
        $topic       = substr($buffer, 2, $topicLength);
        $message     = substr($buffer, ($topicLength + 2));

        $subscribers = $this->repository->getTopicSubscriptionsMatchingTopic($topic);

        $this->logger->debug('Handling published message received from an MQTT broker.', [
            'broker' => sprintf('%s:%s', $this->host, $this->port),
            'topic' => $topic,
            'message' => $message,
            'subscribers' => count($subscribers),
        ]);

        foreach ($subscribers as $subscriber) {
            call_user_func($subscriber->getCallback(), $topic, $message);
        }
    }

    /**
     * Handles a received publish acknowledgement. The buffer contains the whole
     * message except command and length. The message structure is:
     * 
     *   [message-identifier]
     * 
     * @param string $buffer
     * @return void
     * @throws UnexpectedAcknowledgementException
     */
    protected function handlePublishAcknowledgement(string $buffer): void
    {
        $this->logger->debug('Handling publish acknowledgement received from an MQTT broker.', [
            'broker' => sprintf('%s:%s', $this->host, $this->port),
        ]);

        if (strlen($buffer) !== 2) {
            $this->logger->notice('Received invalid publish acknowledgement from an MQTT broker.', [
                'broker' => sprintf('%s:%s', $this->host, $this->port),
            ]);
            throw new UnexpectedAcknowledgementException(
                self::EXCEPTION_ACK_PUBLISH,
                'The MQTT broker responded with an invalid publish acknowledgement.'
            );
        }

        $messageId = $this->stringToNumber($this->pop($buffer, 2));

        $result = $this->repository->removePendingPublishedMessage($messageId);
        if ($result === false) {
            $this->logger->notice('Received publish acknowledgement from an MQTT broker for already acknowledged message.', [
                'broker' => sprintf('%s:%s', $this->host, $this->port),
            ]);
            throw new UnexpectedAcknowledgementException(
                self::EXCEPTION_ACK_PUBLISH,
                'The MQTT broker acknowledged a publish that has not been pending anymore.'
            );
        }
    }

    /**
     * Handles a received subscription acknowledgement. The buffer contains the whole
     * message except command and length. The message structure is:
     *
     *   [message-identifier:[qos-level]+]
     *
     * The order of the received QoS levels matches the order of the sent subscriptions.
     *
     * @param string $buffer
     * @return void
     * @throws UnexpectedAcknowledgementException
     */
    protected function handleSubscribeAcknowledgement(string $buffer): void
    {
        $this->logger->debug('Handling subscribe acknowledgement received from an MQTT broker.', [
            'broker' => sprintf('%s:%s', $this->host, $this->port),
        ]);

        if (strlen($buffer) < 3) {
            $this->logger->notice('Received invalid subscribe acknowledgement from an MQTT broker.', [
                'broker' => sprintf('%s:%s', $this->host, $this->port),
            ]);
            throw new UnexpectedAcknowledgementException(
                self::EXCEPTION_ACK_SUBSCRIBE,
                'The MQTT broker responded with an invalid subscribe acknowledgement.'
            );
        }

        $messageId        = $this->stringToNumber($this->pop($buffer, 2));
        $subscriptions    = $this->repository->getTopicSubscriptionsWithMessageId($messageId);
        $acknowledgements = str_split($buffer);

        if (count($acknowledgements) !== count($subscriptions)) {
            $this->logger->notice('Received subscribe acknowledgement from an MQTT broker with wrong number of QoS acknowledgements.', [
                'broker' => sprintf('%s:%s', $this->host, $this->port),
                'required' => count($subscriptions),
                'received' => count($acknowledgements),
            ]);
            throw new UnexpectedAcknowledgementException(
                self::EXCEPTION_ACK_SUBSCRIBE,
                sprintf(
                    'The MQTT broker responded with a different amount of QoS acknowledgements as we have subscriptions.'
                        . ' Subscriptions: %s, QoS Acknowledgements: %s',
                    count($subscriptions),
                    count($acknowledgements)
                )
            );
        }

        foreach ($acknowledgements as $index => $qualityOfServiceLevel) {
            $subscriptions[$index]->setAcknowledgedQualityOfServiceLevel(intval($qualityOfServiceLevel));
        }
    }

    /**
     * Handles a received unsubscribe acknowledgement. The buffer contains the whole
     * message except command and length. The message structure is:
     * 
     *   [message-identifier]
     * 
     * @param string $buffer
     * @return void
     * @throws UnexpectedAcknowledgementException
     */
    protected function handleUnsubscribeAcknowledgement(string $buffer): void
    {
        $this->logger->debug('Handling unsubscribe acknowledgement received from an MQTT broker.', [
            'broker' => sprintf('%s:%s', $this->host, $this->port),
        ]);

        if (strlen($buffer) !== 2) {
            $this->logger->notice('Received invalid unsubscribe acknowledgement from an MQTT broker.', [
                'broker' => sprintf('%s:%s', $this->host, $this->port),
            ]);
            throw new UnexpectedAcknowledgementException(
                self::EXCEPTION_ACK_PUBLISH,
                'The MQTT broker responded with an invalid unsubscribe acknowledgement.'
            );
        }

        $messageId = $this->stringToNumber($this->pop($buffer, 2));

        $result = $this->repository->removePendingUnsubscribeRequest($messageId);
        if ($result === false) {
            $this->logger->notice('Received unsubscribe acknowledgement from an MQTT broker for already acknowledged unsubscribe request.', [
                'broker' => sprintf('%s:%s', $this->host, $this->port),
            ]);
            throw new UnexpectedAcknowledgementException(
                self::EXCEPTION_ACK_PUBLISH,
                'The MQTT broker acknowledged an unsubscribe request that has not been pending anymore.'
            );
        }
    }

    /**
     * Handles a received ping request. Simply sends an acknowledgement.
     *
     * @return void
     * @throws DataTransferException
     */
    protected function handlePingRequest(): void
    {
        $this->logger->debug('Received ping request from an MQTT broker. Sending response.', [
            'broker' => sprintf('%s:%s', $this->host, $this->port),
        ]);

        $this->writeToSocket(chr(0xd0) . chr(0x00));
    }

    /**
     * Handles a received ping acknowledgement.
     *
     * @return void
     */
    protected function handlePingAcknowledgement(): void
    {
        $this->logger->debug('Received ping acknowledgement from an MQTT broker.', ['broker' => sprintf('%s:%s', $this->host, $this->port)]);

        $this->lastPingAt = new DateTime();
    }

    /**
     * Republishes pending messages.
     *
     * @return void
     * @throws DataTransferException
     */
    protected function republishPendingMessages(): void
    {
        $this->logger->debug('Re-publishing pending messages to MQTT broker.', [
            'broker' => sprintf('%s:%s', $this->host, $this->port),
        ]);

        /** @noinspection PhpUnhandledExceptionInspection */
        $dateTime = (new DateTime())->sub(new DateInterval('PT' . $this->settings->getResendTimeout() . 'S'));
        $messages = $this->repository->getPendingPublishedMessagesLastSentBefore($dateTime);

        foreach ($messages as $message) {
            $this->logger->debug('Re-publishing pending message to MQTT broker.', [
                'broker' => sprintf('%s:%s', $this->host, $this->port),
                'message_id' => $message->getMessageId(),
            ]);

            $this->publishMessage(
                $message->getTopic(),
                $message->getMessage(),
                $message->getQualityOfServiceLevel(),
                $message->wantsToBeRetained(),
                $message->getMessageId(),
                true
            );
        }
    }

    /**
     * Re-sends pending unsubscribe requests.
     *
     * @return void
     * @throws DataTransferException
     */
    protected function republishPendingUnsubscribeRequests(): void
    {
        $this->logger->debug('Re-sending pending unsubscribe requests to MQTT broker.', [
            'broker' => sprintf('%s:%s', $this->host, $this->port),
        ]);

        /** @noinspection PhpUnhandledExceptionInspection */
        $dateTime = (new DateTime())->sub(new DateInterval('PT' . $this->settings->getResendTimeout() . 'S'));
        $requests = $this->repository->getPendingUnsubscribeRequestsLastSentBefore($dateTime);

        foreach ($requests as $request) {
            $this->logger->debug('Re-sending pending unsubscribe request to MQTT broker.', [
                'broker' => sprintf('%s:%s', $this->host, $this->port),
                'message_id' => $request->getMessageId(),
            ]);

            $this->sendUnsubscribeRequest($request->getMessageId(), $request->getTopic(), true);
        }
    }

    /**
     * Converts the given string to a number, assuming it is an MSB encoded
     * number. This means preceding characters have higher value.
     *
     * @param string $buffer
     * @return int
     */
    protected function stringToNumber(string $buffer): int
    {
        $length = strlen($buffer);
        $result = 0;

        foreach (str_split($buffer) as $index => $char) {
            $result += ord($char) << (($length - 1) * 8 - ($index * 8));
        }

        return $result;
    }

    /**
     * Encodes the given message identifier as string.
     *
     * @param int $messageId
     * @return string
     */
    protected function encodeMessageId(int $messageId): string
    {
        return chr($messageId >> 8) . chr($messageId % 256);
    }

    /**
     * Encodes the length of a message as string, so it can be transmitted
     * over the wire.
     *
     * @param int $length
     * @return string
     */
    protected function encodeMessageLength(int $length): string
    {
        $result = '';

        do {
          $digit  = $length % 128;
          $length = $length >> 7;

          // if there are more digits to encode, set the top bit of this digit
          if ($length > 0) {
              $digit = ($digit | 0x80);
          }

          $result .= chr($digit);
        } while ($length > 0);

        return $result;
    }

    /**
     * Gets the next message id to be used.
     *
     * @return int
     */
    protected function nextMessageId(): int
    {
        return $this->messageId++;
    }

    /**
     * Writes some data to the socket. If a $length is given and it is shorter
     * than the data, only $length amount of bytes will be sent.
     *
     * @param string   $data
     * @param int|null $length
     * @return void
     * @throws DataTransferException
     */
    protected function writeToSocket(string $data, int $length = null): void
    {
        if ($length === null) {
            $length = strlen($data);
        }

        $length = min($length, strlen($data));

        $result = fwrite($this->socket, $data, $length);

        if ($result === false || $result !== $length) {
            $this->logger->error('Sending data over the socket to an MQTT broker failed.', [
                'broker' => sprintf('%s:%s', $this->host, $this->port),
            ]);
            throw new DataTransferException(self::EXCEPTION_TX_DATA, 'Sending data over the socket failed. Has it been closed?');
        }
    }

    /**
     * Reads data from the socket. If the second parameter $withoutBlocking is set to true,
     * a maximum of $limit bytes will be read and returned. If $withoutBlocking is set to false,
     * the method will wait until $limit bytes have been received.
     *
     * @param int  $limit
     * @param bool $withoutBlocking
     * @return string
     * @throws DataTransferException
     */
    protected function readFromSocket(int $limit = 8192, bool $withoutBlocking = false): string
    {
        $result      = '';
        $remaining   = $limit;

        if ($withoutBlocking) {
            $receivedData = fread($this->socket, $remaining);
            if ($receivedData === false) {
                $this->logger->error('Reading data from the socket from an MQTT broker failed.', [
                    'broker' => sprintf('%s:%s', $this->host, $this->port),
                ]);
                throw new DataTransferException(self::EXCEPTION_RX_DATA, 'Reading data from the socket failed. Has it been closed?');
            }
            return $receivedData;
        }

        while (feof($this->socket) === false && $remaining > 0) {
            $receivedData = fread($this->socket, $remaining);
            if ($receivedData === false) {
                $this->logger->error('Reading data from the socket from an MQTT broker failed.', [
                    'broker' => sprintf('%s:%s', $this->host, $this->port),
                ]);
                throw new DataTransferException(self::EXCEPTION_RX_DATA, 'Reading data from the socket failed. Has it been closed?');
            }
            $result .= $receivedData;
            $remaining = $limit - strlen($result);
        }

        return $result;
    }

    /**
     * Returns the host used by the client to connect to.
     *
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Returns the port used by the client to connect to.
     *
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Returns the identifier used by the client.
     *
     * @return string
     */
    public function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * Returns the certificate authority file, if available.
     *
     * @return string|null
     */
    public function getCertificateAuthorityFile(): ?string
    {
        return $this->caFile;
    }

    /**
     * Determines whether a certificate authority file is available.
     *
     * @return bool
     */
    public function hasCertificateAuthorityFile(): bool
    {
        return $this->getCertificateAuthorityFile() !== null;
    }

    /**
     * Creates a string which is prefixed with its own length as bytes.
     * This means a string like 'hello world' will become
     *   \x00\x0bhello world
     * where \x00\0x0b is the hex representation of 00000000 00001011 = 11
     *
     * @param string $data
     * @return string
     */
    protected function buildLengthPrefixedString(string $data): string
    {
        $length = strlen($data);
        $msb    = $length >> 8;
        $lsb    = $length % 256;

        return chr($msb) . chr($lsb) . $data;
    }

    /**
     * Pops the first $limit bytes from the given buffer and returns them.
     *
     * @param string $buffer
     * @param int    $limit
     * @return string
     */
    protected function pop(string &$buffer, int $limit): string
    {
        $limit = min(strlen($buffer), $limit);

        $result = substr($buffer, 0, $limit);
        $buffer = substr($buffer, $limit);

        return $result;
    }

    /**
     * Generates a random client id in the form of an md5 hash.
     *
     * @return string
     */
    protected function generateRandomClientId(): string
    {
        return md5(uniqid(mt_rand(), true));
    }
}