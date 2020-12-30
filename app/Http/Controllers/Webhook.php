<?php


namespace App\Http\Controllers;

use App\Gateway\EventLogGateway;
use App\Gateway\UserGateway;
use App\Gateway\TransactionsGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Logger;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder;

class Webhook extends Controller
{
    /**
     * @var LINEBot
     */
    private $bot;
    /**
     * @var Request
     */
    private $request;
    /**
     * @var Response
     */
    private $response;
    /**
     * @var Logger
     */
    private $logger;
    /**
     * @var EventLogGateway
     */
    private $logGateway;
    /**
     * @var UserGateway
     */
    private $userGateway;
    /**
     * @var TransactionsGateway
     */
    private $transactionsGateway;
    /**
     * @var array
     */
    private $user;


    public function __construct(
        Request $request,
        Response $response,
        Logger $logger,
        EventLogGateway $logGateway,
        UserGateway $userGateway,
        TransactionsGateway $transactionsGateway
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->logger = $logger;
        $this->logGateway = $logGateway;
        $this->userGateway = $userGateway;
        $this->transactionsGateway = $transactionsGateway;

        // create bot object
        $httpClient = new CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
        $this->bot = new LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);
    }

    public function __invoke()
    {
        // get request
        $body = $this->request->all();

        // debuging data
        $this->logger->debug('Body', $body);

        // save log
        $signature = $this->request->server('HTTP_X_LINE_SIGNATURE') ?: '-';
        $this->logGateway->saveLog($signature, json_encode($body, true));

        return $this->handleEvents();
    }

    private function handleEvents()
    {
        $data = $this->request->all();

        if (is_array($data['events'])) {
            foreach ($data['events'] as $event) {
                // skip group and room event
                if (!isset($event['source']['userId'])) continue;

                // get user data from database
                $this->user = $this->userGateway->getUser($event['source']['userId']);

                // if user not registered
                if (!$this->user) $this->followCallback($event);
                else {
                    // respond event
                    if ($event['type'] == 'message') {
                        if (method_exists($this, $event['message']['type'] . 'Message')) {
                            $this->{$event['message']['type'] . 'Message'}($event);
                        }
                    } else {
                        if (method_exists($this, $event['type'] . 'Callback')) {
                            $this->{$event['type'] . 'Callback'}($event);
                        }
                    }
                }
            }
        }


        $this->response->setContent("No events found!");
        $this->response->setStatusCode(200);
        return $this->response;
    }

    private function followCallback($event)
    {
        $res = $this->bot->getProfile($event['source']['userId']);
        if ($res->isSucceeded()) {
            $profile = $res->getJSONDecodedBody();

            // create welcome message
            $message  = "Halo kak, " . $profile['displayName'] . "!\n";
            $message .= 'Kakak bisa ketik "Transaksi" untuk mencatat pengeluaran ataupun pemasukan.';
            $textMessageBuilder = new TextMessageBuilder($message);

            $message1 = 'Kakak juga bisa lihat riwayat transaksi sesuai tanggal loh, ketik "Riwayat" diikuti dengan tanggal yang ingin kakak lihat.' . "\n";
            $message1 .= "\n";
            $message1 .= 'Contoh "Riwayat 08/12/2020"';
            $textMessageBuilder1 = new TextMessageBuilder($message1);

            $message2 = 'Kalau kakak lupa perintahnya apa aja, kakak tinggal ketik "Bantuan", nanti keluar kok hal yang harus kakak ketik dan fungsinya.';
            $textMessageBuilder2 = new TextMessageBuilder($message2);

            // create sticker message
            $stickerMessageBuilder = new StickerMessageBuilder(11537, 52002759);

            // merge all message
            $multiMessageBuilder = new MultiMessageBuilder();
            $multiMessageBuilder->add($textMessageBuilder);
            $multiMessageBuilder->add($textMessageBuilder1);
            $multiMessageBuilder->add($textMessageBuilder2);
            $multiMessageBuilder->add($stickerMessageBuilder);

            // send reply message
            $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);

            // save user data
            $this->userGateway->saveUser(
                $profile['userId'],
                $profile['displayName']
            );
        }
    }

    private function textMessage($event)
    {
        $userMessage = $event['message']['text'];

        $this->user = $this->userGateway->getUser($event['source']['userId']);
        $userId = $this->user["id"];
        $mode = $this->user["transaction_mode"];

        if ($mode == 1) {
            $numberMessage = (int)$userMessage;
            if ($numberMessage !== 0) {
                $this->transactionsGateway->saveTransaction($numberMessage, 0, $userId);

                $message = $numberMessage;
                $message .= "\nValid";

                $textMessageBuilder = new TextMessageBuilder($message);

                // merge all message
                $multiMessageBuilder = new MultiMessageBuilder();
                $multiMessageBuilder->add($textMessageBuilder);
                $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
            } else {
                $message = $numberMessage;
                $message .= "\nSedang dalam mode transaksi nih, kak. Silahkan ketik nominal yang valid, ya.";

                $textMessageBuilder = new TextMessageBuilder($message);

                // merge all message
                $multiMessageBuilder = new MultiMessageBuilder();
                $multiMessageBuilder->add($textMessageBuilder);
                $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
            }
        } else if (strtolower($userMessage) == 'transaksi') {
            $path = storage_path() . '/json/transactions-flex.json';
            $flexTemplate = file_get_contents($path);

            $httpClient = new CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
            $result = $httpClient->post(LINEBot::DEFAULT_ENDPOINT_BASE . '/v2/bot/message/reply', [
                'replyToken' => $event['replyToken'],
                'messages'   => [
                    [
                        'type'     => 'flex',
                        'altText'  => 'Test Flex Message',
                        'contents' => json_decode($flexTemplate)
                    ]
                ],
            ]);
        } else if (strtolower($userMessage) == 'pemasukan' || strtolower($userMessage) == 'pengeluaran') {
            // $this->addTransactions($userMessage, $event['replyToken']);
            $this->transactionsGateway->changeMode(1, $event['source']['userId']);

            $message = $event['source']['userId'];
            // $message = "Silahkan ketik nominal ${userMessage}nya, kak!";

            $textMessageBuilder = new TextMessageBuilder($message);

            // merge all message
            $multiMessageBuilder = new MultiMessageBuilder();
            $multiMessageBuilder->add($textMessageBuilder);
            $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
        }
    }

    private function stickerMessage($event)
    {
        // create sticker message
        $stickerMessageBuilder = new StickerMessageBuilder(11537, 52002759);

        // create text message
        $message = 'Ketik "transaksi" kalau mau mencatat pengeluaran atau pemasukan kakak, yaa!';
        $textMessageBuilder = new TextMessageBuilder($message);

        // merge all message
        $multiMessageBuilder = new MultiMessageBuilder();
        $multiMessageBuilder->add($stickerMessageBuilder);
        $multiMessageBuilder->add($textMessageBuilder);

        // send message
        $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
    }

    private function addTransactions($msg, $replyToken)
    {
        // create text message

        if (strtolower($msg) == "pemasukan") {
            $message = "Silahkan ketik nominal pemasukannya, kak!";
        } else {
            $message = "Silahkan ketik nominal pengeluarannya, kak!";
        }

        $textMessageBuilder = new TextMessageBuilder($message);

        // merge all message
        $multiMessageBuilder = new MultiMessageBuilder();
        $multiMessageBuilder->add($textMessageBuilder);

        // send message
        $this->bot->replyMessage($replyToken, $multiMessageBuilder);
    }
}
