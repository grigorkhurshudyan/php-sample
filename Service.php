<?php

namespace App\Services\SeventeenTrack;

use App\Repositories\ProxiesRepository;
use App\Services\Proxies\BestProxies;
use Carbon\Carbon;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Collection;
use Exception;
use Log;

class Service
{
    /** @var GuzzleClient */
    protected $guzzleClient;

    /** @var ProxiesRepository */
    protected $proxiesRepository;

    protected $tries = 5;

    /**
     * Service constructor.
     * @throws Exception
     */
    public function __construct()
    {
        $this->guzzleClient = new GuzzleClient([
            'connect_timeout' => 5,
            'timeout'         => 10,
        ]);

        $this->proxiesRepository = new ProxiesRepository(
            $this->getProxyService()
        );
    }

    /**
     * @return BestProxies
     */
    private function getProxyService()
    {
        return new BestProxies;
    }

    /**
     * @param array $trackingNumbers
     * @return Collection
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function handle(array $trackingNumbers): Collection
    {
        $json = json_encode([
            'trackingNumbers' => $trackingNumbers,
        ]);

        $phantomScriptLocation = config('services.17track.script');

        //$proxy = $this->currentProxy;

        exec("QT_QPA_PLATFORM=offscreen phantomjs $phantomScriptLocation '$json' 2>&1", $output);

        //exec("QT_QPA_PLATFORM=offscreen phantomjs --proxy=$proxy $phantomScriptLocation '$json' 2>&1", $output);

        //exec("phantomjs $phantomScriptLocation '$json' 2>&1", $output);

        Log::info(json_encode($output));

        $response = json_decode($output[0]);

        if (!$response) {
            throw new Exception('Invalid PhantomJs response');
        }

        $lastEventId = $this->getLastEventId($response);

        $trackingNumbersFormatted = collect($trackingNumbers)->map(function ($trackingNumber) {
            return [
                'num' => $trackingNumber
            ];
        });

        $this->sendRequest($trackingNumbersFormatted->toArray(), $lastEventId);

        $this->sendRequest($trackingNumbersFormatted->toArray(), $lastEventId);

        $result = $this->getRequest($trackingNumbersFormatted->toArray(), $lastEventId);

        $trackingNumbersResult = collect($result->dat)->map(function ($item) {
            if ($item->delay !== 0) return false;
            if (!isset($item->track) || !isset($item->track->z0)) {
                Log::info(json_encode($item->track));
                return false;
            };

            return [
                'number' => $item->no,
                'status' => ($item->track->z0->c ? $item->track->z0->c . ', ' : '') . $item->track->z0->z,
                'logs'   => $this->getItemStatusLogs($item)
            ];
        })->filter();

        return $trackingNumbersResult;
    }

    /**
     * @param array $cookies
     * @return string
     * @throws Exception
     */
    private function getLastEventId(array $cookies): string
    {
        $filteredCookies = collect($cookies)->filter(function ($cookie) {
            return $cookie->name === 'Last-Event-ID';
        });

        if ($filteredCookies->isEmpty()) {
            throw new Exception('Last event ID is empty');
        }

        $lastEventIdCookie = $filteredCookies->first();

        return $lastEventIdCookie->value;
    }

    /**
     * @param $item
     * @return array
     */
    private function getItemStatusLogs($item): array
    {
        if (!isset($item->track->z2) || !is_array($item->track->z2)) {
            return [];
        }

        return array_map(function ($status) {
            return [
                'location' => $status->c,
                'status'   => $status->z,
                'datetime' => Carbon::parse($status->a)->format('Y-m-d H:i:s')
            ];
        }, $item->track->z2);
    }

    /**
     * @param array $trackingNumbers
     * @param $lastEventId
     * @return mixed
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getRequest(array $trackingNumbers, $lastEventId)
    {
        $response = $this->sendRequest($trackingNumbers, $lastEventId);

        if (!$response) {
            throw new Exception('Empty response');
        }

        $content = $response->getBody()->getContents();

        Log::info($content);

        $result = json_decode($content);

        if (property_exists($result, 'msg') && $result->msg === 'uIP') {

            throw new Exception('Empty response');

        }

        return $result;
    }

    /**
     * @param array $trackingNumbers
     * @param $lastEventId
     * @return mixed|\Psr\Http\Message\ResponseInterface
     * @throws Exception
     */
    private function sendRequest(array $trackingNumbers, $lastEventId)
    {
        try {

            return $this->guzzleClient->request('POST', config('services.17track.endpoint'), [
                'proxy'   => [
                    "https" => $this->proxiesRepository->getOne(),
                ],
                'json'    => [
                    'guid' => '',
                    'data' => $trackingNumbers
                ],
                'headers' => [
                    'accept'           => 'application/json, text/javascript, */*; q=0.01',
                    ':authority'       => 't.17track.net',
                    ':method'          => 'POST',
                    ':path'            => '/restapi/track',
                    ':scheme'          => 'https',
                    'accept-language'  => 'en,ru;q=0.9,en-GB;q=0.8,en-US;q=0.7',
                    'origin'           => 'https://t.17track.net',
                    'referer'          => 'https://t.17track.net/en',
                    'user-agent'       => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/71.0.3578.98 Safari/537.36',
                    'x-requested-with' => 'XMLHttpRequest',
                    'cookie'           => 'Last-Event-ID=' . $lastEventId,
                ]
            ]);

        } catch (Exception $exception) {

            $this->tries--;

            if ($this->tries === 0) {

                throw new Exception('Limit of attempts');

            }

            $this->proxiesRepository->shuffle();

            Log::info($exception->getMessage());

            return $this->sendRequest($trackingNumbers, $lastEventId);

        }
    }
}