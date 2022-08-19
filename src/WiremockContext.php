<?php

namespace MTZ\BehatContext\Wiremock;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use MTZ\BehatContext\Wiremock\Exception\WiremockContextException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class WiremockContext implements Context
{
    const PATH_MAPPINGS         = '/__admin/mappings';
    const PATH_REQUESTS         = '/__admin/requests';
    const PATH_RECORDINGS_START = '/__admin/recordings/start';
    const PATH_RECORDINGS_STOP  = '/__admin/recordings/stop';

    const BODY_RECORDINGS_START = '{"targetBaseUrl":"%s","requestBodyPattern":{"matcher":"equalToJson","ignoreArrayOrder":true,"ignoreExtraElements":true},"persist":true}';

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var string|null
     */
    private $stubsDirectory;

    /**
     * @var HttpClientInterface
     */
    private $client;

    /**
     * @var string[]
     */
    private $stubs = [];

    /**
     * This flag indicates that wiremock was checked and it's up and running
     *
     * @var bool
     */
    private static $wiremockReady = false;

    /**
     * @param string $baseUrl
     * @param string $stubsDirectory
     */
    public function __construct(string $baseUrl, string $stubsDirectory = null)
    {
        $this->baseUrl        = $baseUrl;
        $this->stubsDirectory = $stubsDirectory;
        $this->client         = HttpClient::create();
    }

    /**
     * This hook checks that wiremock is ready to be configured
     * Otherwise it waits 1 sec and tries again.
     * If wiremock not ready in 60 seconds exception will be thrown
     *
     * @BeforeScenario
     *
     * @throws WiremockContextException
     */
    public function checkWiremockIsReady()
    {
        if (self::$wiremockReady) {
            return;
        }

        $numberOfTries = 0;
        while ($numberOfTries < 60) {
            try {
                $this->sendRequest(
                    'GET',
                    self::PATH_MAPPINGS
                );

                self::$wiremockReady = true;
                return;
            } catch (WiremockContextException $e) {
                sleep(1);
                $numberOfTries++;
            }
        }

        throw new WiremockContextException(
            sprintf('Cannot check that Wiremock is ready for %d seconds', $numberOfTries)
        );
    }

    /**
     * @Given /^wiremock stub:$/
     *
     * @param PyStringNode $body
     *
     * @throws WiremockContextException
     */
    public function addWiremockStub(PyStringNode $body)
    {
        $this->addStub($body->getRaw());
    }

    /**
     * @param string $body
     *
     * @throws WiremockContextException
     */
    private function addStub(string $body)
    {
        $response = $this->sendRequest(
            'POST',
            self::PATH_MAPPINGS,
            $body
        );

        $this->stubs[$response['id']] = $response;
    }

    /**
     * @param string      $method
     * @param string      $url
     * @param string|null $body
     *
     * @return array
     *
     * @throws WiremockContextException
     */
    private function sendRequest(string $method, string $url, string $body = null): array
    {
        $options = [];
        if ($body) {
            $options['body'] = $body;
        }

        try {
            $response = $this->client->request($method, $this->baseUrl . $url, $options);
        } catch (Throwable $exception) {
            throw  new WiremockContextException('Exception occurred during sending request', 0, $exception);
        }

        try {
            return $response->toArray();
        } catch (Throwable $exception) {
            throw  new WiremockContextException('Exception occurred during deserialization process', 0, $exception);
        }
    }

    /**
     * @Given /^wiremock stubs from "([^"]+)"$/
     *
     * @param string $path
     *
     * @throws Throwable
     */
    public function addWiremockStubFromFile(string $path)
    {
        $absolutePath = $this->stubsDirectory . '/' . $path;

        if (is_dir($absolutePath)) {
            $files = scandir($absolutePath);

            foreach ($files as $file) {
                $filePath = $absolutePath . '/' . $file;
                if (is_dir($filePath)) {
                    continue;
                }

                try {
                    $this->loadStubFromFile($filePath);
                } catch (Throwable $exception) {
                    throw  new WiremockContextException(
                        sprintf(
                            'Unable to load file "%s"',
                            $filePath
                        )
                        , 0, $exception);
                }
            }
        } else {
            $this->loadStubFromFile($absolutePath);
        }
    }

    /**
     * @param string $filePath
     *
     * @throws WiremockContextException
     */
    private function loadStubFromFile(string $filePath)
    {
        $this->addStub(file_get_contents($filePath));
    }

    /**
     * @Given /^clean wiremock$/
     *
     * @throws WiremockContextException
     */
    public function cleanWiremock()
    {
        $this->sendRequest(
            'DELETE',
            self::PATH_MAPPINGS
        );
        $this->sendRequest(
            'DELETE',
            self::PATH_REQUESTS
        );
    }

    /**
     * @Then /^all stubs should be matched$/
     *
     * @throws WiremockContextException
     */
    public function allStubsMatched()
    {
        $response = $this->sendRequest(
            'GET',
            self::PATH_REQUESTS
        );

        $requestedStubsIds = [];

        foreach ($response['requests'] as $requestData) {
            if (!isset($requestData['stubMapping'])) {
                throw  new WiremockContextException(sprintf(
                    'Unexpected request found: %s %s',
                    $requestData["request"]["method"],
                    $requestData["request"]["absoluteUrl"]
                ));
            }

            if (false === array_search($requestData['stubMapping']['id'], array_keys($this->stubs))) {
                throw  new WiremockContextException(sprintf(
                    'Unexpected stub found: %s %s',
                    $requestData["request"]["method"],
                    $requestData["request"]["absoluteUrl"]
                ));
            }

            $requestedStubsIds[] = $requestData['stubMapping']['id'];
        }

        $requestedStubsIds = array_unique($requestedStubsIds);

        if ($diff = array_diff(array_keys($this->stubs), $requestedStubsIds)) {
            $unrequestedStubs = [];
            foreach ($diff as $stubId) {
                $unrequestedStubs[] = $this->stubs[$stubId];
            }
            throw  new WiremockContextException('Unrequested stub(s) found: ' . json_encode($unrequestedStubs, JSON_PRETTY_PRINT));
        }
    }

    /**
     * @Then /^start wiremock recording with redirection to "([^"]+)"$/
     *
     * @throws WiremockContextException
     */
    public function startRecording(string $url)
    {
        $this->sendRequest(
            'POST',
            self::PATH_RECORDINGS_START,
            sprintf(self::BODY_RECORDINGS_START, $url)
        );
    }

    /**
     * @Then /^stop wiremock recording$/
     *
     * @throws WiremockContextException
     */
    public function stopRecording()
    {
        $this->sendRequest(
            'POST',
            self::PATH_RECORDINGS_STOP
        );
    }

    /**
     * @Then /^stop wiremock recording and save mocks to "([^"]+)"$/
     *
     * @throws WiremockContextException
     */
    public function stopRecordingAndSave(string $path)
    {
        $result = $this->sendRequest(
            'POST',
            self::PATH_RECORDINGS_STOP
        );

        $mappings = $result['mappings'];
        array_walk($mappings, function (array &$mapping) {
            $urlData = parse_url($mapping['request']['url']);

            unset($mapping['request']['url']);
            $mapping['request']['urlPath'] = $urlData['path'];

            $queryParams = [];
            parse_str($urlData['query'], $queryParams);
            unset($queryParams['wa_key']);

            $stubQueryParameters = [];
            foreach ($queryParams as $name => $value) {
                $stubQueryParameters[$name] = ['equalTo' => $value];
            }
            if ($stubQueryParameters) {
                $mapping['request']['queryParameters'] = $stubQueryParameters;
            }

            if (isset($mapping['response']['body'])) {
                $jsonBody = @json_decode($mapping['response']['body']);
                if ($jsonBody) {
                    $mapping['response']['jsonBody'] = $jsonBody;
                    unset($mapping['response']['body']);
                }
            }

            if (isset($mapping['request']['bodyPatterns'])) {
                foreach ($mapping['request']['bodyPatterns'] as &$bodyPattern) {
                    if (isset($bodyPattern['equalToJson'])) {
                        $bodyPattern['equalToJson'] = json_decode($bodyPattern['equalToJson']);
                    }
                }
            }

            unset($mapping['id']);
            unset($mapping['uuid']);
            unset($mapping['persistent']);
            unset($mapping['response']['headers']);
        });

        array_walk($mappings, function (array &$mapping, $key) use ($path) {
            $filename = sprintf("%02d_%s.json", $key, $mapping['name']);

            file_put_contents(
                join('/', [$absolutePath = $this->stubsDirectory, $path, $filename]),
                json_encode($mapping, JSON_PRETTY_PRINT)
            );
        });
    }
}