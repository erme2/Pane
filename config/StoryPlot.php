<?php

namespace config;

use App\Exceptions\SystemException;
use Illuminate\Http\Request;

/**
 * Class StoryPlot
 * the story plot is the core of every story, and all the actions will use it to communicate with each other
 *
 * @package App\Stories
 */

class StoryPlot
{
    const VALID_CONTENT_TYPES = [
        'json' => 'application/json',
    ];

    protected string $contentType;
    public array $data = [];
    protected array $headers = [];
    protected array $log = [
        'errors' => [],
        'warnings' => [],
        'info' => [],
    ];
    public array $options = [];
    protected array $pagination = [];
    public array $requestData = [
        'data' => [],
        'headers' => [],
        'method' => '',
    ];
    protected int $status;

    /**
     * Class constructor.
     *
     * @param string $contentType
     * @throws SystemException
     * @test StoryPlotTest::test__construct
     */
    public function __construct(string $contentType = 'application/json') {
        $this->setContentType($contentType);
    }

    /**
     * Gets the content type of the story plot
     *
     * @return string
     * @test StoryPlotTest::testSetGetContentType_basic
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * Gets the status of the story plot
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status ?? 0;
    }

    /**
     * Gets the data of the story plot
     *
     * @param Request $request
     * @return $this
     */
    public function setRequestData(Request $request): StoryPlot
    {
        $this->requestData['data'] = $request->all();
        $this->requestData['headers'] = $request->headers->all();
        $this->requestData['method'] = $request->method();
        return $this;
    }

    /**
     * Validates and set the content type of the story plot
     *
     * @param string $contentType
     * @return $this
     * @throws SystemException
     */
    public function setContentType(string $contentType): StoryPlot
    {
        if (!in_array($contentType, self::VALID_CONTENT_TYPES)) {
            throw new SystemException("Invalid content type: $contentType");
        }
        $this->contentType = $contentType;
        return $this;
    }

    /**
     * Validates and Set the status of the story plot
     *
     * @param int $status
     * @return $this
     * @throws SystemException
     * @test StoryPlotTest::testSetStatus
     */
    public function setStatus(int $status): StoryPlot
    {
        if ($status < 100 || $status > 599) {
            throw new SystemException("Invalid status code: $status");
        }
        $this->status = $status;
        return $this;
    }

}
